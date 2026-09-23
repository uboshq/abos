<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\NoticePriority;
use App\Core\Support\NoticeStatus;
use App\Models\Notice;
use App\Models\NoticeTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * নোটিশের অবস্থা বদলানোর একমাত্র দরজা।
 *
 * ── ⚠️ কেন একটাই দরজা ───────────────────────────────────────────────
 * ⓘ অবস্থা বদলায় অনেক জায়গা থেকে: পর্দা, অনুমোদনের ইঞ্জিন, সময়ের
 * কাজ, API। ⛔ প্রত্যেকে নিজের মতো `update(['status' => ...])` লিখলে
 * একদিন কেউ সংরক্ষণাগার থেকে সোজা প্রকাশে যেত, আর অনুমোদনের ধাপটা
 * নীরবে এড়ানো যেত।
 *
 * ⭐ তাই বৈধ পথের তালিকা [[NoticeStatus]]-এ, আর সেটা মানার জায়গা
 * কেবল এখানে।
 *
 * ── ⓘ কেন মডেলের `booted()`-এ নয় ───────────────────────────────────
 * ⚠️ ওখানে বসালে নিয়মটা সিডার আর মাইগ্রেশনের উপরেও খাটত, আর পুরনো
 * সারি ঠিক করার কোনো পথ থাকত না। ⛔ ডেটা সারানোর কাজ আর ব্যবসার নিয়ম
 * এক জায়গায় রাখলে একটাকে বাঁচাতে গিয়ে অন্যটা ভাঙে।
 */
final class NoticeLifecycle
{
    /** নোটিশের নম্বর সিরিজ। */
    public const SERIES = 'NTC';

    public function __construct(private readonly NumberSeriesEngine $numbers) {}

