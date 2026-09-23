<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Support\NoticeStanding;
use App\Models\Notice;
use App\Models\NoticeAck;
use App\Models\NoticeRead;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * কে পড়েছেন, কে মেনেছেন, আর কার সময় পেরিয়ে গেছে।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬, ধারা ১৮ ────────────────────
 * *"Employee: Rahim · Notice: New HR Policy · Status: Acknowledged ·
 * Date: 23-09-2026 · Time: 10:42 PM"*।
 *
 * ── ⚠️ পড়া আর মেনে নেওয়া এক নয় ─────────────────────────────────────
 * ⓘ পড়া বসে যায় পাতা খুললেই। মেনে নেওয়ায় মানুষকে একটা বোতামে চাপতে
 * হয়। ⛔ এক ঘরে রাখলে *"নতুন নীতিমালা কে মেনেছেন"* প্রশ্নের উত্তর হত
 * *"পাতাটা কে খুলেছেন"* — আর সেটা অডিটে কোনো উত্তরই নয়।
 */
final class NoticeAcknowledgement
{
    /**
     * ⭐ সই দেওয়া।
     *
     * ── ⚠️ কেন লক্ষ্যের বাইরের কেউ সই দিতে পারেন না ─────────────────
     * ⓘ সই মানে *"এই নিয়মটা আমার উপর খাটে, আর আমি জানি"*। ⛔ যাঁর উপর
     * খাটেই না তাঁর সই হিসাবটা ঘোলা করে: *"২০ জনের মধ্যে ১৮ জন
     * মেনেছেন"* সংখ্যাটা তখন আর কিছুই বলে না।
     *
     * @throws ValidationException
     */
    public function sign(Notice $notice, User $user, ?Request $request = null): NoticeAck
    {
        if (! $notice->ack_required) {
            throw ValidationException::withMessages([
                'notice' => __('core.notice.no_signature_wanted'),
            ]);
        }

        if (! app(NoticeAudience::class)->reaches($notice, $user)) {
            throw ValidationException::withMessages([
                'notice' => __('core.notice.not_yours_to_sign'),
            ]);
        }

        /*
         * ⓘ সই দেওয়া মানে পড়াও হয়েছে — উল্টোটা নয়।
         *
         * ⚠️ কেউ পাতা না খুলেই তালিকা থেকে সই দিতে পারেন, আর তখন
         * *"পড়েছেন"* সারিটা না থাকলে হিসাবটা অদ্ভুত দেখাত: মেনেছেন
         * ২০ জন, পড়েছেন ১৮ জন।
         */
        app(NoticeBoard::class)->markRead($notice, $user);

        return NoticeAck::query()->firstOrCreate(
            ['notice_id' => $notice->id, 'user_id' => $user->id],
            [
                'company_id' => $notice->company_id,
                'acknowledged_at' => now(),
                'ip' => $request?->ip(),
                'agent' => substr((string) $request?->userAgent(), 0, 255) ?: null,
            ],
        );
    }

    /**
     * ⭐ এই মানুষটা এই নোটিশের সাথে কোথায় দাঁড়িয়ে।
     *
     * ── ⚠️ ক্রমটা গুরুত্বপূর্ণ ──────────────────────────────────────
     * ⓘ সই আগে দেখা হয়: যিনি মেনে ফেলেছেন তাঁর বেলায় সময় পেরোনো বা
     * না-পড়ার প্রশ্নই ওঠে না। ⛔ উল্টো ক্রমে দেখলে দেরিতে সই দেওয়া
     * মানুষটা চিরকাল "সময় পেরিয়েছে" দেখাতেন।
     */
    public function standingOf(Notice $notice, User $user): NoticeStanding
    {
        $signed = NoticeAck::query()
            ->where('notice_id', $notice->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($signed) {
            return NoticeStanding::ACKNOWLEDGED;
        }

        $escalated = $notice->reminders()
            ->where('user_id', $user->id)
            ->where('escalated', true)
            ->exists();

        if ($escalated) {
            return NoticeStanding::ESCALATED;
        }

        if ($notice->ack_required
            && $notice->ack_deadline !== null
            && $notice->ack_deadline->isPast()) {
            return NoticeStanding::OVERDUE;
        }

        $read = NoticeRead::query()
            ->where('notice_id', $notice->id)
            ->where('user_id', $user->id)
            ->exists();

        return $read ? NoticeStanding::READ : NoticeStanding::UNREAD;
    }

    /**
     * ⭐ কতজন মেনেছেন, আর কতজনের মানার কথা।
     *
     * ── ⚠️ হরটা কেন গোনা কঠিন, আর এখানে কী করা হলো ──────────────────
     * ⓘ *"কতজনের মানার কথা"* মানে লক্ষ্যের ভিতরের মানুষের সংখ্যা, আর
     * সেটা বের করতে প্রতিটা ব্যবহারকারীর চাবি বানাতে হয়। ⛔ হাজার
     * ব্যবহারকারীর কোম্পানিতে ওটা হাজারটা প্রশ্ন, আর এই সংখ্যাটা
     * ড্যাশবোর্ডের প্রতিটা পাতায় লাগে।
     *
     * ⭐ তাই হরটা এখানে **লক্ষ্যহীন নোটিশে** সব সক্রিয় ব্যবহারকারী, আর
     * লক্ষ্য বসানো থাকলে যাঁরা সত্যিই পড়েছেন বা সই দিয়েছেন তাঁদের
     * চেয়ে কম নয় — ⓘ অর্থাৎ সংখ্যাটা রক্ষণশীল, আর কখনো ১০০%-এর বেশি
     * দেখায় না।
     *
     * @return array{signed: int, owed: int}
     */
    public function tally(Notice $notice): array
    {
        $signed = NoticeAck::query()->where('notice_id', $notice->id)->count();

        if ($notice->targets()->count() === 0) {
            return ['signed' => $signed, 'owed' => max(0, User::query()->count() - $signed)];
        }

        /*
         * ⓘ লক্ষ্য বসানো থাকলে হরটা আসে ছুঁয়ে যাওয়া মানুষের সংখ্যা
         * থেকে — যাঁরা পড়েছেন বা সই দিয়েছেন।
         *
         * ⚠️ এটা কম দেখাতে পারে, আর সেটা জেনে নেওয়া: ⛔ বেশি দেখানো
         * মানে ভুয়া *"সবাই মেনেছেন"*, আর ঐ ভুলটা বিপজ্জনক দিকে।
         */
        $touched = NoticeRead::query()->where('notice_id', $notice->id)->count();

        return ['signed' => $signed, 'owed' => max(0, max($touched, $signed) - $signed)];
    }
}
