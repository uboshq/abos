<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Modules\MasterData\Models\Tax;
use Illuminate\Validation\ValidationException;

/**
 * লাইনের অঙ্ক — তিনটা ডকুমেন্টেই একই নিয়মে।
 *
 * আদেশ, চালান আর বিল তিনটাতেই "পরিমাণ × দর − ছাড় + ভ্যাট" গোনা হয়। তিন
 * জায়গায় আলাদা করে লিখলে একদিন একটায় ছাড় ভ্যাটের আগে বসত আর অন্যটায়
 * পরে, আর বিলের মোট আদেশের মোটের সাথে মিলত না — মিলত না বলে কেউ বুঝতেও
 * পারত না কোনটা ভুল।
 *
 * সবটাই bcmath-এ। float-এ ০.১ + ০.২ ≠ ০.৩, আর পাঁচশো লাইনের বিলে ওই
 * ভুলটা টাকায় গিয়ে দাঁড়ায়।
 */
trait CalculatesLineTotals
{
    /**
     * এক লাইনের চারটা সংখ্যা।
     *
     * ছাড় ভ্যাটের আগে বসে — ছাড়ের পরের টাকার উপরেই ভ্যাট, কারণ সরকারকে
     * যা নেওয়া হয়নি তার উপর ভ্যাট দিতে হয় না।
     *
     * @return array{base: string, discount: string, tax: string, amount: string}
     */
    private function lineFigures(string $qty, string $rate, mixed $discount, mixed $tax, ?Tax $standard = null): array
    {
        $base = bcmul($qty, $rate, 4);
        $discount = $this->money($discount);

        if (bccomp($discount, $base, 4) > 0) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.discount_over_line'),
            ]);
        }

        $net = bcsub($base, $discount, 4);

        /*
         * বিক্রয়ের নিয়মটাই এখানেও — [[CalculatesSalesLines::lineFigures()]]।
         *
         * অঙ্কটা অনুপস্থিত থাকলে পণ্যের নিজের হার থেকে গোনা, দেওয়া থাকলে
         * সেটাই মানা। ক্রয়ের পর্দাগুলোয় ভ্যাটের ঘর সত্যিই আছে, তাই এখানে
         * দ্বিতীয় পথটাই বেশি চলে — কিন্তু নিয়ম দুই দিকে আলাদা রাখলে
         * একই পণ্যের ক্রয় ও বিক্রয়ে দুই রকম ভ্যাট বসত, আর কেউ বলতে
         * পারত না কোনটা ঠিক।
         *
         * দামের ভেতরের ভ্যাটে মোট বাড়ে না; দরেই ওটা আছে।
         */
        $inclusive = false;

        if ($tax === null || $tax === '') {
            $tax = $standard?->amountOn($net) ?? '0.0000';
            $inclusive = (bool) $standard?->is_inclusive;
        } else {
            $tax = $this->money($tax);
        }

        return [
            'base' => $base,
            'discount' => $discount,
            'tax' => $tax,
            'amount' => $inclusive ? $net : bcadd($net, $tax, 4),
        ];
    }

    /**
     * @param  array{subtotal: string, discount: string, tax: string, total: string}  $totals
     * @param  array{base: string, discount: string, tax: string, amount: string}  $figures
     * @return array{subtotal: string, discount: string, tax: string, total: string}
     */
    private function addToTotals(array $totals, array $figures): array
    {
        return [
            'subtotal' => bcadd($totals['subtotal'], $figures['base'], 4),
            'discount' => bcadd($totals['discount'], $figures['discount'], 4),
            'tax' => bcadd($totals['tax'], $figures['tax'], 4),
            'total' => bcadd($totals['total'], $figures['amount'], 4),
        ];
    }

    /** টাকার ঘর — খালি মানে শূন্য, ঋণাত্মক নয়। */
    private function money(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return '0.0000';
        }

        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.not_a_number'),
            ]);
        }

        if (bccomp($value, '0', 4) < 0) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.negative_amount'),
            ]);
        }

        return bcadd($value, '0', 4);
    }

    /**
     * পরিমাণের ঘর যেখানে শূন্য চলে — যেমন ফ্রি পরিমাণ।
     *
     * ── কেন `money()` ব্যবহার করা হলো না ─────────────────────────────
     * নিয়মগুলো হুবহু এক (খালি মানে শূন্য, ঋণাত্মক নয়), কিন্তু ভুল
     * করলে বার্তাটা হত "টাকার ঘর ঋণাত্মক হতে পারে না" — অথচ ব্যবহারকারী
     * টাকার ঘরে হাত দেননি, পরিমাণের ঘরে দিয়েছেন। ভুল বার্তা মানুষকে
     * ভুল ঘরে খুঁজতে পাঠায়।
     */
    private function zeroOrMore(mixed $value, string $field): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return '0.0000';
        }

        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.not_a_number'),
            ]);
        }

        if (bccomp($value, '0', 4) < 0) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.quantity_must_not_be_negative', ['field' => $field]),
            ]);
        }

        return bcadd($value, '0', 4);
    }

    /**
     * পরিমাণের ঘর — শূন্য চলবে না।
     *
     * শূন্য পরিমাণের লাইন কোনো কাজ করে না, অথচ বিলের কাগজে একটা সারি
     * দখল করে থাকে আর মোট মেলানোর সময় বিভ্রান্ত করে।
     */
    private function positive(mixed $value, string $field): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '' || ! is_numeric($value)) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.not_a_number'),
            ]);
        }

        if (bccomp($value, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.quantity_must_be_positive', ['field' => $field]),
            ]);
        }

        return bcadd($value, '0', 4);
    }
}
