<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

/**
 * বিলের নকশার একমাত্র তালিকা — কোন নাম, কোন ছাঁচ।
 *
 * ── ⭐ মালিকের নির্দেশ, ৩০ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"Black & White template ro 3 ti koro, total 15 hobe, Classic & adhunik soho"* — নকশাগুলো
 * একে একে আসছে (abos-3c লেখেন), আর প্রতিটা এখানে এক সারি।
 *
 * ── ⚠️ কেন একটাই তালিকা ─────────────────────────────────────────────
 * ⛔ সেটিংয়ের বাছাই (`sales.print.design.invoice`-এর options), ছাপার সময় ছাঁচ বাছাই
 * ([[SalesPrintController::invoice()]]) আর নমুনা — তিন জায়গায় তিনটা তালিকা থাকলে একদিন
 * একটা নকশা বাছা যেত অথচ ছাপা হত না। ⓘ তাই তিনটাই এখান থেকে পড়ে, আর
 * [[EveryInvoiceDesignObeysTheSwitchesTest]] প্রতিটা সারি ছেপে সুইচ মেলায়।
 *
 * ⓘ `standard` (চলতি নকশা, `print.document`) এখানে নেই — সে ছাঁচ নয়, পুরো ছাপার ইঞ্জিন।
 * মালিক পুরনোগুলো মুছতে বলেছেন; ১৫টা পুরো হলে সেটা সরবে, তার আগে নয় (নয়টা দাবি ওর মাপ নেয়)।
 */
final class InvoiceDesigns
{
    /** যে নকশা অচেনা বা পুরনো মানের জায়গায় বসে */
    public const FALLBACK = 'mono_light';

    /** @var array<string, string> নাম → ছাঁচ, মালিকের ক্রমে */
    public const ALL = [
        'modern_green' => 'sales::print.invoice-modern_green',
        'corporate_navy' => 'sales::print.invoice-corporate_navy',
        'minimal_mono' => 'sales::print.invoice-minimal_mono',
        'sidebar_band' => 'sales::print.invoice-sidebar_band',
        'bangla_heritage' => 'sales::print.invoice-bangla_heritage',
        'distributor_compact' => 'sales::print.invoice-distributor_compact',
        'ink_saver' => 'sales::print.invoice-ink_saver',
        'premium_gold' => 'sales::print.invoice-premium_gold',
        'split_copy' => 'sales::print.invoice-split_copy',
        'summary_first' => 'sales::print.invoice-summary_first',
        'bw_ledger' => 'sales::print.invoice-bw_ledger',
        'bw_typewriter' => 'sales::print.invoice-bw_typewriter',
        'bw_bilingual' => 'sales::print.invoice-bw_bilingual',
        'classic_table' => 'sales::print.invoice-classic',
        'swiss_grid' => 'sales::print.invoice-swiss_grid',
        'editorial_serif' => 'sales::print.invoice-editorial_serif',
        'modern_card' => 'sales::print.invoice-modern_card',
        'seal_boxes' => 'sales::print.invoice-seal_boxes',
        'statement' => 'sales::print.invoice-statement',
        'statement_ledger' => 'sales::print.invoice-statement_ledger',

        /* ⭐ মালিকের ডিফল্ট, ৩০ সেপ্টেম্বর ২০২৬ — ইংরেজি, আর তার বাংলা রূপ */
        'mono_light' => 'sales::print.invoice-mono_light',
        'mono_light_bn' => 'sales::print.invoice-mono_light_bn',
        'mono_bold' => 'sales::print.invoice-mono_bold',
        'brutal_mono' => 'sales::print.invoice-brutal_mono',
        'world_standard' => 'sales::print.invoice-world_standard',
        'world_standard_bn' => 'sales::print.invoice-world_standard_bn',
        'mono_bold_classic' => 'sales::print.invoice-mono_bold_classic',
    ];

    /** সেটিংয়ের বাছাইয়ের তালিকা — চলতি নকশা আগে, তারপর ছাঁচগুলো */
    public static function options(): array
    {
        return ['standard', ...array_keys(self::ALL)];
    }

    /**
     * একটা নামের ছাঁচ — `standard` হলে null (চলতি নকশার পথ), অচেনা হলে ক্লাসিক।
     *
     * ⛔ অচেনা মানে ব্যতিক্রম নয়: একটা নকশা তালিকা থেকে সরলে যে কোম্পানি ওটা বেছে রেখেছিল তার
     * বিল ছাপাই বন্ধ হত — কাউন্টারে, গ্রাহকের সামনে।
     */
    public static function template(?string $design): ?string
    {
        if ($design === 'standard') {
            return null;
        }

        return self::ALL[$design] ?? self::ALL[self::FALLBACK];
    }
}
