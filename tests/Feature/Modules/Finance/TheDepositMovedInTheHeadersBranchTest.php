<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositAccrual;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\DepositMovement;
use App\Modules\Finance\Services\DepositAccrualService;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\DepositService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ জমার টাকা হেডারের শাখায় নড়ত, আর জমা বাতিলে অন্য শাখার ভাউচার আর মাসের মুনাফা খাতায় থেকে যেত — লাইন-ধরে নিরীক্ষা,
 * ১৩ অক্টোবর ২০২৬ ([[DepositService]])।
 *
 * ⭐ দাবি:
 *   · মুনাফা তোলা আর ভাঙার ভাউচার জমার শাখায়, হেডারে অন্য শাখা থাকলেও
 *   · হেডারে অন্য শাখা রেখে জমা বাতিল — খোলার ভাউচারও বাতিল, ১১৬০-এ জমার কিছু থাকে না
 *   · জমা বাতিলে মাসের অর্জিত মুনাফাও বাতিল — ১১৬৫/৪৩১০-এ কিছু থাকে না, মাসের সারি নেই
 */
final class TheDepositMovedInTheHeadersBranchTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->branch('MMS')->id);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        app(DepositKindInstaller::class)->install();
        /*
         * ⓘ বাক্সটা কোম্পানির, কোনো শাখার নয় — ১৯ অক্টোবর ২০২৬ (ec, RCV-0143): জমা এখন যে বাক্স থেকে টাকা গেল তার শাখায় বসে
         * ([[FinanceSignature::branchFor()]])। ময়মনসিংহে বাঁধা বাক্স থেকে "নেত্রকোনার জমা" আর বানানোই যায় না; শাখাহীন বাক্সে জমা
         * আগের মতো উপরের শাখায়, তাই দৃশ্যটা অক্ষত।
         */
        $till = app(CashTillService::class)->ensurePrimaryTill();
        CashTill::query()->withoutGlobalScopes()->whereKey($till->id)->update(['branch_id' => null]);

        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->putMoneyIn($this->cash(), '1000000', '2026-07-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_payout_and_close_post_in_the_deposits_branch(): void
    {
        $deposit = $this->openIn('NTK');
        $this->choose('MMS');

        $payout = app(DepositService::class)->payout($deposit->fresh(), [
            'amount' => '1000', 'moved_on' => '2026-11-10', 'money_account_id' => $this->cash()->id,
        ]);
        $close = app(DepositService::class)->close($deposit->fresh(), [
            'amount' => '100000', 'moved_on' => '2026-11-14', 'money_account_id' => $this->cash()->id,
        ]);

        foreach ([$payout, $close] as $movement) {
            $this->assertSame((int) $this->branch('NTK')->id, (int) Voucher::acrossBranches()->findOrFail($movement->voucher_id)->branch_id,
                "⛔ জমার {$movement->kind} হেডারের শাখায় বসল — ১১৬০ এক শাখায় +, আরেক শাখায় −");
        }
    }

    public function test_cancelling_from_another_branchs_header_takes_every_voucher_and_the_months_profit_back(): void
    {
        $deposit = $this->openIn('NTK');
        $this->choose('all');
        app(DepositAccrualService::class)->run(Carbon::parse('2026-08-01'));
        $this->assertSame(1, DepositAccrual::query()->where('deposit_id', $deposit->id)->count(), 'দৃশ্যটাই বানানো যায়নি — মাসের মুনাফা বসেনি');

        $this->choose('MMS');
        app(DepositService::class)->cancel($deposit->fresh(), 'ভুল জমা');

        $opened = DepositMovement::query()->where('deposit_id', $deposit->id)->where('kind', DepositMovement::OPENED)->sole();
        $this->assertTrue(Voucher::acrossBranches()->findOrFail($opened->voucher_id)->isCancelled(),
            '⛔ অন্য শাখার হেডারে জমা বাতিল — খোলার ভাউচার খাতায় রয়ে গেল');
        $ids = [
            ...DepositMovement::query()->where('deposit_id', $deposit->id)->pluck('voucher_id')->all(),
            ...Voucher::acrossBranches()->where('against_type', Deposit::drillSourceType())->where('against_id', $deposit->id)->pluck('id')->all(),
        ];
        $this->assertSame('0', $this->net(StandardChart::DEPOSITS_AND_INVESTMENTS, $ids), '⛔ বাতিল জমার টাকা ১১৬০-এ রয়ে গেল');
        $this->assertSame(0, DepositAccrual::query()->where('deposit_id', $deposit->id)->count(), '⛔ বাতিল জমার মাসের মুনাফার সারি রয়ে গেল');
        $this->assertSame('0', $this->net(StandardChart::INTEREST_INCOME, $ids), '⛔ যে জমা কখনো ছিল না তার মুনাফা আয়ে রয়ে গেল');
    }

    /** ⛔ জমার পাতার উপরের মোট সব শাখার গুনত, অথচ নিচের তালিকা হেডারের শাখার (লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬) */
    public function test_the_summary_above_the_list_counts_the_header_branch_only(): void
    {
        $this->openIn('NTK');
        $this->choose('MMS');

        $listed = Deposit::query()->inViewedBranch()->open()->get();
        $standing = app(DepositService::class)->standing();

        $this->assertSame($listed->count(), $standing['count'], '⛔ মোটে অন্য শাখার জমা গোনা হল');
        $this->assertSame(0, bccomp(
            $listed->filter(fn (Deposit $d) => $d->isBusinessAsset())->reduce(fn (string $s, Deposit $d) => bcadd($s, (string) $d->principal, 4), '0'),
            $standing['business'], 4), '⛔ ব্যবসার জমার মোট তালিকার সাথে মেলে না');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function openIn(string $branch): Deposit
    {
        CompanyContext::set($this->company->id, $this->branch($branch)->id);

        $deposit = app(DepositService::class)->open([
            'kind_id' => DepositKind::query()->where('code', 'FDR')->firstOrFail()->id, 'institution' => 'সোনালী ব্যাংক',
            'held_by' => Deposit::BUSINESS, 'principal' => '100000', 'profit_rate' => '10', 'return_word' => 'interest',
            'opened_on' => '2026-07-01', 'matures_on' => '2027-07-01', 'funded_from_account_id' => $this->cash()->id,
        ]);
        $this->assertSame((int) $this->branch($branch)->id, (int) $deposit->branch_id, 'দৃশ্যটাই বানানো যায়নি — জমার শাখা');

        return $deposit;
    }

    /** @param  list<int|string|null>  $voucherIds */
    private function net(string $code, array $voucherIds): string
    {
        $n = (string) LedgerEntry::query()->where('company_id', $this->company->id)->where('account_id', StandardChart::find($code)->id)
            ->whereIn('source_type', ['payment_voucher', 'payment_voucher:reversal', 'journal_voucher', 'journal_voucher:reversal'])
            ->whereIn('source_id', array_filter($voucherIds))
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as n')->value('n');

        return bccomp($n, '0', 4) === 0 ? '0' : $n;
    }

    private function choose(string $code): void
    {
        $id = $code === 'all' ? 'all' : (string) $this->branch($code)->id;
        $this->actingAs($this->owner->fresh())->post(route('branch.switch'), ['branch_id' => $id])->assertRedirect();

        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id ?? $this->branch('MMS')->id);
        app(DataScope::class)->forget();
        $this->app->forgetScopedInstances();
        $this->actingAs($this->owner);
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->where('money_kind', Account::CASH)->orderBy('code')->firstOrFail();
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
