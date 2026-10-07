<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\YearEndService;
use Carbon\Carbon;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ মাসের তালা বছর বন্ধ আটকাত — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (হিসাব ⚠️৯)।
 *
 * ⓘ বছর বন্ধের দাখিলা বসে বছরের শেষ দিনে; স্বাভাবিক ক্রমে সেই মাস আগে তালাবন্ধ হয় — তাই বছর বন্ধ ([[PostingEngine::post()]])
 * আর বছর আবার খোলা (উল্টো দাখিলা, [[PostingEngine::reverse()]]) দুইটাই ব্যর্থ হত। এখন `year_close` মাসের তালা আর পেছনের সীমা
 * পেরোয়; অন্য সব কাগজ আগের মতোই আটকায়।
 */
final class TheYearClosesPastTheMonthLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_year_closes_and_reopens_with_its_last_month_locked(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($owner = User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->assertSame(YearEndService::CLOSE_SOURCE, PostingEngine::YEAR_CLOSE, 'দুই জায়গার উৎসের নাম আলাদা হয়ে গেল');

        $year = FinancialYear::query()->where('is_current', true)->firstOrFail();
        $income = Account::query()->where('type', Account::INCOME)->where('is_group', false)->orderBy('code')->firstOrFail();
        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999),
            trxDate: Carbon::parse($year->starts_on)->addDays(2)->toDateString(), lines: [
                ['account_id' => StandardChart::find(StandardChart::SALARY_PAYABLE)->id, 'debit' => '3000'],
                ['account_id' => $income->id, 'credit' => '3000'],
            ]);

        // ⓘ বছরের শেষ মাস তালাবন্ধ — স্বাভাবিক ক্রম
        $end = Carbon::parse($year->ends_on);
        PeriodLock::query()->create(['company_id' => $company->id, 'year' => $end->year, 'month' => $end->month, 'locked_by' => $owner->id, 'locked_at' => now()]);

        // ⓘ অন্য কাগজ ওই মাসে আগের মতোই আটকায় — তালাটা ঢিলে হয়নি
        $this->assertThrows(fn () => app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999),
            trxDate: $end->toDateString(), lines: [
                ['account_id' => StandardChart::find(StandardChart::SALARY_PAYABLE)->id, 'debit' => '10'],
                ['account_id' => $income->id, 'credit' => '10'],
            ]), ValidationException::class);

        app(YearEndService::class)->close($year);
        $this->assertTrue((bool) $year->fresh()->is_closed, '⛔ শেষ মাস তালাবন্ধ বলে বছর বন্ধ হল না');
        $this->assertGreaterThan(0, LedgerEntry::query()->where('source_type', YearEndService::CLOSE_SOURCE)->where('source_id', $year->id)->count());

        app(YearEndService::class)->reopen($year->fresh(), $owner);
        $this->assertFalse((bool) $year->fresh()->is_closed, '⛔ শেষ মাস তালাবন্ধ বলে বছর আবার খোলা গেল না');
        $this->assertGreaterThan(0, LedgerEntry::query()->where('source_type', YearEndService::CLOSE_SOURCE.':reversal')->where('source_id', $year->id)->count());
    }
}