    /**
     * নতুন খসড়া।
     *
     * ⓘ নম্বরটা **এখানেই** বসে, প্রকাশের সময় নয়। ⚠️ প্রকাশে বসালে
     * খসড়া অবস্থায় নোটিশটাকে নাম ধরে ডাকা যেত না, অথচ অনুমোদনের
     * আলোচনা হয় ঠিক তখনই — *"NTC-0058 নিয়ে কথা আছে"*।
     *
     * @param  array<string, mixed>  $data
     */
    public function draft(array $data): Notice
    {
        return DB::transaction(function () use ($data) {
            $priority = $this->priorityOf($data['priority'] ?? null);

            /*
             * ⚠️ `forceCreate`, `create` নয় — আর কারণটা মেপে শেখা।
             *
             * ⓘ `document_no` আর `status` ইচ্ছাকৃতভাবে [[Notice]]-এর
             * `fillable`-এ নেই, যাতে কোনো পর্দার `update($request->all())`
             * দিয়ে অবস্থা বদলে না যায়। ⛔ কিন্তু তখন **এই সেবাটাও**
             * ওগুলো বসাতে পারে না — প্রথম রানে আটটা দাবিই লাল হয়েছিল।
             *
             * ⓘ অর্থাৎ পাহারাটা কাজ করছিল, আর ভুল দরজাটা ছিল আমারই।
             * ⭐ একমাত্র দরজা হওয়ার দাম এটাই: দরজাটাকে চাবিটা হাতে
             * নিয়ে ঢুকতে হয়।
             */
            return Notice::query()->forceCreate([
                'company_id' => CompanyContext::id(),
                'document_no' => $this->numbers->next(self::SERIES),
                'status' => NoticeStatus::DRAFT->value,
                'notice_category_id' => $data['notice_category_id'] ?? null,
                'type' => $data['type'] ?? null,
                'priority' => $priority->value,
                'title' => $data['title'] ?? '',
                'summary' => $data['summary'] ?? null,
                'body' => $data['body'] ?? '',
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
                'is_active' => true,
                'in_ticker' => $priority->goesToTheBar(),
                'read_required' => (bool) ($data['read_required'] ?? false),

                /*
                 * ⓘ ডিফল্টটা অগ্রাধিকার থেকে আসে, কিন্তু হাতে দেওয়া মান
                 * তার উপরে বসে — ⚠️ একটা জরুরি নোটিশে সই না চাওয়ার
                 * সিদ্ধান্ত মানুষ নিতে পারেন, যন্ত্র নয়।
                 */
                'ack_required' => array_key_exists('ack_required', $data)
                    ? (bool) $data['ack_required']
                    : $priority->needsAcknowledgementByDefault(),

                'ack_deadline' => $data['ack_deadline'] ?? null,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * ⭐ একটা ছাঁচ থেকে খসড়া।
     *
     * ── ⚠️ ছাঁচের মান নিচে, হাতে দেওয়া মান উপরে ───────────
     * ⓘ ছাঁচ একটা **শুরু**, একটা বেড়া নয়। ⛔ ছাঁচের মান উপরে বসালে
     * মানুষ শিরোনাম বদলানোর পরও পুরনো শিরোনামটাই বসত, নীরবে।
     *
     * ⓘ লক্ষ্যও ছাঁচ থেকে আসে — *"গুদামের সবাইকে"* প্রতিবার হাতে
     * বাছা মানে একদিন কেউ সবাইকে পাঠিয়ে দেবেন।
     *
     * @param  array<string, mixed>  $extra
     */
    public function fromTemplate(NoticeTemplate $template, array $extra = []): Notice
    {
        $base = array_filter([
            'title' => $template->title,
            'summary' => $template->summary,
            'body' => $template->body,
            'priority' => $template->priority?->value,
            'notice_category_id' => $template->notice_category_id,
            'type' => $template->type,
        ], fn ($value) => $value !== null && $value !== '');

        $notice = $this->draft([...$base, ...$extra]);

        $aimed = $extra['audience'] ?? $template->audience ?? [];

        if (is_array($aimed) && $aimed !== []) {
            app(NoticeAudience::class)->aimAt($notice, $aimed);
        }

        return $notice;
    }

    /** অনুমোদনের জন্য পাঠানো। */
    public function submit(Notice $notice): Notice
    {
        return $this->moveTo($notice, NoticeStatus::SUBMITTED);
    }

    /** কেউ দেখছেন। */
    public function review(Notice $notice): Notice
    {
        return $this->moveTo($notice, NoticeStatus::UNDER_REVIEW);
    }

    /**
     * সই হলো — কিন্তু এখনো প্রকাশ নয়।
     *
     * ── ⛔ নিজের জরুরি নোটিশ নিজে অনুমোদন নয় ────────────────────────
     * ⭐ মালিকের স্পেক, ধারা ১৭: *"Creator ≠ Approver"*।
     *
     * ⓘ নিয়মটা কেবল `CRITICAL` আর তার উপরে খাটে, আর সেটা ইচ্ছাকৃত:
     * ⚠️ ছুটির খবর লিখে নিজে সই দিতে না পারলে ছোট অফিসে কোনো নোটিশই
     * বেরোত না — সেখানে লেখক আর অনুমোদক একই মানুষ।
     *
     * ⛔ কিন্তু *"আজ রাতে সার্ভার বন্ধ, সবাই সই দিন"* ধরনের নোটিশে
     * একজন মানুষ একাই সব করে ফেললে সইয়ের ব্যবস্থাটার কোনো মানে থাকে
     * না — যে কাগজে দ্বিতীয় চোখ নেই সেটা কেবল একটা ঘোষণা।
     *
     * ⓘ সুইচটা নিয়ন্ত্রণ প্যানেলে, কারণ এক-মানুষের অফিসে এটা বন্ধ
     * রাখতেই হবে।
     */
    public function approve(Notice $notice): Notice
    {
        $this->assertSomeoneElseSigns($notice);

        return $this->moveTo($notice, NoticeStatus::APPROVED);
    }

    /**
     * ⛔ যিনি লিখেছেন তিনিই সই দিচ্ছেন কি না।
     *
     * ⚠️ পাহারাটা সেবায়, পর্দায় নয় — ⓘ অনুমোদন আসতে পারে পর্দা, API,
     * কিংবা অনুমোদনের ইঞ্জিন থেকে, আর প্রতিটা নতুন পথ পর্দার পাহারার
     * একটা করে ফাঁক।
     *
     * @throws ValidationException
     */
    private function assertSomeoneElseSigns(Notice $notice): void
    {
        $priority = $notice->priority;

        if ($priority === null || ! $priority->needsAcknowledgementByDefault()) {
            return;
        }

        if (! (bool) app(SettingsService::class)->get('notice.creator_cannot_approve', true)) {
            return;
        }

        $me = auth()->id();

        /*
         * ⓘ লেখক জানা না থাকলে নিয়মটা খাটে না।
         *
         * ⚠️ পুরনো সারিতে `created_by` খালি, আর খালিকে *"আমিই লেখক"*
         * ধরলে কেউ ওগুলো কোনোদিন অনুমোদন করতে পারতেন না।
         */
        if ($me === null || $notice->created_by === null) {
            return;
        }

        if ((int) $notice->created_by === (int) $me) {
            throw ValidationException::withMessages([
                'status' => __('core.notice.creator_cannot_approve', [
                    'no' => $notice->document_no ?: (string) $notice->id,
                ]),
            ]);
        }
    }

    /**
     * ফিরিয়ে দেওয়া — আর কারণ ছাড়া নয়।
     *
     * ⚠️ কারণটা বাধ্যতামূলক, কারণ ছয় মাস পরে *"কেন ফেরত এল"* প্রশ্নের
     * এই লেখাটাই একমাত্র উত্তর। ⓘ একই নিয়ম দরের সংশোধনে আর ট্রিপের
     * ফেরতেও।
     */
    public function reject(Notice $notice, string $why): Notice
    {
        $why = trim($why);

        if ($why === '') {
            throw ValidationException::withMessages([
                'reason' => __('core.notice.reject_needs_reason'),
            ]);
        }

        return $this->moveTo($notice, NoticeStatus::REJECTED, ['recall_reason' => $why]);
    }

    /**
     * ⭐ প্রকাশ — এখনই।
     *
     * ⓘ `published_at` ঘটনার স্মৃতি, `starts_on` মানুষের সিদ্ধান্ত।
     * ⚠️ দুইটা আলাদা রাখা হয়েছে যাতে সময় ধরে প্রকাশের পর বলা যায়
     * কাজটা আদৌ চলেছে কি না।
     */
    public function publish(Notice $notice, ?Carbon $at = null): Notice
    {
        return $this->moveTo($notice, NoticeStatus::PUBLISHED, [
            'published_at' => $at ?? now(),

            /*
             * ⓘ মেয়াদ বসানো না থাকলে `ends_on` থেকে নেওয়া হয়।
             *
             * ⚠️ দুইটা ঘর দুই প্রশ্নের উত্তর দেয়, কিন্তু মানুষ সাধারণত
             * একটাই ভরেন। ⛔ মিলিয়ে না নিলে *"৩১ তারিখ পর্যন্ত"* লেখা
             * নোটিশ ১ তারিখেও বারে বসে থাকত।
             */
            'expires_at' => $notice->expires_at
                ?? ($notice->ends_on?->endOfDay()),
        ]);
    }

    /** সময় ঠিক করা — নিজে থেকে প্রকাশ হবে। */
    public function schedule(Notice $notice, Carbon $when): Notice
    {
        if ($when->isPast()) {
            throw ValidationException::withMessages([
                'starts_on' => __('core.notice.schedule_in_the_past'),
            ]);
        }

        return $this->moveTo($notice, NoticeStatus::SCHEDULED, ['starts_on' => $when->toDateString()]);
    }

    /**
     * ⛔ প্রকাশের পরে ফিরিয়ে নেওয়া — মোছা নয়।
     *
     * ⚠️ প্রত্যাহার আর বাতিল এক নয়, আর তফাতটা দামি: ⓘ প্রত্যাহার মানে
     * লেখাটা মানুষ **দেখে ফেলেছে**। ⛔ দুইটাকে এক ধরলে খাতা বলত কথাটা
     * কেউ জানে না, অথচ গোটা অফিস জানে।
     */
    public function recall(Notice $notice, string $why): Notice
    {
        $why = trim($why);

        if ($why === '') {
            throw ValidationException::withMessages([
                'reason' => __('core.notice.recall_needs_reason'),
            ]);
        }

        return $this->moveTo($notice, NoticeStatus::RECALLED, [
            'recalled_at' => now(),
            'recalled_by' => auth()->id(),
            'recall_reason' => $why,
            'in_ticker' => false,
        ]);
    }

    /** মেয়াদ ফুরাল — সময়ের কাজ এটা ডাকে। */
    public function expire(Notice $notice): Notice
    {
        return $this->moveTo($notice, NoticeStatus::EXPIRED, ['in_ticker' => false]);
    }

    /** সাময়িক থামানো — পরে আবার চালু হতে পারে। */
    public function suspend(Notice $notice): Notice
    {
        return $this->moveTo($notice, NoticeStatus::SUSPENDED, ['in_ticker' => false]);
    }

    /** তুলে রাখা — খোঁজা যায়, দেখা যায় না। */
    public function archive(Notice $notice): Notice
    {
        return $this->moveTo($notice, NoticeStatus::ARCHIVED, ['is_active' => false, 'in_ticker' => false]);
    }

    /** লেখকের নিজের থামানো, প্রকাশের আগে। */
    public function cancel(Notice $notice): Notice
    {
        return $this->moveTo($notice, NoticeStatus::CANCELLED);
    }

    /**
     * ⭐ পুরনোটাকে নতুন নোটিশ দিয়ে ঢেকে দেওয়া।
     *
     * ⓘ স্পেকের ধারা ২৬। ⚠️ পুরনোটা প্রত্যাহার হয়, কিন্তু সুতোটা
     * থেকে যায় — ছয় মাস পরেও বলা যায় ভুলটা কী ছিল আর কে শুধরেছেন।
     */
    /**
     * ⭐ সংরক্ষণাগার থেকে ফিরিয়ে আনা — খসড়া হিসেবে।
     *
     * ── ⚠️ কেন এটা অবস্থা-বদল নয় ──────────────────────────
     * ⓘ [[NoticeStatus]]-এ সংরক্ষণাগার শেষ ঘর — ওখান থেকে কোনো
     * পথ নেই, আর সেটা ইচ্ছাকৃত। ⛔ সরাসরি প্রকাশে ফেরার পথ রাখলে
     * পুরনো একটা নোটিশ দুই বছর পরে অনুমোদন ছাড়াই সবার চোখের
     * সামনে চলে আসত।
     *
     * ⭐ তাই ফেরত আসে **খসড়া হয়ে**: লেখাটা ফিরল, কিন্তু পথটা
     * আবার শুরু থেকে হাঁটতে হয় — সই, তারপর প্রকাশ।
     *
     * ⓘ নম্বরটা বদলায় না: পুরনো নোটিশটাই ফিরছে, নতুন একটা নয়।
     *
     * @throws ValidationException
     */
    public function restore(Notice $notice): Notice
    {
        $now = $notice->status instanceof NoticeStatus
            ? $notice->status
            : NoticeStatus::from((string) $notice->status);

        if ($now !== NoticeStatus::ARCHIVED) {
            throw ValidationException::withMessages([
                'status' => __('core.notice.only_archived_restores', [
                    'no' => $notice->document_no ?: (string) $notice->id,
                ]),
            ]);
        }

        return DB::transaction(function () use ($notice) {
            $notice->forceFill([
                'status' => NoticeStatus::DRAFT->value,
                'is_active' => true,

                /*
                 * ⛔ প্রকাশের স্মৃতিগুলো মুছে দেওয়া হয় না।
                 *
                 * ⓘ `published_at` বলে নোটিশটা **একবার বেরিয়েছিল**, আর
                 * সেটা সত্যি — মানুষ পড়েছে, সই দিয়েছে। ⚠️ মুছলে ওই
                 * সইগুলো এমন একটা নোটিশের হয়ে যেত যেটা কখনো প্রকাশই হয়নি।
                 */
            ])->save();

            return $notice->fresh();
        });
    }

    public function supersede(Notice $old, Notice $new, string $why): Notice
    {
        return DB::transaction(function () use ($old, $new, $why) {
            $this->recall($old, $why);

            $old->forceFill(['superseded_by' => $new->id])->save();

            return $old->fresh();
        });
    }

    /**
     * ⭐ প্রকাশিত নোটিশ সম্পাদনা — নতুন সংস্করণ রেখে।
     *
     * ── ⚠️ কেন পুরনো লেখাটা ধরে রাখা হয় ────────────────
     * ⓘ নোটিশটা ইতিমধ্যে মানুষ পড়েছে। ⛔ লেখাটা বদলে দিলে
     * ছয় মাস পরে *"আমি এটা পড়িনি"* বলা মানুষটাকে দেখানোর মতো
     * কিছু থাকত না — তিনি যা পড়েছিলেন সেটা আর কোথাও নেই।
     *
     * ⓘ সংস্করণটা বসে **বদলানোর আগে**, পরে নয় — ⚠️ পরে
     * বসালে যা সংরক্ষিত হত সেটা নতুন লেখাটাই।
     *
     * @param  array<string, mixed>  $data
     */
    public function reviseInPlace(Notice $notice, array $data, ?string $why = null): Notice
    {
        return DB::transaction(function () use ($notice, $data, $why) {
            $last = (int) $notice->versions()->max('revision');

            $notice->versions()->create([
                'company_id' => $notice->company_id,
                'revision' => $last + 1,
                'title' => (string) $notice->title,
                'summary' => $notice->summary,
                'body' => (string) $notice->body,
                'priority' => $notice->priority?->value,
                'change_note' => $why,
                'created_by' => auth()->id(),
            ]);

            $notice->fill(array_intersect_key($data, array_flip([
                'title', 'summary', 'body', 'priority',
                'starts_on', 'ends_on', 'ack_required', 'ack_deadline',
            ])))->save();

            return $notice->fresh();
        });
    }

    /**
     * অবস্থা বদলের আসল কাজ — আর একমাত্র পাহারা।
     *
     * ⚠️ `forceFill` ব্যবহার করা হয় ইচ্ছাকৃতভাবে: ⓘ `status` মডেলের
     * `fillable`-এ **রাখা হয়নি**, যাতে কোনো পর্দার `update($request->all())`
     * দিয়ে অবস্থা বদলে না যায়। ⛔ ওভাবে বদলালে এই পাহারাটা পাশ কাটিয়ে
     * যেত, আর নিয়মটা থাকত নামমাত্র।
     *
     * @param  array<string, mixed>  $also
     */
    private function moveTo(Notice $notice, NoticeStatus $next, array $also = []): Notice
    {
        /*
         * ⚠️ `status` মডেলে enum হয়ে ফেরে, কিন্তু এখানে ধরে নেওয়া হয়নি।
         *
         * ⓘ পুরনো সারি বা কাঁচা `DB::table()` থেকে আসা নোটিশে ওটা লেখা
         * হয়েই থাকতে পারে। ⛔ ধরে নিলে ঐ পথগুলোয় একটা `TypeError` পড়ত,
         * আর সেটা পড়ত ছাপার সময়, পরীক্ষায় নয়।
         */
        $now = $notice->status instanceof NoticeStatus
            ? $notice->status
            : NoticeStatus::from((string) $notice->status);

        if (! $now->canBecome($next)) {
            throw ValidationException::withMessages([
                'status' => __('core.notice.cannot_go_there', [
                    'no' => $notice->document_no ?: (string) $notice->id,
                    'from' => $now->label(),
                    'to' => $next->label(),
                ]),
            ]);
        }

        return DB::transaction(function () use ($notice, $next, $also) {
            $notice->forceFill(['status' => $next->value, ...$also])->save();

            return $notice->fresh();
        });
    }

    /** লেখা থেকে অগ্রাধিকার — অচেনা হলে সাধারণ। */
    private function priorityOf(mixed $raw): NoticePriority
    {
        return is_string($raw)
            ? (NoticePriority::tryFrom($raw) ?? NoticePriority::NORMAL)
            : NoticePriority::NORMAL;
    }
}
