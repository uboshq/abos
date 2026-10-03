<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Models\DeliveryChallan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ডেলিভারির মাপকাঠি — সময়মতো ও পুরো (OTIF), আদেশ থেকে রওনার গড় সময়, দেরির তালিকা (মালিকের বিক্রয়-পরিকল্পনা, ৪ অক্টোবর ২০২৬)।
 *
 * ── সংজ্ঞা — একবার, এখানে ─────────────────────────────────────────────
 * • প্রতিশ্রুত দিন: আদেশের `deliver_on` → নাহলে চালানের `ship_date` → নাহলে চালানের তারিখ।
 * • পৌঁছানোর দিন: প্রথম "পৌঁছেছে" বা "আংশিক" ঘটনা ([[DeliveryEvent]]) — ধাপের খাতা থেকে, হাতের লেখা নয়।
 * • সময়মতো: পৌঁছানোর দিন ≤ প্রতিশ্রুত দিন। পুরো: চালানের এখনকার ধাপ "পৌঁছেছে" (আংশিক নয়)।
 * • OTIF %: যাদের প্রতিশ্রুত দিন আজ বা আগে (সময় পেরিয়েছে) — তাদের কতগুলো সময়মতো **আর** পুরো। ⓘ সামনের
 *   চালান হিসাবে নেই: ওদের দেরি হয়নি, আবার ঠিক সময়েও পৌঁছায়নি।
 * • আদেশ থেকে রওনা: আদেশ লেখা (`created_at`) থেকে প্রথম "রওনা" ঘটনা — কেবল আদেশ থেকে আসা চালান।
 * • দেরি: প্রতিশ্রুত দিন পেরিয়ে পৌঁছেছে, বা পেরিয়ে গেছে আর এখনো পৌঁছায়নি।
 *
 * ⓘ চালান নিশ্চিত হতে হবে (খসড়া আর বাতিল নয়)। শাখা: চালানের নিজের স্কোপ ([[ScopedToUserBranch]]) হেডারের শাখা মানে।
 * ⓘ সময় সবই অ্যাপের ঘড়িতে — "আজ" ডাটাবেজকে জিজ্ঞেস করা হয় না।
 */
final class DeliveryPerformance
{
    /** প্রতিশ্রুত দিন — এক জায়গায় লেখা, বাছাই আর ছাঁকনি দুইজনেই পড়ে */
    private const PROMISED = 'DATE(COALESCE(o.deliver_on, sal_challans.ship_date, sal_challans.trx_date))';

    /**
     * @return array{due: int, otif: int, on_time: int, in_full: int, percent: ?string, avg_hours: ?int, late: int}
     */
    public function summary(string $from, string $to): array
    {
        $today = Carbon::today()->toDateString();
        $rows = $this->base($from, $to)->get();

        $due = $rows->filter(fn ($r) => $r->promised_on <= $today);
        $onTime = $due->filter(fn ($r) => $r->arrived_on !== null && $r->arrived_on <= $r->promised_on);
        $inFull = $due->filter(fn ($r) => $r->stage === DeliveryStage::DELIVERED);
        $otif = $onTime->filter(fn ($r) => $r->stage === DeliveryStage::DELIVERED);

        $leadHours = $rows->pluck('lead_hours')->filter(fn ($h) => $h !== null)->map(fn ($h) => (int) $h);

        return [
            'due' => $due->count(),
            'otif' => $otif->count(),
            'on_time' => $onTime->count(),
            'in_full' => $inFull->count(),
            // ⓘ শতাংশ bcmath-এ, এক ঘর দশমিক — float কখনো নয়
            'percent' => $due->isEmpty() ? null : bcdiv(bcmul((string) $otif->count(), '100', 4), (string) $due->count(), 1),
            'avg_hours' => $leadHours->isEmpty() ? null : intdiv($leadHours->sum(), $leadHours->count()),
            'late' => $this->late($from, $to)->count(),
        ];
    }

    /**
     * দেরির তালিকা — যাদের প্রতিশ্রুত দিন পেরিয়েছে আর পৌঁছেছে পরে, বা এখনো পৌঁছায়নি।
     *
     * @return Builder<DeliveryChallan>
     */
    public function late(string $from, string $to): Builder
    {
        $today = Carbon::today()->toDateString();

        /* ⓘ নাম ধরে HAVING নয় — লাইভের MariaDB-র কড়া নিয়মে ঝুঁকি; হিসাবটাই শর্তে */
        return $this->base($from, $to)
            ->whereRaw(self::PROMISED.' < ?', [$today])
            ->whereRaw('(arr.at IS NULL OR DATE(arr.at) > '.self::PROMISED.')')
            ->orderByRaw(self::PROMISED);
    }

    /**
     * প্রতি চালানে এক সারি: প্রতিশ্রুত দিন, পৌঁছানোর দিন, এখনকার ধাপ, আদেশ থেকে রওনার ঘণ্টা।
     *
     * @return Builder<DeliveryChallan>
     */
    private function base(string $from, string $to): Builder
    {
        $company = CompanyContext::id();

        $arrived = DB::table('sal_delivery_events')
            ->where('company_id', $company)
            ->whereIn('to_stage', [DeliveryStage::DELIVERED, DeliveryStage::PARTIALLY_DELIVERED])
            ->selectRaw('delivery_challan_id, MIN(occurred_at) as at')
            ->groupBy('delivery_challan_id');

        $left = DB::table('sal_delivery_events')
            ->where('company_id', $company)
            ->where('to_stage', DeliveryStage::DISPATCHED)
            ->selectRaw('delivery_challan_id, MIN(occurred_at) as at')
            ->groupBy('delivery_challan_id');

        return DeliveryChallan::query()
            ->whereIn('sal_challans.status', DocumentStatus::POSTED)
            ->whereBetween('sal_challans.trx_date', [$from, $to])
            ->leftJoin('sal_orders as o', 'o.id', '=', 'sal_challans.sales_order_id')
            ->leftJoin('sal_delivery_states as st', 'st.delivery_challan_id', '=', 'sal_challans.id')
            ->leftJoinSub($arrived, 'arr', 'arr.delivery_challan_id', '=', 'sal_challans.id')
            ->leftJoinSub($left, 'dep', 'dep.delivery_challan_id', '=', 'sal_challans.id')
            ->with('customer')
            ->select('sal_challans.*')
            ->selectRaw(self::PROMISED.' as promised_on')
            ->selectRaw('DATE(arr.at) as arrived_on')
            ->selectRaw('st.stage as stage')
            ->selectRaw('o.document_no as order_no')
            ->selectRaw('CASE WHEN o.id IS NULL OR dep.at IS NULL THEN NULL ELSE TIMESTAMPDIFF(HOUR, o.created_at, dep.at) END as lead_hours');
    }
}
