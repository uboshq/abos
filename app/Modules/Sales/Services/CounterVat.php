<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\MasterData\Models\Tax;

/**
 * কাউন্টারের "ভ্যাট" বাছাই — পুরো কাগজের জন্য একটা হার ও ধরন (ভ্যাট বাদে · ভ্যাট সহ · ভ্যাটমুক্ত)।
 *
 * ⛔ আগে বাছাইটা কেবল পর্দায় গোনা হত, সার্ভার পড়ত না (Sales অডিট, ১০ অক্টোবর ২০২৬): বিক্রেতা পর্দার
 * ভ্যাট-সহ মোট নিতেন, বিলে বসত পণ্যের নিজের হার (না থাকলে শূন্য) — টাকা আর খাতা দুই কথা বলত।
 * এখন বাছাই থাকলে প্রতিটা সারির হার এটাই, পর্দার মতোই ([[resources/js/counter/direct-sale.js]] `vatOn()`)।
 *
 * ⓘ ফেরে একটা **অসংরক্ষিত** হার — কেবল [[CalculatesSalesLines::lineFigures()]]-কে গুনতে দেওয়া; বিক্রির ভ্যাট
 * বন্ধ থাকলে ওখানেই শূন্য হয়। বাছাই না থাকলে `null` = পণ্যের নিজের হার।
 */
final class CounterVat
{
    public const MODES = ['exclusive', 'inclusive', 'exempt'];

    /** @param  array<string, mixed>  $data  কাউন্টারের যাচাই করা ঘর */
    public static function rule(array $data): ?Tax
    {
        $mode = (string) ($data['vat_mode'] ?? '');

        if ($mode === 'exempt') {
            return new Tax(['rate' => '0', 'is_inclusive' => false]);
        }

        if ($mode === 'exclusive' || $mode === 'inclusive') {
            return new Tax(['rate' => (string) ($data['vat_rate'] ?? '0'), 'is_inclusive' => $mode === 'inclusive']);
        }

        return null;
    }
}
