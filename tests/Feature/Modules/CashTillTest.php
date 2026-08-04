<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * নগদ কাউন্টার — কার কাছে কত টাকা।
 *
 * DMS-এ "নগদ" ছিল একটাই খাত, আর সেই কারণেই দিনশেষে মোট মিললেও কার
 * হাতে কত তা কেউ বলতে পারত না। এখানে প্রতিটা কাউন্টারের নিজের খাত,
 * তাই লেজার নিজেই উত্তরটা রাখে।
 */
class CashTillTest extends TestCase
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

        app(StandardChart::class)->install();
    }

    private function service(): CashTillService
    {
        return app(CashTillService::class);
    }

    private function make(array $overrides = []): CashTill
    {
        return $this->service()->create([
            'code' => 'CASH01',
            'name_en' => 'Front Counter',
            'name_bn' => 'সামনের কাউন্টার',
            ...$overrides,
        ]);
    }

    // ── খাতের সাথে সম্পর্ক ─────────────────────────────────────────────

    public function test_a_till_always_gets_its_own_cash_account_under_cash_in_hand(): void
    {
        $till = $this->make();

        $this->assertNotNull($till->account);
        $this->assertTrue($till->account->is_cash);
        $this->assertFalse($till->account->is_group);

        // "১১০১ হাতে নগদ"-এর নিচে, নাহলে ক্যাশ বই কাউন্টারগুলো খুঁজে পেত না
        $this->assertSame(
            StandardChart::find(StandardChart::CASH_IN_HAND)->id,
            $till->account->parent_id,
        );
    }

    public function test_the_account_code_carries_the_till_code_so_both_screens_agree(): void
    {
        $till = $this->make(['code' => 'RIDER-A']);

        // হিসাবরক্ষক ছকে গিয়ে যেন বুঝতে পারে কোন খাত কোন কাউন্টারের
        $this->assertSame('1101-RIDER-A', $till->account->code);
    }

    public function test_a_till_cannot_be_created_before_the_chart_exists(): void
    {
        Account::query()->forceDelete();

        $this->expectException(ValidationException::class);

        $this->make();
    }

    public function test_renaming_a_till_renames_its_account_too(): void
    {
        $till = $this->make();

        $this->service()->update($till, ['name_en' => 'Back Counter', 'name_bn' => 'পেছনের কাউন্টার']);

        // দুই পর্দায় একই জিনিসের দুই নাম থাকলে মেলাতে গিয়ে ভুল হয়
        $this->assertSame('Back Counter', $till->fresh()->account->name_en);
        $this->assertSame('পেছনের কাউন্টার', $till->fresh()->account->name_bn);
    }

    // ── ব্যালেন্স ───────────────────────────────────────────────────────

    public function test_the_balance_comes_from_the_ledger_not_a_stored_column(): void
    {
        $till = $this->make(['opening_balance' => '5000', 'opening_date' => '2026-08-01']);

        $this->entry($till, debit: '3000');
        $this->entry($till, credit: '1200');

        $this->assertSame('6800.0000', $till->fresh()->balance());

        // সংরক্ষিত কোনো ব্যালেন্স কলাম নেই — থাকলে সেটা একদিন লেজারের
        // সাথে অমিল হত আর কোনটা সত্যি তা বলার উপায় থাকত না
        $this->assertArrayNotHasKey('balance', $till->getAttributes());
    }

    public function test_a_zero_limit_means_no_limit_not_a_closed_counter(): void
    {
        $till = $this->make(['limit_amount' => 0]);

        $this->entry($till, debit: '999999');

        $this->assertFalse($till->fresh()->isOverLimit());
    }

    public function test_going_over_the_limit_is_flagged_but_never_blocked(): void
    {
        $till = $this->make(['limit_amount' => '20000']);

        $this->entry($till, debit: '25000');

        // টাকাটা ঢুকেছে — আটকানো হয়নি
        $this->assertSame('25000.0000', $till->fresh()->balance());
        $this->assertTrue($till->fresh()->isOverLimit());
    }

    // ── প্রধান কাউন্টার ────────────────────────────────────────────────

    public function test_only_one_till_can_be_primary_at_a_time(): void
    {
        $first = $this->make(['code' => 'CASH01', 'is_primary' => true]);
        $second = $this->make(['code' => 'CASH02', 'is_primary' => true]);

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
        $this->assertSame(1, CashTill::query()->primary()->count());
    }

    public function test_the_first_till_is_created_once_and_only_once(): void
    {
        $first = $this->service()->ensurePrimaryTill();
        $again = $this->service()->ensurePrimaryTill();

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, CashTill::query()->count());
        $this->assertTrue($first->is_primary);
    }

    // ── বন্ধ করা (নিয়ম ৫) ──────────────────────────────────────────────

    public function test_a_till_holding_money_cannot_be_closed(): void
    {
        $till = $this->make();
        $this->entry($till, debit: '4000');

        try {
            $this->service()->deactivate($till->fresh());
            $this->fail('টাকা হাতে থাকা অবস্থায় কাউন্টার বন্ধ করা গেল।');
        } catch (ValidationException $e) {
            // টাকাটা তখন কারও হিসাবেই থাকত না
            $this->assertTrue($till->fresh()->is_active);
        }
    }

    public function test_the_primary_till_cannot_be_closed(): void
    {
        $till = $this->service()->ensurePrimaryTill();

        $this->expectException(ValidationException::class);

        $this->service()->deactivate($till);
    }

    public function test_closing_an_empty_till_deactivates_it_and_its_account(): void
    {
        $this->service()->ensurePrimaryTill();
        $till = $this->make();

        $this->service()->deactivate($till);

        $this->assertFalse($till->fresh()->is_active);

        // খাতটাও — নাহলে ভাউচারের ড্রপডাউনে বন্ধ কাউন্টারের খাত দেখা
        // যেত আর কেউ ওখানে টাকা বসিয়ে দিত
        $this->assertFalse($till->fresh()->account->is_active);

        // রেকর্ড থেকে যায় — নিয়ম ৫
        $this->assertNotNull(CashTill::query()->find($till->id));
    }

    // ── টেন্যান্ট ও কোড ────────────────────────────────────────────────

    public function test_two_tills_cannot_share_a_code(): void
    {
        $this->make(['code' => 'CASH01']);

        $this->expectException(ValidationException::class);

        $this->make(['code' => 'CASH01', 'name_en' => 'Another']);
    }

    public function test_one_company_never_sees_another_companys_tills(): void
    {
        $this->make();

        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($other->id, $other->defaultBranch()?->id);

        $this->assertSame(0, CashTill::query()->count());
    }

    // ── স্ক্রিন ও অনুমতি ───────────────────────────────────────────────

    public function test_the_list_shows_who_holds_what(): void
    {
        $rider = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $till = $this->make(['code' => 'RIDER-A', 'holder_id' => $rider->id]);
        $this->entry($till, debit: '7500');

        $this->actingAs($this->user)
            ->get(route('accounts.till.index'))
            ->assertOk()
            ->assertSee('RIDER-A')
            ->assertSee($rider->name)
            ->assertSee('7,500.00');
    }

    public function test_creating_through_the_screen_works_end_to_end(): void
    {
        $this->actingAs($this->user)
            ->post(route('accounts.till.store'), [
                'code' => 'CASH09',
                'name_en' => 'Evening Counter',
                'name_bn' => 'সন্ধ্যার কাউন্টার',
                'limit_amount' => '30000',
                'opening_balance' => '2000',
                'opening_date' => '2026-08-01',
            ])
            ->assertRedirect();

        $till = CashTill::query()->where('code', 'CASH09')->firstOrFail();

        $this->assertSame('2000.0000', $till->balance());
        $this->assertSame('1101-CASH09', $till->account->code);
    }

    public function test_making_a_till_primary_from_the_list_works(): void
    {
        $first = $this->service()->ensurePrimaryTill();
        $second = $this->make(['code' => 'CASH02']);

        $this->actingAs($this->user)
            ->post(route('accounts.till.primary', $second))
            ->assertRedirect();

        $this->assertTrue($second->fresh()->is_primary);
        $this->assertFalse($first->fresh()->is_primary);
    }

    public function test_view_permission_alone_cannot_create_or_close_a_till(): void
    {
        $till = $this->make();

        $reader = User::factory()->create();
        $reader->companies()->attach($this->company, ['is_active' => true]);
        $reader->forceFill(['current_company_id' => $this->company->id])->save();
        $reader->givePermissionTo(Permission::findOrCreate('accounts.till.view', 'web'));

        $this->actingAs($reader)->get(route('accounts.till.index'))->assertOk();
        $this->actingAs($reader)->get(route('accounts.till.show', $till))->assertOk();

        $this->actingAs($reader)->get(route('accounts.till.create'))->assertForbidden();
        $this->actingAs($reader)->delete(route('accounts.till.destroy', $till))->assertForbidden();
        $this->actingAs($reader)->post(route('accounts.till.primary', $till))->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('accounts.till.index'))->assertRedirect(route('login'));
    }

    /**
     * পাতার সারিগুলো নতুন থেকে পুরনো, কিন্তু ব্যালেন্স পুরনো থেকে গোনা।
     *
     * উল্টো করলে প্রতিটা সারির চলমান ব্যালেন্স ভুল হত — সবচেয়ে উপরের
     * সারিতে সবচেয়ে ছোট সংখ্যা বসত, অথচ ওটাই সর্বশেষ অবস্থা।
     */
    public function test_the_newest_row_carries_the_current_balance(): void
    {
        $till = $this->make(['opening_balance' => '1000', 'opening_date' => '2026-08-01']);

        $this->entry($till, debit: '500', date: '2026-08-05');
        $this->entry($till, debit: '300', date: '2026-08-06');

        $response = $this->actingAs($this->user)->get(route('accounts.till.show', $till));

        $response->assertOk()->assertSee('1,800.00');

        // আর উপরের সারিটাই সেই সংখ্যাটা বহন করে
        $rows = $response->viewData('entries');

        $this->assertSame('1800.0000', $rows->first()->running_balance);
    }

    private function entry(CashTill $till, string $debit = '0', string $credit = '0', string $date = '2026-08-10'): void
    {
        LedgerEntry::create([
            'company_id' => $till->company_id,
            'branch_id' => $till->branch_id,
            'financial_year_id' => $this->company->currentFinancialYear()?->id,
            'account_id' => $till->account_id,
            'trx_date' => $date,
            'debit' => $debit,
            'credit' => $credit,
            'source_type' => 'receipt_voucher',
            'source_id' => 1,
            'document_no' => 'RV-TEST',
            'narration' => 'test',
        ]);
    }
}
