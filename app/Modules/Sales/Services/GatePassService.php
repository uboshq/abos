<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryEvent;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * গেট পাস — রওনার মুহূর্তে নিজে জন্মায়, তারপর কেবল দেখা, ছাপা আর (কারণসহ) বাতিল।
 *
 * ── ⓘ মালিকের নিয়ম (২৮ সেপ্টেম্বর ২০২৬, রাত) ───────────────────────────
 * গেট পাস রওনায়, ডেলিভারির পরে নয়; বিল ডেলিভারি নিশ্চিতের পরে (ওটা অন্য কাজ)।
 *
 * ── ⭐ কোথা থেকে ডাকা হয় ───────────────────────────────────────────────
 * একটাই জায়গা: ধাপ "রওনা" লেখার মুহূর্ত ([[DeliveryStageService::write()]]) — ট্রিপ বেরোলে
 * আর হাতে "রওনা" বসালে, দুইটাই ঐ পথে যায়। তাই কোনো রওনা গেট পাস ছাড়া বেরোয় না।
 *
 * ── ⛔ দুইবার নয় ──────────────────────────────────────────────────────
 * এক রওনা-ঘটনা, এক গেট পাস: আগে থাকলে সেটাই ফেরে, আর ডাটাবেসও অনন্য রাখে।
 * বাতিল বা খসড়া চালানে গেট পাস হয় না — মাল গুদাম থেকে নামেইনি, বা ফিরে এসেছে।
 */
final class GatePassService
{
    public function __construct(private readonly NumberSeriesEngine $numbers) {}

    public function issueFor(DeliveryChallan $challan, DeliveryEvent $event): ?GatePass
    {
        if ($challan->status !== DocumentStatus::CONFIRMED) {
            return null;
        }

        $existing = GatePass::query()->where('delivery_event_id', $event->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        // ⓘ গেটে যা দেখা হয় — ট্রিপে থাকলে ট্রিপের গাড়ি ও চালক, নাহলে চালানের নিজের
        $trip = $event->shipment_id !== null ? Shipment::query()->find($event->shipment_id) : null;

        return GatePass::query()->create([
            'company_id' => $challan->company_id,
            'branch_id' => $challan->branch_id,
            'document_no' => $this->numbers->next('GP'),
            'delivery_challan_id' => $challan->id,
            'delivery_event_id' => $event->id,
            'shipment_id' => $trip?->id,
            'vehicle_no' => $trip?->vehicle_no ?: $challan->vehicle_no,
            'driver_name' => $trip?->driver_name ?: $challan->driver_name,
            'driver_phone' => $challan->driver_phone,
            'issued_by' => auth()->id(),
            'issued_at' => $event->occurred_at ?? now(),
            'status' => GatePass::ISSUED,
        ]);
    }

    /**
     * ⛔ বাতিল — কারণ ছাড়া নয়, আর দুইবার নয়। ⓘ কারণ আর কে বাতিল করলেন, দুইটাই নিরীক্ষায়।
     */
    public function cancel(GatePass $pass, string $reason): GatePass
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('sales::gate_pass.reason_required')]);
        }

        return DB::transaction(function () use ($pass, $reason) {
            $pass = GatePass::query()->lockForUpdate()->findOrFail($pass->id);

            if ($pass->isCancelled()) {
                throw ValidationException::withMessages([
                    'status' => __('sales::gate_pass.already_cancelled', ['no' => $pass->document_no]),
                ]);
            }

            $pass->update([
                'status' => GatePass::CANCELLED,
                'cancel_reason' => mb_substr($reason, 0, 500),
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
            ]);

            return $pass;
        });
    }
}
