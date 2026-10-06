<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Dashboard\SupplierDashboard;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Reports\PrincipalCommissionReport;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * প্রিন্সিপাল কমিশন পায় ডিপো যা তুলল তার উপর — মালিক, ৫ অক্টোবর ২০২৬ ([[PrincipalCommission]])।
 *
 * ⓘ একটা প্রিন্সিপাল (শাখা ক, মার্জিন ৩.৮৫%, চক্র ২ থেকে ১), আজ ৫ অক্টোবর ২০২৬ → চক্র ২ অক্টোবর–১ নভেম্বর।
 * ⓘ সব তারিখ অক্টোবরে — খোলা মাস; "চক্রের আগে" মানে ১ অক্টোবর (আগের চক্রের শেষ দিন)।
 *
 *   গোনা:   শাখা ক-তে গ্রাহকের রসিদ ১,০০,০০০ (৩ অক্টোবর)
 *   বাদ:    চক্রের আগের রসিদ ৫০,০০০ · শাখা খ-এর রসিদ ৭০,০০০ · নিজের খাতে টাকা সরানো ৫,০০০ (খ → ক)
 *           · রসিদ ৮,০০০ যেটা পরে বাতিল (উল্টো কাগজ) — নিট শূন্য
 *   দেওয়া:  এই প্রিন্সিপালকে ২০,০০০ — বাদ: অন্য সরবরাহকারীকে ৭,০০০, চক্রের আগে ৯,০০০,
 *           ফেরতের কাগজে ১,৫০০ (টাকা নয়), বাতিল পরিশোধ ৪,০০০
 *
 *   আদায় ১,০০,০০০ · কমিশন ৩,৮৫০ · অংশ ৯৬,১৫০ · দেওয়া ২০,০০০ · জের "দিতে হবে ৳76,150.00"
 */
