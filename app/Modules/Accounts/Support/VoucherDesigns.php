<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Support;

/**
 * ভাউচারের নকশা — কোন মাপ, কোন নকশা, কোন ছাঁচ।
 *
 * ── ⭐ মালিকের নির্দেশ, ৩০ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"printe template sob gulo kore deploy diba A4 A5 tharmal tintiroi"* — ভাউচারেরও প্রতিটা মাপের
 * নিজের নকশা (abos-3c লেখেন, `resources/views/print/voucher-<code>`)।
 *
 * ⚠️ ক্রমটা বিক্রয়ের কাগজের ক্রমের সাথে এক ([[PaperDesigns::DOCUMENT_ORDER]]), কিন্তু এখানে
 * আলাদা লেখা: Accounts কারও উপর দাঁড়ায় না, তাই বিক্রয়ের ক্লাস ডাকতে পারে না ([[BoundariesTest]])।
 * ⓘ তালিকায় কেবল সেগুলো ওঠে যার ছাঁচের ফাইল আছে ([[codes()]])।
 */
final class VoucherDesigns
{
    public const SIZES = ['a4', 'a5', 'thermal'];

    private const PREFIX = ['a4' => '', 'a5' => 'a5_', 'thermal' => 'thermal_'];

    private const ORDER = [
        'aurora', 'bento', 'neo_brutal', 'soft_minimal', 'dark_mode', 'quick_green', 'cloud_blue',
        'tally_classic', 'sheet_grid', 'bank_form', 'modern_green', 'corporate_navy', 'modern_card',
        'swiss_grid', 'sidebar_band', 'bangla_heritage', 'premium_gold', 'editorial_serif', 'ink_saver', 'seal_boxes',
    ];

    public static function key(string $size): string
    {
        return "accounts.print.design.voucher_{$size}";
    }

    /** @return list<string> */
    public static function codes(string $size): array
    {
        return array_values(array_filter(
            self::ORDER,
            fn (string $code) => is_file(resource_path('views/print/voucher-'.self::PREFIX[$size].$code.'.blade.php')),
        ));
    }

    /** ⛔ `standard`, অচেনা বা ফাইল-না-থাকা হলে null — তখন চলতি ভাউচার (`print.voucher`) */
    public static function template(string $size, ?string $code): ?string
    {
        if ($code === null || $code === 'standard' || ! in_array($code, self::codes($size), true)) {
            return null;
        }

        return 'print.voucher-'.self::PREFIX[$size].$code;
    }
}
