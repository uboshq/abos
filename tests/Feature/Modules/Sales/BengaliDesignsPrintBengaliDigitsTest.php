<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\BanglaDigits;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ বাংলা নকশায় বাংলা অঙ্ক, ইংরেজি নকশায় ইংরেজি অঙ্ক — মালিক, ১০ অক্টোবর ২০২৬ ([[BanglaDigits::inText()]])।
 *
 * ⛔ আগে সব নকশাই ইংরেজি অঙ্কে ছাপত — পুরো বাংলা বিলেও টাকা, তারিখ আর নম্বর ইংরেজিতে।
 * ⓘ দাবি কাগজের পড়ার লেখায় (ট্যাগ আর CSS বাদে): বাংলা নকশায় একটাও ইংরেজি অঙ্ক নয়, ইংরেজি নকশায় একটাও বাংলা নয়।
 */
final class BengaliDesignsPrintBengaliDigitsTest extends TestCase
{
    use RefreshDatabase;

    private const BENGALI = '/[\x{09E6}-\x{09EF}]/u';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_bengali_designs_carry_only_bengali_digits(): void
    {
        foreach ([
            ['sales.invoice_sample', 'mono_light_bn', 'a4'], ['sales.invoice_sample', 'bangla_heritage', 'a4'],
            ['sales.invoice_sample', 'world_standard_bn', 'a5'], ['sales.invoice_sample', 'bangla', 'thermal'],
            ['sales.challan_sample', 'bangla_heritage', 'a4'], ['sales.receipt_sample', 'bangla_heritage', 'a4'],
        ] as [$route, $design, $size]) {
            $text = $this->text($route, $design, $size);

            $this->assertMatchesRegularExpression(self::BENGALI, $text, "দৃশ্যটাই বানানো যায়নি — {$design} ({$size}) কাগজে কোনো অঙ্কই নেই");

            /*
             * ⓘ ইংরেজি অঙ্ক কেবল খোঁজার নম্বরে — কাগজের নম্বর, গাড়ি, TrxID, BIN, ফোন, ইমেইল, ওয়েব (fe-র ডিফল্ট, ১১ অক্টোবর ২০২৬;
             * [[BanglaDigits::keepsItsDigits()]])। ⛔ টাকা, পরিমাণ বা তারিখে একটা ইংরেজি অঙ্কও নয়।
             */
            preg_match_all('/\S*[0-9]\S*/u', $text, $words);
            $keeps = new \ReflectionMethod(BanglaDigits::class, 'keepsItsDigits');
            $stray = array_values(array_filter($words[0], fn (string $w) => ! $keeps->invoke(null, $w)));
            $this->assertSame([], $stray, "⛔ বাংলা নকশা {$design} ({$size}) টাকা/পরিমাণ/তারিখ ইংরেজি অঙ্কে ছাপল");
        }
    }

    /** ⭐ খোঁজার নম্বর ইংরেজিতে, টাকা-পরিমাণ-তারিখ বাংলায়; আর ভাঙা লেখায় কাগজ ফাঁকা নয় (PR #17 রিভিউ ⚠️৯)। */
    public function test_numbers_people_search_for_keep_english_digits_and_a_broken_byte_never_blanks_the_paper(): void
    {
        $this->assertSame('<p>বিল S-2610-0001 · ৳ ১,২০০.০০ · ১১-১০-২০২৬ · ১২ পিস</p>',
            BanglaDigits::inText('<p>বিল S-2610-0001 · ৳ 1,200.00 · 11-10-2026 · 12 পিস</p>'));

        foreach (['adi2024@gmail.com', 'www.adi2024.com', 'DHA-GA-11-2233', 'TrxID:9AB1C2D3', '01711-123456', '01711123456', '002345678901'] as $searched) {
            $this->assertSame("<td>{$searched}</td>", BanglaDigits::inText("<td>{$searched}</td>"), "⛔ «{$searched}» বাংলা অঙ্কে — খোঁজার ঘরে টাইপ করে পাওয়া যাবে না");
        }

        // ⛔ নষ্ট বাইট — PCRE `null` ফেরায়; আগে গোটা কাগজ ফাঁকা হত
        $broken = "<p>বিল 12 \xC3\x28</p>";
        $this->assertSame($broken, BanglaDigits::inText($broken), '⛔ নষ্ট লেখায় কাগজ ফাঁকা হয়ে গেল');
    }

    public function test_english_designs_keep_english_digits(): void
    {
        foreach ([['mono_light', 'a4'], ['world_standard', 'a5'], ['acct_card', 'a4']] as [$design, $size]) {
            $text = $this->text('sales.invoice_sample', $design, $size);

            $this->assertMatchesRegularExpression('/[0-9]/', $text);
            preg_match_all('/\S*[\x{09E6}-\x{09EF}]\S*/u', $text, $found);
            $this->assertSame([], $found[0], "⛔ ইংরেজি নকশা {$design} বাংলা অঙ্ক ছাপল");
        }
    }

    public function test_tags_styles_and_entities_keep_their_digits(): void
    {
        $this->assertSame('<p style="width: 3mm">১২ &#2453;</p>', BanglaDigits::inText('<p style="width: 3mm">12 &#2453;</p>'));
    }

    /** ⓘ কাগজের পড়ার লেখা — ট্যাগ, `<style>` আর চিহ্ন বাদে */
    private function text(string $route, string $design, string $size): string
    {
        return $this->readable($this->html($route, $design, $size));
    }

    private function html(string $route, string $design, string $size): string
    {
        return (string) $this->get(route($route, ['design' => $design, 'size' => $size]))->assertOk()->getContent();
    }

    private function readable(string $html): string
    {
        return (string) preg_replace('/<style\b.*?<\/style>|<script\b.*?<\/script>|<[^>]*>|&#?[a-zA-Z0-9]+;/su', ' ', $html);
    }
}
