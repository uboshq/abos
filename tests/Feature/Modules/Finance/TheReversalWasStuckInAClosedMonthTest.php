<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\InterestAccrual;
use App\Modules\Finance\Services\BankFacilityService;
use App\Modules\Finance\Services\InterestAccrualService;
use App\Modules\Finance\Services\LoanSchedule;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ আগের মাসের সুদ জমার উল্টো দাখিলা বন্ধ মাসে আটকে যেত — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (সারাই ৫)।
 *
 * ⓘ আগস্টের জমা উল্টায় ১ সেপ্টেম্বর। সেপ্টেম্বর বন্ধ হয়ে গেলে (জমা না চালিয়েই) অক্টোবর চালাতে গেলে খাতা ঐ উল্টো দাখিলা
 * নিত না, আর পুরো চালটা থামত — আগস্টের জমা কোনোদিন উল্টাত না, পরের কোনো মাসও বসত না, যতদিন না কেউ পুরনো মাস খোলেন।
 *
 * ⭐ দাবি:
 *   · উল্টানোর দিন বন্ধ মাসে পড়লে উল্টো দাখিলা চালানো মাসের প্রথম দিনে বসে; চালানো মাসের জমাও বসে
 *   · চালানো মাসটাই বন্ধ হলে কিছু লেখার আগেই থামে, কারণসহ — কোনো উল্টো দাখিলাও বসে না
 */
final class TheReversalWasStuckInAClosedMonthTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private BankFacility $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        Carbon::setTestNow('2026-11-15 10:00:00');

        $liability = Account::query()->where('code', '2211')->firstOrFail();
        $this->loan = app(BankFacilityService::class)->open([
            'kind' => BankFacility::TERM, 'bank' => 'Sonali Bank', 'sanctioned_on' => '2026-07-08', 'first_instalment_on' => '2026-08-07',
            'limit_amount' => '1200000', 'interest_rate' => '12', 'instalments' => 12,
            'instalment_amount' => LoanSchedule::instalment('1200000', '12', 12),
            'liability_account_id' => $liability->id,
        ]);

        $vouchers = app(VoucherService::class);
        $vouchers->post($vouchers->create([
            'type' => Voucher::JOURNAL, 'trx_date' => '2026-07-08', 'narration' => 'loan drawn',
            'against_type' => BankFacility::drillSourceType(), 'against_id' => $this->loan->id,
        ], [
            ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'debit' => '1200000', 'credit' => '0'],
            ['account_id' => $liability->id, 'debit' => '0', 'credit' => '1200000'],
        ]));

        app(InterestAccrualService::class)->run(Carbon::parse('2026-08-01'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_reversal_due_in_a_closed_month_lands_on_the_first_day_of_the_month_being_run(): void
    {
        $this->closeMonth(9);

        $done = app(InterestAccrualService::class)->run(Carbon::parse('2026-10-01'));

        $august = InterestAccrual::query()->where('for_month', '2026-08-01')->firstOrFail();
        $this->assertNotNull($august->reversal_voucher_id, '⛔ আগস্টের জমা উল্টাল না');
        $this->assertSame('2026-10-01', Voucher::query()->withoutGlobalScopes()->findOrFail($august->reversal_voucher_id)->trx_date->toDateString(),
            '⛔ উল্টো দাখিলা বন্ধ মাসের বাইরে প্রথম খোলা দিনে নয়');
        $this->assertSame(1, $done['accrued'], '⛔ অক্টোবরের সুদ বসেনি');
        $this->assertSame(1, InterestAccrual::query()->whereNull('reversal_voucher_id')->count(), '⛔ খাতায় ঠিক একটা চলতি জমা নেই');
    }

    public function test_a_closed_month_is_refused_before_anything_is_written(): void
    {
        $this->closeMonth(10);
        $vouchers = Voucher::query()->withoutGlobalScopes()->count();

        try {
            app(InterestAccrualService::class)->run(Carbon::parse('2026-10-01'));
            $this->fail('⛔ বন্ধ মাসে সুদ জমা চলল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('month', $e->errors());
        }

        $this->assertSame($vouchers, Voucher::query()->withoutGlobalScopes()->count(), '⛔ থামার আগে উল্টো দাখিলা বসে গেল');
        $this->assertNull(InterestAccrual::query()->where('for_month', '2026-08-01')->value('reversal_voucher_id'));
    }

    private function closeMonth(int $month): void
    {
        PeriodLock::query()->create([
            'company_id' => $this->company->id, 'year' => 2026, 'month' => $month,
            'reason' => 'রিপোর্ট পাঠানো হয়ে গেছে', 'locked_by' => auth()->id(), 'locked_at' => now(),
        ]);
    }
}
