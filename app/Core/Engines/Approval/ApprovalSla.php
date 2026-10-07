<?php

declare(strict_types=1);

namespace App\Core\Engines\Approval;

use App\Models\Approval;
use App\Models\ApprovalFlowStep;
use Illuminate\Support\Carbon;

/**
 * একটা অনুরোধ কত সময় ধরে পড়ে আছে, আর সেটা ঠিক আছে কি না।
 *
 * ── ⭐ মালিকের সিদ্ধান্ত, ২৪ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * *"প্রতিটা ধাপে আলাদা করে বসাব"* — তাই সময়টা ধাপের গায়ে থাকে, আর
 * ধাপ বদলালে ঘড়িটাও নতুন করে শুরু হয়।
 *
 * ── ⚠️ কেন একটা আলাদা শ্রেণি, ইঞ্জিনের ভিতরে নয় ──────────────────────
 * ⓘ তিনটা আলাদা জায়গা একই উত্তর চায়: ইনবক্সের চিহ্ন, দেরির ছাঁকনি, আর
 * প্রতি ঘণ্টার কমান্ড। ⛔ তিন জায়গায় আলাদা করে হিসাব করলে একদিন
 * পর্দা বলত *"সময়ের ভিতরে"* আর কমান্ড ওটাকে উপরে পাঠিয়ে দিত।
 */
final class ApprovalSla
{
    /** সময়ের ভিতরে — এখনো হাতে সময় আছে। */
    public const FINE = 'fine';

    /** কাছাকাছি — সতর্ক করার সময়। */
    public const NEAR = 'near';

    /** পার হয়ে গেছে, কিন্তু এখনো উপরে পাঠানো হয়নি। */
    public const LATE = 'late';

    /** উপরে পাঠানো হয়ে গেছে। */
    public const ESCALATED = 'escalated';

    /** এই ধাপে কোনো সময়সীমা বসানো নেই। */
    public const NONE = 'none';

    /**
     * এই অনুরোধটার অবস্থা।
     *
     * ⚠️ `decided_at` বসে গেলে আর সময়ের প্রশ্নই নেই — শেষ হয়ে যাওয়া
     * কাগজকে "দেরি" বলা মানে রিপোর্টে চিরকালের জন্য লাল সংখ্যা।
     */
    public function stateOf(Approval $approval, ?Carbon $now = null): string
    {
        if ($approval->status !== Approval::PENDING) {
            return self::NONE;
        }

        if ($approval->escalated_at !== null) {
            return self::ESCALATED;
        }

        if ($approval->due_at === null) {
            return self::NONE;
        }

        $now = $now ?? now();

        if ($now->greaterThanOrEqualTo($approval->due_at)) {
            return self::LATE;
        }

        return $this->warned($approval, $now) ? self::NEAR : self::FINE;
    }

    /**
     * এই ধাপের জন্য কখন সময় শেষ হবে।
     *
     * ⓘ `null` ফিরলে ঐ ধাপে কোনো ঘড়ি নেই, আর সেটাই ডিফল্ট — পুরনো
     * প্রবাহগুলো **অবিকল আগের মতো** চলে।
     */
    public function dueFor(?ApprovalFlowStep $step, ?Carbon $from = null): ?Carbon
    {
        if ($step?->sla_hours === null) {
            return null;
        }

        return ($from ?? now())->copy()->addHours((int) $step->sla_hours);
    }

    /**
     * ⭐ দেরি হলে কাগজটা কার কাছে যাবে।
     *
     * ── ⚠️ মালিকের বাছাই: "প্রবাহে ঠিক করা একজন" ────────────────────
     * ⛔ পরের ধাপের জনের কাছে **নয়**। ⓘ কারণটা ব্যবসার: দেরি করছেন
     * যিনি, তাঁর উপরের জন সবসময় প্রবাহের পরের ধাপ নন — অনেক সময়
     * প্রবাহে পরের ধাপই নেই।
     *
     * ⚠️ ফল: ধাপে গন্তব্য না বসালে কাগজ **কোথাও যায় না**। ⓘ সেই
     * ফাঁকটা চুপ থাকতে দেওয়া যায় না, তাই [[hasHoleAt()]] সেটা ধরে আর
     * `flow/coverage` পর্দা দেখায়।
     *
     * @return array{type: string, id: int}|null
     */
    public function escalationTarget(?ApprovalFlowStep $step): ?array
    {
        if ($step === null
            || $step->escalate_to_type === null
            || $step->escalate_to_id === null) {
            return null;
        }

        return ['type' => (string) $step->escalate_to_type, 'id' => (int) $step->escalate_to_id];
    }

    /**
     * ⛔ ঘড়ি বসানো আছে, অথচ যাওয়ার জায়গা নেই।
     *
     * ⓘ এটা একটা **নীরব** ভুল: সময় পার হয়, কমান্ড চলে, আর কিছুই হয়
     * না। ⚠️ মালিক ভাবতেন ব্যবস্থাটা কাজ করছে।
     */
    public function hasHoleAt(?ApprovalFlowStep $step): bool
    {
        if ($step === null) {
            return false;
        }

        $hasClock = $step->escalate_hours !== null || $step->sla_hours !== null;

        return $hasClock && $this->escalationTarget($step) === null;
    }

    /**
     * এখন কি মনে করানোর সময়?
     *
     * ⓘ `warn_hours` না বসালে ধরা হয় সময়সীমার **অর্ধেক** — একটা
     * আন্দাজ, কিন্তু চুপ থাকার চেয়ে ভালো, আর ধাপে বসিয়ে বদলানো যায়।
     */
    private function warned(Approval $approval, Carbon $now): bool
    {
        $warn = $approval->warnAt();

        return $warn !== null && $now->greaterThanOrEqualTo($warn);
    }
}
