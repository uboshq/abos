<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use Illuminate\Validation\ValidationException;

/**
 * লাইনের অঙ্ক — বিক্রয়ের ডকুমেন্টগুলোতে একই নিয়মে।
 *
 * আদেশ, চালান আর বিল তিনটাতেই "পরিমাণ × দর − ছাড় + ভ্যাট" গোনা হয়। তিন
 * জায়গায় আলাদা করে লিখলে একদিন একটায় ছাড় ভ্যাটের আগে বসত আর অন্যটায়
 * পরে, আর বিলের মোট আদেশের মোটের সাথে মিলত না — মিলত না বলে কেউ বুঝতেও
 * পারত না কোনটা ভুল।
 *
 * সবটাই bcmath-এ। float-এ ০.১ + ০.২ ≠ ০.৩, আর পাঁচশো লাইনের বিলে ওই
 * ভুলটা টাকায় গিয়ে দাঁড়ায়।
 */
trait CalculatesSalesLines
{
    /**
     * এক লাইনের চারটা সংখ্যা।
     *
     * ছাড় ভ্যাটের আগে বসে — ছাড়ের পরের টাকার উপরেই ভ্যাট, কারণ সরকারকে
     * যা নেওয়া হয়নি তার উপর ভ্যাট দিতে হয় না।
     *
     * @return array{base: string, discount: string, tax: string, amount: string}
     */
    private function lineFigures(string $qty, string $rate, mixed $discount, mixed $tax): array
    {
        $base = bcmul($qty, $rate, 4);
        $discount = $this->money($discount);

        if (bccomp($discount, $base, 4) > 0) {
            throw ValidationException::withMessages([
                'lines' => __('sales::validation.discount_over_line'),
            ]);
        }

        $net = bcsub($base, $discount, 4);
        $tax = $this->money($tax);

        return [
            'base' => $base,
            'discount' => $discount,
            'tax' => $tax,
            'amount' => bcadd($net, $tax, 4),
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
                'lines' => __('sales::validation.not_a_number'),
            ]);
        }

        if (bccomp($value, '0', 4) < 0) {
            throw ValidationException::withMessages([
                'lines' => __('sales::validation.negative_amount'),
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
                'lines' => __('sales::validation.not_a_number'),
            ]);
        }

        if (bccomp($value, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'lines' => __('sales::validation.quantity_must_be_positive', ['field' => $field]),
            ]);
        }

        return bcadd($value, '0', 4);
    }
}
