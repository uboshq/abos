<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InstitutionAccount;
use App\Modules\Finance\Services\InstitutionLinkProposer;
use App\Modules\Finance\Services\InstitutionService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ব্যাংকের খাত জানত না সে কোন ব্যাংকের।
 *
 * ⭐ প্রতিষ্ঠানের পাতায় "হিসাবের খাত" — জোড়া খাত আর আজকের জের, খতিয়ান
 * থেকে। জোড়াটা অর্থের টেবিলে, ছকে নয় — হিসাব মডিউল অর্থকে চেনে না।
 *
 * ⚠️ আর নাম দেখে প্রস্তাবের কমান্ড: কেবল দেখায়; `--apply` ছাড়া কিছু বসায়
 * না, আর দুইটা ব্যাংক মিললে কোনোটাই বসায় না।
 */
final class TheBankAccountDidNotKnowItsBankTest extends TestCase
{
    use RefreshDatabase;

    private InstitutionService $institutions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->institutions = app(InstitutionService::class);
    }

    /**
     * ⭐ "খাত জোড়ো" — পাতায় খাতটা আর তার জের ওঠে; খুলে দিলে খাত মোছে না।
     */
    public function test_an_account_is_linked_from_the_institution_page_and_shows_its_balance(): void
    {
        $ibbl = $this->bank('Islami Bank Bangladesh PLC', 'IBBL');
        $cd = $this->account('IBBL Motijheel CD', StandardChart::BANK);

        $page = $this->get(route('finance.institution.show', $ibbl))->assertOk();
        $page->assertSee(route('finance.institution.link', $ibbl), escape: false);
        $page->assertSee('IBBL Motijheel CD');

        $this->post(route('finance.institution.link', $ibbl), ['account_id' => $cd->id])
            ->assertRedirect(route('finance.institution.show', $ibbl));

        $this->assertSame($ibbl->id, (int) InstitutionAccount::query()->where('account_id', $cd->id)->value('institution_id'));

        $this->get(route('finance.institution.show', $ibbl))->assertOk()
            ->assertSee(route('finance.institution.unlink', [$ibbl, $cd->id]), escape: false)
            ->assertSee(__('finance::institution.balance_today'));

        $this->delete(route('finance.institution.unlink', [$ibbl, $cd->id]))->assertRedirect();

        $this->assertFalse(InstitutionAccount::query()->where('account_id', $cd->id)->exists());
        $this->assertNotNull(Account::query()->find($cd->id), 'খাত খুলে দিতে গিয়ে খাতটাই মুছে গেছে।');
    }

    /**
     * ⛔ নগদ বা MFS খাত ব্যাংকে নয়; আর এক খাত দুই ব্যাংকে নয়।
     */
    public function test_a_wrong_kind_or_an_already_linked_account_is_refused(): void
    {
        $ibbl = $this->bank('Islami Bank Bangladesh PLC', 'IBBL');
        $dbbl = $this->bank('Dutch-Bangla Bank PLC', 'DBBL');
        $bkash = $this->account('bKash Merchant', StandardChart::MOBILE_MONEY);
        $cd = $this->account('IBBL CD 0012', StandardChart::BANK);
        $cash = Account::query()->where('money_kind', Account::CASH)->where('is_group', false)->firstOrFail();

        foreach ([$bkash, $cash] as $wrong) {
            try {
                $this->institutions->link($ibbl, $wrong->id);
                $this->fail("{$wrong->name_en} ব্যাংকের সাথে জোড়া লেগে গেছে।");
            } catch (ValidationException) {
                // ঠিক আছে
            }
        }

        $this->institutions->link($ibbl, $cd->id);

        $this->expectException(ValidationException::class);
        $this->institutions->link($dbbl, $cd->id);
    }

    /**
     * ⭐ প্রস্তাব — নাম, সংক্ষেপ, আর চেনা সংক্ষেপে মেলে; দুইটা মিললে কোনোটা নয়।
     */
    public function test_the_proposer_matches_by_name_code_and_alias_and_refuses_to_guess(): void
    {
        $ibbl = $this->bank('Islami Bank Bangladesh PLC');              // সংক্ষেপ নেই — চেনা সংক্ষেপে মিলবে
        $ebl = $this->bank('Eastern Bank PLC', 'EBL');
        $this->bank('Bank Asia PLC');
        $bkash = Institution::query()->create([
            'company_id' => CompanyContext::id(), 'kind' => Institution::MFS, 'name_en' => 'bKash Limited',
        ]);

        $a1 = $this->account('IBBL Motijheel CD', StandardChart::BANK);
        $a2 = $this->account('EBL STD 1102', StandardChart::BANK);
        $a3 = $this->account('Bank Asia PLC Gulshan', StandardChart::BANK);
        $a4 = $this->account('bKash Agent', StandardChart::MOBILE_MONEY);
        $a5 = $this->account('Some Unknown Bank', StandardChart::BANK);
        $a6 = $this->account('EBL and IBBL sweep', StandardChart::BANK);

        $by = app(InstitutionLinkProposer::class)->propose()->keyBy(fn ($p) => $p['account']->id);

        $this->assertSame($ibbl->id, $by[$a1->id]['institution']?->id, 'IBBL চেনা সংক্ষেপে মেলেনি।');
        $this->assertSame($ebl->id, $by[$a2->id]['institution']?->id, 'সংক্ষেপ EBL মেলেনি।');
        $this->assertSame('name', $by[$a3->id]['why'], 'পুরো নাম মেলেনি।');
        $this->assertSame($bkash->id, $by[$a4->id]['institution']?->id, 'bKash খাত bKash-এ যায়নি।');
        $this->assertSame('none', $by[$a5->id]['why']);
        $this->assertSame('ambiguous', $by[$a6->id]['why'], 'দুইটা ব্যাংক মেলা খাতকে একটায় বসানোর প্রস্তাব দিয়েছে।');
        $this->assertNull($by[$a6->id]['institution']);
    }

    /**
     * ⛔ কমান্ড `--apply` ছাড়া কিছু বসায় না; দিলে কেবল নিশ্চিতগুলো।
     */
    public function test_the_command_only_shows_until_told_to_apply(): void
    {
        $this->bank('Eastern Bank PLC', 'EBL');
        $this->bank('Islami Bank Bangladesh PLC', 'IBBL');
        $sure = $this->account('EBL STD 1102', StandardChart::BANK);
        $unsure = $this->account('EBL and IBBL sweep', StandardChart::BANK);

        Artisan::call('finance:propose-institution-links', ['--company' => 'TDEPOT']);
        $this->assertSame(0, InstitutionAccount::query()->count(), 'দেখানোর কমান্ড নিজে থেকে জোড়া বসিয়েছে।');

        Artisan::call('finance:propose-institution-links', ['--company' => 'TDEPOT', '--apply' => true]);

        $this->assertTrue(InstitutionAccount::query()->where('account_id', $sure->id)->exists());
        $this->assertFalse(InstitutionAccount::query()->where('account_id', $unsure->id)->exists(),
            'দ্ব্যর্থক খাতে --apply জোড়া বসিয়েছে।');
    }

    private function bank(string $name, ?string $code = null): Institution
    {
        return Institution::query()->create([
            'company_id' => CompanyContext::id(),
            'kind' => Institution::BANK,
            'name_en' => $name,
            'short_code' => $code,
        ]);
    }

    private function account(string $name, string $parentCode): Account
    {
        return app(AccountService::class)->create([
            'code' => $parentCode.'-'.fake()->unique()->numberBetween(10, 99),
            'name_en' => $name,
            'parent_id' => Account::query()->where('code', $parentCode)->value('id'),
        ]);
    }
}
