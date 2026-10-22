<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Dashboard\AccountsWidgets;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\HandLoanMovement;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\MasterData\Models\Person;
use App\Modules\Supplier\Reports\PrincipalCommissionReport;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনের হোমের নতুন ঘর — মালিক, ৬ অক্টোবর ২০২৬ (ফোনের ছবিতে আঁকা): আজকের ইনফ্লো, Payable, "হাতে ও ব্যাংকে মোট"
 * (নগদ · MFS · ব্যাংক · পথে — ওয়েবের হোমের ডান-উপরের ঘরটাই), আর প্রিন্সিপালের কমিশন ([[DashboardTodayController]])।
 *
 * ⭐ দাবি:
 *   · প্রতিটা ঘর নিজের চাবিতে আসে, চাবি ছাড়া ঘরটাই নেই (চুক্তির নিয়ম ক)
 *   · "হাতে ও ব্যাংকে মোট" ওয়েবের ঘরের হুবহু সংখ্যা — মোট = নগদ + MFS + ব্যাংক, পথে আলাদা
 *   · ইনফ্লো = আজ টাকার খাতে যা ঢুকল; নিজের মধ্যে স্থানান্তর (নগদ → ব্যাংক) ইনফ্লো নয়
 *   · Payable = দায়ের গোটা দল + হাতধারে আমাদের দেনা — নতুন দেনা দুই জায়গাতেই ঠিক ততটা বাড়ায়
 *   · কমিশন = রিপোর্টের পুরো ফল, নিজে গোনা নয়
 */
