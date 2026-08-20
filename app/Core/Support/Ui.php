<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * আটটা চেহারা — ব্যবহারকারী যেটা বাছেন, গোটা ERP সেটাই হয়ে যায়।
 *
 * ── এটা "স্কিন" নয় ──────────────────────────────────────────────────
 * পর্দার রং বদলানো নয়। Apps বাছলে দুইশো বাহান্নটা পর্দাই Odoo-র মতো
 * দেখায়; Tiles বাছলে সবগুলোই SAP Fiori-র মতো। ব্যবহারকারী তাঁর চেনা
 * ERP-তে বসেন, কেবল ডাটাটা ABOS-এর।
 *
 * ── কেন এই তালিকাটা কোড, ডাটাবেস নয় ─────────────────────────────────
 * প্রতিটা চেহারার সাথে একগাদা CSS টোকেন আর একটা শেল জড়ানো। ডাটাবেসে
 * সারি হিসেবে রাখলে কেউ নবমটা যোগ করতে পারতেন — আর তার কোনো টোকেন
 * থাকত না, কোনো শেল থাকত না, ফলে পর্দা ফাঁকা আসত। "খোলা তালিকা"
 * নিয়মটা (দলের ধরন, পক্ষের ধরন) ওইসব জিনিসের জন্য যেগুলো কেবল সারি;
 * এটা সারি নয়, এটা কোড।
 *
 * ── চারটা হুবহু নকল ─────────────────────────────────────────────────
 * Tiles · Suite · Apps · Dynamic · Redwood — পাঁচটাই সত্যিকারের ERP-র
 * নকল, আন্দাজে নয়। কোনটা কার, সেটা প্রতিটার `imitates`-এ লেখা, আর
 * নকলটা যাচাই করা যায় বলেই নামটা এখানে থাকা দরকার।
 */
final class Ui
{
    public const DEFAULT = 'classic';

    /**
     * @return array<string, array{
     *     label: string, blurb: string, imitates: ?string,
     *     density: string, swatch: string, ink: string,
     * }>
     */
    public static function all(): array
    {
        return [
            /*
             * ক্লাসিক — আজ যা দেখা যাচ্ছে, হুবহু তাই।
             *
             * এটাই ডিফল্ট, আর সেটাই একমাত্র নিরাপদ ডিফল্ট: যে
             * ব্যবহারকারী কোনোদিন এই পাতাটা খুলবেন না, তাঁর ERP যেন
             * কোনোদিন নিজে থেকে না বদলায়।
             */
            'classic' => [
                'label' => 'core.ui.classic',
                'blurb' => 'core.ui.classic_blurb',
                'imitates' => null,
                'density' => 'comfortable',
                'swatch' => '#2563eb',
                'ink' => '#0f172a',
            ],
            'tiles' => [
                'label' => 'core.ui.tiles',
                'blurb' => 'core.ui.tiles_blurb',
                'imitates' => 'SAP Fiori',
                'density' => 'comfortable',
                'swatch' => '#0a6ed1',
                'ink' => '#32363a',
            ],
            'suite' => [
                'label' => 'core.ui.suite',
                'blurb' => 'core.ui.suite_blurb',
                'imitates' => 'Oracle NetSuite',
                'density' => 'dense',
                'swatch' => '#125740',
                'ink' => '#1f2b28',
            ],
            'apps' => [
                'label' => 'core.ui.apps',
                'blurb' => 'core.ui.apps_blurb',
                'imitates' => 'Odoo',
                'density' => 'comfortable',
                'swatch' => '#714b67',
                'ink' => '#374151',
            ],
            'dynamic' => [
                'label' => 'core.ui.dynamic',
                'blurb' => 'core.ui.dynamic_blurb',
                'imitates' => 'Microsoft Dynamics 365',
                'density' => 'dense',
                'swatch' => '#0078d4',
                'ink' => '#242424',
            ],
            'redwood' => [
                'label' => 'core.ui.redwood',
                'blurb' => 'core.ui.redwood_blurb',
                'imitates' => 'Oracle Fusion Cloud',
                'density' => 'comfortable',
                'swatch' => '#c74634',
                'ink' => '#161513',
            ],
            /*
             * শেষ দুইটা কারও নকল নয় — ABOS-এর নিজের।
             *
             * পাঁচটা নকলের পাশে দুইটা নিজের চেহারা রাখার কারণ: নকল
             * মানে অন্যের সীমাও নকল করা। রোজ ও নেভি সেই সীমার বাইরে,
             * আর ওখানেই ABOS নিজের মতো দেখতে পারে।
             */
            'rose' => [
                'label' => 'core.ui.rose',
                'blurb' => 'core.ui.rose_blurb',
                'imitates' => null,
                'density' => 'comfortable',
                'swatch' => '#be123c',
                'ink' => '#1f1417',
            ],
            'navy' => [
                'label' => 'core.ui.navy',
                'blurb' => 'core.ui.navy_blurb',
                'imitates' => null,
                'density' => 'dense',
                'swatch' => '#1e3a8a',
                'ink' => '#0b1220',
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * অচেনা মান এলে ক্লাসিক।
     *
     * ── কেন এই ছাঁকনিটা দরকার ───────────────────────────────────────
     * কলামটা একটা string। কেউ যদি সরাসরি ডাটাবেসে `odoo` বসিয়ে দেন,
     * বা একটা চেহারা কোনোদিন বাদ দেওয়া হয়, তখন `<html data-ui="odoo">`
     * বসত — যার কোনো টোকেন নেই। ফলে পাতাটা আঁকা হত রংহীন, মাপহীন,
     * আর দেখে মনে হত CSS লোডই হয়নি।
     *
     * ভুল মানে ভাঙা পর্দা নয়, ভুল মানে ডিফল্ট।
     */
    public static function clean(?string $key): string
    {
        return in_array($key, self::keys(), true) ? $key : self::DEFAULT;
    }
}
