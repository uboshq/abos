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
            $this->assertDoesNotMatchRegularExpression('/[0-9]/', $text, "⛔ বাংলা নকশা {$design} ({$size}) ইংরেজি অঙ্ক ছাপল");
        }
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
