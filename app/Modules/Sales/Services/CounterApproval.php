<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * কাউন্টারে দাঁড়িয়েই অনুমোদন।
 *
 * ── কেন লাগে ────────────────────────────────────────────────────────
 * অনুমোদনের সাধারণ পথটা গুদামের কাগজের জন্য বানানো: অনুরোধ যায়,
 * ম্যানেজার সময় করে Approval Centre-এ ঢুকে সিদ্ধান্ত দেন, তারপর কাজটা
 * এগোয়। কাউন্টারে সেটা চলে না — ক্রেতা সামনে দাঁড়িয়ে, আর পেছনে আরও
 * তিনজন। ম্যানেজার পাশেই আছেন; তাঁর কেবল "হ্যাঁ" বলাটা দরকার।
 *
 * ── কেন নিজের লগইন, ভাগ করা PIN নয় ─────────────────────────────────
 * ভাগ করা PIN দিনে দুইবার ব্যবহারের পর সবাই জেনে যায়। তখন অডিটে
 * ম্যানেজারের নাম বসে অথচ কাজটা কর্মীর — **অডিটটাই মিথ্যা সাক্ষী হয়ে
 * দাঁড়ায়**, আর যে জিনিসটার জন্য পুরো ব্যবস্থাটা, সেটাই নষ্ট হয়।
 * তাই ম্যানেজার তাঁর নিজের ইমেইল ও পাসওয়ার্ড দেন।
 *
 * ── যা এখানে ইচ্ছাকৃতভাবে করা হয় না ────────────────────────────────
 * অনুমোদনের অধিকার এখানে যাচাই করা হয় না — সেটা `ApprovalEngine`-এর
 * কাজ, আর সেখানেই নিজের অনুরোধ নিজে অনুমোদন করার নিষেধটা বসানো।
 * এখানে দ্বিতীয়বার লিখলে দুইটা নিয়ম আলাদা হয়ে যেত, আর কাউন্টারেরটা
 * নীরবে ঢিলা হত।
 */
final class CounterApproval
{
    /**
     * এক মিনিটে পাঁচবার — তার বেশি নয়।
     *
     * কাউন্টারের যন্ত্রটা খোলা পড়ে থাকে। সীমা না থাকলে যে কেউ দাঁড়িয়ে
     * ম্যানেজারের পাসওয়ার্ড আন্দাজ করে যেতে পারতেন, আর প্রতিটা চেষ্টা
     * দেখতে হত একটা সাধারণ বিক্রয়ের মতো।
     */
    private const TRIES = 5;

    private const DECAY_SECONDS = 60;

    public function __construct(
        private readonly ApprovalEngine $approvals,
    ) {}

    /**
     * এই কাগজের এই কাজের অপেক্ষমাণ অনুরোধ — থাকলে।
     */
    public function pending(Model $document, string $action): ?Approval
    {
        $approval = $this->approvals->latestFor($document, $action);

        return $approval?->isPending() === true ? $approval : null;
    }

    /**
     * ম্যানেজার নিজের লগইন দিয়ে অনুমোদন করলেন।
     *
     * @param  string  $email  ম্যানেজারের নিজের ইমেইল
     * @param  string  $password  তাঁর নিজের পাসওয়ার্ড — কোথাও লেখা হয় না
     */
    public function decide(Approval $approval, string $email, string $password, ?string $remarks = null): User
    {
        $key = 'counter-approval:'.CompanyContext::id().':'.(auth()->id() ?? 'guest');

        if (RateLimiter::tooManyAttempts($key, self::TRIES)) {
            throw ValidationException::withMessages([
                'approver_password' => __('sales::validation.approver_too_many', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
            ]);
        }

        $approver = $this->approverBy($email);

        /*
         * ইমেইল ভুল আর পাসওয়ার্ড ভুল — একই বার্তা।
         *
         * আলাদা বার্তা দিলে কাউন্টারে দাঁড়িয়ে জেনে নেওয়া যেত কোন কোন
         * ইমেইল এই কোম্পানিতে আছে, আর তখন আন্দাজের কাজটা অর্ধেক হয়ে
         * যেত। পাসওয়ার্ড না জানলে ইমেইলটাও জানার দরকার নেই।
         *
         * `Hash::check` তবু ডাকা হয় না — ব্যবহারকারী না পেলে যাচাই
         * করার মতো কিছু নেই, আর সময়ের পার্থক্য এখানে ঝুঁকি নয়: সীমাটা
         * উপরে বসানো, তাই মিনিটে পাঁচবারের বেশি মাপাই যায় না।
         */
        if ($approver === null || ! Hash::check($password, (string) $approver->password)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'approver_password' => __('sales::validation.approver_unknown'),
            ]);
        }

        try {
            $this->approvals->approve($approval, $approver, $remarks);
        } catch (RuntimeException) {
            /*
             * ইঞ্জিন "না" বলল — দুইটা কারণেই হতে পারে: ইনি অনুমোদনকারী
             * নন, নয়তো ছাড়টা ইনি নিজেই চেয়েছিলেন। দুইটাই ব্যবহারকারীর
             * ভুল, সার্ভারের নয়, তাই ৫০০ নয় — পর্দাতেই বার্তা।
             */
            throw ValidationException::withMessages([
                'approver_email' => __('sales::validation.approver_not_allowed', [
                    'name' => $approver->name,
                ]),
            ]);
        }

        RateLimiter::clear($key);

        return $approver;
    }

    /**
     * এই কোম্পানির সক্রিয় একজন ব্যবহারকারী, এই ইমেইলে।
     *
     * কোম্পানির সদস্যপদ দেখা হয় কারণ একই ABOS-এ একাধিক কোম্পানি চলে;
     * না দেখলে অন্য কোম্পানির ম্যানেজার এই কোম্পানির ছাড় অনুমোদন করতে
     * পারতেন।
     */
    private function approverBy(string $email): ?User
    {
        return User::query()
            ->where('email', trim($email))
            ->where('is_active', true)
            ->whereHas('companies', fn ($q) => $q->where('companies.id', CompanyContext::id()))
            ->first();
    }
}
