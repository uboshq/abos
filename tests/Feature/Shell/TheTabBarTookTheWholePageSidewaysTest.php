<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ট্যাব-বারটা গোটা পাতা নিয়ে পাশে সরে যেত।
 *
 * ── ⛔ কী ভাঙা ছিল, আর মাপটা কী বলেছিল ────────────────────────────────
 * বিক্রয়ের পর্দাগুলোয় ১৩৬৬×৯০০-এ নথিটা আড়াআড়ি স্ক্রল করত। ⓘ দায়
 * নিশ্চিত হয়েছিল অঙ্কে: মডিউলবারের **সবচেয়ে ডানের ঘরটা** পর্দার ধার
 * থেকে যত px বাইরে ছিল, নথির পাশে-স্ক্রলও ছিল ঠিক তত —
 * `/dashboard/sales`-এ দুইটাই ৪৫৭, `/sales/overview`-এ দুইটাই ৪৯৬।
 *
 * ⚠️ ফল কেবল "একটু নড়ে" নয়: ডান দিকের বোতামগুলো পর্দার বাইরে চলে যায়,
 * আর মানুষ আড়াআড়ি স্ক্রল করার কথা ভাবেন না — তাঁরা ধরে নেন বোতামটা নেই।
 *
 * ── ⚠️ আর কারণটা ফাইলের নিজের মন্তব্যেই লেখা ছিল ──────────────────────
 * [[shell.modulebar]]-এ লেখা ছিল গ্রুপ-মোডে স্ক্রল দরকার নেই, কারণ
 * *"২৯টা পর্দা পাঁচটা ঘরে নেমে আসে, যা এমনিতেই এক সারিতে ধরে"*।
 * ⛔ ২৮–২৯ সেপ্টেম্বরে বিক্রয়ে নতুন ভাঁজ যোগ হওয়ার পর সেটা আর সত্য নয় —
 * ওখানে এখন তেরোটা ঘর।
 *
 * ── ⭐ এই ফাইলের দাবিটা আকৃতি নয়, অপরিবর্তনীয়টা ─────────────────────
 * ⓘ *"ক্লাসে `flex-wrap` লেখা আছে"* — এটা একটা আকৃতি, আর আকৃতি ধরে
 * পাহারা দিলে কাল অন্য কোনো উপায়ে সারালেই দাবিটা মিথ্যা লাল দিত।
 *
 * ⭐ আসল কথাটা হলো: **যে মোডেই থাকুক, বারের উপচে পড়া সামলানোর একটা পথ
 * থাকতে হবে** — হয় সে নিজে স্ক্রল করবে (`overflow-x-auto`), নয় মুড়ে
 * যাবে (`flex-wrap`)। ⛔ দুইটার একটাও না থাকা মানেই বাগটা ফিরে এসেছে,
 * আর ঠিক সেই দশাতেই সে মাসখানেক ছিল।
 *
 * ── ⓘ লেআউটটা এখানে মাপা যায় না, আর সেটা লুকানো হচ্ছে না ─────────────
 * ⚠️ PHPUnit কোনো CSS চালায় না, তাই এখান থেকে `scrollWidth` জানা যায়
 * না। ⓘ আসল মাপটা নেওয়া হয়েছে আসল ব্রাউজারে (Playwright, রিপোর বাইরে,
 * `E:\ABOS\e2e`): সারাইয়ের পর ১৩৬৬, ১৯২০ আর ৩৬০ — তিন চওড়ায়, বিক্রয়,
 * হিসাব ও মজুদের পাতায় বারের সবচেয়ে ডানের ঘর পর্দার ভিতরে, আর
 * দ্বিতীয় সারিতে নেমে যাওয়া গ্রুপের ড্রপডাউনটা খোলে ও পুরোটা দেখা যায়
 * (২৪০×২২২, কোনো পূর্বপুরুষ ওটা কাটে না)।
 *
 * ⭐ তাই এই ফাইলটা পাহারা দেয় **কারণটা**, আর ব্রাউজারের মাপটা দেখায়
 * **ফলটা**। দুইটার একটাও একা যথেষ্ট নয়।
 */
