<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\OpenPeriod;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DayClose;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashCount;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Executive\Services\DayClosingService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⭐ দিন শেষের হিসাব — মালিকের অনুমোদিত নমুনা v7 (১৬ নভেম্বর ২০২৬ "ok"; ১৮ নভেম্বর: "এখনই করে লাইভে দাও")।
 *
 * দাবিগুলো: (১) টাকা আসা পার্টি ধরে, টাকার ধরন ধরে, মোট খাতার সাথে; (২) খরচ আর "টাকা গেছে কিন্তু খরচ নয়" আলাদা — একই কাগজ দুই জায়গায় নয়;
 * (৩) স্থির খরচের দৈনিক ভাগ = মাসের অঙ্ক ÷ কর্মদিবস (শুক্রবার বাদে), মাসে আজ পর্যন্ত = ভাগ × পেরোনো কর্মদিবস; (৪) নগদ গণনার ঘাটতি আর কারণ;
 * (৫) পার্থক্য থাকলে কারণ ছাড়া দিন বন্ধ নয়; বন্ধের পর ঐ দিনের তারিখে লেখা আটকায় — পেছনের তারিখের অনুমতি ছাড়া; (৬) পর্দা দুই ভাষায় সব
 * অংশসহ, কাঁচা চাবি নেই, কাগজ PDF; (৭) দেখা আর বন্ধ — দুই আলাদা চাবি।
 */
final class TheDayIsCountedAndClosedTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-11-16'; // ⓘ সোমবার — নভেম্বর ২০২৬-এ শুক্রবার ৬, ১৩, ২০, ২৭: কর্মদিবস ২৬, এই দিন পর্যন্ত ১৪

    private Company $company;

    private Branch $a;

    private User $owner;

    private Account $cash;

    private Account $bank;

    private Customer $dealer;

    private int $source = 9300;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Carbon::setTestNow(self::DAY.' 21:00:00');

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->a = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        CompanyContext::set($this->company->id, null);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['locale' => 'en'])->save();
        $this->actingAs($this->owner->fresh());
        app()->setLocale('en');

        app(StandardChart::class)->install();
        $this->cash = app(AccountService::class)->create(['name_en' => 'Head office cash', 'parent_id' => StandardChart::find(StandardChart::CASH_IN_HAND)->id, 'held_by' => $this->owner->id]);
        $this->bank = app(AccountService::class)->create(['name_en' => 'Islami Bank current', 'parent_id' => StandardChart::find(StandardChart::BANK)->id]);
        $this->dealer = Customer::query()->orderBy('id')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_money_in_is_listed_by_party_and_kind_and_totals_the_books(): void
    {
        $this->theDay();
        $r = app(DayClosingService::class)->build(Carbon::parse(self::DAY));

        $kinds = array_column($r['inflow']['groups'], 'kind');
        $this->assertSame(['cash', 'bank'], $kinds, '⛔ টাকা আসা নগদ → ব্যাংক ক্রমে ভাগ হয়নি');
        $this->assertSame(0, bccomp($r['inflow']['total'], '70000', 2), '⛔ আজ মোট টাকা এল খাতার সাথে মেলে না (২০,০০০ নগদ + ৫০,০০০ ব্যাংক)');
        $this->assertStringStartsWith((string) $this->dealer->name(), (string) $r['inflow']['groups'][0]['rows'][0]['party'], '⛔ টাকা আসার সারিতে পার্টি নেই');
        $this->assertStringContainsString(' · ', (string) $r['inflow']['groups'][0]['rows'][0]['party'], '⛔ পার্টির পাশে পয়েন্ট/শাখা নেই');

        // ⓘ টাকার খাত থেকে খাতে সরানো (নগদ → ব্যাংক) টাকা আসা নয়
        $this->assertCount(1, $r['inflow']['groups'][1]['rows'], '⛔ নিজের খাত থেকে খাতে সরানো টাকাকে "টাকা এল" ধরা হলো');
    }

    public function test_an_expense_and_a_payment_that_is_not_an_expense_never_land_in_the_same_place(): void
    {
        $this->theDay();
        $r = app(DayClosingService::class)->build(Carbon::parse(self::DAY));

        $this->assertSame(0, bccomp($r['expenses']['total'], '1500', 2), '⛔ আজকের খরচ খাতার সাথে মেলে না');
        $this->assertSame(['T-9303', 'T-9305', 'T-9306'], array_column($r['paid_out']['rows'], 'no'),
            '⛔ "টাকা গেছে, কিন্তু খরচ নয়"-এ সরবরাহকারীকে দেওয়া আর ব্যাংকে জমা নেই, বা খরচের কাগজ ঢুকেছে');
        $this->assertSame(0, bccomp($r['paid_out']['total'], '50000', 2));
    }

    public function test_the_fixed_cost_is_shared_over_working_days_without_fridays(): void
    {
        app(SettingsService::class)->set('accounts.day_close.fixed.salary', '20800');
        app(SettingsService::class)->set('accounts.day_close.fixed.rent', '5200');
        $this->theDay();

        $r = app(DayClosingService::class)->build(Carbon::parse(self::DAY));

        $this->assertSame(['workdays' => 26, 'done' => 14, 'day_of_month' => 16, 'holiday' => 'friday'], $r['calendar'],
            '⛔ কর্মদিবস গোনা ভুল (নভেম্বর ২০২৬: ৩০ দিন − ৪ শুক্রবার = ২৬; ১৬ তারিখ পর্যন্ত ১৪)');
        $this->assertSame(0, bccomp($r['fixed']['daily'], '1000', 2), '⛔ স্থির খরচের দৈনিক ভাগ = ২৬,০০০ ÷ ২৬ = ১,০০০ নয়');
        $this->assertSame(0, bccomp($r['pl']['today']['fixed'], '1000', 2), '⛔ আজকের লাভে স্থির খরচের ভাগ ভুল');
        $this->assertSame(0, bccomp($r['pl']['month']['fixed'], '14000', 2), '⛔ মাসে আজ পর্যন্ত স্থির খরচ = ভাগ × ১৪ কর্মদিবস নয়');
        $this->assertSame(0, bccomp($r['pl']['today']['office'], '1500', 2), '⛔ অফিস ও অন্যান্য খরচ খাতার সাথে মেলে না');

        // ⓘ নিট মুনাফা = মুনাফা − ডেলিভারি − অফিস − স্থির
        $pl = $r['pl']['today'];
        $this->assertSame(0, bccomp($pl['profit'], bcsub(bcsub(bcsub($pl['margin'], $pl['delivery'], 4), $pl['office'], 4), $pl['fixed'], 4), 2),
            '⛔ নিট মুনাফার হিসাব ধাপগুলোর যোগ নয়');
    }

    public function test_a_cash_shortage_needs_a_reason_and_a_closed_day_refuses_new_entries(): void
    {
        $this->theDay();
        $this->countCash('19850', '20000', '');

        $r = app(DayClosingService::class)->build(Carbon::parse(self::DAY));
        $this->assertSame(0, bccomp($r['count']['gap'], '-150', 2), '⛔ নগদ গণনার ঘাটতি দেখায় না');

        // ⛔ কারণ ছাড়া বন্ধ নয়
        $this->post(route('executive.dayclose.close'), ['date' => self::DAY])->assertSessionHasErrors('gap_reason');
        $this->assertSame(0, DayClose::query()->count(), '⛔ কারণ ছাড়া ঘাটতির দিন বন্ধ হয়ে গেল');

        $this->post(route('executive.dayclose.close'), ['date' => self::DAY, 'gap_reason' => 'Change returned from the cashier'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $closed = DayClose::query()->firstOrFail();
        $this->assertSame(0, bccomp((string) $closed->cash_gap, '-150', 2));
        $this->assertSame('Change returned from the cashier', $closed->gap_reason);

        // ⭐ ডিফল্টে কেবল সতর্কতা — বন্ধ দিনেও লেখা চলে (মালিক, ১৮ নভেম্বর ২০২৬: "কাজ যাতে কোনোভাবে আটকে না থাকে")
        $clerk = $this->clerk(['accounts.day_close.view']);
        $this->actingAs($clerk);
        try {
            app(OpenPeriod::class)->assertOpen(self::DAY);
        } catch (ValidationException) {
            $this->fail('⛔ ডিফল্টে বন্ধ দিনে লেখা আটকালো — মালিকের নিয়ম: কেবল সতর্কতা, কাজ আটকাবে না');
        }

        // ⛔ সুইচ চালু করলে তবেই, পেছনের তারিখের অনুমতি ছাড়া, লেখা আটকায়
        app(SettingsService::class)->set('accounts.day_close.lock', true);
        try {
            app(OpenPeriod::class)->assertOpen(self::DAY);
            $this->fail('⛔ বন্ধ দিনে অনুমতি ছাড়া লেখা গেল');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('closed', (string) collect($e->errors())->flatten()->first(), '⛔ আটকালো, কিন্তু অন্য কারণে');
        }
        app(OpenPeriod::class)->assertOpen('2026-11-17'); // ⓘ পরের দিন খোলা

        // ⓘ অনুমতি যাঁর আছে তিনি পারেন — সেটাই "অনুমোদন"
        $clerk->givePermissionTo(Permission::findOrCreate('accounts.backdate.override', 'web'));
        $this->actingAs($clerk->fresh());
        app(OpenPeriod::class)->assertOpen(self::DAY);

        // ⓘ দুইবার বন্ধ নয়
        $this->actingAs($this->owner->fresh());
        $this->post(route('executive.dayclose.close'), ['date' => self::DAY, 'gap_reason' => 'again'])->assertSessionHasErrors('date');
        $this->assertSame(1, DayClose::query()->count());
    }

    public function test_the_screen_and_the_paper_show_every_section_in_both_languages(): void
    {
        $this->theDay();
        $this->countCash('20000', '20000', '');

        foreach (['en' => 'Money in today', 'bn' => 'আজ টাকা এল'] as $locale => $heading) {
            $this->owner->forceFill(['locale' => $locale])->save();
            $this->actingAs($this->owner->fresh());
            app()->setLocale($locale);

            $html = (string) $this->get(route('executive.dayclose', ['date' => self::DAY]))->assertOk()->getContent();

            $this->assertStringContainsString($heading, $html, "⛔ ({$locale}) প্রথম অংশের নাম নেই");
            foreach (['inflow', 'delivery', 'sales', 'expense', 'count', 'paid_out', 'balances', 'pl', 'stock', 'fixed', 'vat'] as $sec) {
                $this->assertStringContainsString('data-sec="'.$sec.'"', $html, "⛔ ({$locale}) অংশ নেই: {$sec}");
            }
            $this->assertSame(0, preg_match('/accounts::dayclose\.[a-z_.]+/', $html), "⛔ ({$locale}) কাঁচা চাবি");
            $this->assertStringContainsString('70,000.00', $html, "⛔ ({$locale}) মোট টাকা আসা লাখের ধাঁচে নেই");
        }

        $pdf = $this->get(route('executive.dayclose', ['date' => self::DAY, 'export' => 'pdf']))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
    }

    /**
     * ⭐ মালিক, ১৮ নভেম্বর ২০২৬ ("ওকে, তাহলে তোমার প্ল্যান মতো এটা করো"): বন্ধ দিনে পরে যা বসে তা চোখের সামনে, আর কারণ লিখে দিন আবার খোলা।
     */
    public function test_an_entry_after_the_close_is_listed_and_the_day_can_be_reopened_with_a_reason(): void
    {
        $this->theDay();
        $this->post(route('executive.dayclose.close'), ['date' => self::DAY])->assertSessionHasNoErrors();

        // ⓘ পরের দিন সকালে মালিক (পেছনের তারিখের অনুমতি আছে) ১৬ তারিখে একটা ভুলে যাওয়া খরচ বসালেন
        Carbon::setTestNow('2026-11-18 10:00:00');
        $this->book('expense_voucher', [
            ['account_id' => StandardChart::find(StandardChart::BANK_CHARGES)->id, 'debit' => '700', 'branch_id' => $this->a->id],
            ['account_id' => $this->cash->id, 'credit' => '700', 'branch_id' => $this->a->id],
        ]);

        $late = app(DayClosingService::class)->build(Carbon::parse(self::DAY))['late'];
        $this->assertSame(['T-9307'], array_column($late, 'no'), '⛔ দিন বন্ধের পরে বসা কাগজ "পরে যোগ হয়েছে"-তে নেই, বা আগের কাগজও এসেছে');
        $this->assertSame(0, bccomp($late[0]['amount'], '700', 2));
        $this->get(route('executive.dayclose', ['date' => self::DAY]))->assertOk()->assertSee('data-sec="late"', false);

        // ⛔ কারণ ছাড়া খোলা নয়
        $this->post(route('executive.dayclose.reopen'), ['date' => self::DAY])->assertSessionHasErrors('reopen_reason');
        $this->assertTrue($this->isClosed(), '⛔ কারণ ছাড়া দিন খুলে গেল');

        $this->post(route('executive.dayclose.reopen'), ['date' => self::DAY, 'reopen_reason' => 'Three old bills to enter'])->assertSessionHasNoErrors();
        $this->assertFalse($this->isClosed(), '⛔ দিন আবার খুলল না');

        // ⓘ খোলা দিনে অনুমতি ছাড়াও লেখা যায়
        $this->actingAs($this->clerk(['accounts.day_close.view']));
        app(OpenPeriod::class)->assertOpen(self::DAY);

        // ⓘ আবার বন্ধ — একই সারি, খোলার দাগ মোছে
        $this->actingAs($this->owner->fresh());
        $this->post(route('executive.dayclose.close'), ['date' => self::DAY])->assertSessionHasNoErrors();
        $this->assertSame(1, DayClose::query()->count(), '⛔ আবার বন্ধে নতুন সারি হলো');
        $this->assertNull(DayClose::query()->firstOrFail()->reopened_at);
        $this->assertTrue($this->isClosed(), '⛔ আবার বন্ধ হলো না');
    }

    public function test_only_the_reopen_key_reopens_and_the_create_menu_offers_the_close_to_who_may_close(): void
    {
        $this->post(route('executive.dayclose.close'), ['date' => self::DAY])->assertSessionHasNoErrors();

        $clerk = $this->clerk(['accounts.day_close.view', 'accounts.day_close.close']);
        $this->actingAs($clerk);
        $this->post(route('executive.dayclose.reopen'), ['date' => self::DAY, 'reopen_reason' => 'x y z'])->assertForbidden();
        $this->assertTrue($this->isClosed(), '⛔ খোলার চাবি ছাড়াই দিন খুলল');

        $this->assertStringContainsString(route('executive.dayclose'), (string) $this->blade('<x-shell.create-menu />'),
            '⛔ "+" মেনুতে দিন বন্ধের শর্টকাট নেই');

        $this->actingAs($this->clerk(['accounts.day_close.view']));
        $this->assertStringNotContainsString(route('executive.dayclose'), (string) $this->blade('<x-shell.create-menu />'),
            '⛔ দিন বন্ধের চাবি ছাড়াই "+" মেনুতে শর্টকাট');
    }

    /**
     * ⭐ কর্মীর হাতে টাকা — মালিক, ১৮ নভেম্বর ২০২৬: মালিক আর ব্যাংক ছাড়া কর্মীর জিম্মায় নগদ থাকলে দিনের হিসাবে হাইলাইট করে।
     */
    public function test_cash_held_by_staff_is_highlighted_and_the_owners_own_cash_is_not(): void
    {
        $this->theDay(); // ⓘ "Head office cash" মালিকের জিম্মায় — তালিকায় আসবে না

        $cashier = $this->clerk([]);
        $till = app(AccountService::class)->create(['name_en' => 'Counter cash', 'parent_id' => StandardChart::find(StandardChart::CASH_IN_HAND)->id, 'held_by' => $cashier->id]);
        $this->book('receipt_voucher', [
            ['account_id' => $till->id, 'debit' => '12000', 'branch_id' => $this->a->id],
            ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'credit' => '12000', 'party_type' => 'customer', 'party_id' => $this->dealer->id, 'branch_id' => $this->a->id],
        ]);

        $staff = app(DayClosingService::class)->build(Carbon::parse(self::DAY))['staff_cash'];

        $this->assertSame(['Counter cash'], array_column($staff, 'account'), '⛔ কর্মীর জিম্মার নগদ তালিকায় নেই, বা মালিকের নিজের নগদ এসেছে');
        $this->assertSame($cashier->name, $staff[0]['who']);
        $this->assertSame(0, bccomp($staff[0]['amount'], '12000', 2));

        $this->get(route('executive.dayclose', ['date' => self::DAY]))->assertOk()->assertSee('data-staff-cash', false)->assertSee($cashier->name);
    }

    public function test_seeing_and_closing_are_two_keys(): void
    {
        $clerk = $this->clerk([]);
        $this->actingAs($clerk);
        $this->get(route('executive.dayclose'))->assertForbidden();

        $clerk->givePermissionTo(Permission::findOrCreate('accounts.day_close.view', 'web'));
        $this->actingAs($clerk->fresh());
        $this->get(route('executive.dayclose'))->assertOk();
        $this->post(route('executive.dayclose.close'), ['date' => self::DAY])->assertForbidden();
        $this->assertSame(0, DayClose::query()->count(), '⛔ দেখার চাবিতেই দিন বন্ধ হলো');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** ⓘ দিনটা বন্ধ কি না — যে শাখায় বন্ধ হোক (অনুরোধের দেখার শাখায় বন্ধের দাগ বসে) */
    private function isClosed(): bool
    {
        return DayClose::query()->where('day', self::DAY)->whereNull('reopened_at')->exists();
    }

    /**
     * দিনের কাগজ: নগদ রসিদ ২০,০০০ (T-9301), ব্যাংক রসিদ ৫০,০০০ (T-9302), নগদ → ব্যাংক সরানো ৫,০০০ (T-9303, কন্ট্রা),
     * খরচ ১,৫০০ নগদে (T-9304), সরবরাহকারীকে ৪০,০০০ ব্যাংক থেকে (T-9305), আরেকটা ব্যাংকে জমা — নগদ থেকে ৫,০০০ (T-9306)।
     */
    private function theDay(): void
    {
        $receivable = StandardChart::find(StandardChart::RECEIVABLE)->id;
        $this->book('receipt_voucher', [
            ['account_id' => $this->cash->id, 'debit' => '20000', 'branch_id' => $this->a->id],
            ['account_id' => $receivable, 'credit' => '20000', 'party_type' => 'customer', 'party_id' => $this->dealer->id, 'branch_id' => $this->a->id],
        ]);
        $this->book('receipt_voucher', [
            ['account_id' => $this->bank->id, 'debit' => '50000', 'branch_id' => $this->a->id],
            ['account_id' => $receivable, 'credit' => '50000', 'party_type' => 'customer', 'party_id' => $this->dealer->id, 'branch_id' => $this->a->id],
        ]);
        $this->book('contra_voucher', [
            ['account_id' => $this->bank->id, 'debit' => '5000', 'branch_id' => $this->a->id],
            ['account_id' => $this->cash->id, 'credit' => '5000', 'branch_id' => $this->a->id],
        ]);
        $this->book('expense_voucher', [
            ['account_id' => StandardChart::find(StandardChart::BANK_CHARGES)->id, 'debit' => '1500', 'branch_id' => $this->a->id],
            ['account_id' => $this->cash->id, 'credit' => '1500', 'branch_id' => $this->a->id],
        ]);
        $supplier = Supplier::query()->onlySuppliers()->orderBy('id')->firstOrFail();
        $this->book('payment_voucher', [
            ['account_id' => StandardChart::find(StandardChart::PAYABLE)->id, 'debit' => '40000', 'party_type' => 'supplier', 'party_id' => $supplier->id, 'branch_id' => $this->a->id],
            ['account_id' => $this->bank->id, 'credit' => '40000', 'branch_id' => $this->a->id],
        ]);
        $this->book('contra_voucher', [
            ['account_id' => $this->bank->id, 'debit' => '5000', 'branch_id' => $this->a->id],
            ['account_id' => $this->cash->id, 'credit' => '5000', 'branch_id' => $this->a->id],
        ]);
    }

    private function countCash(string $counted, string $book, string $why): void
    {
        $till = CashTill::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->a->id, 'account_id' => $this->cash->id, 'code' => 'TIL-T1',
            'name_en' => 'Head office cash', 'status' => 'active', 'is_active' => true,
        ]);

        CashCount::query()->forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $this->a->id, 'financial_year_id' => FinancialYear::forDate(self::DAY)?->id,
            'document_no' => 'CNT-T1', 'trx_date' => self::DAY, 'cash_till_id' => $till->id,
            'counted_amount' => $counted, 'expected_amount' => $book, 'difference' => bcsub($counted, $book, 4),
            'denominations' => ['1000' => 19, '500' => 1, '200' => 1, '100' => 1, '50' => 1],
            'status' => 'confirmed', 'narration' => $why,
        ]);
    }

    /** @param  list<string>  $keys */
    private function clerk(array $keys): User
    {
        $clerk = User::factory()->create(['is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['default_branch_id' => $this->a->id, 'is_active' => true]);

        foreach ($keys as $key) {
            $clerk->givePermissionTo(Permission::findOrCreate($key, 'web'));
        }

        return $clerk->fresh();
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function book(string $type, array $lines): void
    {
        $id = ++$this->source;
        app(PostingEngine::class)->post($type, $id, self::DAY, $lines, 'T-'.$id, $this->a->id);
    }
}
