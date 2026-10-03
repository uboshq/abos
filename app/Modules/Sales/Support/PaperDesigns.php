<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

/**
 * বিক্রয়ের কাগজের নকশা — কোন কাগজ, কোন মাপ, কোন নকশা, কোন ছাঁচ।
 *
 * ── ⭐ মালিকের নির্দেশ, ৩০ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"printe template sob gulo kore deploy diba A4 A5 tharmal tintiroi"* — প্রতিটা কাগজের (বিল,
 * চালান, অর্ডার, আদায় রসিদ) প্রতিটা মাপের নিজের নকশা, নিজের বাছাই। নকশাগুলো লেখেন abos-3c,
 * একই ক্রমে সব কাগজে।
 *
 * ── ⭐ তালিকা আসে ফাইল থেকে, হাতে লেখা নয় ─────────────────────────────
 * ⓘ ক্রমটা এখানে ([[ORDER]]), কিন্তু একটা নকশা তালিকায় ওঠে কেবল তার ছাঁচের ফাইল থাকলে
 * ([[codes()]])। ⛔ হাতে লেখা তালিকায় একদিন একটা নাম থাকত যার ফাইল নেই — বাছা যেত, ছাপায়
 * ৫০০। উল্টোটাও: নতুন ফাইল এলে কেউ তালিকায় লিখতে ভুললে নকশাটা কোনোদিন দেখা যেত না।
 *
 * ⓘ বিল-A4-এর তালিকা আগের মতোই [[InvoiceDesigns]] (সেটিং `sales.print.design.invoice`)।
 */
final class PaperDesigns
{
    /** ছাপার নিয়ন্ত্রণের কাগজের নাম — [[PrintControlController::PAPERS]]-এর বিক্রয়ের অংশ */
    public const PAPERS = ['invoice', 'challan', 'order', 'receipt'];

    public const SIZES = ['a4', 'a5', 'thermal'];

    /** ছাঁচের ফাইলের নামের শুরু — কাগজ ধরে */
    private const FILE = ['invoice' => 'invoice', 'challan' => 'challan', 'order' => 'order', 'receipt' => 'receipt'];

    /** মাপের উপসর্গ — A4-এর কোনো উপসর্গ নেই */
    private const PREFIX = ['a4' => '', 'a5' => 'a5_', 'thermal' => 'thermal_'];

    /** মালিকের ক্রম — বিলের A5 (A4-এর বিশটা আর "আধা পাতা") */
    private const INVOICE_ORDER = [
        'modern_green', 'corporate_navy', 'minimal_mono', 'sidebar_band', 'bangla_heritage',
        'distributor_compact', 'ink_saver', 'premium_gold', 'split_copy', 'summary_first',
        'bw_ledger', 'bw_typewriter', 'bw_bilingual', 'classic_table', 'half_page',
        'swiss_grid', 'editorial_serif', 'modern_card', 'seal_boxes', 'statement', 'statement_ledger',
        'mono_light', 'mono_light_bn', 'mono_bold', 'brutal_mono', 'world_standard', 'world_standard_bn', 'mono_bold_classic',
        // ⭐ Special for DB — A4-এর সাথে একই গড়ন (মালিক, ৩ অক্টোবর ২০২৬: "সেম টেমপ্লেটটা A5 ও দিয়ে দাও")
        'special_db',
    ];

    /** মালিকের ক্রম — বিলের থার্মাল */
    private const THERMAL_ORDER = [
        'hero_total', 'qr_first', 'clean_air', 'bold_block', 'big_number', 'supermarket', 'pos_standard',
        'retail_box', 'bank_slip', 'two_language', 'bangla', 'compact', 'ink_saver', 'cut_stub',
        'summary_first', 'ledger_grid', 'account', 'movement', 'seal_boxes', 'serif', 'swiss',
        'mono_light', 'mono_light_bn', 'mono_bold', 'brutal_mono', 'world_standard', 'world_standard_bn', 'mono_bold_classic',
    ];

    /** মালিকের ক্রম — চালান, অর্ডার, আদায় রসিদ (আর ভাউচার, Accounts-এর নিজের তালিকায়) */
    public const DOCUMENT_ORDER = [
        'aurora', 'bento', 'neo_brutal', 'soft_minimal', 'dark_mode', 'quick_green', 'cloud_blue',
        'tally_classic', 'sheet_grid', 'bank_form', 'modern_green', 'corporate_navy', 'modern_card',
        'swiss_grid', 'sidebar_band', 'bangla_heritage', 'premium_gold', 'editorial_serif', 'ink_saver', 'seal_boxes',
        'mono_light', 'mono_light_bn',
    ];