final class APrincipalEarnsOnWhatTheDepotCollectsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Branch $a;

    private Branch $b;

    private Supplier $principal;

    private Supplier $other;

    private Customer $dealer;

    private Account $bank;

    private Account $wallet;

    private int $source = 7000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        Carbon::setTestNow('2026-10-05 10:00:00');

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->a = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $this->b = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();

        CompanyContext::set($this->company->id, $this->a->id);
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();

        $this->bank = app(AccountService::class)->create(['name_en' => 'Principal test bank', 'parent_id' => StandardChart::find(StandardChart::BANK)->id]);
        $this->wallet = app(AccountService::class)->create(['name_en' => 'Principal test wallet', 'parent_id' => StandardChart::find(StandardChart::MOBILE_MONEY)->id]);

        $suppliers = Supplier::query()->onlySuppliers()->orderBy('id')->take(2)->get();
        $this->principal = $suppliers->first();
        $this->other = $suppliers->last();
        $this->assertNotSame($this->principal->id, $this->other->id);

        $this->principal->forceFill([
            'principal_branch_id' => $this->a->id,
            'commission_basis' => 'margin',
            'commission_rate' => '3.850',
            'cycle_start_day' => 2,
            'cycle_close_day' => 1,
        ])->save();

        $this->dealer = Customer::query()->orderBy('id')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_row_counts_only_customer_money_in_the_principal_branch_and_only_payments_to_it(): void
    {
        $this->theMonth();

        $row = $this->row();

        $this->assertSame('2026-10-02', $row['period_from']);
        $this->assertSame('2026-11-01', $row['period_to']);
        $this->assertSame('100000.00', $row['inflow'], '⛔ আদায়ে অন্য শাখা, নিজের খাতে টাকা সরানো, চক্রের আগের বা বাতিল রসিদ ঢুকেছে।');
        $this->assertSame('3850.00', $row['commission']);
        $this->assertSame('96150.00', $row['share']);
        $this->assertSame('20000.00', $row['paid'], '⛔ দেওয়ায় অন্য সরবরাহকারী, চক্রের আগের, টাকাহীন বা বাতিল কাগজ ঢুকেছে।');
        $this->assertSame('76150.00', $row['balance']);
    }

    public function test_collections_in_another_branch_are_not_counted(): void
    {
        $this->receipt($this->b, '70000', '2026-10-03');

        $this->assertSame('0.00', $this->row()['inflow']);

        // ⓘ একই রসিদ প্রিন্সিপালের শাখায় হলে গোনা হয় — ছাঁকনিটা শাখার, রসিদের ধরনের নয়
        $this->receipt($this->a, '70000', '2026-10-03');
        $this->assertSame('70000.00', $this->row()['inflow']);
    }

    public function test_a_transfer_between_own_money_accounts_is_not_counted(): void
    {
        // ⓘ শাখা খ-এর MFS থেকে শাখা ক-এর ব্যাংকে — ক-এর টাকা বাড়ে, অথচ কোনো গ্রাহক নেই
        $this->book('contra_voucher', '2026-10-03', [
            ['account_id' => $this->bank->id, 'debit' => '5000', 'branch_id' => $this->a->id],
            ['account_id' => $this->wallet->id, 'credit' => '5000', 'branch_id' => $this->b->id],
        ]);

        $this->assertSame('0.00', $this->row()['inflow']);
    }

    public function test_a_receipt_cancelled_later_takes_its_money_back_out(): void
    {
        $id = $this->receipt($this->a, '8000', '2026-10-03');
        $this->assertSame('8000.00', $this->row()['inflow']);

        app(PostingEngine::class)->reverse('collection', $id, '2026-10-04');
        $this->assertSame('0.00', $this->row()['inflow'], '⛔ বাতিল রসিদের টাকা আদায়ে থেকে গেছে — তার উপর কমিশনও।');
    }

    public function test_paid_sums_only_this_suppliers_posted_money_in_the_period(): void
    {
        $this->pay($this->principal, '20000', '2026-10-03');
        $this->pay($this->other, '7000', '2026-10-03');
        $this->pay($this->principal, '9000', '2026-10-01');

        // ⓘ ফেরতের কাগজ সরবরাহকারীর খাতা ডেবিট করে, কিন্তু টাকা যায় না
        $this->book('purchase_return', '2026-10-03', [
            ['account_id' => StandardChart::find(StandardChart::PAYABLE)->id, 'debit' => '1500',
                'party_type' => 'supplier', 'party_id' => $this->principal->id],
            ['account_id' => StandardChart::find(StandardChart::INVENTORY)->id, 'credit' => '1500'],
        ]);

        $cancelled = $this->pay($this->principal, '4000', '2026-10-03');
        app(PostingEngine::class)->reverse('payment_voucher', $cancelled, '2026-10-04');

        $this->assertSame('20000.00', $this->row()['paid']);
    }

    public function test_the_balance_says_who_owes_whom_in_words(): void
    {
        $this->theMonth();
        app()->setLocale('bn');

        $html = $this->get(route('supplier.report.show', ['slug' => 'principal-commission']))->assertOk()->getContent();
        $this->assertStringContainsString('দিতে হবে ৳76,150.00', $html);

        // ⓘ মার্কআপ ৪%, আর প্রিন্সিপালকে অংশের চেয়ে বেশি দেওয়া — উল্টো দিক
        $this->principal->forceFill(['commission_basis' => 'markup', 'commission_rate' => '4'])->save();
        $this->pay($this->principal, '100000', '2026-10-04');

        $row = $this->row();
        $this->assertSame('3846.15', $row['commission']);
        $this->assertSame('-23846.15', $row['balance']);

        $html = $this->get(route('supplier.report.show', ['slug' => 'principal-commission']))->assertOk()->getContent();
        $this->assertStringContainsString('কোম্পানির কাছে পাব ৳23,846.15', $html);
        $this->assertStringNotContainsString('-23,846.15', $html, '⛔ জের খালি বিয়োগ চিহ্নে ছাপা হলো।');
    }

    public function test_the_month_picker_moves_every_row_to_the_cycle_closing_in_that_month(): void
    {
        $this->theMonth();

        $row = $this->row(['month' => '2026-10']);
        $this->assertSame(['2026-09-02', '2026-10-01'], [$row['period_from'], $row['period_to']]);
        // ⓘ ১ অক্টোবরের রসিদ ৫০,০০০ আর পরিশোধ ৯,০০০ এই চক্রের
        $this->assertSame('50000.00', $row['inflow']);
        $this->assertSame('9000.00', $row['paid']);
    }

    public function test_nothing_is_posted_to_the_ledger(): void
    {
        $this->theMonth();
        $before = LedgerEntry::query()->withoutGlobalScopes()->count();

        $this->row();
        $this->get(route('supplier.report.show', ['slug' => 'principal-commission']))->assertOk();
        $this->get(route('supplier.report.show', ['slug' => 'principal-commission', 'export' => 'csv']))->assertOk();
        $this->get(route('module.dashboard', ['module' => 'supplier']))->assertOk();

        $this->assertSame($before, LedgerEntry::query()->withoutGlobalScopes()->count(), '⛔ রিপোর্ট খাতায় কিছু বসিয়েছে।');
    }

    public function test_the_dashboard_shows_the_reports_own_figures(): void
    {
        $this->theMonth();
        app()->setLocale('bn');

        $row = $this->row();
        $listing = collect(SupplierDashboard::dashboard()->listings)
            ->first(fn (Listing $l) => $l->label === __('supplier::principal.dashboard_title'));

        $this->assertNotNull($listing, '⛔ ড্যাশবোর্ডে প্রিন্সিপালের কমিশন নেই।');

        $shown = $listing->rows->firstWhere('supplier_id', $this->principal->id);
        $cells = collect($listing->columns)->mapWithKeys(fn (array $c) => [$c['key'] => ($c['render'])($shown)]);

        // ⓘ মালিকের বাক্স, ৬ অক্টোবর ২০২৬: প্রিন্সিপাল · মোট ইনফ্লো · কমিশন · প্রিন্সিপালকে পাঠানো · বাকি ইনফ্লো (কথায়);
        //   সময়কাল শিরোনামের নিচে, এক চক্রে; হোমের টাকার বাক্সের রূপে
        $this->assertTrue($listing->hero, '⛔ বাক্সটা হোমের টাকার বাক্সের রূপে নয়।');
        $this->assertSame(['principal', 'inflow', 'commission', 'paid', 'balance'], array_keys($cells->all()));
        $this->assertSame(Money::format($row['commission']), $cells['commission']);
        $this->assertSame('1,00,000.00', $cells['inflow']);
        $this->assertSame('3,850.00', $cells['commission']);
        $this->assertSame('20,000.00', $cells['paid']);
        $this->assertSame('দিতে হবে: 76,150.00', $cells['balance']);
        $this->assertSame('সময়কাল: 02/10/2026 – আজ পর্যন্ত', $listing->note, '⛔ শিরোনামের নিচে চলতি চক্রের সময়কাল নেই।');

        // ⓘ নাম: কোড নয়; সংক্ষিপ্ত নাম থাকলে সেটা, না থাকলে নাম
        $this->assertStringNotContainsString((string) $this->principal->code, $cells['principal'], '⛔ প্রিন্সিপালের নামে কোড।');
        $this->assertSame($this->principal->name_bn ?: $this->principal->name_en, $cells['principal']);
        $this->principal->forceFill(['short_name' => 'Star Line'])->save();
        $this->assertSame('Star Line', $this->cellsNow()['principal'], '⛔ সংক্ষিপ্ত নাম বসিয়েও পুরো নাম দেখাল।');

        $this->get(route('module.dashboard', ['module' => 'supplier']))->assertOk()
            ->assertSee('data-hero-listing', false)
            ->assertSee('দিতে হবে: 76,150.00')
            ->assertSee('Star Line')
            ->assertSee('প্রিন্সিপালকে পাঠানো');

        // ⓘ উল্টো দিক — বাকি ইনফ্লো কথায়, খালি বিয়োগ নয়
        $this->pay($this->principal, '100000', '2026-10-04');
        $this->assertSame('কোম্পানির কাছে পাব: 23,850.00', $this->cellsNow()['balance'], '⛔ বাকি ইনফ্লো খালি বিয়োগ চিহ্নে।');
    }

    /** @return array<string, string> */
    private function cellsNow(): array
    {
        $listing = collect(SupplierDashboard::dashboard()->listings)
            ->first(fn (Listing $l) => $l->label === __('supplier::principal.dashboard_title'));
        $shown = $listing->rows->firstWhere('supplier_id', $this->principal->id);

        return collect($listing->columns)->mapWithKeys(fn (array $c) => [$c['key'] => ($c['render'])($shown)])->all();
    }

    public function test_only_the_supplier_edit_key_sets_the_principal_fields(): void
    {
        $fields = [
            'name_en' => 'New Principal',
            'principal_branch_id' => $this->a->id,
            'commission_basis' => 'markup',
            'commission_rate' => '4',
            'cycle_start_day' => 26,
            'cycle_close_day' => 25,
        ];

        $creator = User::factory()->create();
        $creator->companies()->attach($this->company, ['is_active' => true]);
        $creator->forceFill(['current_company_id' => $this->company->id])->save();
        $creator->givePermissionTo(Permission::findOrCreate('supplier.view', 'web'));
        $creator->givePermissionTo(Permission::findOrCreate('supplier.create', 'web'));

        $this->actingAs($creator)->get(route('supplier.create'))->assertOk()->assertDontSee('data-principal-commission', false);
        $this->actingAs($creator)->post(route('supplier.store'), $fields)->assertSessionHasErrors('commission_basis');
        $this->assertFalse(Supplier::query()->where('name_en', 'New Principal')->exists());

        $this->actingAs($this->owner)->get(route('supplier.edit', $this->other))->assertOk()->assertSee('data-principal-commission', false);
        $this->actingAs($this->owner)->put(route('supplier.update', $this->other), [...$fields, 'name_en' => $this->other->name_en])
            ->assertSessionHasNoErrors();

        $saved = $this->other->fresh();
        $this->assertSame([$this->a->id, 'markup', '4.000', 26, 25],
            [$saved->principal_branch_id, $saved->commission_basis, (string) $saved->commission_rate, $saved->cycle_start_day, $saved->cycle_close_day]);
    }

    public function test_a_basis_without_its_rate_and_days_is_refused(): void
    {
        $this->actingAs($this->owner)->put(route('supplier.update', $this->other), [
            'name_en' => $this->other->name_en,
            'commission_basis' => 'margin',
        ])->assertSessionHasErrors(['commission_rate', 'principal_branch_id', 'cycle_start_day', 'cycle_close_day']);

        $this->actingAs($this->owner)->put(route('supplier.update', $this->other), [
            'name_en' => $this->other->name_en,
            'commission_basis' => 'royalty',
            'principal_branch_id' => $this->a->id,
            'commission_rate' => '100',
            'cycle_start_day' => 0,
            'cycle_close_day' => 32,
        ])->assertSessionHasErrors(['commission_basis', 'commission_rate', 'cycle_start_day', 'cycle_close_day']);
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** ক্লাসের মাথায় লেখা গোটা মাস। */
    private function theMonth(): void
    {
        $this->receipt($this->a, '100000', '2026-10-03');
        $this->receipt($this->a, '50000', '2026-10-01');
        $this->receipt($this->b, '70000', '2026-10-03');
        $this->book('contra_voucher', '2026-10-03', [
            ['account_id' => $this->bank->id, 'debit' => '5000', 'branch_id' => $this->a->id],
            ['account_id' => $this->wallet->id, 'credit' => '5000', 'branch_id' => $this->b->id],
        ]);
        $cancelled = $this->receipt($this->a, '8000', '2026-10-03');
        app(PostingEngine::class)->reverse('collection', $cancelled, '2026-10-04');

        $this->pay($this->principal, '20000', '2026-10-03');
        $this->pay($this->other, '7000', '2026-10-03');
        $this->pay($this->principal, '9000', '2026-10-01');
        $this->book('purchase_return', '2026-10-03', [
            ['account_id' => StandardChart::find(StandardChart::PAYABLE)->id, 'debit' => '1500',
                'party_type' => 'supplier', 'party_id' => $this->principal->id],
            ['account_id' => StandardChart::find(StandardChart::INVENTORY)->id, 'credit' => '1500'],
        ]);
        $paidBack = $this->pay($this->principal, '4000', '2026-10-03');
        app(PostingEngine::class)->reverse('payment_voucher', $paidBack, '2026-10-04');
    }

    /** গ্রাহকের রসিদ — টাকা ঐ শাখার ব্যাংকে। */
    private function receipt(Branch $branch, string $amount, string $date): int
    {
        return $this->book('collection', $date, [
            ['account_id' => $this->bank->id, 'debit' => $amount, 'branch_id' => $branch->id],
            ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'credit' => $amount,
                'party_type' => 'customer', 'party_id' => $this->dealer->id, 'branch_id' => $branch->id],
        ]);
    }

    /** সরবরাহকারীকে পরিশোধ — শাখা ক-এর ব্যাংক থেকে। */
    private function pay(Supplier $to, string $amount, string $date): int
    {
        return $this->book('payment_voucher', $date, [
            ['account_id' => StandardChart::find(StandardChart::PAYABLE)->id, 'debit' => $amount,
                'party_type' => 'supplier', 'party_id' => $to->id],
            ['account_id' => $this->bank->id, 'credit' => $amount],
        ]);
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function book(string $type, string $date, array $lines): int
    {
        $id = ++$this->source;

        app(PostingEngine::class)->post($type, $id, $date, $lines, 'T-'.$id, $this->a->id);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function row(array $filters = []): array
    {
        $rows = app(ReportEngine::class)->run(PrincipalCommissionReport::KEY, $filters)->rows;
        $row = collect($rows)->firstWhere('supplier_id', $this->principal->id);

        $this->assertNotNull($row, '⛔ প্রিন্সিপালের সারি নেই।');
        $this->assertCount(1, $rows, '⛔ কমিশন বসানো নেই এমন সরবরাহকারীও সারি পেয়েছে।');

        return $row;
    }
}
