<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\CashTillService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * টিলের তালিকা, টাকার জিম্মা আর টাকার পিকার — "সব শাখা"-তেও নাগালের ভেতরে (অডিট ⛔১১, ৬ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে এক শাখায় সীমিত মানুষ "সব শাখা" বাছলে সব শাখার টিল দেখতেন, তাদের জের সহ।
 * ⭐ শাখাহীন টিল (মালিকের হাতে নগদ ধরনের) সব শাখায় — মালিকের ৬ অক্টোবরের আদেশ, 610bea77; সেটা অক্ষত।
 *
 * দাবি, শাখা A-তে সীমিত মানুষ, "সব শাখা" বাছা: A-র আর শাখাহীন টিল আসে, B-র টিল আসে না — মডেলে, টিলের পাতায়,
 * জিম্মার পাতায়, আর টাকার পিকারে। সীমাহীন মালিকের কাছে তিনটাই (দাবিটা সত্যিই তাকায়)।
 */
final class TheTillsStayInsideTheBranchesYouMaySeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_branches_shows_the_tills_in_your_reach_and_the_unbranched_ones(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $a = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $b = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($company->id, $a->id);
        $this->actingAs($owner);

        $tills = [];
        foreach (['inA' => $a->id, 'inB' => $b->id, 'none' => null] as $where => $branch) {
            $till = app(CashTillService::class)->create(['code' => 'RT'.strtoupper($where), 'name_en' => 'Reach till '.$where]);
            CashTill::query()->withoutGlobalScopes()->whereKey($till->id)->update(['branch_id' => $branch]);
            $tills[$where] = $till->fresh();
        }

        $clerk = User::factory()->create(['current_company_id' => $company->id, 'current_branch_id' => null, 'is_active' => true]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $a->id,
        ]);
        CompanyContext::forCompany($company->id, function () use ($clerk) {
            foreach (['accounts.till.view', 'accounts.view'] as $key) {
                $clerk->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // ── শাখা-সীমিত মানুষ, "সব শাখা" ──
        $seen = $this->as($company, $clerk);
        $this->assertContains('Reach till inA', $seen['model'], 'নিজের শাখার টিল নেই — দাবি অন্ধ।');
        $this->assertContains('Reach till none', $seen['model'], '⛔ শাখাহীন টিল (মালিকের হাতে নগদ) হারাল।');
        $this->assertNotContains('Reach till inB', $seen['model'], '⛔ "সব শাখা"-তে নাগালের বাইরের শাখার টিল এল।');
        $this->assertNotContains($tills['inB']->account_id, $seen['picker'], '⛔ টাকার পিকারে নাগালের বাইরের শাখার টিল।');
        $this->assertContains($tills['none']->account_id, $seen['picker'], '⛔ টাকার পিকারে শাখাহীন টিল নেই।');
        foreach (['tills' => route('accounts.till.index'), 'custody' => route('accounts.custody')] as $page => $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('Reach till inA', $html, "{$page}: নিজের শাখার টিল নেই — দাবি অন্ধ।");
            $this->assertStringNotContainsString('Reach till inB', $html, "⛔ {$page}: নাগালের বাইরের শাখার টিল দেখা গেল।");
        }

        // ── সীমাহীন মালিক, "সব শাখা" — তিনটাই ──
        $seen = $this->as($company, $owner);
        foreach (['inA', 'inB', 'none'] as $where) {
            $this->assertContains('Reach till '.$where, $seen['model'], "মালিকের \"সব শাখা\"-তে {$where} টিল নেই — দাবি অন্ধ।");
        }
    }

    /** @return array{model: list<string>, picker: list<int>} */
    private function as(Company $company, User $who): array
    {
        $who->forceFill(['current_company_id' => $company->id, 'current_branch_id' => null])->save();
        CompanyContext::set($company->id, null);
        $this->actingAs($who->fresh());
        app(DataScope::class)->forget();

        return [
            'model' => CashTill::query()->pluck('name_en')->all(),
            'picker' => Account::query()->notAnotherBranchsTill()->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ];
    }
}
