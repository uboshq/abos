<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InstitutionAccount;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Services\BankFacilityService;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\DepositService;
use App\Modules\Finance\Services\InstitutionPosition;
use App\Modules\Finance\Services\InsuranceService;
use App\Modules\Finance\Services\LoanSchedule;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⭐ এক প্রতিষ্ঠানের এক পাতা — অর্থ-মডিউলের পরিকল্পনা ৭, ৬ অক্টোবর ২০২৬ ([[InstitutionPosition]])।
 *
 * ⭐ দাবি (সমন্বয়কের উত্তর প্র৪): নিট = ব্যাংক হিসাবের জের + চালু আমানত − ঋণের বাকি; বন্ধ আমানত গোনা হয় না; ঋণের বাকি =
 * ঋণের পাতার "তোলা"; গ্যারান্টি আর বীমা আলাদা লাইনে, নিটে নয়; অন্য প্রতিষ্ঠানের কিছু আসে না; পাতা আর তালিকা একই নিট বলে।
 */
final class AnInstitutionHasOnePageTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(DepositKindInstaller::class)->install();
    }

    public function test_net_is_balances_and_open_deposits_less_loans_and_the_rest_stand_aside(): void
    {
        $bank = $this->institution('Dutch Bangla Probe');
        $other = $this->institution('Other Probe');

        // ⓘ ব্যাংক হিসাব — জের ৫০,০০০
        $account = $this->bankAccount('DBBL-01');
        InstitutionAccount::query()->create(['company_id' => CompanyContext::id(), 'institution_id' => $bank->id, 'account_id' => $account->id]);
        $this->putMoneyIn($account, '50000', now()->subDays(5)->toDateString());

        // ⓘ চালু আমানত ১,০০,০০০ আর একটা বন্ধ ৩০,০০০ (গোনা হয় না); অন্য প্রতিষ্ঠানের আমানত আসে না
        $this->deposit($bank, '100000');
        $this->deposit($bank, '30000')->forceFill(['status' => Deposit::CLOSED])->save();
        $this->deposit($other, '70000');

        // ⓘ মেয়াদি ঋণ ৪,০০,০০০ তোলা; গ্যারান্টি ২,০০,০০০ — নিটে নয়
        $loan = $this->facility($bank, BankFacility::TERM, '400000');
        $this->draw($loan, '400000');
        $this->facility($bank, BankFacility::GUARANTEE, '200000');

        // ⓘ বীমা — কেবল তথ্য
        app(InsuranceService::class)->create([
            'institution_id' => $bank->id, 'policy_no' => 'POL-INST', 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '900000', 'premium' => '4500',
            'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonths(11)->toDateString(),
        ]);

        $p = app(InstitutionPosition::class)->of($bank);
        $owed = app(BankFacilityService::class)->standing(collect([$loan]))[$loan->id]['used'];

        $this->assertSame(0, bccomp($p['accounts'], '50000', 4));
        $this->assertSame(0, bccomp($p['deposits'], '100000', 4), '⛔ বন্ধ বা অন্য প্রতিষ্ঠানের আমানত গোনা হলো।');
        $this->assertSame(0, bccomp($p['loans'], $owed, 4), '⛔ ঋণের বাকি ঋণের পাতার সংখ্যা নয়।');
        $this->assertSame(0, bccomp($p['loans'], '400000', 4));
        $this->assertSame(0, bccomp($p['guarantees'], '200000', 4));
        $this->assertSame(0, bccomp($p['net'], '-250000', 4), '⛔ নিট = ১,৫০,০০০ − ৪,০০,০০০ নয় — গ্যারান্টি বা বীমা ঢুকল?');
        $this->assertSame(0, bccomp($p['sum_insured'], '900000', 4));
        $this->assertSame(0, bccomp($p['premiums_due'], '4500', 4));

        $page = (string) $this->get(route('finance.institution.show', $bank))->assertOk()->getContent();
        $this->assertStringContainsString('data-institution-position', $page);
        $this->assertStringContainsString(Money::format('-250000'), $page, 'পাতায় নিট নেই।');

        $list = (string) $this->get(route('finance.institution.index'))->assertOk()->getContent();
        $this->assertStringContainsString(Money::format('-250000'), $list, '⛔ তালিকা আর পাতা দুই নিট বলে।');

        // ⓘ পট্টির নিট = গোটা ছাঁকনির প্রতিটা প্রতিষ্ঠানের নিটের যোগ, কেবল এই পাতার নয় — নামের ক্রমে আগে বসা ৫০টা
        // ফাঁকা প্রতিষ্ঠান প্রথম পাতা ভরে দেয়, DBBL দ্বিতীয় পাতায় যায়, তবু পট্টি তার নিট গোনে
        foreach (range(1, 50) as $n) {
            $this->institution(sprintf('Aa Filler %02d', $n));
        }
        $expected = Institution::query()->get()
            ->reduce(fn (string $s, Institution $i) => bcadd($s, app(InstitutionPosition::class)->of($i)['net'], 4), '0');
        $this->assertSame(-1, bccomp($expected, '0', 4), 'দাবির ভিত্তি নেই — যোগে DBBL-এর ঋণাত্মক নিট নেই।');

        $first = $this->get(route('finance.institution.index'))->assertOk();
        $this->assertArrayNotHasKey($bank->id, $first->viewData('positions'), 'দাবির ভিত্তি নেই — DBBL প্রথম পাতাতেই।');
        $first->assertViewHas('grand', fn (array $grand) => bccomp($grand['net'], $expected, 4) === 0);
        $first->assertSee(Money::format($expected), false);
        $this->get(route('finance.institution.index', ['kind' => Institution::INSURANCE]))->assertOk()
            ->assertViewHas('grand', fn (array $grand) => bccomp($grand['net'], '0', 4) === 0);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────────────

    private function institution(string $name): Institution
    {
        return Institution::query()->create(['company_id' => CompanyContext::id(), 'kind' => Institution::BANK, 'name_en' => $name]);
    }

    private function bankAccount(string $code): Account
    {
        $till = Account::query()->where('money_kind', Account::CASH)->postable()->firstOrFail();
        $bank = $till->replicate(['public_id']);
        $bank->forceFill(['code' => $code, 'name_en' => $code, 'name_bn' => $code, 'money_kind' => Account::BANK,
            'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id')])->save();

        return $bank;
    }

    private function deposit(Institution $institution, string $amount): Deposit
    {
        $till = app(CashTillService::class)->ensurePrimaryTill();
        $this->putMoneyIn($till->account, $amount, now()->toDateString());

        return app(DepositService::class)->open([
            'kind_id' => DepositKind::query()->where('issuer', 'bank')->firstOrFail()->id,
            'institution_id' => $institution->id, 'institution' => $institution->name(),
            'held_by' => Deposit::BUSINESS, 'principal' => $amount, 'return_word' => 'interest',
            'opened_on' => now()->toDateString(), 'matures_on' => now()->addYear()->toDateString(),
            'funded_from_account_id' => $till->account_id,
        ]);
    }

    private function facility(Institution $institution, string $kind, string $limit): BankFacility
    {
        return app(BankFacilityService::class)->open([
            'kind' => $kind, 'institution_id' => $institution->id, 'bank' => $institution->name(),
            'sanctioned_on' => now()->subDays(20)->toDateString(), 'limit_amount' => $limit, 'interest_rate' => '10',
            ...($kind === BankFacility::TERM ? [
                'instalments' => 12, 'instalment_amount' => LoanSchedule::instalment($limit, '10', 12),
                'liability_account_id' => Account::query()->where('code', '2211')->value('id'),
            ] : ['margin_percent' => '10']),
        ]);
    }

    private function draw(BankFacility $facility, string $amount): void
    {
        $vouchers = app(VoucherService::class);
        $vouchers->post($vouchers->create([
            'type' => Voucher::JOURNAL, 'trx_date' => now()->subDays(10)->toDateString(), 'narration' => 'draw',
            'against_type' => BankFacility::drillSourceType(), 'against_id' => $facility->id,
        ], [
            ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'debit' => $amount, 'credit' => '0'],
            ['account_id' => $facility->liability_account_id, 'debit' => '0', 'credit' => $amount],
        ]));
    }
}
