<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\LookGate;
use App\Core\Support\LookRegistry;
use App\Core\Support\Ui;
use Tests\TestCase;

/**
 * কালি আর জমিনের প্রতিটা জোড়া পড়া যায় কি না — থিম ইঞ্জিনের ধাপ ২।
 *
 * ── কেন একটা গেট লাগে ────────────────────────────────────────────────
 * ধাপ ৩-এ মানুষ পর্দা থেকে রূপ বানাবেন, আর রঙের সবচেয়ে সাধারণ ভুলটা
 * হলো কম কনট্রাস্ট: হালকা ধূসরে হালকা ধূসর। বানানোর সময় বড় পর্দায়
 * ওটা ঠিকই লাগে; কাউন্টারের সস্তা মনিটরে দুপুরের আলোয় লাগে না।
 *
 * ── আজকের দশটা রূপ পুরোপুরি পাশ করে না, আর সেটা লুকানো হয়নি ──────────
 * বারোটা জোড়া AA-র নিচে, আর প্রায় সবগুলো **নকলের দাম**: Odoo-র ফিকে
 * কালি (#8A7F90) সাদায় ৩.৮:১, Redwood-এরটা ৩.৫৬, SAP-এর সতর্ক-ব্যাজ
 * ৪.১৯। আসল পণ্যেই ওগুলো ওখানে।
 *
 * মালিকের হার্ড রুল: **হুবহু নকল, আর্টিফ্যাক্টই মাপকাঠি**। রংটা নিজে
 * থেকে গাঢ় করে দিলে নকলটা ভাঙত, আর সেটা আমার সিদ্ধান্ত নয়।
 *
 * ── জিজ্ঞেস করা হয়েছে, উত্তর এসেছে ──────────────────────────────────
 * ২৪ আগস্ট ২০২৬-এ মালিককে দুইটা পথ দেখানো হয়েছিল: রং আসল পণ্যের মতো
 * রাখা, নাকি সামান্য গাঢ় করে AA পার করানো। উত্তর: **"আসল পন্যের মত
 * রাখ"**।
 *
 * তাই এই সারিগুলো ভুল নয়, সিদ্ধান্ত। কেউ "ঠিক করে দিই" ভেবে Odoo-র
 * #8A7F90 গাঢ় করলে নকলটা ভাঙবে, আর সেটা মালিকের নিয়মের বিরুদ্ধে —
 * এই মন্তব্যটা ঠিক সেজন্যই।
 *
 * তাই ছাড়টা **নীরব নয়, গোনা**: নিচের তালিকায় প্রতিটা জোড়া নাম ধরে
 * লেখা। নতুন কোনো জোড়া খারাপ হলে টেস্ট ভাঙে; কোনোটা ঠিক করলেও ভাঙে,
 * আর তখন সারিটা তালিকা থেকে তুলে দিতে হয়। দুই দিকেই আটকানো — এটাই
 * ছাড় আর ঘুমিয়ে পড়ার মধ্যে পার্থক্য।
 */
class FaintTextOnACheapMonitorTest extends TestCase
{
    /**
     * যেগুলো আজ AA-র নিচে — প্রতিটার কারণসহ।
     *
     * `রূপ|থিম|কালি` => কেন এটা আজ মেনে নেওয়া হচ্ছে
     *
     * @var array<string, string>
     */
    private const KNOWN = [
        // SAP Fiori-র নিজের সতর্ক-রং। ৪.১৯ — AA থেকে সামান্য নিচে,
        // আর ব্যাজের লেখা ছোট হলেও পাশে একটা আইকন ও শব্দ থাকে।
        'tiles|light|--color-badge-pending-ink' => 'SAP-এর নিজের #B8860B',
        'tiles|light|--color-badge-warning-ink' => 'SAP-এর নিজের #B8860B',
        'tiles|dark|--color-badge-pending-ink' => 'SAP-এর নিজের #B8860B',
        'tiles|dark|--color-badge-warning-ink' => 'SAP-এর নিজের #B8860B',

        // Odoo-র ফিকে কালি #8A7F90। আসল Odoo-তেও এটাই, আর এটাই তার
        // নরম চেহারার একটা বড় অংশ।
        'apps|light|--color-ink-muted' => 'Odoo-র নিজের #8A7F90',
        'apps|light|--color-topnav-ink-muted' => 'Odoo-র নিজের #8A7F90',
        'apps|light|--color-table-head-ink' => 'Odoo-র নিজের #8A7F90',
        'apps|light|--color-footer-ink' => 'Odoo-র নিজের #8A7F90',
        'apps|dark|--color-topbar-ink-muted' => 'অবার্জিন মাথায় ফিকে কালি',

        // Oracle Redwood-এর #8A8681 — ক্রিম জমিনে তার নিজের ধূসর।
        'redwood|light|--color-ink-muted' => 'Redwood-এর নিজের #8A8681',
        'redwood|light|--color-topnav-ink-muted' => 'Redwood-এর নিজের #8A8681',
        'redwood|light|--color-footer-ink' => 'Redwood-এর নিজের #8A8681',
    ];

