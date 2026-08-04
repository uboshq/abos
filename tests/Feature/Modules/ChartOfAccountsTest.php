<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * হিসাবের ছক — Accounts মডিউলের ভিত্তি।
 *
 * এই টেবিলটা ভুল হলে বাকি সব ভুল: প্রতিটা বিক্রয়, ক্রয়, বেতন ও ঋণ
 * শেষমেশ দুইটা খাতের মধ্যে টাকা সরায়। তাই এখানকার পরীক্ষাগুলো নিয়ম
 * নিয়ে বেশি, স্ক্রিন নিয়ে কম।
 */
class ChartOfAccountsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    private function service(): AccountService
    {
        return app(AccountService::class);
    }

    private function make(array $overrides = []): Account
    {
        return $this->service()->create([
            'code' => '9001',
            'name_en' => 'Test Account',
            'type' => Account::ASSET,
            ...$overrides,
        ]);
    }

    // ── প্রমিত ছক ──────────────────────────────────────────────────────

    public function test_the_standard_chart_installs_a_usable_five_type_structure(): void
    {
        $created = app(StandardChart::class)->install();

        $this->assertGreaterThan(40, $created);

        foreach (Account::TYPES as $type) {
            $this->assertTrue(
                Account::query()->where('type', $type)->exists(),
                "প্রমিত ছকে {$type} ধরনের কোনো খাত নেই।",
            );
        }

        // প্রতিটা মডিউল যে খাতগুলো কোড ধরে খোঁজে
        foreach (StandardChart::SYSTEM_CODES as $code) {
            $account = StandardChart::find($code);

            $this->assertNotNull($account, "সিস্টেমের খাত {$code} নেই।");
            $this->assertTrue($account->is_system, "{$code} সিস্টেমের বলে চিহ্নিত নয়।");
        }
    }

    public function test_installing_twice_changes_nothing(): void
    {
        $first = app(StandardChart::class)->install();

        $this->assertSame(0, app(StandardChart::class)->install());
        $this->assertSame($first, Account::query()->count());
    }

    public function test_every_account_in_the_standard_chart_has_both_names(): void
    {
        app(StandardChart::class)->install();

        // নিয়ম ৯ — একটা খাতের বাংলা নাম না থাকলে সেটা বাংলা রিপোর্টেও
        // ইংরেজিতেই থাকত, আর ছাপা কাগজে দুই ভাষা মিশে যেত
        $missing = Account::query()
            ->where(fn ($q) => $q->whereNull('name_bn')->orWhere('name_bn', ''))
            ->pluck('code')
            ->all();

        $this->assertSame([], $missing);
    }

    public function test_a_group_never_holds_a_child_of_a_different_type(): void
    {
        app(StandardChart::class)->install();

        foreach (Account::query()->whereNotNull('parent_id')->with('parent')->get() as $account) {
            $this->assertSame(
                $account->parent->type,
                $account->type,
                "{$account->code} তার বাবার চেয়ে অন্য ধরনের।",
            );
        }
    }

    // ── গাছের নিয়ম ─────────────────────────────────────────────────────

    public function test_a_child_takes_its_type_from_its_parent(): void
    {
        $parent = $this->make(['code' => '9000', 'type' => Account::EXPENSE, 'is_group' => true]);

        // ইচ্ছাকৃতভাবে ভুল ধরন পাঠানো — বাবার ধরনই টিকবে
        $child = $this->make(['code' => '9001', 'type' => Account::INCOME, 'parent_id' => $parent->id]);

        $this->assertSame(Account::EXPENSE, $child->type);
    }

    public function test_an_account_that_takes_entries_cannot_hold_children(): void
    {
        $leaf = $this->make(['code' => '9000', 'is_group' => false]);

        $this->expectException(ValidationException::class);

        $this->make(['code' => '9001', 'parent_id' => $leaf->id]);
    }

    public function test_an_account_cannot_be_moved_under_itself(): void
    {
        $top = $this->make(['code' => '9000', 'is_group' => true]);
        $mid = $this->make(['code' => '9010', 'is_group' => true, 'parent_id' => $top->id]);

        $this->expectException(ValidationException::class);

        // top-কে তার নিজের সন্তানের নিচে — গাছটা চক্র হয়ে যেত
        $this->service()->update($top, ['parent_id' => $mid->id]);
    }

    public function test_changing_a_group_type_carries_down_to_everything_under_it(): void
    {
        $top = $this->make(['code' => '9000', 'type' => Account::ASSET, 'is_group' => true]);
        $mid = $this->make(['code' => '9010', 'is_group' => true, 'parent_id' => $top->id]);
        $leaf = $this->make(['code' => '9011', 'parent_id' => $mid->id]);

        $this->service()->update($top, ['type' => Account::EXPENSE]);

        // একটা সম্পদের নিচে খরচ ঝুলে থাকতে পারে না
        $this->assertSame(Account::EXPENSE, $mid->fresh()->type);
        $this->assertSame(Account::EXPENSE, $leaf->fresh()->type);
    }

    // ── লেজার থাকলে যা যা আটকে যায় ────────────────────────────────────

    public function test_an_account_with_entries_cannot_change_type(): void
    {
        $account = $this->make();
        $this->entry($account, debit: '100');

        $this->expectException(ValidationException::class);

        $this->service()->update($account, ['type' => Account::EXPENSE]);
    }

    public function test_an_account_with_entries_cannot_become_a_group(): void
    {
        $account = $this->make();
        $this->entry($account, debit: '100');

        $this->expectException(ValidationException::class);

        $this->service()->update($account, ['is_group' => true]);
    }

    public function test_a_group_with_children_cannot_stop_being_a_group(): void
    {
        $parent = $this->make(['code' => '9000', 'is_group' => true]);
        $this->make(['code' => '9001', 'parent_id' => $parent->id]);

        $this->expectException(ValidationException::class);

        $this->service()->update($parent, ['is_group' => false]);
    }

    // ── সিস্টেমের খাত ──────────────────────────────────────────────────

    public function test_a_system_account_cannot_be_renamed_by_code_or_deactivated(): void
    {
        app(StandardChart::class)->install();

        $sales = StandardChart::find(StandardChart::SALES);

        // নাম বদলানো যায় — ব্যবসা "বিক্রয়"-কে "রাজস্ব" বলতেই পারে
        $this->service()->update($sales, ['name_bn' => 'রাজস্ব']);
        $this->assertSame('রাজস্ব', $sales->fresh()->name_bn);

        // কোড নয় — অন্য মডিউল কোড ধরেই খোঁজে
        try {
            $this->service()->update($sales, ['code' => '9999']);
            $this->fail('সিস্টেমের খাতের কোড বদলানো গেল।');
        } catch (ValidationException) {
            $this->assertSame(StandardChart::SALES, $sales->fresh()->code);
        }

        $this->expectException(ValidationException::class);
        $this->service()->deactivate($sales);
    }

    public function test_nobody_may_deactivate_a_system_account_through_the_screen(): void
    {
        app(StandardChart::class)->install();

        $sales = StandardChart::find(StandardChart::SALES);

        $this->actingAs($this->user)
            ->delete(route('accounts.coa.destroy', $sales))
            ->assertForbidden();

        $this->assertTrue($sales->fresh()->is_active);
    }

    // ── নিষ্ক্রিয় করা (নিয়ম ৫) ─────────────────────────────────────────

    public function test_deactivating_a_group_takes_everything_under_it_with_it(): void
    {
        $top = $this->make(['code' => '9000', 'is_group' => true]);
        $mid = $this->make(['code' => '9010', 'is_group' => true, 'parent_id' => $top->id]);
        $leaf = $this->make(['code' => '9011', 'parent_id' => $mid->id]);

        $this->service()->deactivate($top);

        // একটা সক্রিয় সন্তান নিষ্ক্রিয় বাবার নিচে ঝুললে ড্রপডাউনে দেখা
        // যেত কিন্তু গাছে খুঁজে পাওয়া যেত না
        $this->assertFalse($mid->fresh()->is_active);
        $this->assertFalse($leaf->fresh()->is_active);

        // রেকর্ডগুলো থেকে যায় — নিয়ম ৫
        $this->assertNotNull(Account::query()->find($leaf->id));
    }

    public function test_activating_a_child_activates_its_parents_too(): void
    {
        $top = $this->make(['code' => '9000', 'is_group' => true]);
        $leaf = $this->make(['code' => '9001', 'parent_id' => $top->id]);

        $this->service()->deactivate($top);
        $this->service()->activate($leaf);

        $this->assertTrue($top->fresh()->is_active);
        $this->assertTrue($leaf->fresh()->is_active);
    }

    // ── ব্যালেন্স ───────────────────────────────────────────────────────

    public function test_a_debit_nature_account_shows_a_positive_balance_when_debited(): void
    {
        $cash = $this->make(['code' => '9101', 'type' => Account::ASSET, 'is_cash' => true]);

        $this->entry($cash, debit: '5000');
        $this->entry($cash, credit: '2000');

        $this->assertSame('3000.0000', $cash->balanceOn());
    }

    /**
     * ক্রেডিট প্রকৃতির খাতে চিহ্নটা উল্টে দেওয়া হয়।
     *
     * নাহলে প্রতিটা রিপোর্টে আলাদা করে চিহ্ন সামলাতে হত, আর কোথাও না
     * কোথাও বাদ পড়ত — তখন ব্যালেন্স শিটে দায় ঋণাত্মক দেখাত।
     */
    public function test_a_credit_nature_account_shows_a_positive_balance_when_credited(): void
    {
        $payable = $this->make(['code' => '9201', 'type' => Account::LIABILITY]);

        $this->assertSame(Account::CREDIT, $payable->nature);

        $this->entry($payable, credit: '8000');
        $this->entry($payable, debit: '3000');

        $this->assertSame('5000.0000', $payable->balanceOn());
    }

    public function test_a_group_balance_is_the_sum_of_what_sits_under_it(): void
    {
        $group = $this->make(['code' => '9000', 'is_group' => true]);
        $a = $this->make(['code' => '9001', 'parent_id' => $group->id]);
        $b = $this->make(['code' => '9002', 'parent_id' => $group->id]);

        $this->entry($a, debit: '1200');
        $this->entry($b, debit: '800');

        // গ্রুপে নিজের কোনো এন্ট্রি নেই, তবু সংখ্যাটা থাকতে হবে —
        // নাহলে ব্যালেন্স শিটে প্রতিটা মাথা শূন্য দেখাত
        $this->assertSame('2000.0000', $group->fresh()->balanceOn());
    }

    public function test_an_opening_balance_counts_only_from_its_own_date(): void
    {
        $account = $this->make([
            'code' => '9301',
            'opening_balance' => '1000',
            'opening_date' => '2026-08-01',
        ]);

        // ব্যবসা শুরুর আগের একটা রিপোর্টে খোলা ব্যালেন্স দেখা যাবে না
        $this->assertSame('0.0000', $account->balanceOn('2026-07-31'));
        $this->assertSame('1000.0000', $account->balanceOn('2026-08-31'));
    }

    // ── কোড ও অনন্যতা ──────────────────────────────────────────────────

    public function test_two_accounts_cannot_share_a_code(): void
    {
        $this->make(['code' => '9001']);

        $this->expectException(ValidationException::class);

        $this->make(['code' => '9001', 'name_en' => 'Another']);
    }

    public function test_the_same_code_may_exist_in_another_company(): void
    {
        $this->make(['code' => '9001']);

        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($other->id, $other->defaultBranch()?->id);

        $this->assertSame('9001', $this->make(['code' => '9001'])->code);
    }

    public function test_one_company_never_sees_another_companys_chart(): void
    {
        app(StandardChart::class)->install();
        $mine = Account::query()->count();

        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($other->id, $other->defaultBranch()?->id);

        $this->assertGreaterThan(0, $mine);
        $this->assertSame(0, Account::query()->count());
    }

    // ── স্ক্রিন ও অনুমতি ───────────────────────────────────────────────

    public function test_the_empty_chart_screen_offers_to_install_the_standard_one(): void
    {
        $this->actingAs($this->user)
            ->get(route('accounts.coa.index'))
            ->assertOk()
            ->assertSee(__('accounts::action.install_chart'), false);
    }

    public function test_installing_from_the_screen_works_end_to_end(): void
    {
        $this->actingAs($this->user)
            ->post(route('accounts.coa.install'))
            ->assertRedirect(route('accounts.coa.index'));

        $this->assertGreaterThan(40, Account::query()->count());

        $this->actingAs($this->user)
            ->get(route('accounts.coa.index'))
            ->assertOk()
            // গাছের মাথাগুলো
            ->assertSee('1000')
            ->assertSee('5000');
    }

    public function test_creating_through_the_screen_works_end_to_end(): void
    {
        app(StandardChart::class)->install();

        $parent = StandardChart::find('5200');

        $this->actingAs($this->user)
            ->post(route('accounts.coa.store'), [
                'code' => '5250',
                'name_en' => 'Warehouse Rent',
                'name_bn' => 'গুদাম ভাড়া',
                'parent_id' => $parent->id,
            ])
            ->assertRedirect();

        $created = Account::query()->where('code', '5250')->firstOrFail();

        $this->assertSame(Account::EXPENSE, $created->type);
        $this->assertSame($parent->id, $created->parent_id);
        $this->assertFalse($created->is_system);
    }

    public function test_a_user_with_only_view_permission_cannot_change_the_chart(): void
    {
        app(StandardChart::class)->install();

        $reader = User::factory()->create();
        $reader->companies()->attach($this->company, ['is_active' => true]);
        $reader->forceFill(['current_company_id' => $this->company->id])->save();
        $reader->givePermissionTo(Permission::findOrCreate('accounts.coa.view', 'web'));

        $account = StandardChart::find('5202');

        $this->actingAs($reader)->get(route('accounts.coa.index'))->assertOk();
        $this->actingAs($reader)->get(route('accounts.coa.show', $account))->assertOk();

        $this->actingAs($reader)->get(route('accounts.coa.create'))->assertForbidden();
        $this->actingAs($reader)->get(route('accounts.coa.edit', $account))->assertForbidden();
        $this->actingAs($reader)->post(route('accounts.coa.install'))->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('accounts.coa.index'))->assertRedirect(route('login'));
    }

    public function test_the_account_screen_lists_the_entries_behind_its_balance(): void
    {
        $account = $this->make(['code' => '9401']);

        $this->entry($account, debit: '2500', documentNo: 'INV-2026-2027-0044');

        $this->actingAs($this->user)
            ->get(route('accounts.coa.show', $account))
            ->assertOk()
            ->assertSee('INV-2026-2027-0044')
            ->assertSee('2,500.00');
    }

    private function entry(Account $account, string $debit = '0', string $credit = '0', ?string $documentNo = null): void
    {
        LedgerEntry::create([
            'company_id' => $account->company_id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => $this->company->currentFinancialYear()?->id,
            'account_id' => $account->id,
            'trx_date' => '2026-08-10',
            'debit' => $debit,
            'credit' => $credit,
            'source_type' => 'journal_voucher',
            'source_id' => 1,
            'document_no' => $documentNo ?? 'JV-TEST',
            'narration' => 'test',
        ]);
    }
}