final class ThePhoneHomeCarriesTheOwnersNewBoxesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(StandardChart::class)->install();

        $this->user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $this->user->companies()->attach($this->company->id, ['is_active' => true]);
    }

    public function test_each_new_box_follows_its_own_key(): void
    {
        $this->borrow('1200');

        foreach ([['money', 'accounts.till.view'], ['inflow', 'accounts.view'], ['principals', 'supplier.report']] as [$field, $key]) {
            $this->today()->assertOk()->assertJsonMissingPath($field);
            $this->grant($key);
            $this->assertNotNull($this->today()->json($field), "⛔ {$key} দেওয়ার পরেও {$field} আসেনি।");
        }

        $this->assertNotNull($this->today()->json('payable'), 'Payable accounts.view-এ আসে।');
        $this->assertSame('0.0000', $this->today()->json('payable.handLoans'), '⛔ হাতধারের চাবি ছাড়াই হাতধারের দেনা গোনা হলো।');
    }

    public function test_the_money_box_is_the_webs_box_to_the_paisa(): void
    {
        $this->grant('accounts.till.view');
        $till = Account::query()->findOrFail(app(CashTillService::class)->ensurePrimaryTill()->account_id);
        $this->moneyIn($till, '7250.5', 'test:cash-in', 1);

        // ⓘ MFS-এ টাকা আর পথে একটা হস্তান্তর — দুইটাই শূন্য থাকলে ভুল যোগফলও মিলে যেত
        $mfs = $till->replicate(['public_id']);
        $mfs->forceFill(['code' => 'TST-MFS', 'name_en' => 'Probe bKash', 'name_bn' => 'Probe bKash',
            'parent_id' => Account::query()->where('code', StandardChart::MOBILE_MONEY)->value('id'), 'money_kind' => Account::MFS])->save();
        $this->moneyIn($mfs, '830', 'test:mfs-in', 5);
        \App\Modules\Accounts\Models\MoneyTransfer::query()->create([
            'company_id' => CompanyContext::id(), 'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => 'TRF-TST1',
            'trx_date' => Carbon::today()->toDateString(), 'amount' => '640', 'status' => \App\Core\Support\DocumentStatus::DRAFT,
            'from_till_id' => app(CashTillService::class)->ensurePrimaryTill()->id,
            'financial_year_id' => \App\Models\FinancialYear::forDate(Carbon::today())?->id,
        ]);

        $money = $this->today()->json('money');

        $this->actAsTheUser();
        $this->assertSame(bcadd(AccountsWidgets::cashInHand(), '0', 4), $money['cash']);
        $this->assertSame(bcadd(AccountsWidgets::mfsBalance(), '0', 4), $money['mfs']);
        $this->assertSame(bcadd(AccountsWidgets::bankBalance(), '0', 4), $money['bank']);
        $this->assertSame(bcadd(AccountsWidgets::inTransit(), '0', 4), $money['transit']);
        $this->assertSame(0, bccomp($money['mfs'], '830', 4), 'MFS-এর টাকা ঘরে আসেনি।');
        $this->assertSame(0, bccomp($money['transit'], '640', 4), 'পথের টাকা ঘরে আসেনি।');
        $this->assertSame(bcadd(bcadd($money['cash'], $money['mfs'], 4), $money['bank'], 4), $money['amount'],
            '⛔ মোট নগদ + MFS + ব্যাংক নয় — ওয়েবের ঘর আর ফোন আলাদা কথা বলবে।');
    }

    public function test_inflow_is_todays_money_in_and_a_move_between_our_own_accounts_is_not(): void
    {
        $this->grant('accounts.view');
        $before = $this->today()->json('inflow.amount');

        $till = Account::query()->findOrFail(app(CashTillService::class)->ensurePrimaryTill()->account_id);
        $this->moneyIn($till, '4000', 'test:money-in', 2);
        $this->assertSame(bcadd($before, '4000', 4), $this->today()->json('inflow.amount'), '⛔ আজ ঢোকা টাকা ইনফ্লোতে নেই।');

        // ⓘ নগদ থেকে ব্যাংকে জমা — টাকা আসাও নয়, যাওয়াও নয়
        $bank = Account::query()->where('code', StandardChart::BANK)->firstOrFail();
        $bankLeaf = $till->replicate(['public_id']);
        $bankLeaf->forceFill(['code' => 'TST-BNK', 'name_en' => 'Probe bank', 'name_bn' => 'Probe bank', 'parent_id' => $bank->id,
            'money_kind' => Account::BANK])->save();
        app(PostingEngine::class)->post('test:deposit', 3, Carbon::today()->toDateString(), [
            ['account_id' => $bankLeaf->id, 'debit' => '1500', 'credit' => '0'],
            ['account_id' => $till->id, 'debit' => '0', 'credit' => '1500'],
        ], 'TST-DEP');

        $this->assertSame(bcadd($before, '4000', 4), $this->today()->json('inflow.amount'),
            '⛔ নিজের মধ্যে স্থানান্তর ইনফ্লো হয়ে গোনা হলো — একই টাকা দুবার।');
    }

    public function test_payable_is_every_liability_plus_what_we_owe_on_hand_loans(): void
    {
        $this->grant('accounts.view');
        $this->grant('finance.hand_loan.view');
        $before = $this->today()->json('payable');

        // ⓘ সরবরাহকারীর নতুন দেনা ৯০০
        $payable = Account::query()->where('code', StandardChart::PAYABLE)->firstOrFail();
        $stock = Account::query()->where('code', StandardChart::INVENTORY)->firstOrFail();
        app(PostingEngine::class)->post('test:bill', 4, Carbon::today()->toDateString(), [
            ['account_id' => $stock->id, 'debit' => '900', 'credit' => '0'],
            ['account_id' => $payable->id, 'debit' => '0', 'credit' => '900'],
        ], 'TST-BILL');

        // ⓘ আর ব্যাংক ঋণ ৩০০০ — দীর্ঘমেয়াদি দায়ও "যাকে দিতে হবে"
        $loan = Account::query()->where('code', '2210')->firstOrFail();
        $till0 = Account::query()->findOrFail(app(CashTillService::class)->ensurePrimaryTill()->account_id);
        app(PostingEngine::class)->post('test:loan', 6, Carbon::today()->toDateString(), [
            ['account_id' => $till0->id, 'debit' => '3000', 'credit' => '0'],
            ['account_id' => $loan->id, 'debit' => '0', 'credit' => '3000'],
        ], 'TST-LOAN');

        // ⓘ হাতধারে নেওয়া ২০০০ — আমাদের দেনা
        $this->borrow('2000');

        $after = $this->today()->json('payable');

        $this->assertSame(bcadd($before['books'], '3900', 4), $after['books'], '⛔ নতুন দেনা (সরবরাহকারী + ব্যাংক ঋণ) দায়ের ঘরে আসেনি।');
        $this->assertSame(bcadd($before['handLoans'], '2000', 4), $after['handLoans'], '⛔ হাতধারে আমাদের দেনা আসেনি।');
        $this->assertSame(bcadd($after['books'], $after['handLoans'], 4), $after['amount']);

        $this->actAsTheUser();
        $this->assertSame(bcadd(app(AccountsFacts::class)->liabilities(), '0', 4), $after['books']);
    }

    public function test_the_commission_box_is_the_reports_own_rows(): void
    {
        $this->grant('supplier.report');
        // ⓘ একজন প্রিন্সিপাল — ডেমোতে কেউ নেই, আর খালি তালিকায় "সব সারি" দাবি কিছুই মাপে না
        \App\Modules\Supplier\Models\Supplier::query()->orderBy('id')->firstOrFail()->forceFill([
            'principal_branch_id' => $this->company->defaultBranch()?->id, 'commission_basis' => 'margin',
            'commission_rate' => '3.850', 'cycle_start_day' => 2, 'cycle_close_day' => 1,
        ])->save();
        $rows = $this->today()->json('principals');
        $this->assertNotEmpty($rows, 'প্রিন্সিপাল বসানোর পরেও কমিশনের ঘর খালি।');

        $this->actAsTheUser();
        $report = app(ReportEngine::class)->run(PrincipalCommissionReport::KEY, [], 1, 50)->rows;

        $this->assertCount(count($report), $rows);
        foreach ($report as $i => $r) {
            $this->assertSame(bcadd((string) $r['balance'], '0', 4), $rows[$i]['balance']);
            $this->assertSame(bcadd((string) $r['commission'], '0', 4), $rows[$i]['commission']);
            $this->assertSame((string) $r['supplier_name'], $rows[$i]['name']);
            // ⓘ ওয়েবের বাক্সের "সময়কাল: … – আজ পর্যন্ত" ফোনেও একই কথা (মালিক, ৬ অক্টোবর ২০২৬)
            $this->assertSame([(string) $r['period_from'], (string) $r['period_to']], [$rows[$i]['periodFrom'], $rows[$i]['periodTo']]);
            $this->assertSame(
                \App\Modules\Supplier\Reports\PrincipalCommission::soFar((string) $r['period_from'], (string) $r['period_to']),
                $rows[$i]['periodSoFar'],
                '⛔ ফোনের সময়কাল ওয়েবের বাক্সের সাথে মেলে না।',
            );
        }
    }

    /**
     * ⭐ MFS-এ চাপলে প্রতিটা খাত আর তার জের — মালিক, ২০ অক্টোবর ২০২৬: "অ্যাপের হোমে MFS-এ কত আছে দেখায় না"।
     * দুটো খাত, পয়সাসহ অঙ্ক — যোগফল ঠিক `mfs`, আর `mfs` ঠিক ওয়েবের।
     */
    public function test_the_mfs_part_lists_each_mfs_account_and_they_add_up_to_the_mfs_figure(): void
    {
        $this->grant('accounts.till.view');
        $till = Account::query()->findOrFail(app(CashTillService::class)->ensurePrimaryTill()->account_id);
        $parent = Account::query()->where('code', StandardChart::MOBILE_MONEY)->value('id');

        $bkash = $till->replicate(['public_id']);
        $bkash->forceFill(['code' => 'TST-MFS1', 'name_en' => 'Probe bKash', 'name_bn' => 'প্রোব বিকাশ', 'parent_id' => $parent,
            'money_kind' => Account::MFS])->save();
        $nagad = $till->replicate(['public_id']);
        $nagad->forceFill(['code' => 'TST-MFS2', 'name_en' => 'Probe Nagad', 'name_bn' => 'প্রোব নগদ', 'parent_id' => $parent,
            'money_kind' => Account::MFS])->save();
        $this->moneyIn($bkash, '1234.56', 'test:mfs-1', 11);
        $this->moneyIn($nagad, '78.9', 'test:mfs-2', 12);

        $money = $this->today()->json('money');
        $rows = collect($money['mfsAccounts'])->keyBy('name');

        $this->assertSame(0, bccomp($rows['প্রোব বিকাশ']['balance'] ?? '-1', '1234.56', 4), '⛔ বিকাশের জের ভুল বা নেই।');
        $this->assertSame(0, bccomp($rows['প্রোব নগদ']['balance'] ?? '-1', '78.9', 4), '⛔ নগদ-খাতের জের ভুল বা নেই।');
        $sum = array_reduce($money['mfsAccounts'], fn (string $c, array $r) => bcadd($c, $r['balance'], 4), '0');
        $this->assertSame($money['mfs'], $sum, '⛔ খাতগুলোর যোগফল হোমের MFS-এর সাথে মেলে না।');

        $this->actAsTheUser();
        $this->assertSame(bcadd(AccountsWidgets::mfsBalance(), '0', 4), $money['mfs'], '⛔ ফোনের MFS ওয়েবের হিরোর MFS নয়।');
    }

    /** ⭐ আজ যত টাকা গেল — ইনফ্লোর একই নিয়মে: টাকার খাত থেকে বাইরে যা গেল; নিজের মধ্যে স্থানান্তর নয় (২০ অক্টোবর ২০২৬) */
    public function test_outflow_is_todays_money_out_and_a_move_between_our_own_accounts_is_not(): void
    {
        $this->today()->assertOk()->assertJsonMissingPath('outflow');
        $this->grant('accounts.view');
        $before = $this->today()->json('outflow.amount');
        $this->assertNotNull($before, '⛔ accounts.view দেওয়ার পরেও outflow আসেনি।');

        $till = Account::query()->findOrFail(app(CashTillService::class)->ensurePrimaryTill()->account_id);
        $this->moneyIn($till, '5000', 'test:float', 21);

        // ⓘ টাকা বাইরে গেল ১২০০.২৫ (মালিকের তোলা)
        $capital = Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail();
        app(PostingEngine::class)->post('test:drawing', 22, Carbon::today()->toDateString(), [
            ['account_id' => $capital->id, 'debit' => '1200.25', 'credit' => '0'],
            ['account_id' => $till->id, 'debit' => '0', 'credit' => '1200.25'],
        ], 'TST-OUT');
        $this->assertSame(bcadd($before, '1200.25', 4), $this->today()->json('outflow.amount'), '⛔ আজ যাওয়া টাকা "আজ গেছে"-তে নেই।');

        // ⓘ নগদ থেকে ব্যাংকে জমা — যাওয়া নয়
        $bankLeaf = $till->replicate(['public_id']);
        $bankLeaf->forceFill(['code' => 'TST-BNK2', 'name_en' => 'Probe bank 2', 'name_bn' => 'Probe bank 2',
            'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id'), 'money_kind' => Account::BANK])->save();
        app(PostingEngine::class)->post('test:deposit2', 23, Carbon::today()->toDateString(), [
            ['account_id' => $bankLeaf->id, 'debit' => '700', 'credit' => '0'],
            ['account_id' => $till->id, 'debit' => '0', 'credit' => '700'],
        ], 'TST-DEP2');
        $this->assertSame(bcadd($before, '1200.25', 4), $this->today()->json('outflow.amount'),
            '⛔ নিজের মধ্যে স্থানান্তর "আজ গেছে" হয়ে গোনা হলো।');

        $this->actAsTheUser();
        $today = Carbon::today()->toDateString();
        $this->assertSame(bcadd(app(AccountsFacts::class)->moneyFlowBetween($today, $today)['out'], '0', 4), $this->today()->json('outflow.amount'));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────────────

    /** হাতধারে নেওয়া — আমাদের দেনা, মালিকের হাতে আসল পথে */
    private function borrow(string $amount): void
    {
        $till = (int) app(CashTillService::class)->ensurePrimaryTill()->account_id;
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $person = Person::query()->create(['company_id' => CompanyContext::id(), 'code' => 'P-'.substr(md5($amount.microtime()), 0, 6),
            'name_en' => 'Lender '.$amount, 'is_active' => true]);
        app(HandLoanService::class)->move(app(HandLoanService::class)->open(['person_id' => $person->id]), [
            'direction' => HandLoanMovement::IN, 'amount' => $amount, 'money_account_id' => $till, 'moved_on' => Carbon::today()->toDateString(),
        ]);
    }

    private function moneyIn(Account $money, string $amount, string $source, int $id): void
    {
        $capital = Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail();
        app(PostingEngine::class)->post($source, $id, Carbon::today()->toDateString(), [
            ['account_id' => $money->id, 'debit' => $amount, 'credit' => '0'],
            ['account_id' => $capital->id, 'debit' => '0', 'credit' => $amount],
        ], 'TST-'.$id);
    }

    private function actAsTheUser(): void
    {
        $this->actingAs($this->user->fresh());
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    private function today(): TestResponse
    {
        // ⓘ খাতের জের এক অনুরোধের জন্য মনে রাখা হয় ([[LedgerBalances]], scoped) — পরীক্ষায় অনুরোধগুলো একই অ্যাপে চলে
        $this->app->forgetScopedInstances();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->user->fresh(), [AuthController::APP]);

        return $this->getJson('/api/v1/dashboard/today');
    }

    private function grant(string $key): void
    {
        CompanyContext::forCompany($this->company->id,
            fn () => $this->user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
