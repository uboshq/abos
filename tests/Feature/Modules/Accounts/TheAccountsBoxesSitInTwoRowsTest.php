<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * হিসাবের ড্যাশবোর্ড — নয়টা বাক্স দুই লাইনে (৫ + ৪)। মালিক, ১ অক্টোবর ২০২৬: *"accounts deshboard e ei
 * box gulo dui line bosaw"*।
 *
 * ⓘ নতুন সিদ্ধান্ত (মালিক, 10 নভেম্বর 2026 রাত — চূড়ান্ত নমুনা TErMgXrtgVBkuyuoCZq8NS আর মজুদের HmzeE5CN2wvtyLPE5exjvV): সংখ্যার বাক্সের
 * সারি আর নেই; পাতার মাথায় সমস্যা কেন্দ্র, বাকিটা নমুনার সারি ([[TheAccountsBoardReadsTheBooksTest]], [[TheStockDashboardShowsItsMoneyTest]])।
 * তাই দাবি বদলাল: দুই পাতায় পুরনো বাক্সের ঘর আর ফিরে আসে না, প্রথমে সমস্যা কেন্দ্র।
 */
final class TheAccountsBoxesSitInTwoRowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
    }

    public function test_the_accounts_page_opens_on_the_problem_centre_not_on_the_old_boxes(): void
    {
        $html = $this->get(route('module.dashboard', ['module' => 'accounts']))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-stat-grid', $html, '⛔ পুরনো নয়টা বাক্স আবার পাতায় — মালিকের চূড়ান্ত নমুনায় নেই।');
        $this->assertStringContainsString('data-acc-problems', $html, '⛔ পাতার মাথায় সমস্যা কেন্দ্র নেই।');
    }

    public function test_the_stock_page_opens_on_the_problem_centre_not_on_the_old_boxes(): void
    {
        $html = $this->get(route('module.dashboard', ['module' => 'inventory']))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-stat-grid', $html, '⛔ পুরনো ছয়টা বাক্স আবার পাতায় — মালিকের চূড়ান্ত নমুনায় নেই।');
        $this->assertStringContainsString('data-inv-problems', $html, '⛔ পাতার মাথায় সমস্যা কেন্দ্র নেই।');
    }
}
