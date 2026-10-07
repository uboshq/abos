<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Support\Ui;
use Tests\TestCase;

/**
 * বাকি ন'টা রূপে কেউ হাত দেয়নি।
 *
 * ── কেন এই পাহারাটা লেখা হলো ─────────────────────────────────────────
 * ২ সেপ্টেম্বর ২০২৬-এ ABOS-এর নিজের রূপটার (চাবি `navy`) রং বদলে
 * ব্র্যান্ডের টিয়া করা হয়েছে। মালিকের নির্দেশ ছিল একটাই বাক্যে:
 * **"শুধু navy থিমটাই আপডেট করবে, অন্য ন'টা ১০০% ঠিক থাকবে, ভুলেও
 * হাত দিবে না।"**
 *
 * ── কেন মনে রাখার উপর ছাড়া গেল না ────────────────────────────────────
 * দশটা রূপ একটাই ফাইলে, একটাই অ্যারেতে, আর প্রতিটার ঘরগুলো হুবহু এক
 * রকম দেখতে। একটা `swatch` কপি করতে গিয়ে পাশেরটায় পড়লে চোখে ধরা
 * পড়ার কোনো উপায় নেই। **রঙের ভুল নীরব: পাতা ভাঙে না, টেস্ট লাল হয়
 * না, কেবল কারও পর্দা অন্যরকম হয়ে যায়** — আর তিনি বলতেও আসেন না,
 * ভাবেন এমনই ছিল।
 *
 * ── কেন হ্যাশ নয়, পুরো তালিকা ─────────────────────────────────────────
 * একটা checksum রাখলে পাহারাটা কেবল বলত "কিছু একটা বদলেছে"। পুরো
 * তালিকা থাকলে ব্যর্থতার বার্তাতেই **কোন রূপের কোন ঘর, আগে কী ছিল আর
 * এখন কী** — তিনটাই দেখা যায়।
 *
 * ── `navy` এখানে ইচ্ছে করে নেই ───────────────────────────────────────
 * ওটাই একমাত্র রূপ যেটা বদলানোর কথা। থাকলে প্রতিবার ব্র্যান্ড বদলালে
 * এই ফাইলটাও বদলাতে হত, আর তখন পাহারাটা কেবল একটা বাধা হয়ে যেত —
 * যে বাধা মানুষ শেষ পর্যন্ত মুছে ফেলে।
 */
