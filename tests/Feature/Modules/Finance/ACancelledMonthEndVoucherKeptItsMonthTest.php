<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositAccrual;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\DepositMovement;
use App\Modules\Finance\Models\RentalAccrual;
use App\Modules\Finance\Services\DepositAccrualService;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\RentalAccrualService;
use App\Modules\Finance\Services\RentalContractService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * মাসশেষের ভাউচার Accounts থেকে বাতিল — মাসটা "হয়ে গেছে" থেকে যেত (Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬;
 * [[UndoThePaperOfACancelledVoucher]])।
 *
 * ⭐ দাবিগুলো:
 *   · জমার মুনাফার আগস্টের ভাউচার বাতিল — তার উল্টো দাখিলাও বাতিল, সারিটা মোছা, আগস্ট আবার চালানো যায়।
 *   · ভাড়ার মাসের প্রদেয় বাতিল — সারিটা মোছা, আর মাসটা আর বাতিল প্রদেয় "মেটায়" না।
 */
final class ACancelledMonthEndVoucherKeptItsMonthTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(DepositKindInstaller::class)->install();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_cancelled_deposit_profit_voucher_takes_its_reversal_and_frees_the_month(): void
    {
        $this->fdr();
        app(DepositAccrualService::class)->run(Carbon::parse('2026-08-01'));
        app(DepositAccrualService::class)->run(Carbon::parse('2026-09-01'));
        $august = DepositAccrual::query()->where('for_month', '2026-08-01')->sole();
        $this->assertNotNull($august->reversal_voucher_id, 'দৃশ্যটাই বানানো যায়নি — আগস্ট উল্টায়নি');

        app(VoucherService::class)->cancel(Voucher::query()->withoutGlobalScopes()->findOrFail($august->voucher_id), 'ভুল হার');

        $this->assertSame(DocumentStatus::CANCELLED, Voucher::query()->withoutGlobalScopes()->find($august->reversal_voucher_id)?->status,
            '⛔ আসলটা বাতিল, তার উল্টোটা খাতায় রয়ে গেল — সেপ্টেম্বরের আয় ততটা কম');
        $this->assertNull(DepositAccrual::query()->find($august->id), '⛔ বাতিল ভাউচারের মাস "হয়ে গেছে" থেকে গেল');
        $this->assertSame(1, app(DepositAccrualService::class)->run(Carbon::parse('2026-08-01'))['accrued'], '⛔ আগস্ট আবার চালানো গেল না');
    }

    public function test_a_cancelled_rent_payable_no_longer_settles_its_month(): void
    {
        $contract = app(RentalContractService::class)->open([
            'counterparty' => 'Cancel Landlord', 'subject' => 'Cancel place', 'deposit_amount' => '0', 'monthly_rent' => '50000',
            'monthly_adjustment' => '0', 'term_months' => 36, 'starts_on' => '2026-08-01', 'rent_day' => 5,
        ]);
        app(RentalAccrualService::class)->run(Carbon::parse('2026-09-01'));
        $september = RentalAccrual::query()->where('rental_contract_id', $contract->id)->sole();
        $this->assertSame(0, bccomp((string) RentalAccrualService::settles($contract, Carbon::parse('2026-09-01')), '50000', 4), 'দৃশ্যটাই বানানো যায়নি');

        app(VoucherService::class)->cancel(Voucher::query()->withoutGlobalScopes()->findOrFail($september->voucher_id), 'ভুল চুক্তি');

        $this->assertNull(RentalAccrual::query()->find($september->id), '⛔ বাতিল প্রদেয়ের মাস "হয়ে গেছে" থেকে গেল');
        $this->assertNull(RentalAccrualService::settles($contract->fresh(), Carbon::parse('2026-09-01')),
            '⛔ বাতিল প্রদেয় এখনো মাসটা মেটায় — শোধ করলে Dr ২১৪১ বসত, খরচ কোনোদিন নয়');
        $this->assertSame(1, app(RentalAccrualService::class)->run(Carbon::parse('2026-09-01'))['accrued'], '⛔ সেপ্টেম্বর আবার চালানো গেল না');
    }

    private function fdr(): Deposit
    {
        $deposit = Deposit::query()->create([
            'company_id' => $this->company->id, 'branch_id' => Branch::query()->value('id'),
            'document_no' => 'FDR-CANCEL', 'kind_id' => DepositKind::query()->where('code', 'FDR')->value('id'),
            'institution' => 'সোনালী ব্যাংক', 'held_by' => Deposit::BUSINESS, 'principal' => '73000',
            'profit_rate' => '10', 'tax_rate' => '10', 'return_word' => 'interest',
            'opened_on' => '2026-07-01', 'matures_on' => '2027-07-01',
            'account_id' => StandardChart::find(StandardChart::DEPOSITS_AND_INVESTMENTS)->id, 'status' => Deposit::ACTIVE,
        ]);
        DepositMovement::query()->create(['company_id' => $this->company->id, 'deposit_id' => $deposit->id,
            'kind' => DepositMovement::OPENED, 'amount' => '73000', 'moved_on' => '2026-07-01']);

        return $deposit;
    }
}
