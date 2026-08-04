<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Supplier মডিউল — Phase 5।
 *
 * গ্রাহকের পরীক্ষাগুলো ভিত্তি প্রমাণ করেছে, তাই এখানে সেগুলো আবার লেখা
 * হয়নি। এখানকার পরীক্ষা সেই জায়গাগুলোয় যেখানে সরবরাহকারী গ্রাহকের
 * আয়না নয়: চিহ্ন উল্টো (প্রদেয়, প্রাপ্য নয়), ক্রেডিট সীমা তথ্য মাত্র,
 * BIN-এর নিজস্ব সুইচ, আর দুইটা রিপোর্ট যাদের অঙ্ক লেজারের সাথে মিলতেই
 * হবে — নাহলে পুরো Phase 5-এর কোনো মানে নেই।
 */
class SupplierTest extends TestCase
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

    private function make(array $overrides = []): Supplier
    {
        return app(SupplierService::class)->create([
            'name_en' => 'Pran RFL',
            'credit_limit' => 0,
            'credit_days' => 0,
            ...$overrides,
        ]);
    }

    // ── কোড ও নম্বর সিরিজ ──────────────────────────────────────────────

    public function test_a_blank_code_comes_from_the_supplier_series_not_the_customer_one(): void
    {
        $supplier = $this->make();

        // SUP, CUS নয় — দুইটা পক্ষ একই কাউন্টার ভাগ করলে "SUP-0007 কার"
        // প্রশ্নের উত্তরে দুইজন আসত
        $this->assertStringStartsWith('SUP-', $supplier->code);
    }

    public function test_a_supplier_and_a_customer_may_share_a_code(): void
    {
        $this->make(['code' => 'SAME-1']);

        // কোডের অনন্যতা টেবিলের ভেতরে — একই প্রতিষ্ঠান দুই ভূমিকায়
        // থাকলে দুইটা রেকর্ডের নম্বর মেলাতে বাধ্য করার কারণ নেই
        $customer = app(CustomerService::class)
            ->create(['name_en' => 'Same Party', 'code' => 'SAME-1']);

        $this->assertSame('SAME-1', $customer->code);
    }

    public function test_two_suppliers_cannot_share_a_code(): void
    {
        $this->make(['code' => 'DUP-1']);

        $this->expectException(ValidationException::class);

        $this->make(['code' => 'DUP-1', 'name_en' => 'Someone Else']);
    }

    // ── সেটিংস সুইচ (নিয়ম ৭) ──────────────────────────────────────────

    public function test_a_bin_is_optional_until_the_setting_says_otherwise(): void
    {
        $this->assertNotNull($this->make());

        app(SettingsService::class)->set('supplier.require_bin', true);

        $this->expectException(ValidationException::class);

        $this->make(['name_en' => 'No BIN Supplier']);
    }

    public function test_the_bin_switch_is_separate_from_the_bangla_name_switch(): void
    {
        app(SettingsService::class)->set('supplier.require_bin', true);

        // BIN বাধ্যতামূলক, বাংলা নাম নয় — দুইটা সুইচ আলাদা হওয়ার মানেই
        // এই: একটা চালু করলে অন্যটা চালু হয় না
        $supplier = $this->make(['bin' => '000123456-0101']);

        $this->assertNull($supplier->name_bn);
    }

    // ── প্রদেয় — চিহ্ন উল্টো ──────────────────────────────────────────

    /**
     * ক্রেডিট বেশি হলে সংখ্যাটা ধনাত্মক।
     *
     * গ্রাহকের ঠিক উল্টো, আর এটাই সবচেয়ে সহজে ভুল হওয়ার জায়গা: চিহ্ন
     * উল্টে না দিলে প্রতিটা পর্দায় "প্রদেয় −৫,০০০" দেখাত, যা পড়ে কেউ
     * বুঝত না আমরা পাব না দেব।
     */
    public function test_payable_is_positive_when_we_owe_them(): void
    {
        $supplier = $this->make();

        $this->entry($supplier, credit: '5000.0000');

        $this->assertSame('5000.0000', $supplier->payable());
    }

    public function test_paying_them_lowers_the_payable(): void
    {
        $supplier = $this->make();

        $this->entry($supplier, credit: '5000.0000');
        $this->entry($supplier, debit: '2000.0000');

        $this->assertSame('3000.0000', $supplier->payable());
    }

    public function test_an_advance_shows_as_a_negative_payable(): void
    {
        $supplier = $this->make();

        // আগাম দেওয়া টাকা — এখন তারা আমাদের দেবে, আমরা নয়
        $this->entry($supplier, debit: '1500.0000');

        $this->assertSame('-1500.0000', $supplier->payable());
    }

    /**
     * তালিকার প্রদেয় আর একক পাতার প্রদেয় একই সংখ্যা।
     *
     * তালিকা সাব-কোয়েরি দিয়ে গোনে (N+1 এড়াতে), একক পাতা নিজের কোয়েরিতে।
     * দুইটা পথ মানে দুইটা সুযোগ আলাদা হওয়ার — তাই মিলিয়ে দেখা হয়।
     */
    public function test_the_list_figure_matches_the_single_record_figure(): void
    {
        $supplier = $this->make(['opening_balance' => '1000.0000', 'opening_date' => '2026-07-01']);

        $this->entry($supplier, credit: '2500.0000');

        $fromList = Supplier::query()->withPayable()->whereKey($supplier->id)->firstOrFail();

        $this->assertSame($supplier->fresh()->payable(), $fromList->payable());
        $this->assertSame('3500.0000', $fromList->payable());
    }

    // ── খোলা ব্যালেন্স খাতায় বসে ───────────────────────────────────────

    public function test_an_opening_balance_reaches_the_ledger_as_a_real_entry(): void
    {
        $supplier = $this->make(['opening_balance' => '1000.0000', 'opening_date' => '2026-07-01']);

        $lines = LedgerEntry::query()
            ->where('source_type', 'supplier'.OpeningBalanceService::SOURCE_SUFFIX)
            ->where('source_id', $supplier->id)
            ->get();

        $this->assertCount(2, $lines);

        // পক্ষের লাইনটা ক্রেডিট — দেনা ক্রেডিট প্রকৃতির
        $partyLine = $lines->firstWhere('party_id', $supplier->id);

        $this->assertNotNull($partyLine);
        $this->assertSame('1000.0000', $partyLine->credit);
        $this->assertSame('0.0000', $partyLine->debit);
    }

    /**
     * ঋণাত্মক খোলা ব্যালেন্সে দুই দিক উল্টে যায়।
     *
     * বাস্তব ঘটনা: পুরনো খাতায় সরবরাহকারীকে আগাম দেওয়া ছিল। উল্টে না
     * দিলে লেজারে ঋণাত্মক ক্রেডিট বসত, যা bcmath মানলেও হিসাব মানে না।
     */
    public function test_a_negative_opening_balance_flips_both_sides(): void
    {
        $supplier = $this->make(['opening_balance' => '-800.0000', 'opening_date' => '2026-07-01']);

        $partyLine = LedgerEntry::query()
            ->where('source_type', 'supplier'.OpeningBalanceService::SOURCE_SUFFIX)
            ->where('party_id', $supplier->id)
            ->firstOrFail();

        $this->assertSame('800.0000', $partyLine->debit);
        $this->assertSame('0.0000', $partyLine->credit);
        $this->assertSame('-800.0000', $supplier->payable());
    }

    // ── ক্রেডিট সীমা: তথ্য, নিয়ম নয় ───────────────────────────────────

    public function test_going_over_their_limit_is_reported_but_never_blocked(): void
    {
        $supplier = $this->make(['credit_limit' => '1000.0000']);

        $this->entry($supplier, credit: '2500.0000');

        $this->assertTrue($supplier->isOverTheirLimit());

        // সীমা ছাড়ানো অবস্থাতেও সম্পাদনা চলে — সীমাটা তাদের সিদ্ধান্ত,
        // আমাদের সিস্টেমের আটকানোর কিছু নেই
        $this->actingAs($this->user)
            ->put(route('supplier.update', $supplier), ['name_en' => 'Still Editable'])
            ->assertRedirect();

        $this->assertSame('Still Editable', $supplier->fresh()->name_en);
    }

    public function test_a_zero_limit_means_none_stated_not_blocked(): void
    {
        $supplier = $this->make(['credit_limit' => 0]);

        $this->entry($supplier, credit: '999999.0000');

        $this->assertFalse($supplier->isOverTheirLimit());
    }

    // ── পরিশোধের শর্ত ───────────────────────────────────────────────────

    public function test_the_payment_term_decides_the_due_date_over_credit_days(): void
    {
        $term = PaymentTerm::query()->where('code', 'NET30')->firstOrFail();

        // শর্ত ৩০ দিন, credit_days ৭ — শর্তই জেতে, কারণ ওটাই দুই পক্ষের
        // লিখিত সমঝোতা, আর credit_days কেবল ফলব্যাক
        $supplier = $this->make(['payment_term_id' => $term->id, 'credit_days' => 7]);

        $this->assertSame(
            $term->dueDateFrom('2026-08-01')->toDateString(),
            $supplier->dueDateFrom('2026-08-01')->toDateString(),
        );
    }

    public function test_without_a_term_the_credit_days_decide(): void
    {
        $supplier = $this->make(['credit_days' => 7]);

        $this->assertSame('2026-08-08', $supplier->dueDateFrom('2026-08-01')->toDateString());
    }

    // ── ধরন মাস্টার তালিকা থেকে ────────────────────────────────────────

    public function test_the_type_dropdown_offers_supplier_and_both_kinds_only(): void
    {
        $response = $this->actingAs($this->user)->get(route('supplier.create'));

        $response->assertOk();

        $offered = PartyType::query()->for(PartyType::SUPPLIER)->pluck('applies_to')->unique();

        // "customer" ধরনগুলো বাদ, "both" থাকে — একটা প্রতিষ্ঠান একইসাথে
        // গ্রাহক ও সরবরাহকারী হতে পারে
        $this->assertNotContains(PartyType::CUSTOMER, $offered);
        $this->assertContains(PartyType::BOTH, $offered);
    }

    // ── রিপোর্ট: অঙ্ক লেজারের সাথে মিলতেই হবে ─────────────────────────

    /**
     * প্রদেয় তালিকার যোগফল = প্রতিটা সরবরাহকারীর পাতার যোগফল।
     *
     * এটাই Phase 5-এর আসল শর্ত। দুইটা আলাদা পথে গোনা হয় — রিপোর্ট
     * SQL-এ GROUP BY দিয়ে, পাতা মডেলের payable() দিয়ে — আর দুইটা
     * না মিললে ব্যবহারকারীর কাছে সিস্টেমটা মিথ্যা বলে।
     */
    public function test_the_payable_list_total_matches_the_sum_of_every_supplier_page(): void
    {
        $a = $this->make(['name_en' => 'Alpha', 'opening_balance' => '1000.0000', 'opening_date' => '2026-07-01']);
        $b = $this->make(['name_en' => 'Beta']);
        $this->entry($b, credit: '2500.0000');

        // শূন্য বকেয়ার একজন — তালিকায় আসা উচিত নয়
        $this->make(['name_en' => 'Settled']);

        $result = $this->report('supplier.payable_list');

        $this->assertSame(2, $result->totalRows, 'শূন্য বকেয়ার সরবরাহকারী তালিকায় থাকে না।');
        $this->assertSame(
            bcadd($a->fresh()->payable(), $b->fresh()->payable(), 2),
            $result->totals['payable'],
        );
    }

    public function test_the_payable_list_row_carries_a_drill_target(): void
    {
        $supplier = $this->make(['opening_balance' => '1000.0000', 'opening_date' => '2026-07-01']);

        $row = $this->report('supplier.payable_list')->rows[0];

        // নিয়ম ১ — সংখ্যাটা থেকে তার উৎসে যাওয়া যাবে
        $this->assertSame('supplier', $row['party_type_literal']);
        $this->assertSame($supplier->id, (int) $row['party_id']);
        $this->assertStringContainsString($supplier->code, $row['supplier_name']);
    }

    /**
     * প্রতিটা এন্ট্রির বয়স তার নিজের তারিখ থেকে।
     *
     * সরবরাহকারীর শেষ লেনদেন থেকে গুনলে ছয় মাসের পুরনো বকেয়া রেখে
     * গতকাল একটা ছোট বিল দিলে পুরোটা "১ দিনের" হয়ে যেত — আর ঠিক ওই
     * সরবরাহকারীর সাথেই সমস্যাটা সবচেয়ে বড়।
     */
    public function test_ageing_puts_each_entry_in_the_bucket_of_its_own_date(): void
    {
        $supplier = $this->make();

        $this->entry($supplier, credit: '1000.0000', date: '2026-08-01');   // নতুন
        $this->entry($supplier, credit: '2000.0000', date: '2026-04-01');   // ১২০+ দিন

        $row = $this->report('supplier.ageing', ['to' => '2026-08-10'])->rows[0];

        $this->assertSame('1000.0000', $row['bucket_current']);
        $this->assertSame('2000.0000', $row['bucket_90']);
        $this->assertSame('3000.0000', $row['payable']);
    }

    public function test_ageing_buckets_always_add_up_to_the_payable(): void
    {
        $supplier = $this->make();

        foreach (['2026-08-05', '2026-07-10', '2026-06-05', '2026-03-01'] as $i => $date) {
            $this->entry($supplier, credit: (string) (($i + 1) * 1000).'.0000', date: $date);
        }

        $row = $this->report('supplier.ageing', ['to' => '2026-08-10'])->rows[0];

        $sum = '0';

        foreach (['bucket_current', 'bucket_30', 'bucket_60', 'bucket_90'] as $bucket) {
            $sum = bcadd($sum, $row[$bucket], 4);
        }

        $this->assertSame($row['payable'], $sum, 'ধাপগুলোর যোগফল মোট প্রদেয়ের সমান হতেই হবে।');
    }

    // ── অনুমতি (অলঙ্ঘনীয় শর্ত ৪) ──────────────────────────────────────

    public function test_a_user_without_the_permission_cannot_reach_any_screen(): void
    {
        $stranger = $this->userWithout();
        $supplier = $this->make();

        $this->actingAs($stranger)->get(route('supplier.index'))->assertForbidden();
        $this->actingAs($stranger)->get(route('supplier.show', $supplier))->assertForbidden();
        $this->actingAs($stranger)->get(route('supplier.create'))->assertForbidden();
        $this->actingAs($stranger)->delete(route('supplier.destroy', $supplier))->assertForbidden();
        $this->actingAs($stranger)->post(route('supplier.activate', $supplier))->assertForbidden();
        $this->actingAs($stranger)->get(route('supplier.report.show', 'ageing'))->assertForbidden();
    }

    /**
     * ফিরিয়ে আনা আর নিষ্ক্রিয় করা একই অনুমতিতে।
     *
     * আলাদা হলে যে নিষ্ক্রিয় করতে পারে না, সে-ও অন্যের বন্ধ করা
     * সরবরাহকারী ফিরিয়ে আনতে পারত — সুইচের একদিকে তালা, অন্যদিকে নয়।
     */
    public function test_reactivating_needs_the_same_permission_as_deactivating(): void
    {
        $editor = $this->userWithout();
        $editor->givePermissionTo(Permission::findOrCreate('supplier.view', 'web'));
        $editor->givePermissionTo(Permission::findOrCreate('supplier.update', 'web'));

        $supplier = $this->make();
        $supplier->forceFill(['is_active' => false])->save();

        $this->actingAs($editor)->post(route('supplier.activate', $supplier))->assertForbidden();
        $this->assertFalse($supplier->fresh()->is_active);
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('supplier.index'))->assertRedirect(route('login'));
    }

    // ── স্ক্রিন ─────────────────────────────────────────────────────────

    public function test_creating_through_the_screen_works_end_to_end(): void
    {
        $type = PartyType::query()->where('code', 'VENDOR')->firstOrFail();
        $term = PaymentTerm::query()->where('code', 'NET30')->firstOrFail();

        $this->actingAs($this->user)
            ->post(route('supplier.store'), [
                'name_en' => 'Screen Supplier',
                'name_bn' => 'স্ক্রিন সরবরাহকারী',
                'phone' => '01711000000',
                'contact_person' => 'Rezaul',
                'bin' => '000123456-0101',
                'party_type_id' => $type->id,
                'payment_term_id' => $term->id,
                'credit_limit' => '2500.00',
                'credit_days' => 15,
                'opening_balance' => '0',
            ])
            ->assertRedirect();

        $supplier = Supplier::query()->where('name_en', 'Screen Supplier')->firstOrFail();

        $this->assertSame('স্ক্রিন সরবরাহকারী', $supplier->name('bn'));
        $this->assertSame(DocumentStatus::CONFIRMED, $supplier->status);
        $this->assertSame($type->id, $supplier->party_type_id);
        $this->assertSame($this->user->id, $supplier->created_by);
    }

    public function test_another_companys_party_type_is_refused(): void
    {
        $other = Company::query()->where('code', 'FMART')->firstOrFail();

        $foreign = CompanyContext::forCompany(
            $other->id,
            fn () => PartyType::query()->where('code', 'VENDOR')->firstOrFail()->id,
        );

        // গ্লোবাল স্কোপ Eloquent-এ কাজ করে, ভ্যালিডেটরের কাঁচা কোয়েরিতে
        // নয় — তাই exists নিয়মে company_id না বসালে এটা পাশ করে যেত
        $this->actingAs($this->user)
            ->post(route('supplier.store'), [
                'name_en' => 'Cross Tenant',
                'party_type_id' => $foreign,
            ])
            ->assertSessionHasErrors('party_type_id');
    }

    public function test_the_show_screen_lists_the_entries_behind_the_payable(): void
    {
        $supplier = $this->make(['opening_balance' => '1000.0000', 'opening_date' => '2026-07-01']);

        $this->entry($supplier, credit: '500.0000', documentNo: 'PUR-2026-2027-0001');
        $this->entry($supplier, debit: '300.0000', documentNo: 'PAY-2026-2027-0001');

        $this->actingAs($this->user)
            ->get(route('supplier.show', $supplier))
            ->assertOk()
            ->assertSee('PUR-2026-2027-0001')
            ->assertSee('PAY-2026-2027-0001')
            ->assertSee(__('accounts::message.opening_balance'), false)
            // চলমান ব্যালেন্স: ১০০০ + ৫০০ = ১৫০০, তারপর − ৩০০ = ১২০০
            ->assertSee('1,500.00')
            ->assertSee('1,200.00');
    }

    // ── নরম মুছে ফেলা ও ফেরা (নিয়ম ৫) ────────────────────────────────

    public function test_deleting_deactivates_and_the_record_can_come_back(): void
    {
        $supplier = $this->make();

        $this->actingAs($this->user)
            ->delete(route('supplier.destroy', $supplier))
            ->assertRedirect(route('supplier.index'));

        $this->assertFalse($supplier->fresh()->is_active);
        $this->assertNotNull(Supplier::query()->find($supplier->id), 'রেকর্ডটা থেকে যাবে — নিয়ম ৫।');

        $this->actingAs($this->user)
            ->post(route('supplier.activate', $supplier))
            ->assertRedirect(route('supplier.show', $supplier));

        $this->assertTrue($supplier->fresh()->is_active);
    }

    /**
     * বকেয়া থাকা অবস্থাতেও নিষ্ক্রিয় করা যায়।
     *
     * আটকে দিলে ব্যবহারকারী বাধ্য হত একটা ভুয়া ভাউচার দিয়ে হিসাবটা
     * শূন্য করতে — যা আসল সমস্যাটা লুকিয়ে ফেলত।
     */
    public function test_a_supplier_with_a_balance_can_still_be_deactivated(): void
    {
        $supplier = $this->make();
        $this->entry($supplier, credit: '5000.0000');

        $this->actingAs($this->user)->delete(route('supplier.destroy', $supplier))->assertRedirect();

        $this->assertFalse($supplier->fresh()->is_active);
        $this->assertSame('5000.0000', $supplier->fresh()->payable(), 'বকেয়া মুছে যায় না।');
    }

    // ── টেন্যান্ট বিচ্ছিন্নতা ───────────────────────────────────────────

    public function test_one_company_never_sees_another_companys_suppliers(): void
    {
        $mine = $this->make();

        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($other->id, $other->defaultBranch()?->id);

        $this->assertNull(Supplier::query()->find($mine->id));
        $this->assertSame(0, Supplier::query()->count());
    }

    // ── drill-down (নিয়ম ১) ────────────────────────────────────────────

    public function test_a_supplier_can_be_reached_from_any_figure_that_names_it(): void
    {
        $supplier = $this->make();

        $this->assertSame('supplier', Supplier::drillSourceType());
        $this->assertSame($supplier->code, $supplier->drillDocumentNo());
        $this->assertSame(['supplier.show', ['supplier' => $supplier->id]], $supplier->drillRoute());
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function report(string $key, array $filters = [])
    {
        return app(ReportEngine::class)->run($key, [
            'from' => '2026-07-01',
            'to' => '2027-06-30',
            ...$filters,
        ]);
    }

    private function userWithout(): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id])->save();

        return $user;
    }

    private function entry(
        Supplier $supplier,
        string $debit = '0',
        string $credit = '0',
        ?string $documentNo = null,
        string $date = '2026-08-01',
    ): void {
        LedgerEntry::create([
            'company_id' => $supplier->company_id,
            'branch_id' => $supplier->branch_id,
            'financial_year_id' => $this->company->currentFinancialYear()?->id,
            'account_id' => 1,
            'party_type' => Supplier::drillSourceType(),
            'party_id' => $supplier->id,
            'trx_date' => $date,
            'debit' => $debit,
            'credit' => $credit,
            // প্রতিটা লাইনে উৎস থাকতেই হবে (নিয়ম ১)
            'source_type' => 'purchase_bill',
            'source_id' => 1,
            'document_no' => $documentNo ?? 'TEST-0001',
            'narration' => 'test',
        ]);
    }
}