class TheOtherNineLooksWereNotTouchedTest extends TestCase
{
    /**
     * ২ সেপ্টেম্বর ২০২৬-এ যেমন ছিল, হুবহু।
     *
     * @var array<string, array<string, string|null>>
     */
    private const AS_SHIPPED = [
        'rose' => [
            'accent' => 'crimson',
            'band' => 'none',
            'blurb' => 'core.ui.rose_blurb',
            'commands' => 'inline',
            'density' => 'comfortable',
            'filters' => 'toggle',
            'imitates' => null,
            'ink' => '#1f1417',
            'label' => 'core.ui.rose',
            'nav' => 'rail',
            'panel' => 'light',
            'record' => 'facts',
            'sections' => 'plain',
            'swatch' => '#be123c',
            'topnav' => 'none',
            'views' => 'title',
        ],
        'tiles' => [
            'accent' => 'fiori',
            'band' => 'none',
            'blurb' => 'core.ui.tiles_blurb',
            'commands' => 'bar',
            'density' => 'comfortable',
            'filters' => 'bar',
            'imitates' => 'SAP Fiori',
            'ink' => '#32363a',
            'label' => 'core.ui.tiles',
            'nav' => 'top',
            'panel' => 'light',
            'record' => 'facts',
            'sections' => 'anchors',
            'swatch' => '#0a6ed1',
            'topnav' => 'modules',
            'views' => 'title',
        ],
        'suite' => [
            'accent' => 'netsuite',
            'band' => 'none',
            'blurb' => 'core.ui.suite_blurb',
            'commands' => 'bar',
            'density' => 'dense',
            'filters' => 'toggle',
            'imitates' => 'Oracle NetSuite',
            'ink' => '#1f2b28',
            'label' => 'core.ui.suite',
            'nav' => 'top',
            'panel' => 'light',
            'record' => 'facts',
            'sections' => 'plain',
            'swatch' => '#125740',
            'topnav' => 'modules',
            'views' => 'title',
        ],
        'apps' => [
            'accent' => 'aubergine',
            'band' => 'none',
            'blurb' => 'core.ui.apps_blurb',
            'commands' => 'inline',
            'density' => 'comfortable',
            'filters' => 'toggle',
            'imitates' => 'Odoo',
            'ink' => '#374151',
            'label' => 'core.ui.apps',
            'nav' => 'top',
            'panel' => 'light',
            'record' => 'smartbuttons',
            'sections' => 'plain',
            'swatch' => '#714b67',
            'topnav' => 'sections',
            'views' => 'title',
        ],
        'dynamic' => [
            'accent' => 'fluent',
            'band' => 'chevrons',
            'blurb' => 'core.ui.dynamic_blurb',
            'commands' => 'bar',
            'density' => 'dense',
            'filters' => 'toggle',
            'imitates' => 'Microsoft Dynamics 365',
            'ink' => '#242424',
            'label' => 'core.ui.dynamic',
            'nav' => 'rail',
            'panel' => 'light',
            'record' => 'facts',
            'sections' => 'plain',
            'swatch' => '#0078d4',
            'topnav' => 'none',
            'views' => 'dropdown',
        ],
        'redwood' => [
            'accent' => 'brick',
            'band' => 'none',
            'blurb' => 'core.ui.redwood_blurb',
            'commands' => 'inline',
            'density' => 'comfortable',
            'filters' => 'toggle',
            'imitates' => 'Oracle Fusion Cloud',
            'ink' => '#161513',
            'label' => 'core.ui.redwood',
            'nav' => 'rail',
            'panel' => 'light',
            'record' => 'facts',
            'sections' => 'plain',
            'swatch' => '#c74634',
            'topnav' => 'none',
            'views' => 'title',
        ],
        'salesforce' => [
            'accent' => 'salesforce',
            'band' => 'none',
            'blurb' => 'core.ui.salesforce_blurb',
            'commands' => 'inline',
            'density' => 'comfortable',
            'filters' => 'toggle',
            'imitates' => 'Salesforce Lightning',
            'ink' => '#032d60',
            'label' => 'core.ui.salesforce',
            'nav' => 'top',
            'panel' => 'light',
            'record' => 'facts',
            'sections' => 'plain',
            'swatch' => '#0176d3',
            'topnav' => 'none',
            'views' => 'title',
        ],
        'linear' => [
            'accent' => 'linear',
            'band' => 'none',
            'blurb' => 'core.ui.linear_blurb',
            'commands' => 'inline',
            'density' => 'dense',
            'filters' => 'toggle',
            'imitates' => 'Linear',
            'ink' => '#0e0f11',
            'label' => 'core.ui.linear',
            'nav' => 'quiet',
            'panel' => 'dark',
            'record' => 'facts',
            'sections' => 'plain',
            'swatch' => '#5e6ad2',
            'topnav' => 'none',
            'views' => 'title',
        ],
        'classic' => [
            'accent' => 'amber',
            'band' => 'none',
            'blurb' => 'core.ui.classic_blurb',
            'commands' => 'bar',
            'density' => 'dense',
            'filters' => 'toggle',
            'imitates' => null,
            'ink' => '#23303c',
            'label' => 'core.ui.classic',
            'nav' => 'top',
            'panel' => 'light',
            'record' => 'facts',
            'sections' => 'plain',
            'swatch' => '#e08c1a',
            'topnav' => 'modules',
            'views' => 'title',
        ],
    ];

    /** ন'টা রূপের একটা ঘরও বদলায়নি। */
    public function test_the_other_nine_looks_are_exactly_as_they_shipped(): void
    {
        $now = Ui::all();
        unset($now['navy']);

        foreach ($now as $key => $look) {
            ksort($look);
            $now[$key] = $look;
        }

        $expected = self::AS_SHIPPED;
        foreach ($expected as $key => $look) {
            ksort($look);
            $expected[$key] = $look;
        }

        ksort($now);
        ksort($expected);

        $this->assertSame($expected, $now, implode(PHP_EOL, [
            'ABOS-এর নিজেরটা ছাড়া অন্য রূপে পরিবর্তন হয়েছে।',
            '',
            'মালিকের নির্দেশ: শুধু ABOS-এর রূপটাই বদলাবে, বাকি নয়টা অপরিবর্তিত।',
            'সত্যিই বদলানোর কথা থাকলে এই ফাইলের AS_SHIPPED তালিকাটাও',
            'একই কমিটে হালনাগাদ করুন — তাহলে সিদ্ধান্তটা লেখা থাকে।',
        ]));
    }

    /**
     * আর ABOS-এর নিজেরটা সত্যিই বদলেছে।
     *
     * উপরের পরীক্ষাটা কেবল "কিছু বদলায়নি" বলে। কেউ ব্র্যান্ডের রংটা
     * ফিরিয়ে নীল করে দিলে ওটা সবুজই থাকত — কারণ `navy` ওখানে নেই।
     */
    public function test_the_abos_look_carries_the_brand_colour(): void
    {
        $navy = Ui::all()['navy'];

        $this->assertSame('abos', $navy['accent'], 'ABOS-এর রূপ ব্র্যান্ডের রং ছেড়ে দিয়েছে।');
        $this->assertSame('#087F91', $navy['swatch']);
        $this->assertSame('#06323C', $navy['ink']);
    }
}
