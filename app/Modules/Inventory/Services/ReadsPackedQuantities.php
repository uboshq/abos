<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Product;

/**
 * পর্দা থেকে আসা "২ বাক্স"-কে লাইনের তিনটা ঘরে বসানো।
 *
 * প্রতিটা ডকুমেন্ট সার্ভিস নিজের মতো লাইন বানায়, কিন্তু প্যাক পড়ার
 * নিয়মটা এক জায়গায় — নাহলে একদিন বিক্রয়ে বাক্স গুণ হত আর ক্রয়ে হত না,
 * আর দুই কাগজের মজুদ আলাদা দিকে যেত।
 */
trait ReadsPackedQuantities
{
    /**
     * পরিমাণ আর দর — দুইটাই একসাথে নামে।
     *
     * দুইটা আলাদা ডাকে ভাগ করলে একদিন কোনো এক সার্ভিসে দ্বিতীয় ডাকটা
     * বাদ পড়ত, আর "২ বাক্স @ ৮০০" বিল হত ১,৬০,০০০ টাকা।
     *
     * @return array{qty: string, rate: string, entered_qty: string|null, entered_unit_id: int|null}
     */
    private function packed(Product $product, string $qty, mixed $unitId, string $rate = '0', array $carry = []): array
    {
        $unitId = is_numeric($unitId) ? (int) $unitId : null;

        /*
         * এক কাগজ থেকে আরেক কাগজে যাওয়া লাইন — আবার নামানো হয় না।
         *
         * সরাসরি বিক্রিতে চালানের লাইনটাই বিলের লাইন হয়, আর চালানে
         * সংখ্যাটা আগেই পণ্যের এককে নেমে গেছে। দ্বিতীয়বার ভাগ করলে ২
         * বাক্স ২০০ পিস না হয়ে ২ পিস হয়ে যেত — মজুদ কমত ঠিকই, কিন্তু
         * একশো ভাগের এক ভাগ।
         *
         * তাই এই পথে unit_id আসে না, আসে আগের কাগজে বসে যাওয়া entered_*
         * দুইটা — "যা লেখা হয়েছিল সেটা মনে রাখো, হিসাব আর কোরো না"।
         */
        if ($unitId === null && is_numeric($carry['entered_unit_id'] ?? null)) {
            return [
                'qty' => $qty,
                'rate' => $rate,
                'entered_qty' => (string) $carry['entered_qty'],
                'entered_unit_id' => (int) $carry['entered_unit_id'],
            ];
        }

        /*
         * পণ্যের নিজের একক এলে ঘর দুইটা খালিই থাকে।
         *
         * "NULL মানে যেভাবে সবসময় হত" — এই কথাটা সত্যি রাখলে ছাপা আর
         * পর্দায় আর শর্ত মেলাতে হয় না। বসিয়ে দিলে চালানে "১০ পিস
         * (১০ পিস)" ছাপা হত।
         */
        if ($unitId === null || $unitId === $product->unit_id) {
            return ['qty' => $qty, 'rate' => $rate, 'entered_qty' => null, 'entered_unit_id' => null];
        }

        $packs = app(PackConversion::class);

        return [
            'qty' => $packs->toStockQty($product, $qty, $unitId),
            'rate' => $packs->toStockRate($product, $rate, $unitId),
            'entered_qty' => $qty,
            'entered_unit_id' => $unitId,
        ];
    }
}
