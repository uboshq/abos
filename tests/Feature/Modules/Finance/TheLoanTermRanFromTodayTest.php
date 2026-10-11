<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Services\BankFacilityService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ঋণের নবায়নের দিন গোনা হত আজ থেকে, মঞ্জুরির দিন থেকে নয় — পুরো-ERP অডিট, অর্থ M25b, ১০ অক্টোবর ২০২৬।
 *
 * ⭐ মেয়াদ না দিলে নবায়নের দিন = মঞ্জুরির দিন + মেয়াদ (মাস); নিজে দিলে সেটাই, আগের মতো।
 */
final class TheLoanTermRanFromTodayTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_renewal_day_counts_from_the_sanction(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        $bank = Institution::query()->create(['company_id' => CompanyContext::id(), 'kind' => Institution::BANK, 'name_en' => 'Term Bank']);

        // ⓘ মেয়াদি ঋণ — নবায়নের দিন সব ধরনে একই হিসাবে; মেয়াদির দরকারি ঘর দায়ের খাত আর কিস্তি
        $open = fn (array $extra) => app(BankFacilityService::class)->open([
            'kind' => BankFacility::TERM, 'institution_id' => $bank->id, 'bank' => 'Term Bank',
            'limit_amount' => '500000', 'interest_rate' => '11', 'instalments' => 24,
            'liability_account_id' => \App\Modules\Accounts\Models\Account::query()->where('code', '2211')->value('id'), ...$extra,
        ]);

        // ⓘ চলতি ঋণ — মঞ্জুরি ১৪ মাস আগে, মেয়াদ ২৪ মাস: নবায়ন আর ১০ মাস পরে, আজ থেকে ২৪ মাস নয়
        $sanction = now()->subMonthsNoOverflow(14)->startOfDay();
        $running = $open(['sanctioned_on' => $sanction->toDateString(), 'term_months' => 24]);
        $this->assertSame($sanction->copy()->addMonthsNoOverflow(24)->toDateString(), $running->renews_on?->toDateString(),
            '⛔ নবায়নের দিন আজ থেকে গোনা — মঞ্জুরির দিন থেকে নয়');

        // ⓘ নিজে দেওয়া দিন থাকে
        $given = $open(['sanctioned_on' => $sanction->toDateString(), 'term_months' => 24, 'renews_on' => now()->addDays(5)->toDateString()]);
        $this->assertSame(now()->addDays(5)->toDateString(), $given->renews_on?->toDateString());
    }
}
