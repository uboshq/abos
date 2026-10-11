<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Services\StockFacts;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ড্যাশবোর্ড এক শাখার মানুষকে গোটা কোম্পানির সংখ্যা দেখাত — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ভুল ছিল ────────────────────────────────────────────────────
 * হিসাবের ড্যাশবোর্ডের ব্যাংকের জের ([[AccountsFacts::sumOf()]]), সম্পদের
 * বইমূল্য (`assetValue()`), সবচেয়ে বেশি বকেয়ার তালিকা (`topDue()`) আর
 * মজুদের মূল্য ([[StockFacts::value()]], "সব শাখা"-তে) শাখা ছাঁকত না।
 * ⚠️ বাকি সংখ্যাগুলো ([[Account::balanceInView()]]) মানত — তাই একই পাতায়
 * কিছু সংখ্যা এক শাখার, কিছু গোটা কোম্পানির।
 *
 * ⓘ বিপজ্জনক মানুষটা এখানে: কেবল শাখা A দেখার অধিকার, পাতায় "সব শাখা"।
 * শাখা B-তে বড় অঙ্ক বসিয়ে দেখা হয় তাঁর সংখ্যা নড়ে কি না; মালিকের নড়ে।
 */
final class TheDashboardShowedABranchPersonTheWholeCompanyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $a;

    private Branch $b;

    private User $owner;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->a = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $this->b = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $this->clerk = User::factory()->create(['current_company_id' => $this->company->id, 'current_branch_id' => null, 'is_active' => true]);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);
        UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => $this->clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $this->a->id,
        ]);
        CompanyContext::forCompany($this->company->id, function (): void {
            foreach (['accounts.view', 'inventory.view', 'inventory.cost.view'] as $key) {
                $this->clerk->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_bank_assets_and_top_due_stay_inside_the_branches_you_may_see(): void
    {
        CompanyContext::set($this->company->id, null);
        $bank = $this->bank();

        $clerkBefore = $this->figuresAs($this->clerk);
        $ownerBefore = $this->figuresAs($this->owner);

        CompanyContext::set($this->company->id, null);
        $customer = Customer::query()->firstOrFail();

        // ── শাখা B-তে: ব্যাংকে ৯,৯৯৯; সম্পদ ৮,৮৮৮; একজন গ্রাহকের বকেয়া ৭,৭৭,৭৭৭ ──
        $this->postIn($this->b, [
            ['account_id' => $bank->id, 'debit' => '9999'],
            ['account_id' => $this->fixedAssetLeaf()->id, 'debit' => '8888'],
            ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'debit' => '777777',
                'party_type' => 'customer', 'party_id' => $customer->id],
            ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'credit' => '796664'],
        ]);

        $clerkAfter = $this->figuresAs($this->clerk);
        $ownerAfter = $this->figuresAs($this->owner);

        $this->assertSame(0, bccomp(bcsub($ownerAfter['bank'], $ownerBefore['bank'], 4), '9999', 4), 'মালিকের ব্যাংকে B-র টাকা আসেনি — দাবি অন্ধ।');
        $this->assertSame(0, bccomp($clerkAfter['bank'], $clerkBefore['bank'], 4), '⛔ A-র মানুষের ব্যাংকের জেরে শাখা B-র টাকা এল।');
        $this->assertSame(0, bccomp($clerkAfter['controllerBank'], $clerkBefore['controllerBank'], 4), '⛔ হিসাবের পাতার ব্যাংকের ঘরে শাখা B-র টাকা এল।');

        $this->assertSame(0, bccomp(bcsub($ownerAfter['assets'], $ownerBefore['assets'], 4), '8888', 4), 'মালিকের সম্পদে B-র অঙ্ক আসেনি — দাবি অন্ধ।');
        $this->assertSame(0, bccomp($clerkAfter['assets'], $clerkBefore['assets'], 4), '⛔ A-র মানুষের সম্পদের বইমূল্যে শাখা B-র সম্পদ এল।');

        $this->assertContains($customer->id, $ownerAfter['topDue'], 'মালিকের বকেয়ার তালিকায় B-র গ্রাহক নেই — দাবি অন্ধ।');
        $this->assertSame($clerkBefore['topDueAmounts'], $clerkAfter['topDueAmounts'], '⛔ A-র মানুষের বকেয়ার তালিকায় শাখা B-র বকেয়া এল।');
    }

    public function test_stock_value_under_all_branches_is_the_value_in_your_reach(): void
    {
        $before = $this->stockValueAs($this->clerk);

        $inventory = StandardChart::find(StandardChart::INVENTORY);
        $capital = StandardChart::find(StandardChart::OWNER_CAPITAL);

        $this->postIn($this->b, [
            ['account_id' => $inventory->id, 'debit' => '5555'],
            ['account_id' => $capital->id, 'credit' => '5555'],
        ]);
        $this->assertSame(0, bccomp($this->stockValueAs($this->clerk), $before, 2), '⛔ A-র মানুষের "সব শাখা"-র মজুদের মূল্যে শাখা B-র মাল এল।');

        $this->postIn($this->a, [
            ['account_id' => $inventory->id, 'debit' => '4444'],
            ['account_id' => $capital->id, 'credit' => '4444'],
        ]);
        $this->assertSame(0, bccomp(bcsub($this->stockValueAs($this->clerk), $before, 2), '4444', 2),
            '⛔ নিজের শাখার মজুদের খাত বাড়লেও সংখ্যাটা নড়েনি — তিনি গোটা কোম্পানির স্তর দেখছেন।');
    }

    /** @param list<array<string, mixed>> $lines */
    private function postIn(Branch $branch, array $lines): void
    {
        CompanyContext::set($this->company->id, $branch->id);
        $this->actingAs($this->owner);
        app(PostingEngine::class)->post(sourceType: 'test:dash-branch', sourceId: random_int(1, 999999), trxDate: now(), branchId: $branch->id, lines: $lines);
    }

    /** @return array{bank: string, controllerBank: string, assets: string, topDue: list<int>, topDueAmounts: array<int, string>} */
    private function figuresAs(User $who): array
    {
        $this->allBranchesAs($who);
        $facts = app(AccountsFacts::class);
        $due = $facts->topDue('customer', StandardChart::RECEIVABLE, 50);

        $controllerBank = (string) $this->get(route('accounts.dashboard'))->assertOk()->viewData('bankBalance');

        return [
            'bank' => $facts->bankBalance(),
            'controllerBank' => $controllerBank,
            'assets' => $facts->assetValue(),
            'topDue' => array_column($due, 'party_id'),
            'topDueAmounts' => array_column($due, 'amount', 'party_id'),
        ];
    }

    private function stockValueAs(User $who): string
    {
        $this->allBranchesAs($who);

        return (string) app(StockFacts::class)->value();
    }

    private function allBranchesAs(User $who): void
    {
        $who->forceFill(['current_branch_id' => null, 'current_company_id' => $this->company->id])->save();
        CompanyContext::set($this->company->id, null);
        $this->actingAs($who->fresh());
        app(DataScope::class)->forget();
    }

    /** ⓘ ডেমোতে ব্যাংক খাত নেই, তাই ব্যাংকের দলে একটা (অন্য দাবিগুলোর একই পথ)। */
    private function bank(): Account
    {
        return Account::query()->where('code', '110299')->first() ?? tap(
            Account::query()->where('code', StandardChart::BANK)->firstOrFail()->replicate(['public_id']),
            function (Account $a): void {
                $a->forceFill(['code' => '110299', 'name_en' => 'Test Bank', 'name_bn' => 'পরীক্ষার ব্যাংক', 'is_group' => false,
                    'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id'), 'money_kind' => Account::BANK])->save();
            });
    }

    private function fixedAssetLeaf(): Account
    {
        return StandardChart::find(StandardChart::FIXED_ASSETS)->selfAndDescendants()
            ->reject(fn (Account $a) => (bool) $a->is_group || $a->code === StandardChart::ACCUMULATED_DEPRECIATION)
            ->sortBy('id')->firstOrFail();
    }
}