final class TheTabBarTookTheWholePageSidewaysTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⓘ আট ঘরের বেশি হলে বারটা গ্রুপ-মোডে যায় ([[shell.modulebar]]-এ
     * `$groupAfter = 8`)। ⚠️ সংখ্যাটা এখানে আবার লেখা হয়নি — দুই জায়গায়
     * রাখলে একদিন দুইটা আলাদা কথা বলত; নিচে পাতাটা আঁকিয়ে **গোনা** হয়।
     */
    private const CROWDED = '/sales/overview';

    private const ROOMY = '/customers';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_a_crowded_bar_has_somewhere_for_the_extra_cells_to_go(): void
    {
        [$nav, $cells] = $this->bar(self::CROWDED);

        /*
         * ⛔ ভিড়টা আগে প্রমাণ করা হয় — নাহলে পর্দাটা কোনোদিন সোজা মোডে
         * চলে গেলে দাবিটা নীরবে অর্থহীন হয়ে যেত, আর সবুজই থাকত।
         */
        $this->assertGreaterThan(8, $cells,
            self::CROWDED.'-এ বারটা আর ভিড় নয় — এই দাবিটা তখন কিছুই মাপছে না।');

        $this->assertTrue(
            $this->scrolls($nav) || $this->wraps($nav),
            'ভিড়ের বারে উপচে পড়া সামলানোর কোনো পথ নেই — সে গোটা পাতা নিয়ে পাশে সরবে।',
        );
    }

    public function test_the_crowded_bar_wraps_rather_than_clipping_its_dropdowns(): void
    {
        /*
         * ⛔ ভিড়ের বারে ঘরগুলো ড্রপডাউন — আর `overflow-x-auto` একটা
         * ক্লিপিং বাক্স বানায়, তাই ঐ ড্রপডাউনগুলো খুলত কিন্তু দেখা যেত
         * না। ⚠️ প্রথম খসড়ায় ঠিক তাই হয়েছিল: গ্রুপে চাপলে মনে হত কিছুই
         * হচ্ছে না।
         *
         * ⓘ তাই এখানে পথটা মোড়ানো, স্ক্রল নয় — আর এটাই সেই একটা জায়গা
         * যেখানে কোন পথ, সেটাও দাবির অংশ।
         */
        [$nav, $cells] = $this->bar(self::CROWDED);

        $this->assertGreaterThan(8, $cells);

        $this->assertTrue($this->wraps($nav),
            'ভিড়ের বারটা মুড়ে যায় না।');

        $this->assertFalse($this->scrolls($nav),
            'ভিড়ের বারে স্ক্রল বসানো হয়েছে — গ্রুপের ড্রপডাউনগুলো তখন কেটে যাবে।');
    }

    public function test_a_roomy_bar_still_scrolls_instead_of_growing_taller(): void
    {
        /*
         * ⭐ একই উপাদান, কেবল ঘরের সংখ্যা আলাদা — তাই পার্থক্যটা আর
         * কিছু থেকে আসতে পারে না।
         *
         * ⓘ সোজা মোডে মোড়ানো হয় না, আর কারণটা এখনো খাটে: বিশটা পর্দার
         * মডিউলে মোড়ালে বারের উচ্চতা পর্দাভেদে বদলাত আর নিচের পাতা
         * লাফাত। ⚠️ ওখানে ড্রপডাউনও নেই, তাই স্ক্রল করা যায়।
         */
        [$nav, $cells] = $this->bar(self::ROOMY);

        $this->assertLessThanOrEqual(8, $cells,
            self::ROOMY.'-এ বারটা এখন ভিড় — এই দাবিটা তখন ভুল দশা মাপছে।');

        $this->assertTrue($this->scrolls($nav),
            'ফাঁকা বারটা নিজে স্ক্রল করে না, তাই ঘর বাড়লে পাতা পাশে সরবে।');

        $this->assertFalse($this->wraps($nav),
            'ফাঁকা বারটা মুড়ে যায় — তখন বারের উচ্চতা পর্দাভেদে বদলাবে।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * আঁকা পাতাটা থেকে বারের `<nav>`-এর শ্রেণি আর তার ঘরের সংখ্যা।
     *
     * ⓘ `data-module-bar` ধরে খোঁজা হয়, `aria-label` ধরে নয় — একই লেখা
     * নিচের মোবাইল-নেভেও আছে, আর ঐ ভুলটা এই বারেই আগে হয়েছে
     * ([[shell.modulebar]]-এর মন্তব্য)।
     *
     * @return array{0: string, 1: int}
     */
    private function bar(string $path): array
    {
        $html = $this->actingAs($this->owner)->get($path)->assertOk()->getContent();

        $at = strpos($html, 'data-module-bar');

        $this->assertNotFalse($at, $path.'-এ মডিউলবারটাই আঁকা হয়নি।');

        $navAt = strpos($html, '<nav', $at);

        $this->assertNotFalse($navAt, 'বারের ভিতরে কোনো `<nav>` নেই।');

        $end = strpos($html, '>', $navAt);
        $tag = substr($html, $navAt, $end - $navAt);

        $this->assertMatchesRegularExpression('/class="([^"]*)"/', $tag,
            'বারের `<nav>`-এ কোনো শ্রেণিই নেই।');

        preg_match('/class="([^"]*)"/', $tag, $m);

        /*
         * ⓘ ঘর গোনা হয় `</nav>` পর্যন্ত, আর সরাসরি সন্তানের বদলে
         * `modulebar-cell` ধরে — ঘরটা সরাসরি একটা `<a>` হতে পারে, নয়তো
         * একটা ড্রপডাউনের ভিতরে বসা বোতাম।
         */
        $closes = strpos($html, '</nav>', $navAt);
        $inside = substr($html, $navAt, $closes - $navAt);

        return [$m[1], substr_count($inside, 'modulebar-cell')];
    }

    private function scrolls(string $classes): bool
    {
        return str_contains($classes, 'overflow-x-auto');
    }

    private function wraps(string $classes): bool
    {
        return str_contains($classes, 'flex-wrap');
    }
}
