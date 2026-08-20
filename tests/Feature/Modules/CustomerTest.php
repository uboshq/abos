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
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Customer মডিউল — Phase 2-এর ভিত্তি-পরীক্ষা (সেকশন ২.৩)।
 *
 * এই মডিউলটার কাজ শুধু গ্রাহক রাখা নয়; এর কাজ প্রমাণ করা যে Phase 1-এ
 * বানানো জিনিসগুলো সত্যিই একটা মডিউল ধরে রাখতে পারে। তাই এখানকার
 * পরীক্ষাগুলো গ্রাহকের চেয়ে ভিত্তির দিকেই বেশি তাকায়: টেন্যান্ট স্কোপ,
 * নম্বর সিরিজ, সেটিংস সুইচ, অনুমতি, নরম মুছে ফেলা, drill-down।
 */
class CustomerTest extends TestCase
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

    private function make(array $overrides = []): Customer
    {
        return app(CustomerService::class)->create([
            'name_en' => 'Karim Store',
            'credit_limit' => 0,
            'credit_days' => 0,
            ...$overrides,
        ]);
    }

    // ── কোড ও নম্বর সিরিজ ──────────────────────────────────────────────

    public function test_a_blank_code_comes_from_the_number_series(): void
    {
        $first = $this->make();

        /*
         * নামটা "Rahim Traders" ছিল, আর ওটা DemoSeeder-এ আগে থেকেই
         * আছে। নকল-পাহারা বসার পর এই টেস্ট লাল হয়ে যায় — ঠিকই, কারণ
         * ওটা সত্যিই একটা নকল নাম ছিল।
         *
         * এখানকার প্রশ্নটা নাম নিয়ে নয়, নম্বর সিরিজ নিয়ে; তাই নামটা
         * এমন কিছুতে বদলানো হলো যা ডেমো ডাটায় নেই।
         */
        $second = $this->make(['name_en' => 'Nabanna Distribution']);

        $this->assertNotSame($first->code, $second->code);
        $this->assertStringStartsWith('CUS-', $first->code);
    }

    public function test_a_given_code_is_kept_as_typed(): void
    {
        $this->assertSame('LEGACY-42', $this->make(['code' => ' LEGACY-42 '])->code);
    }

    public function test_two_customers_cannot_share_a_code(): void
    {
        $this->make(['code' => 'DUP-1']);

        $this->expectException(ValidationException::class);

        $this->make(['code' => 'DUP-1', 'name_en' => 'Someone Else']);
    }

    /**
     * সেভ ব্যর্থ হলে কোডটা খরচ হয়ে যায় না।
     *
     * নম্বর ইস্যু করা হয় ট্রানজেকশনের ভেতরে, তাই রোলব্যাকে সিরিজটাও
     * পিছিয়ে যায়। বাইরে থাকলে প্রতিটা ব্যর্থ চেষ্টায় একটা করে কোড হারাত,
     * আর অডিটে ফাঁক দেখা যেত যার কোনো ব্যাখ্যা থাকত না।
     */
    public function test_a_failed_save_does_not_burn_a_code(): void
    {
        $before = $this->make()->code;

        try {
            $this->make(['code' => $before, 'name_en' => 'Clash']);
        } catch (ValidationException) {
            // প্রত্যাশিত
        }

        $this->assertSame(
            (int) substr($before, -4) + 1,
            (int) substr($this->make(['name_en' => 'Next'])->code, -4),
        );
    }

    // ── সেটিংস সুইচ (নিয়ম ৭) ──────────────────────────────────────────

    public function test_a_bangla_name_is_optional_until_the_setting_says_otherwise(): void
    {
        $this->assertNotNull($this->make());

        app(SettingsService::class)->set('customer.require_bn_name', true);

        $this->expectException(ValidationException::class);

        $this->make(['name_en' => 'No Bangla Name']);
    }

    // ── টেন্যান্ট বিচ্ছিন্নতা ───────────────────────────────────────────

    public function test_one_company_never_sees_another_companys_customers(): void
    {
        $mine = $this->make();

        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($other->id, $other->defaultBranch()?->id);

        $this->assertNull(Customer::query()->find($mine->id));
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_the_same_code_may_exist_in_two_companies(): void
    {
        $this->make(['code' => 'SHARED-1']);

        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($other->id, $other->defaultBranch()?->id);

        // কোডের অনন্যতা কোম্পানির ভেতরে, সারা সিস্টেমে নয় — দুই ভিন্ন
        // প্রতিষ্ঠানের গ্রাহকদের নম্বর মেলাতে বাধ্য করার কোনো কারণ নেই।
        $this->assertSame('SHARED-1', $this->make(['code' => 'SHARED-1'])->code);
    }

    // ── বকেয়া ও ক্রেডিট সীমা ───────────────────────────────────────────

    public function test_outstanding_is_the_opening_balance_plus_the_ledger(): void
    {
        $customer = $this->make(['opening_balance' => '1000.0000', 'opening_date' => '2026-07-01']);

        $this->entry($customer, debit: '500.0000');
        $this->entry($customer, credit: '200.0000');

        $this->assertSame('1300.0000', $customer->outstanding());
    }

    /**
     * খোলা ব্যালেন্স খাতায় বসে, শুধু গ্রাহকের সারিতে নয়।
     *
     * আগে বসত না, আর তাতে গ্রাহকের পাতায় পাওনা দেখাত অথচ ট্রায়াল
     * ব্যালেন্সে অঙ্কটা কোথাও থাকত না। দাখিলাটা দুই লাইনের — প্রাপ্য
     * ডেবিট, সঞ্চিত মুনাফা ক্রেডিট — তাই খাতা মেলাও থাকে।
     */
    public function test_an_opening_balance_reaches_the_ledger_as_a_real_entry(): void
    {
        $customer = $this->make(['opening_balance' => '1000.0000', 'opening_date' => '2026-07-01']);

        $lines = LedgerEntry::query()
            ->where('source_type', 'customer'.OpeningBalanceService::SOURCE_SUFFIX)
            ->where('source_id', $customer->id)
            ->get();

        $this->assertCount(2, $lines, 'খোলা ব্যালেন্স দুই লাইনের দাখিলা।');

        // দাখিলাটা ভারসাম্যপূর্ণ — দুই দিকে সমান। যোগফলটা bccomp দিয়ে
        // মেলানো হয়, স্ট্রিং তুলনায় নয়: Collection::sum() দশমিকের শূন্য
        // ফেলে দেয় ("1000.0000" নয়, "1000"), আর ওটা অঙ্কের ভুল নয়।
        $this->assertSame(0, bccomp((string) $lines->sum('debit'), '1000', 4));
        $this->assertSame(0, bccomp((string) $lines->sum('credit'), '1000', 4));

        // পক্ষের লাইনটাই গ্রাহকের নামে — অন্যটা সঞ্চিত মুনাফা, নির্দিষ্ট
        // কারও নয়, তাই তাতে party_id থাকে না
        $this->assertSame(
            1,
            $lines->where('party_type', 'customer')->where('party_id', $customer->id)->count(),
        );
    }

    public function test_a_zero_opening_balance_posts_nothing_at_all(): void
    {
        $customer = $this->make(['opening_balance' => '0.0000']);

        // শূন্যের দাখিলা বসালে লেজারে দুইটা শূন্য সারি থাকত, যা পড়ার
        // সময় শুধু বিভ্রান্ত করে
        $this->assertFalse(app(OpeningBalanceService::class)->exists('customer', $customer->id));
    }

    public function test_a_zero_credit_limit_means_unlimited_not_blocked(): void
    {
        $customer = $this->make(['credit_limit' => 0]);

        $this->assertFalse($customer->wouldExceedCreditLimit('999999.0000'));
    }

    public function test_a_real_credit_limit_is_enforced_on_the_total_not_the_bill(): void
    {
        $customer = $this->make(['credit_limit' => '5000.0000']);

        $this->entry($customer, debit: '4000.0000');

        // একটা ২০০০ টাকার বিল নিজে সীমার নিচে, কিন্তু আগের ৪০০০-এর সাথে
        // যোগ হলে ছাড়িয়ে যায় — সীমাটা মোটের উপর, বিলের উপর নয়।
        $this->assertTrue($customer->wouldExceedCreditLimit('2000.0000'));
        $this->assertFalse($customer->wouldExceedCreditLimit('900.0000'));
    }

    // ── নরম মুছে ফেলা (নিয়ম ৫) ────────────────────────────────────────

    public function test_deleting_deactivates_and_keeps_the_record(): void
    {
        $customer = $this->make();

        $this->actingAs($this->user)
            ->delete(route('customer.destroy', $customer))
            ->assertRedirect(route('customer.index'));

        $customer->refresh();

        $this->assertFalse($customer->is_active);
        $this->assertNotNull(Customer::query()->find($customer->id), 'রেকর্ডটা থেকে যাবে — নিয়ম ৫।');
    }

    public function test_the_list_hides_inactive_customers_unless_asked(): void
    {
        $active = $this->make(['name_en' => 'Active Shop']);
        $gone = $this->make(['name_en' => 'Closed Shop']);
        $gone->forceFill(['is_active' => false])->save();

        $this->actingAs($this->user)
            ->get(route('customer.index'))
            ->assertSee('Active Shop')
            ->assertDontSee('Closed Shop');

        $this->actingAs($this->user)
            ->get(route('customer.index', ['inactive' => 1]))
            ->assertSee('Closed Shop');

        $this->assertTrue($active->is_active);
    }

    // ── অনুমতি (অলঙ্ঘনীয় শর্ত ৪) ──────────────────────────────────────

    public function test_a_user_without_the_permission_cannot_reach_any_screen(): void
    {
        $stranger = User::factory()->create();
        $stranger->companies()->attach($this->company, ['is_active' => true]);
        $stranger->forceFill(['current_company_id' => $this->company->id])->save();

        $customer = $this->make();

        $this->actingAs($stranger)->get(route('customer.index'))->assertForbidden();
        $this->actingAs($stranger)->get(route('customer.show', $customer))->assertForbidden();
        $this->actingAs($stranger)->get(route('customer.create'))->assertForbidden();
        $this->actingAs($stranger)->delete(route('customer.destroy', $customer))->assertForbidden();
    }

    public function test_view_permission_alone_does_not_allow_creating(): void
    {
        $reader = User::factory()->create();
        $reader->companies()->attach($this->company, ['is_active' => true]);
        $reader->forceFill(['current_company_id' => $this->company->id])->save();
        $reader->givePermissionTo(Permission::findOrCreate('customer.view', 'web'));

        $this->actingAs($reader)->get(route('customer.index'))->assertOk();
        $this->actingAs($reader)->get(route('customer.create'))->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('customer.index'))->assertRedirect(route('login'));
    }

    // ── স্ক্রিন ─────────────────────────────────────────────────────────

    public function test_the_show_screen_lists_the_entries_behind_the_outstanding_figure(): void
    {
        $customer = $this->make(['opening_balance' => '1000.0000']);

        $this->entry($customer, debit: '500.0000', documentNo: 'INV-2026-2027-0001');
        $this->entry($customer, credit: '300.0000', documentNo: 'RCV-2026-2027-0001');

        $response = $this->actingAs($this->user)->get(route('customer.show', $customer));

        $response->assertOk()
            ->assertSee('INV-2026-2027-0001')
            ->assertSee('RCV-2026-2027-0001')
            // খোলা ব্যালেন্সও একটা সারি — নাহলে প্রথম ব্যালেন্স ১৫০০
            // দেখাত অথচ ডেবিট ৫০০, আর বাকি ১০০০-এর কোনো ব্যাখ্যা থাকত না।
            // সারিটা এখন লেজারের সত্যিকারের দাখিলা, কৃত্রিম নয়।
            ->assertSee(__('accounts::message.opening_balance'), false)
            // চলমান ব্যালেন্স: ১০০০ + ৫০০ = ১৫০০, তারপর − ৩০০ = ১২০০
            ->assertSee('1,500.00')
            ->assertSee('1,200.00');
    }

    public function test_a_customer_with_no_opening_balance_gets_no_opening_row(): void
    {
        $customer = $this->make(['opening_balance' => '0.0000']);
        $this->entry($customer, debit: '500.0000');

        $this->actingAs($this->user)
            ->get(route('customer.show', $customer))
            ->assertOk()
            ->assertDontSee(__('accounts::message.opening_balance'), false);
    }

    public function test_creating_through_the_screen_works_end_to_end(): void
    {
        $this->actingAs($this->user)
            ->post(route('customer.store'), [
                'name_en' => 'Screen Store',
                'name_bn' => 'স্ক্রিন স্টোর',
                'phone' => '01711000000',
                'credit_limit' => '2500.00',
                'credit_days' => 15,
                'opening_balance' => '0',
            ])
            ->assertRedirect();

        $customer = Customer::query()->where('name_en', 'Screen Store')->firstOrFail();

        $this->assertSame('স্ক্রিন স্টোর', $customer->name('bn'));
        $this->assertSame('Screen Store', $customer->name('en'));
        $this->assertSame(DocumentStatus::CONFIRMED, $customer->status);
        $this->assertSame($this->user->id, $customer->created_by);
    }

    public function test_the_opening_balance_cannot_be_changed_after_creation(): void
    {
        $customer = $this->make(['opening_balance' => '1000.0000']);

        $this->actingAs($this->user)
            ->put(route('customer.update', $customer), [
                'name_en' => 'Karim Store',
                'opening_balance' => '99999.00',
            ])
            ->assertRedirect();

        // খোলা ব্যালেন্স বদলালে লেজার আর এই সংখ্যাটা দুই রকম বলত, আর
        // কোনটা সত্যি তা বলার উপায় থাকত না — বদলাতে হলে জাবেদা ভাউচার।
        $this->assertSame('1000.0000', $customer->fresh()->opening_balance);
    }

    /**
     * লগইন থেকে শুরু করে একটা রেকর্ডের পাতা — প্রসঙ্গ নিজে না বসিয়ে।
     *
     * বাকি পরীক্ষাগুলো setUp()-এ CompanyContext::set() ডাকে, যা আসল
     * রিকোয়েস্টে কখনো ঘটে না — আর সেটাই একটা সত্যিকারের বাগ ঢেকে
     * রেখেছিল: ResolveCompanyContext চলত রুট-মডেল বাইন্ডিং-এর পরে, তাই
     * /customers/4 খুললে বাইন্ডিং কোম্পানি ছাড়া Customer খুঁজত আর পাতাটা
     * ৫০০ দিত। তালিকার পাতায় কোনো {model} নেই বলে ওটা কাজ করত, আর
     * ব্রাউজারে না দেখলে ধরাই পড়ত না।
     *
     * তাই এখানে ইচ্ছাকৃতভাবে প্রসঙ্গ মুছে ফেলা হয়।
     */
    public function test_a_record_screen_works_on_a_fresh_request_without_a_preset_context(): void
    {
        $customer = $this->make();

        CompanyContext::clear();

        $this->post(route('login'), [
            'identifier' => 'owner@abos.test',
            'password' => 'password',
        ])->assertRedirect();

        $this->get(route('customer.show', $customer))->assertOk()->assertSee($customer->code);
        $this->get(route('customer.edit', $customer))->assertOk();
    }

    // ── drill-down (নিয়ম ১) ────────────────────────────────────────────

    public function test_a_customer_can_be_reached_from_any_figure_that_names_it(): void
    {
        $customer = $this->make();

        $this->assertSame('customer', Customer::drillSourceType());
        $this->assertSame($customer->code, $customer->drillDocumentNo());
        $this->assertSame(['customer.show', ['customer' => $customer->id]], $customer->drillRoute());
    }

    // ── কে কত দিল ───────────────────────────────────────────────────────

    /**
     * আদায়ের রিপোর্ট বিক্রয় আর আদায় আলাদা করে দেখায়।
     *
     * ── কেন বকেয়ার তালিকা দিয়ে এই প্রশ্নের উত্তর হয় না ──────────────
     * এই গ্রাহক এ মাসে ৫,০০০ টাকার মাল নিয়েছেন আর ৫,০০০ দিয়েছেন। তাঁর
     * বকেয়া ঠিক যেখানে ছিল সেখানেই — অথচ তিনি মাসের সবচেয়ে ভালো
     * পরিশোধকারী। বকেয়ার তালিকা এটা বলতেই পারে না; দুইটা কলাম আলাদা
     * লাগে।
     */
    public function test_the_collection_report_separates_billing_from_paying(): void
    {
        $customer = $this->make();

        $this->entry($customer, debit: '5000.0000');
        $this->entry($customer, credit: '5000.0000');

        $rows = app(ReportEngine::class)
            ->run('customer.collection', ['from' => '2026-08-01', 'to' => '2026-08-31'])
            ->rows;

        $mine = collect($rows)->firstWhere('party_id', $customer->id);

        $this->assertNotNull($mine, 'গ্রাহকটা তালিকায় নেই।');
        $this->assertSame(0, bccomp((string) $mine['billed'], '5000', 4));
        $this->assertSame(0, bccomp((string) $mine['collected'], '5000', 4));

        // নিট শূন্য — বকেয়া বাড়েওনি, কমেওনি
        $this->assertSame(0, bccomp((string) $mine['movement'], '0', 4));
    }

    /** যে গ্রাহকের এই সময়ে কিছুই হয়নি, তাঁর সারি আসে না। */
    public function test_a_quiet_customer_stays_out_of_the_collection_report(): void
    {
        $quiet = $this->make(['name_en' => 'Quiet Shop']);

        $rows = app(ReportEngine::class)
            ->run('customer.collection', ['from' => '2026-08-01', 'to' => '2026-08-31'])
            ->rows;

        $this->assertNull(collect($rows)->firstWhere('party_id', $quiet->id));
    }

    private function entry(Customer $customer, string $debit = '0', string $credit = '0', ?string $documentNo = null): void
    {
        LedgerEntry::create([
            'company_id' => $customer->company_id,
            'branch_id' => $customer->branch_id,
            'financial_year_id' => $this->company->currentFinancialYear()?->id,
            'account_id' => 1,
            'party_type' => Customer::drillSourceType(),
            'party_id' => $customer->id,
            'trx_date' => '2026-08-01',
            'debit' => $debit,
            'credit' => $credit,
            // প্রতিটা লাইনে উৎস থাকতেই হবে (নিয়ম ১) — কলামটা nullable নয়,
            // তাই "কোথা থেকে এল" জানা নেই এমন সারি লেখাই যায় না।
            'source_type' => 'sales_invoice',
            'source_id' => 1,
            'document_no' => $documentNo ?? 'TEST-0001',
            'narration' => 'test',
        ]);
    }
}
