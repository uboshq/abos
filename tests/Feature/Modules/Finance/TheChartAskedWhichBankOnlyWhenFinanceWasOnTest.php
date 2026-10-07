<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InstitutionAccount;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * খাতের ফর্ম "কোন ব্যাংক" জিজ্ঞেস করে — কেবল অর্থ চালু থাকলে।
 *
 * ⚠️ হিসাব অর্থকে চেনে না: ফর্ম কেবল [[AccountFormOpened]] ঘোষণা করে,
 * আর জমায় `ext[...]` অংশ [[AccountSaved]]-এ বয়ে দেয়। এই ফাইল দেখে
 * ঘরটা সত্যিই আসে, জমায় জোড়া বসে, আর অর্থ বন্ধ করলে ঘরও নেই, জোড়াও নেই।
 */
final class TheChartAskedWhichBankOnlyWhenFinanceWasOnTest extends TestCase
{
    use RefreshDatabase;

    private Institution $ibbl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->ibbl = Institution::query()->create([
            'company_id' => $company->id, 'kind' => Institution::BANK,
            'name_en' => 'Islami Bank Bangladesh PLC', 'short_code' => 'IBBL',
        ]);
    }

    public function test_a_new_bank_account_can_name_its_bank_on_the_chart_form(): void
    {
        $this->get(route('accounts.coa.create'))->assertOk()
            ->assertSee('name="ext[institution_id]"', escape: false)
            ->assertSee('Islami Bank Bangladesh PLC (IBBL)');

        $this->post(route('accounts.coa.store'), $this->form('IBBL CD 0099'))->assertSessionHasNoErrors();

        $account = Account::query()->where('name_en', 'IBBL CD 0099')->firstOrFail();

        $this->assertSame($this->ibbl->id,
            (int) InstitutionAccount::query()->where('account_id', $account->id)->value('institution_id'),
            'খাতের ফর্মে বাছা ব্যাংক জোড়া লাগেনি।');

        $this->get(route('accounts.coa.edit', $account))->assertOk()
            ->assertSee('name="ext[institution_id]"', escape: false);

        $this->put(route('accounts.coa.update', $account), [
            ...$this->form('IBBL CD 0099'), 'ext' => ['institution_id' => ''],
        ])->assertSessionHasNoErrors();

        $this->assertFalse(InstitutionAccount::query()->where('account_id', $account->id)->exists(),
            'ঘর ফাঁকা করে জমা দিলেও জোড়া থেকে গেছে।');
    }

    public function test_with_finance_off_the_form_has_no_such_field_and_links_nothing(): void
    {
        app(SettingsService::class)->set('finance.enabled', false);

        $this->get(route('accounts.coa.create'))->assertOk()
            ->assertDontSee('name="ext[institution_id]"', escape: false);

        $this->post(route('accounts.coa.store'), $this->form('IBBL STD 0100'))->assertSessionHasNoErrors();

        $this->assertSame(0, InstitutionAccount::query()->count(), 'অর্থ বন্ধ, তবু জোড়া বসেছে।');
    }

    /** @return array<string, mixed> */
    private function form(string $name): array
    {
        return [
            'code' => '1102-'.fake()->unique()->numberBetween(10, 99),
            'name_en' => $name,
            'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id'),
            'type' => Account::ASSET,
            'is_active' => 1,
            'ext' => ['institution_id' => $this->ibbl->id],
        ];
    }
}
