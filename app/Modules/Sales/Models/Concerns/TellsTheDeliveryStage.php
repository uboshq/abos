<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models\Concerns;

use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Sales\Models\ShipmentLine;
use App\Modules\Sales\Services\DeliveryStageService;
use Illuminate\Database\Eloquent\Model;

/**
 * এই কাগজ বদলালে ডেলিভারির ধাপও জানে — চালান, ট্রিপ, ট্রিপের সারি।
 *
 * ── ⓘ কেন মডেলের ঘটনা থেকে, সেবার ভেতর থেকে নয় ──────────────────────
 * [[IsAudited]]-এর যুক্তিই: চালান নিশ্চিত হয় চার পথে (অফিসের চালান,
 * সরাসরি বিক্রয়, ধরে রাখা বিল, পোর্টাল), আর প্রতিটা সেবায় হাতে "ধাপটা
 * বদলাও" লিখলে পঞ্চম পথের দিন কেউ ভুলত — চালান নিশ্চিত, অথচ তালিকায়
 * "অপেক্ষায়"। এখানে থাকলে ভোলার সুযোগ নেই।
 *
 * ⭐ `updated` ঘটনা সেবার লেনদেনের ভেতরেই চলে, তাই ধাপের সারি আর
 * কাগজের বদল একসাথে বসে বা একসাথে ফেরে — অর্ধেক বসা বলে কিছু নেই।
 *
 * ⛔ এই ট্রেইট স্টকে হাত দেয় না — [[DeliveryStage]]-এ কারণ।
 */
trait TellsTheDeliveryStage
{
    public static function bootTellsTheDeliveryStage(): void
    {
        static::created(function (Model $model): void {
            if ($model instanceof DeliveryChallan) {
                app(DeliveryStageService::class)->challanWritten($model);
            }
        });

        static::updated(function (Model $model): void {
            $stages = app(DeliveryStageService::class);

            match (true) {
                $model instanceof DeliveryChallan => $stages->challanChanged($model),
                $model instanceof Shipment => $stages->tripChanged($model),
                $model instanceof ShipmentLine => $stages->tripLineChanged($model),
                default => null,
            };
        });
    }
}
