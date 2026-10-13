<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\Finance\Services\OwnerCapital;
use App\Modules\Finance\Services\RentalAccrualService;
use App\Modules\Finance\Services\RentalContractService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ অর্থের কাগজ নিজের ভাউচারের অবস্থা পড়ত হেডারের শাখার দেয়াল দিয়ে — লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⓘ ভাউচার বসে কাগজের শাখায়; হেডারে অন্য শাখা থাকলে `whereHas('voucher')` / `with('voucher')` সেটা "নেই" দেখত:
 *   · মূলধনের রেজিস্টার অন্য শাখার রসিদে আসা মূলধন বাদ দিত — খোলা মূলধনের "ফাঁক" দেখিয়ে মালিকের নামে ভুয়া সারি বসাত
 *   · ভাড়ার চুক্তির জামানত থেকে কাটা মাস গোনা হত না — জামানত বেশি দেখাত, বন্ধের ফেরত বেশি যেত
 *   · মাসের প্রদেয় ভাড়া "সই বাকি" ধরা হত — অন্য শাখার হেডার থেকে ভাড়া দেওয়াই যেত না
 */
final class APaperWithItsVoucherInAnotherBranchLookedUnpaidTest extends TestCase
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
        app(CashTillService::class)->ensurePrimaryTill();

        Carbon::setTestNow('2026-10-13 10:00:00');
        $this->putMoneyIn($this->cash(), '500000', '2026-07-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_capital_register_sees_a_receipt_in_another_branch(): void
    {
        CompanyContext::set($this->company->id, $this->branch('NTK')->id);
        $person = Person::query()->create(['code' => 'CAP-NTK', 'name_en' => 'Branch Partner', 'name_bn' => 'Branch Partner', 'is_active' => true]);
        app(CapitalService::class)->post(app(CapitalService::class)->record([
            'person_id' => $person->id, 'contributor_type' => CapitalEntry::PARTNER, 'entry_type' => CapitalEntry::CONTRIBUTION,
            'trx_date' => '2026-10-10', 'amount' => '200000',
        ]), $this->cash());

        $this->choose('all');
        $all = app(OwnerCapital::class)->gaps();
        $this->choose('MMS');

        $this->assertSame($all, app(OwnerCapital::class)->gaps(), '⛔ হেডারে অন্য শাখা — রসিদে আসা মূলধন রেজিস্টারে "নেই", ভুয়া ফাঁক');
    }

    public function test_a_rent_contract_reads_its_months_from_another_branchs_header(): void
    {
        CompanyContext::set($this->company->id, $this->branch('NTK')->id);
        $service = app(RentalContractService::class);
        $contract = $service->open([
            'counterparty' => 'Branch Landlord', 'subject' => 'Godown', 'deposit_amount' => '30000', 'monthly_rent' => '10000',
            'monthly_adjustment' => '4000', 'term_months' => 6, 'starts_on' => '2026-08-01', 'rent_day' => 5,
            'money_account_id' => $this->cash()->id,
        ]);
        $service->adjustMonth($contract->fresh(), ['for_month' => '2026-08-01', 'money_account_id' => $this->cash()->id]);
        app(RentalAccrualService::class)->run(Carbon::parse('2026-09-01'));

        $this->choose('MMS');

        $this->assertSame('26000', bcadd($contract->fresh()->depositLeft(), '0', 0), '⛔ অন্য শাখার হেডারে জামানতের কাটা মাস গোনা হল না');
        $service->adjustMonth($contract->fresh(), ['for_month' => '2026-09-01', 'money_account_id' => $this->cash()->id]);
        $this->assertSame('22000', bcadd($contract->fresh()->depositLeft(), '0', 0), '⛔ অন্য শাখার হেডার থেকে সেপ্টেম্বরের ভাড়া দেওয়া গেল না');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

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
