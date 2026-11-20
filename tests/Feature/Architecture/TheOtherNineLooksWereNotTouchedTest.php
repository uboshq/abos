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
            // ⭐ ২০ নভেম্বর ২০২৬: SAP-এ পাতার পথের সারি নেই — আসল Fiori Horizon-এ breadcrumb-পটি নেই (মালিকের "SAP 100% হুবহু")
            'pathbar' => 'none',
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
        // ⓘ `blue` (নীল প্যানেল, ১৮ নভেম্বর ২০২৬) ABOS-এর নিজের নতুন রূপ, navy-র গড়নে — এই ন'টার একটাও নয়; নিচে আলাদা দাবি
        // ⓘ `rose` ১৯ নভেম্বর ২০২৬ থেকে নতুন "ABOS" — মালিকের অনুমোদিত নতুন রূপ, নিচে নিজের দাবি; বাকি আটটা অটুট
        unset($now['navy'], $now['blue'], $now['rose']);

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

    /**
     * ⭐ নীল প্যানেল মালিকের নিজের রং পরে, আর navy-র গড়নে দাঁড়ায় (মালিক, ১৮ নভেম্বর ২০২৬)।
     *
     * ⛔ মালিক Microsoft Fluent-এর গাঢ় নেভিতে রেগে গিয়েছিলেন — তাঁর রং: ফ্রেম #3a7be9, রেল/বার #2160d8, কাজের জায়গা #eef3fc,
     * ব্র্যান্ড #2563eb। কেউ "একটু ভদ্র" করতে গিয়ে বদলালে এখানে লাল।
     */
    public function test_the_blue_panels_wear_the_owners_own_blue_on_the_abos_skeleton(): void
    {
        $blue = Ui::all()['blue'];
        $light = \App\Core\Support\LookRegistry::tokens('blue', 'light');

        // ⓘ ১৯ নভেম্বর ২০২৬ থেকে প্রথম রূপ নতুন "ABOS" (`rose`) — নীল প্যানেল তবু নিজের খোলস আর রঙে আছে
        // ⭐ আলাদা থিম (মালিক, ১৮ নভেম্বর ২০২৬ রাত): navy-র উপর দাঁড়ায় না — নিজের খোলসের ফাইল, তবু ABOS-এর মডিউল-বার আর পথ পরে,
        //    আর চেহারার পাতায় Rose-এর পাশে "একই সাজ, আলাদা রং"
        $this->assertSame('blue', Ui::family('blue'), '⛔ নীল প্যানেল আবার navy-র উপর দাঁড়িয়েছে — navy বদলালে নীলও বদলাবে।');
        $this->assertTrue(view()->exists('components.shell.chrome.blue'), '⛔ নীল প্যানেলের নিজের খোলসের ফাইল নেই।');
        $this->assertTrue(Ui::wearsAbosShell('blue') && Ui::wearsAbosShell('navy'), '⛔ মডিউল-বার আর পাতার পথ হারাবে।');
        $this->assertFalse(Ui::wearsAbosShell('apps'), '⛔ অন্য রূপও ABOS-এর খোলস পরছে।');
        $this->assertFalse(Ui::changesArrangement('blue'), '⛔ নীল প্যানেল চেহারার পাতায় Rose-এর দলে নয়।');
        $this->assertTrue(Ui::changesArrangement('rose'), '⛔ নতুন "ABOS" রেল তুলে মডিউল-তালিকা বসায় — সাজই বদলায়।');
        $this->assertSame(['#2563eb', 'blue'], [$blue['swatch'], $blue['accent']]);
        $this->assertSame('#2160d8', $light['--color-topbar'], '⛔ উপরের বার মালিকের নীল নয়।');
        $this->assertSame('#2160d8', $light['--color-sidebar'], '⛔ রেল মালিকের নীল নয়।');
        $this->assertSame('#eef3fc', $light['--color-surface-app'], '⛔ কাজের জায়গা মালিকের হালকা নীল নয়।');
        $this->assertSame('22px', $light['--radius-card'], '⛔ কার্ডের কোণ স্পষ্ট গোল নয়।');
        $this->assertStringContainsString('--blue-frame: #3a7be9;', (string) file_get_contents(resource_path('css/blue-look.css')),
            '⛔ ফ্রেমের নীল মালিকের নয়।');
    }
    /**
     * ⭐ নতুন "ABOS" (`rose`) — মালিকের অনুমোদিত নমুনা, ১৯ নভেম্বর ২০২৬ (claude.ai artifact AAjAfjZ8TnCfnjtt3Kthjz v11)।
     *
     * ⛔ স্বাধীন রূপ — মালিক: "একটা বদলালে অন্যটা বদলাবে না"। নিজের টোকেনের ফাইল (ABOS Blue-র রং **লেখা**, পড়া নয়), নিজের খোলস
     * (`chrome/rose`), নিজের গড়নের CSS (`abos-look.css`, কেবল `[data-ui='rose']`); navy বা blue-র উপর দাঁড়ায় না।
     * ⓘ মেনু সমতল, ফ্রেমের হুবহু নীল; বাঁয়ের রেল নেই, পাতার মাথায় মডিউল-বার বা পথ নেই; প্রথম রূপ আর ডিফল্ট।
     */
    public function test_the_new_abos_look_stands_alone_in_the_frame_blue_and_comes_first(): void
    {
        $rose = Ui::all()['rose'];
        $light = \App\Core\Support\LookRegistry::tokens('rose', 'light');
        $blueLight = \App\Core\Support\LookRegistry::tokens('blue', 'light');

        $this->assertSame('rose', Ui::DEFAULT, '⛔ নতুন "ABOS" ডিফল্ট নয় — মালিকের ১৯ নভেম্বরের সিদ্ধান্ত।');
        $this->assertSame('rose', Ui::APPEARANCE_ORDER[0], '⛔ চেহারার পাতায় "ABOS" প্রথমে নয়।');
        $this->assertSame('ABOS', __('core.ui.rose', [], 'bn'));
        $this->assertSame('ABOS', __('core.ui.rose', [], 'en'));

        // ── স্বাধীন: নিজের ফাইল, নিজের পরিবার ──
        $this->assertSame('rose', Ui::family('rose'), '⛔ "ABOS" অন্য রূপের গড়নে দাঁড়িয়েছে।');
        $this->assertArrayNotHasKey('builds_on', $rose);
        $this->assertTrue(view()->exists('components.shell.chrome.rose'), '⛔ "ABOS"-এর নিজের খোলসের ফাইল নেই।');
        $tokens = (string) file_get_contents(app_path('Core/Support/Looks/rose.php'));
        $this->assertStringNotContainsString('blue.php', $tokens, '⛔ "ABOS"-এর রং ABOS Blue-র ফাইল থেকে পড়া — একটা বদলালে অন্যটা বদলাবে।');
        $this->assertStringNotContainsString('require', $tokens);
        $css = (string) file_get_contents(resource_path('css/abos-look.css'));
        $this->assertSame([], array_values(array_unique(array_diff(
            (preg_match_all("/data-ui='([a-z]+)'/", (string) preg_replace('#/\*.*?\*/#s', '', $css), $m) ? $m[1] : []), ['rose']))),
            '⛔ abos-look.css অন্য রূপকে ছোঁয়।');
        $this->assertStringNotContainsString('--blue-', $css, '⛔ abos-look.css ABOS Blue-র ভেরিয়েবল পড়ে।');
        $this->assertStringContainsString("@import './abos-look.css';", (string) file_get_contents(resource_path('css/app.css')));
        $chrome = (string) file_get_contents(resource_path('views/components/shell/chrome/rose.blade.php'));
        $this->assertStringNotContainsString('chrome.navy', $chrome);
        $this->assertStringNotContainsString('chrome.blue', $chrome);

        // ── ABOS Blue-র রং, মেনু সমতল ফ্রেমের নীলে ──
        foreach (['--color-topbar', '--color-surface-app', '--color-surface-card', '--color-ink', '--color-ink-muted', '--color-border', '--color-link', '--radius-card'] as $token) {
            $this->assertSame($blueLight[$token], $light[$token], "⛔ \"ABOS\"-এর {$token} ABOS Blue-র রং নয়।");
        }
        $this->assertSame('#2160d8', $light['--color-sidebar-panel'], '⛔ মেনু ফ্রেমের হুবহু নীল নয় (সমতল)।');
        $this->assertSame('#2160d8', $light['--color-sidebar']);
        $this->assertSame(['#ffffff', '#2160d8'], [$light['--rail-item-on-bg'], $light['--rail-item-on-ink']], '⛔ বাছা সারি সাদা বড়িতে নীল লেখা নয়।');
        $this->assertSame(['#2160d8', 'blue'], [$rose['swatch'], $rose['accent']]);

        // ── রেল নেই, মডিউল-বার বা পথ নেই; বাকিরা আগের মতো ──
        $this->assertSame('none', Ui::moduleRail('rose'), '⛔ "ABOS"-এ বাঁয়ের রেল ফিরে এসেছে।');
        // ⓘ ১৯ নভেম্বর ২০২৬ রাত থেকে `collapsed` — মেনু খোলা থাকলে মাথায় কিছু নেই, গুটালে মেনু টপবারের নিচে আড়াআড়ি (মালিক:
        //   "মেনু বারটা হাইড করলে উপর দিকে চলে যেত মেনুগুলা … আগে এইভাবে আসতো")
        $this->assertSame('collapsed', Ui::pathBar('rose'), '⛔ "ABOS"-এ মেনু গুটালে উপরের মেনু-সারির নিয়ম বদলেছে।');
        $this->assertFalse(Ui::wearsAbosShell('rose'));
        foreach (array_diff(Ui::keys(), ['rose']) as $other) {
            $this->assertSame('icons', Ui::moduleRail($other), "⛔ {$other}-এর রেল হারিয়েছে।");
            // ⓘ SAP (`tiles`) ২০ নভেম্বর ২০২৬ থেকে `none` — Fiori Horizon-এ breadcrumb-পটি নেই
            $this->assertSame($other === 'tiles' ? 'none' : (Ui::wearsAbosShell($other) ? 'modulebar' : 'crumbs'), Ui::pathBar($other), "⛔ {$other}-এর মাথার সারি বদলেছে।");
        }
    }
}
