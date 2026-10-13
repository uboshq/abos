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
use App\Modules\Finance\Services\WithdrawalService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ মূলধনের পাতা হেডারের শাখার মূলধন আর গোটা কোম্পানির তোলা মেলাত — "অতিরিক্ত তোলা" ভুয়া দেখাত (লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬;
 * [[CapitalService::positions()]])।
 *
 * ⭐ দাবি: হেডারে এক শাখা — দেওয়া আর তোলা দুইটাই সেই শাখার; গোটা কোম্পানির হিসাবে (লাভ বণ্টন) দুইটাই গোটা কোম্পানির।
 */
final class TheCapitalPageMixedOneBranchWithTheWholeCompanyTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    public function test_one_branch_shows_its_own_capital_and_its_own_withdrawals(): void
    {
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        CompanyContext::set($this->company->id, $this->branch('MMS')->id);
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $person = Person::query()->create(['code' => 'CAP-2B', 'name_en' => 'Two Branch Partner', 'name_bn' => 'Two Branch Partner', 'is_active' => true]);

        foreach (['MMS', 'NTK'] as $code) {
            CompanyContext::set($this->company->id, $this->branch($code)->id);
            $this->putMoneyIn($this->cash(), '100000', now()->subDays(3)->toDateString());
            app(CapitalService::class)->post(app(CapitalService::class)->record([
                'person_id' => $person->id, 'contributor_type' => CapitalEntry::PARTNER, 'entry_type' => CapitalEntry::CONTRIBUTION,
                'trx_date' => now()->subDays(2)->toDateString(), 'amount' => '100000',
            ]), $this->cash());
        }

        $taken = app(WithdrawalService::class)->request([
            'person_id' => $person->id, 'amount' => '150000', 'trx_date' => now()->toDateString(),
        ]);
        app(WithdrawalService::class)->post($taken, $this->cash());

        $this->choose('MMS');
        $row = collect(app(CapitalService::class)->positions())->firstWhere('person_id', (int) $person->id);

        $this->assertSame(0, bccomp((string) $row['contributed'], '100000', 4), 'দৃশ্যটাই বানানো যায়নি — এক শাখার মূলধন');
        $this->assertSame(0, bccomp((string) $row['withdrawn'], '0', 4), '⛔ নেত্রকোনার তোলা ময়মনসিংহের পাতায় — "অতিরিক্ত তোলা" ভুয়া');

        $whole = collect(app(CapitalService::class)->positions(wholeCompany: true))->firstWhere('person_id', (int) $person->id);
        $this->assertSame(0, bccomp((string) $whole['net'], '50000', 4), '⛔ গোটা কোম্পানির নিট মূলধন ভুল');
    }

    private function choose(string $code): void
    {
        $this->actingAs($this->owner->fresh())->post(route('branch.switch'), ['branch_id' => (string) $this->branch($code)->id])->assertRedirect();

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