    /**
     * ⭐ কেউ না বাছলে যে নকশা — মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"notun desine theke ekta sadaron beche naw
     * othoba world er sobcheye jono prio … defolte koro"*, প্রস্তাব দেখে "OK"।
     *
     * ⓘ বিলে ক্লাসিক (মালিকের নিজের নমুনা), থার্মালে পিওএস মানক (দোকানের সবচেয়ে চেনা রসিদ), বাকি সব
     * কাগজে ট্যালি ক্লাসিক — তিন মাপেই এক পরিবার। ⚠️ ঐ মাপের ফাইল না থাকলে [[template()]] null
     * দেয়, তখন চলতি কাগজ — ফাইল এলেই নিজে থেকে এই নকশায় যায়।
     */
    public static function defaultFor(string $paper, string $size): string
    {
        /*
         * ⭐ ৩০ সেপ্টেম্বর ২০২৬, পরে: *"ok eigulo kei defolt korte bolo keu select na korle egulotei print hobe"* —
         * বিল আর চালানের "মোনো ক্লাসিক হালকা" — A4, আর A5 ও থার্মালের রূপ আসার পর তিন মাপেই (abos-3c)।
         */
        return match (true) {
            in_array($paper, ['invoice', 'challan'], true) => 'mono_light',
            $paper === 'invoice' && $size === 'thermal' => 'pos_standard',
            $paper === 'invoice' => 'classic_table',
            default => 'tally_classic',
        };
    }

    /** কাগজ-মাপের বাছাইয়ের সেটিং — বিল-A4 আগের চাবিতেই থাকে */
    public static function key(string $paper, string $size): string
    {
        return $paper === 'invoice' && $size === 'a4'
            ? 'sales.print.design.invoice'
            : "sales.print.design.{$paper}_{$size}";
    }

    /**
     * এই কাগজ-মাপের নকশা — মালিকের ক্রমে, কেবল যার ছাঁচের ফাইল আছে।
     *
     * @return list<string>
     */
    public static function codes(string $paper, string $size): array
    {
        if ($paper === 'invoice' && $size === 'a4') {
            return array_keys(InvoiceDesigns::ALL);
        }

        $order = match (true) {
            $paper === 'invoice' && $size === 'thermal' => self::THERMAL_ORDER,
            $paper === 'invoice' => self::INVOICE_ORDER,
            default => self::DOCUMENT_ORDER,
        };

        return array_values(array_filter(
            $order,
            fn (string $code) => is_file(self::path($paper, $size, $code)),
        ));
    }

    /**
     * একটা নকশার ছাঁচ — `standard`, অচেনা বা ফাইল-না-থাকা হলে null (চলতি কাগজের পথ)।
     *
     * ⛔ অচেনা মানে ব্যতিক্রম নয়: একটা নকশা সরলে যে কোম্পানি ওটা বেছে রেখেছিল তার কাগজ ছাপাই
     * বন্ধ হত — কাউন্টারে, গ্রাহকের সামনে। তখন চলতি কাগজ।
     */
    public static function template(string $paper, string $size, ?string $code): ?string
    {
        if ($code === null || $code === 'standard' || ! in_array($code, self::codes($paper, $size), true)) {
            return null;
        }

        if ($paper === 'invoice' && $size === 'a4') {
            return InvoiceDesigns::ALL[$code];
        }

        return 'sales::print.'.self::FILE[$paper].'-'.self::PREFIX[$size].$code;
    }

    /** কাগজের মাপ (`a4`, `a5`, `80mm` …) থেকে নকশার মাপ — থার্মালগুলো এক, বাকিরা A4 */
    public static function sizeOf(string $paper, bool $isThermal): string
    {
        return match (true) {
            $isThermal => 'thermal',
            $paper === 'a5' => 'a5',
            default => 'a4',
        };
    }

    private static function path(string $paper, string $size, string $code): string
    {
        return __DIR__.'/../Resources/views/print/'.self::FILE[$paper].'-'.self::PREFIX[$size].$code.'.blade.php';
    }
}