    /**
     * তালিকার বাইরে একটাও নতুন ব্যর্থতা নেই।
     *
     * এটাই এই ফাইলের আসল দাবি। কেউ একটা রূপের রং বদলে লেখাটা ফিকে করে
     * দিলে এখানেই ধরা পড়বে — মানুষের চোখে নয়, যেটা কেবল ওই একটা পর্দায়
     * বসে কাজ করার পর হয়।
     */
    public function test_no_look_grew_a_new_unreadable_pair(): void
    {
        $found = [];

        foreach (Ui::keys() as $look) {
            foreach (['light', 'dark'] as $theme) {
                foreach (LookGate::failures(LookRegistry::tokens($look, $theme)) as $bad) {
                    $found[] = "{$look}|{$theme}|{$bad['ink']}";
                }
            }
        }

        $new = array_values(array_diff($found, array_keys(self::KNOWN)));

        $this->assertSame([], $new, implode("\n", [
            'এই জোড়াগুলোয় লেখা জমিনের সাথে মিশে যাচ্ছে (AA = ৪.৫:১):',
            ...$new,
            '',
            'রংটা ঠিক করুন, অথবা নকলের দাম হলে KNOWN-এ কারণসহ লিখুন।',
        ]));
    }

    /**
     * তালিকাটা বাড়েও না, বাসিও হয় না।
     *
     * একটা জোড়া ঠিক করার পর সারিটা তালিকায় থেকে গেলে ছাড়টা চিরকাল
     * বসে থাকত, আর একদিন ওই একই জোড়া আবার খারাপ হলে কেউ জানত না।
     *
     * ঠিক এই ভুলটাই আজ `Ui::BARE`-এ ধরা পড়েছে: একটা ছাড় যে জগতের
     * কথা বলছিল সেটা আর ছিল না, আর তার একমাত্র ফল ছিল ক্লাসিক কোনো
     * পরীক্ষায় না পড়া।
     */
    public function test_the_list_of_exceptions_has_no_stale_rows(): void
    {
        $found = [];

        foreach (Ui::keys() as $look) {
            foreach (['light', 'dark'] as $theme) {
                foreach (LookGate::failures(LookRegistry::tokens($look, $theme)) as $bad) {
                    $found[] = "{$look}|{$theme}|{$bad['ink']}";
                }
            }
        }

        $stale = array_values(array_diff(array_keys(self::KNOWN), $found));

        $this->assertSame([], $stale, implode("\n", [
            'এই ছাড়গুলো আর দরকার নেই — জোড়াগুলো এখন AA পাশ করে।',
            ...$stale,
            '',
            'KNOWN থেকে সারিগুলো তুলে দিন, নাহলে ছাড়টা ঘুমিয়ে পড়বে।',
        ]));
    }

    /**
     * গেটটা সত্যিই ধরে — একটা ফিকে রূপ বানিয়ে দেখা।
     *
     * উপরের দুইটা পরীক্ষা আজকের অবস্থা মাপে। এটা মাপে গেটটা কাজ করে
     * কি না, আর ওটা আলাদা প্রশ্ন: `failures()` সবসময় খালি ফেরালে
     * উপরের দুইটাও দিব্যি সবুজ থাকত।
     */
    public function test_the_gate_actually_refuses_faint_text(): void
    {
        $faint = LookGate::failures([
            '--color-ink' => '#cccccc',
            '--color-surface-app' => '#ffffff',
        ]);

        $this->assertCount(1, $faint);
        $this->assertSame('--color-ink', $faint[0]['ink']);
        $this->assertLessThan(LookGate::AA, $faint[0]['ratio']);
    }

    /** আর সত্যিই ছাড়েও — কালো-সাদা নিয়ে আপত্তি তোলে না। */
    public function test_the_gate_lets_readable_text_through(): void
    {
        $this->assertSame([], LookGate::failures([
            '--color-ink' => '#111111',
            '--color-surface-app' => '#ffffff',
        ]));
    }

    /**
     * স্বচ্ছ জমিনে গেটটা চুপ থাকে।
     *
     * স্বচ্ছ জমিনের নিজের কোনো রং নেই — নিচে যা আছে সেটাই দেখা যায়।
     * ওটাকে সাদা ধরে নিলে গেট এমন কিছু নিয়ে রায় দিত যা সে জানে না।
     * Fiori ও Redwood-এর ছকের মাথা সত্যিই `transparent`।
     */
    public function test_a_transparent_ground_is_not_guessed_at(): void
    {
        $this->assertSame([], LookGate::failures([
            '--color-table-head-ink' => '#8a8681',
            '--color-table-head' => 'transparent',
        ]));
    }
}
