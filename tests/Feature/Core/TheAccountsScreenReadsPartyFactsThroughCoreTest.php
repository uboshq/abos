<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Contracts\CreditHolds;
use App\Core\Contracts\PartyCreditLimit;
use App\Core\Contracts\PartyTaxIds;
use App\Core\Contracts\PayeeBankView;
use App\Core\Services\NoPartyCreditLimit;
use App\Core\Services\NoPartyTaxIds;
use App\Core\Services\NoPayeeBankView;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerCreditLimit;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierBankDetail;
use App\Modules\Supplier\Services\SupplierPayeeBankView;
use App\Modules\Supplier\Services\SupplierTaxIds;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ হিসাবের পর্দা পক্ষের তথ্য পড়ে কোরের চুক্তি দিয়ে — গ্রাহক বা সরবরাহকারী মডিউল না ছুঁয়ে (রসিদ-পরিশোধ মিল-তালিকা #১৯,
 * #৬০, #৬৬; ২৫ অক্টোবর ২০২৬)।
 *
 * দাবি:
 *  ১. [[PartyCreditLimit]] — গ্রাহক মডিউলের বাঁধন গ্রাহকের নিজের সীমা, ব্যবহৃত আর অবশিষ্ট দেয় (চলতি সীমা অস্থায়ীসহ; ব্যবহৃত
 *     = খাতার বকেয়া + আটকে থাকা + ক্লিয়ার না হওয়া চেক); শূন্য সীমা "০ — কিছুই বাকি নয়", `null` নয় (সীমা পরম); কোম্পানির
 *     সীমার সুইচ বন্ধ থাকলে `null`।
 *  ২. [[PartyTaxIds]] — সরবরাহকারীর মাস্টারের BIN আর TIN; না থাকলে `null`।
 *  ৩. [[PayeeBankView]] — signed / pending / none, আর ঢাকা লাইন "ব্যাংক ••••7890"; পুরো নম্বর কখনো নয়।
 *  ৪. কোরের তিন ডিফল্ট `null` বলে।
 *  ৫. অন্য কোম্পানির পক্ষ — তিনটাই `null`; আর একই পক্ষ নিজের কোম্পানিতে উত্তর পায়, অর্থাৎ ফেরাল কোম্পানির দেয়ালই।
 */
final class TheAccountsScreenReadsPartyFactsThroughCoreTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    public function test_the_modules_bind_the_real_readers(): void
    {
        $this->assertInstanceOf(CustomerCreditLimit::class, app(PartyCreditLimit::class), '⛔ বাকির সীমার চুক্তি গ্রাহক মডিউলের বাস্তবায়নে বাঁধা নয়');
        $this->assertInstanceOf(SupplierTaxIds::class, app(PartyTaxIds::class), '⛔ BIN/TIN-এর চুক্তি সরবরাহকারী মডিউলের বাস্তবায়নে বাঁধা নয়');
        $this->assertInstanceOf(SupplierPayeeBankView::class, app(PayeeBankView::class), '⛔ প্রাপকের ব্যাংকের চুক্তি সরবরাহকারী মডিউলের বাস্তবায়নে বাঁধা নয়');
    }

    /** ১ — সীমা, ব্যবহৃত, অবশিষ্ট: গ্রাহকের নিজের তিন উত্তর */
    public function test_the_credit_limit_is_the_customers_own_limit_used_and_left(): void
    {
        $customer = Customer::query()->where('code', 'CUS-0001')->firstOrFail();
        $this->assertSame(0, bccomp((string) $customer->credit_limit, '50000', 2), 'প্রস্তুতিটাই ভুল — নমুনার CUS-0001-এর সীমা ৳50,000 নয়');

        // ⓘ বিক্রয়ের আটকে থাকা টাকা আর ক্লিয়ার না হওয়া চেক — জানা অঙ্কে, যাতে "ব্যবহৃত" তিন অংশই মাপা হয়
        $this->app->instance(CreditHolds::class, new class implements CreditHolds
        {
            public function heldFor(int $customerId): string
            {
                return '12000';
            }

            public function unclearedChequesOf(int $customerId): string
            {
                return '500.50';
            }
        });

        $due = (string) (LedgerEntry::query()->forParty('customer', $customer->id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as net')->value('net') ?? '0');
        $used = bcadd(bcadd($due, '12000', 4), '500.50', 2);
        $left = bcsub('50000', $used, 2);

        $this->assertSame(
            ['limit' => '50000.00', 'used' => $used, 'available' => bccomp($left, '0', 2) > 0 ? $left : '0.00'],
            app(PartyCreditLimit::class)->creditOf('customer', $customer->id),
            '⛔ হিসাবের পর্দার বাকির সীমা গ্রাহকের নিজের হিসাব নয় — ব্যবহৃত = বকেয়া + আটকে থাকা + ক্লিয়ার না হওয়া চেক',
        );

        // ⓘ চলতি সীমা — অস্থায়ী সীমা তার শেষ দিন পর্যন্ত ([[Customer::effectiveCreditLimit()]])
        DB::table('customers')->where('id', $customer->id)->update(['temp_credit_limit' => '80000', 'temp_credit_limit_until' => now()->addDay()->toDateString()]);

        $this->assertSame('80000.00', app(PartyCreditLimit::class)->creditOf('customer', $customer->id)['limit'] ?? null,
            '⛔ চালু অস্থায়ী সীমা হিসাবের পর্দায় আসে না — গ্রাহকের পাতা ৳80,000 বলে, পর্দা অন্য কথা');
        $this->assertSame(bcsub('80000', $used, 2), app(PartyCreditLimit::class)->creditOf('customer', $customer->id)['available'] ?? null,
            '⛔ অবশিষ্ট সীমা গ্রাহকের পাতার "অবশিষ্ট সীমা"-র সাথে মেলে না');
    }

    /** ১ক — শূন্য সীমা মানে বাকি নেই (সীমা পরম), সীমাহীন নয় */
    public function test_a_zero_limit_reads_as_no_credit_not_as_no_answer(): void
    {
        $customer = Customer::query()->where('code', 'CUS-0001')->firstOrFail();
        DB::table('customers')->where('id', $customer->id)->update(['credit_limit' => '0', 'temp_credit_limit' => null, 'temp_credit_limit_until' => null]);

        $credit = app(PartyCreditLimit::class)->creditOf('customer', $customer->id);

        $this->assertNotNull($credit, '⛔ শূন্য সীমার গ্রাহকে উত্তর নেই — পর্দা কিছু না দেখালে মনে হত যত খুশি বাকি চলে');
        $this->assertSame('0.00', $credit['limit'], '⛔ শূন্য সীমা "০" পড়ে না');
        $this->assertSame('0.00', $credit['available'], '⛔ শূন্য সীমায় অবশিষ্ট বাকি "০" নয় — মালিকের নিয়মে সীমা না থাকলে বাকি নেই');
    }

    /** ১খ — কোম্পানির সীমার সুইচ বন্ধ: সীমার কথাই নেই */
    public function test_a_company_with_the_credit_switch_off_has_no_limit_to_show(): void
    {
        $customer = Customer::query()->where('code', 'CUS-0001')->firstOrFail();
        $this->assertNotNull(app(PartyCreditLimit::class)->creditOf('customer', $customer->id), 'প্রস্তুতিটাই ভুল — সুইচ চালু অবস্থায়ও উত্তর নেই');

        app(SettingsService::class)->set('customer.credit_limit_enabled', false);

        $this->assertNull(app(PartyCreditLimit::class)->creditOf('customer', $customer->id),
            '⛔ কোম্পানি বাকির সীমা বন্ধ রেখেছে, অথচ হিসাবের পর্দায় সীমা দেখায়');
    }

    /** ১গ — কেবল গ্রাহক; সরবরাহকারীর নম্বর দিলে গ্রাহকের সীমা নয় */
    public function test_the_credit_limit_answers_only_for_a_customer(): void
    {
        $customer = Customer::query()->where('code', 'CUS-0001')->firstOrFail();

        $this->assertNull(app(PartyCreditLimit::class)->creditOf('supplier', $customer->id),
            '⛔ সরবরাহকারীর ধরন দিলে একই নম্বরের গ্রাহকের সীমা এল');
    }

    /** ২ — BIN · TIN সরবরাহকারীর মাস্টার থেকে */
    public function test_the_tax_ids_come_from_the_suppliers_master(): void
    {
        $seeded = Supplier::query()->whereNotNull('bin')->whereNotNull('tin')->orderBy('id')->first();
        $this->assertNotNull($seeded, 'প্রস্তুতিটাই ভুল — নমুনায় BIN আর TIN-সহ কোনো সরবরাহকারী নেই');

        $this->assertSame(['bin' => (string) $seeded->bin, 'tin' => (string) $seeded->tin], app(PartyTaxIds::class)->taxIdsOf('supplier', $seeded->id),
            '⛔ পরিশোধ ভাউচারের BIN · TIN সরবরাহকারীর মাস্টারের নয়');

        $plain = Supplier::query()->create(['code' => 'SUP-TAX1', 'name_en' => 'No Tax Ltd', 'name_bn' => 'No Tax Ltd']);
        $this->assertNull(app(PartyTaxIds::class)->taxIdsOf('supplier', $plain->id), '⛔ BIN/TIN না থাকা সরবরাহকারীর জন্য ফাঁকা লেখা এল, null নয়');

        DB::table('suppliers')->where('id', $plain->id)->update(['bin' => '000999888-0101']);
        $this->assertSame(['bin' => '000999888-0101', 'tin' => null], app(PartyTaxIds::class)->taxIdsOf('supplier', $plain->id),
            '⛔ কেবল BIN থাকলে TIN null নয়');

        $this->assertNull(app(PartyTaxIds::class)->taxIdsOf('customer', $seeded->id), '⛔ গ্রাহকের ধরন দিলে একই নম্বরের সরবরাহকারীর BIN এল');
    }

    /** ৩ — প্রাপকের ব্যাংক: অবস্থা আর ঢাকা লাইন */
    public function test_the_payee_bank_says_its_state_and_a_masked_line(): void
    {
        $signed = SupplierBankDetail::query()->where('status', SupplierBankDetail::SIGNED)->orderBy('id')->first();
        $this->assertNotNull($signed, 'প্রস্তুতিটাই ভুল — নমুনায় সই-পাওয়া কোনো ব্যাংক তথ্য নেই');

        $view = app(PayeeBankView::class)->bankOf('supplier', (int) $signed->supplier_id);
        $this->assertSame(['state' => 'signed', 'line' => $signed->bank_name.' ••••'.$signed->account_last4], $view,
            '⛔ সই-পাওয়া ব্যাংক "signed" আর "ব্যাংক ••••শেষ চার" নয়');
        $this->assertStringNotContainsString((string) $signed->account_no, (string) $view['line'], '⛔ লাইনে পুরো হিসাব নম্বর ফাঁস হল');

        $supplier = Supplier::query()->create(['code' => 'SUP-BNK1', 'name_en' => 'Bankless Ltd', 'name_bn' => 'Bankless Ltd']);
        $this->assertSame(['state' => 'none', 'line' => null], app(PayeeBankView::class)->bankOf('supplier', $supplier->id),
            '⛔ ব্যাংক তথ্যহীন সরবরাহকারী "none" নয়');

        SupplierBankDetail::query()->create([
            'company_id' => $this->company->id, 'supplier_id' => $supplier->id, 'bank_name' => 'Dutch-Bangla', 'account_name' => 'Bankless Ltd',
            'account_no' => '1234567890', 'account_last4' => '7890', 'status' => SupplierBankDetail::PENDING, 'requested_by' => $this->owner->id,
        ]);
        $this->assertSame(['state' => 'pending', 'line' => 'Dutch-Bangla ••••7890'], app(PayeeBankView::class)->bankOf('supplier', $supplier->id),
            '⛔ কেবল সইয়ের অপেক্ষার ব্যাংক "pending" নয় — পর্দা ভাবত টাকা যেতে পারে');
    }

    /** ৪ — কোরের ডিফল্ট */
    public function test_the_core_defaults_say_nothing(): void
    {
        $customer = Customer::query()->where('code', 'CUS-0001')->firstOrFail();
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();

        $this->assertNull((new NoPartyCreditLimit)->creditOf('customer', $customer->id), '⛔ গ্রাহক মডিউল না থাকলেও সীমার কোনো উত্তর এল');
        $this->assertNull((new NoPartyTaxIds)->taxIdsOf('supplier', $supplier->id), '⛔ সরবরাহকারী মডিউল না থাকলেও BIN/TIN এল');
        $this->assertNull((new NoPayeeBankView)->bankOf('supplier', $supplier->id), '⛔ সরবরাহকারী মডিউল না থাকলেও ব্যাংকের উত্তর এল');
    }

    /** ৫ — কোম্পানির দেয়াল: অন্য কোম্পানির পক্ষ null, নিজের কোম্পানিতে একই পক্ষ উত্তর পায় */
    public function test_a_party_of_another_company_reads_null(): void
    {
        $other = Company::query()->where('code', 'FMART')->firstOrFail();

        $customer = Customer::query()->create(['company_id' => $other->id, 'code' => 'FM-CUS-1', 'name_en' => 'Far Mart Shop', 'credit_limit' => '90000', 'is_active' => true]);
        $supplier = Supplier::query()->create(['company_id' => $other->id, 'code' => 'FM-SUP-1', 'name_en' => 'Far Mart Mill', 'name_bn' => 'Far Mart Mill']);
        DB::table('suppliers')->where('id', $supplier->id)->update(['bin' => '000777666-0101', 'tin' => '7776665554']);
        SupplierBankDetail::query()->create([
            'company_id' => $other->id, 'supplier_id' => $supplier->id, 'bank_name' => 'City Bank', 'account_name' => 'Far Mart Mill',
            'account_no' => '5555666677', 'account_last4' => '6677', 'status' => SupplierBankDetail::SIGNED,
            'requested_by' => $this->owner->id, 'signed_by' => $this->owner->id, 'signed_at' => now(),
        ]);

        $this->assertNull(app(PartyCreditLimit::class)->creditOf('customer', $customer->id), '⛔ অন্য কোম্পানির গ্রাহকের বাকির সীমা এই কোম্পানির পর্দায় এল');
        $this->assertNull(app(PartyTaxIds::class)->taxIdsOf('supplier', $supplier->id), '⛔ অন্য কোম্পানির সরবরাহকারীর BIN/TIN এই কোম্পানির পর্দায় এল');
        $this->assertNull(app(PayeeBankView::class)->bankOf('supplier', $supplier->id), '⛔ অন্য কোম্পানির সরবরাহকারীর ব্যাংক এই কোম্পানির পর্দায় এল');

        // ⓘ ফেরাল দেয়ালই — একই তিন পক্ষ নিজের কোম্পানিতে উত্তর পায়
        CompanyContext::set($other->id, $other->defaultBranch()?->id);

        $this->assertSame('90000.00', app(PartyCreditLimit::class)->creditOf('customer', $customer->id)['limit'] ?? null,
            '⛔ নিজের কোম্পানিতেও গ্রাহকের সীমা আসে না — উপরের null দেয়ালের নয়, অন্য কিছুর');
        $this->assertSame(['bin' => '000777666-0101', 'tin' => '7776665554'], app(PartyTaxIds::class)->taxIdsOf('supplier', $supplier->id),
            '⛔ নিজের কোম্পানিতেও BIN/TIN আসে না — উপরের null দেয়ালের নয়');
        $this->assertSame(['state' => 'signed', 'line' => 'City Bank ••••6677'], app(PayeeBankView::class)->bankOf('supplier', $supplier->id),
            '⛔ নিজের কোম্পানিতেও ব্যাংক আসে না — উপরের null দেয়ালের নয়');
    }
}
