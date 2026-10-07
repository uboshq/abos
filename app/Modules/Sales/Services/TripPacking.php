<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ "লোডিং নিশ্চিত" — ট্রিপের চালানগুলো "প্যাক হয়েছে"-তে, একসাথে (একটা আটকালে কোনোটাই নয়)।
 *
 * ⓘ কেবল যেগুলো এখনো বরাদ্দ বা তোলার ধাপে ([[LOADABLE]]); যা আগেই প্যাক, তা যেমন আছে। ⛔ রওনা হয়ে যাওয়া ট্রিপে নয়।
 * ⭐ ওয়েবের লোডিং শিট আর ফোনের লোডিং শিট — দুই দরজা, এই এক সেবা (মালিকের বিক্রয় পরিকল্পনা ধাপ ৪, ৬ অক্টোবর ২০২৬)।
 */
final class TripPacking
{
    /** যে ধাপ থেকে "লোডিং নিশ্চিত" প্যাকে নেয় */
    public const LOADABLE = [DeliveryStage::ALLOCATED, DeliveryStage::PICKING];

    public function __construct(private readonly DeliveryStageService $stages) {}

    /** @return int কয়টা চালান প্যাক হলো */
    public function pack(Shipment $shipment): int
    {
        if ($shipment->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('sales::loading.not_open', ['no' => $shipment->document_no]),
            ]);
        }

        $shipment->loadMissing('lines.challan');

        return DB::transaction(function () use ($shipment): int {
            $count = 0;

            foreach ($shipment->lines as $line) {
                $challan = $line->challan;

                if ($challan === null) {
                    continue;
                }

                if (in_array((string) $this->stages->ensure($challan)->stage, self::LOADABLE, true)) {
                    $this->stages->move($challan, DeliveryStage::PACKED);
                    $count++;
                }
            }

            return $count;
        });
    }
}
