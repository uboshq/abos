<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DealerScope;
use App\Core\Services\SettingsService;
use App\Core\Support\AmountInWords;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\AuditFieldChange;
use App\Models\AuditTrail;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Approval\Services\OwnerSignsDiscounts;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\DealerBinding;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\CollectionApplication;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * দফা ১, ২, ৩, ৪, ৫, ৭, ৮, ৯, ১০, ১২, ১৩, ১৪, ১৫, ১৬, ১৭, ১৮, ২২, ২৫ — টাকা নেওয়ার পর্দা খাতায় যা লেখে (মালিকের নকশা ১০০,
 * ২১ অক্টোবর ২০২৬; a6)।
 *
 * ⓘ সব দাবি ওয়েবের দরজায় (`sales.collection.store`, "পোস্ট" = `post=1`; প্রিভিউ `sales.collection.preview`), খাতার সারি মেপে।
 * প্রতিটা দাবির নামে নকশার দফার নম্বর (`test_item_N_…`), আর প্রতিটা assert নিজের বাংলা কারণ বলে — নিয়ম ভাঙলে সেটাই পড়া যায়।
 * ⓘ পর্দার আঁকা আলাদা ফাইলে ([[TheCollectionScreenIsDrawnLikeTheDesignTest]]); ফোনের চুক্তি tests/Feature/Api/Collection*Test।
 * ⚠️ সহায়কের নাম `book()`, `post()` নয় — TestCase-এর HTTP `post()`-এর সাথে সংঘর্ষে গোটা ফাইল Fatal হত।
 */
final class TheCollectionFormPostsWhatTheDesignSaysTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $maker;

    private Customer $dealer;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->maker = $this->person(['sales.collection.create', 'sales.collection.view', 'sales.collection.update', 'sales.collection.cancel']);

        $this->bank = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1102-CF', 'name_en' => 'Collection bank', 'name_bn' => 'আদায়ের ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
        ]);

        $this->dealer = Customer::query()->create(['code' => 'CF-1', 'name_en' => 'Karim Store', 'name_bn' => 'করিম স্টোর', 'is_active' => true]);
    }

    // ══ দফা ৫ · ৯ · ২৫ — খাতার প্রতিটা লাইন ════════════════════════════════

    /** দফা ৫ — গ্রাহক কেটে রাখা VDS ১১৩৮-এ, AIT ১১৩৫-এ; ব্যাংকে যা এল তা-ই; গ্রাহকের পাওনা কমে পুরো অঙ্কে */
    public function test_item_5_vds_goes_to_1138_ait_to_1135_and_the_customer_is_credited_the_gross(): void
    {
        $bill = $this->bill('INV-CF-1', 10, '10000');

        $collection = $this->book(['amount' => '9000', 'vds' => '750', 'ait' => '250',
            'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '10000']]]);

        $this->assertSame(DocumentStatus::CONFIRMED, $collection->status, '⛔ দফা ৫: আদায়টা পোস্ট হয়নি — '.json_encode(session('errors')?->all()));
        $this->assertSame('750.0000', $this->dr(StandardChart::VDS_RECEIVABLE, $collection), '⛔ দফা ৫: গ্রাহকের কাটা VDS ১১৩৮-এ Dr হয়নি');
        $this->assertSame('250.0000', $this->dr(StandardChart::ADVANCE_INCOME_TAX, $collection), '⛔ দফা ৫: গ্রাহকের কাটা AIT ১১৩৫-এ Dr হয়নি');
        $this->assertSame('9000.0000', $this->drOn($this->bank->id, $collection), '⛔ দফা ৫: ব্যাংকে যা এল খাতায় তা Dr হয়নি');
        $this->assertSame('10000.0000', $this->customerCr($collection), '⛔ দফা ৫: গ্রাহকের পাওনা পুরো ১০,০০০ (টাকা + VDS + AIT) কমেনি');
        $this->assertSame(0, bccomp($bill->fresh()->dueAmount(), '0', 4), '⛔ দফা ৫: কাটা অংশসহ বিলটা শোধ দেখায় না');
        $this->balanced($collection);
    }

    /** দফা ৯ — চার্জ টাকার ভিতরে: ব্যাংকে অঙ্ক − চার্জ, চার্জ ৫২১০-এ, গ্রাহক পুরো অঙ্কে */
    public function test_item_9_the_bank_charge_sits_inside_the_amount(): void
    {
        $bill = $this->bill('INV-CF-5', 10, '5000');

        $collection = $this->book(['amount' => '5000', 'instrument' => 'transfer', 'instrument_no' => 'NP-CF-9', 'bank_charge' => '25',
            'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '5000']]]);

        $this->assertSame(DocumentStatus::CONFIRMED, $collection->status, '⛔ দফা ৯: আদায়টা পোস্ট হয়নি — '.json_encode(session('errors')?->all()));
        $this->assertSame('4975.0000', $this->drOn($this->bank->id, $collection), '⛔ দফা ৯: ব্যাংকে চার্জ বাদে আসেনি');
        $this->assertSame('25.0000', $this->dr(StandardChart::BANK_CHARGES, $collection), '⛔ দফা ৯: চার্জ ব্যাংক চার্জের খাতে নয়');
        $this->assertSame('5000.0000', $this->customerCr($collection), '⛔ দফা ৯: গ্রাহকের পাওনা পুরো অঙ্কে কমেনি');
        $this->balanced($collection);
    }

    /** দফা ২৫ — চার ঘরের পয়সা পর্যন্ত হুবহু (bcmath), প্রতিটা কাগজে Dr = Cr */
    public function test_item_25_money_is_exact_to_four_places_and_every_paper_balances(): void
    {
        $collection = $this->book(['amount' => '1000.1234', 'vds' => '0.0001', 'lines_given' => '1']);

        $this->assertSame(DocumentStatus::CONFIRMED, $collection->status, '⛔ দফা ২৫: আদায়টা পোস্ট হয়নি — '.json_encode(session('errors')?->all()));
        $this->assertSame('1000.1234', $this->drOn($this->bank->id, $collection), '⛔ দফা ২৫: ব্যাংকের অঙ্ক চার ঘর পর্যন্ত হুবহু নয় (float-এর গোলমাল?)');
        $this->assertSame('1000.1235', $this->customerCr($collection), '⛔ দফা ২৫: গ্রাহকের Cr = টাকা + VDS, পয়সা পর্যন্ত হুবহু নয়');
        $this->balanced($collection);
    }

    // ══ দফা ৪ — কম বা বেশি টাকা ═════════════════════════════════════════

    /** দফা ৪ — রাউন্ডিং সীমার ভিতরে (৳১০) ৫৩০৫-এ, সই ছাড়াই পোস্ট */
    public function test_item_4_rounding_within_the_limit_posts_to_5305_without_a_signature(): void
    {
        $bill = $this->bill('INV-CF-2', 10, '1010');

        $collection = $this->book(['amount' => '1000', 'difference' => ['kind' => 'rounding', 'amount' => '10'],
            'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '1010']]]);

        $this->assertSame(DocumentStatus::CONFIRMED, $collection->status, '⛔ দফা ৪: সীমার ভিতরের (৳১০) রাউন্ডিং সই ছাড়া পোস্ট হয়নি — '.json_encode(session('errors')?->all()));
        $this->assertSame('10.0000', $this->dr(StandardChart::ROUNDING_DIFFERENCE, $collection), '⛔ দফা ৪: রাউন্ডিং ৫৩০৫-এ Dr হয়নি');
        $this->assertSame('0.0000', $this->dr(StandardChart::COLLECTION_DISCOUNT, $collection), '⛔ দফা ৪: রাউন্ডিং ছাড়ের খাতে (৪১২০) গেল');
        $this->assertSame('1010.0000', $this->customerCr($collection), '⛔ দফা ৪: রাউন্ডিংসহ গ্রাহকের পাওনা কমেনি');
        $this->assertSame(0, Approval::query()->where('action', 'discount')->count(), '⛔ দফা ৪: রাউন্ডিংয়ে সই চাওয়া হলো — fe-র D3-এ রাউন্ডিংয়ে সই নেই');
        $this->balanced($collection);
    }

    /** দফা ৪ — ৳১০-এর বেশি রাউন্ডিং ফেরত, বাংলায় সীমা বলে; কোনো কাগজ বা খাতার সারি বসে না */
    public function test_item_4_rounding_above_the_limit_is_refused_in_bengali(): void
    {
        $bill = $this->bill('INV-CF-3', 10, '1011');
        $before = Collection::query()->count();

        $this->send(['amount' => '1000', 'difference' => ['kind' => 'rounding', 'amount' => '11'], 'post' => '1',
            'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '1011']]]);

        $this->assertSame($before, Collection::query()->count(), '⛔ দফা ৪: সীমার বেশি (৳১১) রাউন্ডিং নিয়েও কাগজ বসল');
        $this->assertSame(__('sales::collection_rules.rounding_over_limit', ['amount' => '11.00', 'limit' => '10.00']), $this->said('difference'),
            '⛔ দফা ৪: সীমার বেশি রাউন্ডিংয়ের বাংলা কারণ (সীমা ৳১০) বলেনি');
    }

    /** দফা ৪ — বিলের চেয়ে বেশি টাকা: বাকিটা অপ্রয়োগকৃত, গ্রাহকের নিজের খাতায় Cr; অগ্রিমের কোনো খাতে নয় */
    public function test_item_4_an_overpayment_stays_unapplied_as_credit_on_the_customers_own_ledger(): void
    {
        $bill = $this->bill('INV-CF-4', 10, '700');

        $collection = $this->book(['amount' => '1000', 'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '700']]]);

        $this->assertSame(DocumentStatus::CONFIRMED, $collection->status, '⛔ দফা ৪: আদায়টা পোস্ট হয়নি');
        $this->assertSame(0, bccomp((string) $collection->unapplied, '300', 4), '⛔ দফা ৪: অপ্রয়োগকৃত ৩০০ কাগজে লেখা হয়নি');
        $this->assertSame('1000.0000', $this->customerCr($collection), '⛔ দফা ৪: বাড়তি ৩০০ গ্রাহকের নিজের খাতায় Cr হয়নি');

        $entries = LedgerEntry::query()->where('source_type', Collection::drillSourceType())->where('source_id', $collection->id)->get();
        $this->assertCount(2, $entries, '⛔ দফা ৪: টাকা আর গ্রাহক ছাড়া তৃতীয় কোনো খাত (যেমন অগ্রিম) এল');
        $this->assertSame([(int) $this->bank->id, (int) StandardChart::find(StandardChart::RECEIVABLE)->id],
            $entries->sortBy('id')->pluck('account_id')->map(fn ($id) => (int) $id)->values()->all(),
            '⛔ দফা ৪: অপ্রয়োগকৃত অংশ গ্রাহকের পাওনার খাত (১১১০) ছাড়া অন্য খাতে গেল — মালিকের নিয়মে অগ্রিমের খাত নিষেধ');
    }

    /**
     * দফা ৪ — আদায়ে ছাড় মালিকের সইয়ে: কর্তার "পোস্ট" খসড়ায় থামে আর সই চায়; অন্য কেউ (মালিক নন) সই দিতে পারেন না; মালিক
     * সই দিলে ৪১২০-এ Dr, আর লাভ-ক্ষতির বিক্রয় ভাগে নিট বিক্রি ঠিক ছাড়টুকু কমে (৪১০০ − ৪১১০ − ৪১২০, IFRS 15)।
     */
    public function test_item_4_a_discount_waits_for_the_owner_and_then_posts_to_4120_and_cuts_net_sales(): void
    {
        app(OwnerSignsDiscounts::class)->ensure($this->company);
        $bill = $this->bill('INV-CF-D', 10, '1000');
        $grossBefore = $this->plGross();

        $collection = $this->bookAllowingStops(['amount' => '950', 'difference' => ['kind' => 'discount', 'amount' => '50'],
            'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '1000']]]);

        $this->assertSame(DocumentStatus::DRAFT, $collection->status, '⛔ দফা ৪: মালিকের সই ছাড়াই ছাড়সহ আদায় পোস্ট হলো');
        $this->assertSame('0.0000', $this->dr(StandardChart::COLLECTION_DISCOUNT, $collection), '⛔ দফা ৪: সইয়ের আগেই ছাড় খাতায় উঠল');
        $approval = Approval::query()->where('action', 'discount')->where('status', Approval::PENDING)->latest('id')->first();
        $this->assertNotNull($approval, '⛔ দফা ৪: ছাড়ের সইয়ের অনুরোধ যায়নি');
        $this->assertSame(0, bccomp((string) $approval->amount, '50', 4), '⛔ দফা ৪: সইয়ের অনুরোধ ছাড়ের অঙ্কের নয়');

        // ⛔ মালিক নন এমন কেউ (সইয়ের চাবিসহ) — সই পড়ে না
        $manager = $this->person(['approval.decide', 'approval.view', 'sales.collection.view']);
        $refused = false;
        try {
            app(ApprovalEngine::class)->approve($approval->fresh(), $manager);
        } catch (\RuntimeException) {
            $refused = true;
        }
        $this->assertTrue($refused, '⛔ দফা ৪: মালিক নন এমন একজনের সইয়ে আদায়ের ছাড় পার হলো');
        $this->assertSame(Approval::PENDING, $approval->fresh()->status, '⛔ দফা ৪: অন্যের সইয়ে ছাড়ের অনুরোধ মিটে গেল');

        // ⛔ কর্তা আবার "পোস্ট" চাপলেও সই ছাড়া যায় না
        $this->actingAs($this->maker)->post(route('sales.collection.confirm', $collection))->assertSessionHasErrors('difference');
        $this->assertSame(DocumentStatus::DRAFT, $collection->fresh()->status, '⛔ দফা ৪: কর্তা নিজে আবার চেপে সই ছাড়া ছাড় পার করলেন');

        // ⭐ মালিক সই দিলেন — শেষ সইয়ে আদায় নিজে পোস্ট হয় ([[ConfirmTheCollectionOnTheLastSignature]]); না হলে কর্তা চাপেন
        app(ApprovalEngine::class)->approve($approval->fresh(), $this->owner);
        if ($collection->fresh()->status === DocumentStatus::DRAFT) {
            $this->actingAs($this->maker)->post(route('sales.collection.confirm', $collection))->assertSessionHasNoErrors();
        }

        $collection = $collection->fresh();
        $this->assertSame(DocumentStatus::CONFIRMED, $collection->status, '⛔ দফা ৪: মালিকের সইয়ের পরেও আদায় পোস্ট হলো না');
        $this->assertSame('50.0000', $this->dr(StandardChart::COLLECTION_DISCOUNT, $collection), '⛔ দফা ৪: ছাড় ৪১২০-এ (বিক্রয়ের বিপরীত) Dr হয়নি');
        $this->assertSame('0.0000', $this->dr(StandardChart::DISCOUNT_GIVEN, $collection), '⛔ দফা ৪: আদায়ের ছাড় খরচের খাতে (৫৩০০) গেল — IFRS 15-এ বিক্রয়ের বিপরীত');
        $this->assertSame('1000.0000', $this->customerCr($collection), '⛔ দফা ৪: ছাড়সহ গ্রাহকের পাওনা পুরো বিলে কমেনি');
        $this->balanced($collection);

        // ⭐ নিট বিক্রি = ৪১০০ − ৪১১০ − ৪১২০ — লাভ-ক্ষতির বিক্রয় ভাগে ছাড়টুকুই কমে (মালিকের চোখে, আগের মাপের মতোই)
        $this->actingAs($this->owner);
        $this->assertSame(0, bccomp(bcsub($grossBefore, $this->plGross(), 4), '50', 4), '⛔ দফা ৪: লাভ-ক্ষতির মোট মুনাফা আদায়ের ছাড় (৪১২০) বাদ দিয়ে গোনে না');
        $row = collect($this->plReport()->rows)->firstWhere('account_id', (int) StandardChart::find(StandardChart::COLLECTION_DISCOUNT)->id);
        $this->assertNotNull($row, '⛔ দফা ৪: লাভ-ক্ষতিতে ৪১২০ নেই');
        $this->assertSame(__('accounts::message.pl_revenue'), $row['section'], '⛔ দফা ৪: ৪১২০ বিক্রয় ভাগে নয় — নিট বিক্রি ভুল');
        $this->get(route('accounts.report.show', ['slug' => 'profit-loss', 'from' => now()->toDateString(), 'to' => now()->toDateString()]))->assertOk();
    }

    /** দফা ৪ — অনুমোদন বন্ধ কোম্পানিতেও (UB-র মতো) ছাড় সই ছাড়া যায় না: বাংলায় ফেরত, খসড়া, খাতায় কিছু নয় (fail-closed, বিলের ছাড়ের মতো) */
    public function test_item_4_with_approvals_switched_off_a_discount_is_refused_not_waved_through(): void
    {
        app(OwnerSignsDiscounts::class)->ensure($this->company);
        ApprovalFlow::query()->update(['is_active' => false]);
        $bill = $this->bill('INV-CF-OFF', 10, '1000');

        $collection = $this->bookAllowingStops(['amount' => '990',
            'difference' => ['kind' => 'discount', 'amount' => '10'],
            'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '1000']]]);

        $this->assertSame(DocumentStatus::DRAFT, $collection->status, '⛔ দফা ৪: অনুমোদন বন্ধে ছাড় সই ছাড়াই পোস্ট হলো');
        $this->assertSame(0, LedgerEntry::query()->where('source_type', Collection::drillSourceType())->where('source_id', $collection->id)->count(),
            '⛔ দফা ৪: সই ছাড়া ছাড়ের আদায় খাতায় উঠল');
        $this->assertSame(__('sales::validation.discount_no_signer'), $this->said('difference'), '⛔ দফা ৪: ছাড়ে সই দেওয়ার কেউ নেই — বাংলায় বলেনি');
    }

    // ══ দফা ৩ — খোলা বিল মেলানো ═════════════════════════════════════════

    /** দফা ৩ — আগের আদায়ের অপ্রয়োগকৃত অংশ বিলে বসে: বিলের বাকি কমে, ক্রেডিটের খোলা অংশ কমে, নতুন টাকা কেবল বাকিটুকু */
    public function test_item_3_a_prior_unapplied_credit_clears_a_bill_without_new_money(): void
    {
        $first = $this->book(['amount' => '500', 'lines_given' => '1']);
        $this->assertSame(0, bccomp((string) $first->unapplied, '500', 4), '⛔ দফা ৩: কোনো বিল না বাছায় পুরোটা অপ্রয়োগকৃত হয়নি');

        $bill = $this->bill('INV-CF-7', 5, '1200');

        $second = $this->book(['amount' => '700', 'lines' => [
            ['kind' => 'credit', 'id' => $first->public_id, 'amount' => '-500'],
            ['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '1200'],
        ]]);

        $this->assertSame(DocumentStatus::CONFIRMED, $second->status, '⛔ দফা ৩: ক্রেডিটসহ আদায় পোস্ট হয়নি — '.json_encode(session('errors')?->all()));
        $this->assertSame(0, bccomp($bill->fresh()->dueAmount(), '0', 4), '⛔ দফা ৩: ক্রেডিট (৫০০) আর টাকায় (৭০০) বিলটা শোধ দেখায় না');
        $this->assertSame('700.0000', $this->customerCr($second), '⛔ দফা ৩: ক্রেডিটের কাটাকাটি নতুন টাকা হিসেবে খাতায় বসল');
        $this->assertSame(1, CollectionApplication::query()->where('collection_id', $second->id)->where('kind', 'credit')
            ->where('target_invoice_id', $bill->id)->count(), '⛔ দফা ৩: ক্রেডিটটা বিলে বসার লেখা নেই');

        $open = $this->actingAs($this->maker)->getJson(route('sales.collection.open_items', ['customer' => $this->dealer->id]))->json('items');
        $this->assertSame([], array_values(array_filter($open, fn ($i) => $i['id'] === $first->public_id)), '⛔ দফা ৩: খরচ হওয়া ক্রেডিট এখনো খোলা দেখায়');
    }

    /** দফা ৩ — নতুন পর্দায় সব টিক তুললে কোনো বিলে বসে না; সবটা অপ্রয়োগকৃত */
    public function test_item_3_no_ticked_bill_means_no_bill_is_paid(): void
    {
        $bill = $this->bill('INV-CF-8', 10, '400');

        $collection = $this->book(['amount' => '400', 'lines_given' => '1']);

        $this->assertSame(0, bccomp($bill->fresh()->dueAmount(), '400', 4), '⛔ দফা ৩: টিক না দিলেও বিলে টাকা বসল');
        $this->assertSame(0, bccomp((string) $collection->unapplied, '400', 4), '⛔ দফা ৩: টিকহীন আদায় অপ্রয়োগকৃত হয়নি');
    }

    /** দফা ৩ — একটা বিলে তার বাকির বেশি বসানো যায় না: বাংলায় ফেরত, কাগজ নয় */
    public function test_item_3_more_than_a_bills_due_is_refused(): void
    {
        $bill = $this->bill('INV-CF-OVER', 10, '300');
        $before = Collection::query()->count();

        $this->send(['amount' => '500', 'post' => '1', 'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '500']]]);

        $this->assertSame($before, Collection::query()->count(), '⛔ দফা ৩: বিলের বাকির (৩০০) বেশি (৫০০) বসিয়েও কাগজ বসল');
        $this->assertSame(__('sales::validation.over_allocated', ['no' => 'INV-CF-OVER', 'due' => '300.00']), $this->said('lines'),
            '⛔ দফা ৩: বিলের বাকির বেশি — বাংলায় কারণ বলেনি');
    }

    /** দফা ৩ — সারির চিহ্ন তার ধরনের: ধনাত্মক ক্রেডিট (a4-এর প্রশ্ন ১) বাংলায় ফেরত */
    public function test_item_3_a_credit_line_with_a_plus_sign_is_refused(): void
    {
        $first = $this->book(['amount' => '200', 'lines_given' => '1']);
        $bill = $this->bill('INV-CF-SIGN', 5, '500');
        $before = Collection::query()->count();

        $this->send(['amount' => '300', 'post' => '1', 'lines' => [
            ['kind' => 'credit', 'id' => $first->public_id, 'amount' => '200'],
            ['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '500'],
        ]]);

        $this->assertSame($before, Collection::query()->count(), '⛔ দফা ৩: ধনাত্মক ক্রেডিটের সারি নীরবে মেনে কাগজ বসল');
        $this->assertSame(__('sales::collection_rules.sign_mismatch', ['kind' => 'credit', 'amount' => '200']), $this->said('lines'),
            '⛔ দফা ৩: চিহ্ন ভুলের বাংলা কারণ বলেনি');
    }

    // ══ দফা ১০ — খাতার প্রিভিউ ══════════════════════════════════════════

    /** দফা ১০ — প্রিভিউর লাইন = পোস্টের লাইন: একই খাত, একই অঙ্ক, Dr = Cr, আর প্রিভিউ কিছুই লেখে না */
    public function test_item_10_the_preview_shows_exactly_the_lines_the_post_writes(): void
    {
        $bill = $this->bill('INV-CF-6', 10, '2010');
        $form = $this->form(['amount' => '1800', 'vds' => '150', 'ait' => '50', 'difference' => ['kind' => 'rounding', 'amount' => '10'],
            'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '2010']]]);
        $papers = Collection::query()->count();
        $rows = LedgerEntry::query()->count();

        $preview = $this->actingAs($this->maker)->postJson(route('sales.collection.preview'), $form)->assertOk()->json();

        $this->assertSame([$papers, $rows], [Collection::query()->count(), LedgerEntry::query()->count()], '⛔ দফা ১০: প্রিভিউ কাগজ বা খাতায় কিছু লিখল');
        $this->assertSame([], $preview['stops'], '⛔ দফা ১০: বৈধ আদায়ের প্রিভিউ থামার কারণ বলল');
        $this->assertTrue($preview['summary']['balanced'], '⛔ দফা ১০: মিলেছে বলেনি');
        $this->assertSame($preview['total_dr'], $preview['total_cr'], '⛔ দফা ১০: প্রিভিউর Dr ≠ Cr');

        $collection = $this->book(array_diff_key($form, ['customer_id' => 1, 'account_id' => 1, 'trx_date' => 1]));

        $codes = Account::query()->pluck('code', 'id');
        $posted = LedgerEntry::query()->where('source_type', Collection::drillSourceType())->where('source_id', $collection->id)
            ->orderBy('id')->get()
            ->map(fn (LedgerEntry $e) => [(string) $codes[(int) $e->account_id], bcadd((string) $e->debit, '0', 4), bcadd((string) $e->credit, '0', 4)])
            ->sort()->values()->all();
        $shown = collect($preview['lines'])
            ->map(fn (array $l) => [explode(' ', $l['account'])[0], $l['dr'], $l['cr']])
            ->sort()->values()->all();

        $this->assertCount(5, $shown, '⛔ দফা ১০: প্রিভিউতে পাঁচ লাইন নেই (ব্যাংক, VDS, AIT, রাউন্ডিং, গ্রাহক)');
        $this->assertSame($posted, $shown, '⛔ দফা ১০: প্রিভিউ যা দেখাল খাতায় তা বসেনি');
    }

    // ══ দফা ১, ২, ৭, ৮ ═════════════════════════════════════════════════

    /** দফা ১ — খোলা কাগজের বকেয়া = খাতার বকেয়া; বিল পুরনো আগে, দেরির দিনসহ; সবচেয়ে পুরনো বাকির দিন মাথায় */
    public function test_item_1_open_items_say_the_ledger_due_and_list_the_oldest_bill_first(): void
    {
        // ⓘ গ্রাহকের শাখা আছে — তবেই "নাম · পয়েন্ট · শাখা" আর শুধু নাম আলাদা হয়
        $this->dealer->forceFill(['branch_id' => CompanyContext::branchId()])->save();
        $newer = $this->bill('INV-CF-NEW', 3, '200');
        $older = $this->bill('INV-CF-OLD', 40, '300', dueDaysAgo: 9);

        $json = $this->actingAs($this->maker)->getJson(route('sales.collection.open_items', ['customer' => $this->dealer->id]))->assertOk()->json();

        $this->assertSame($this->dealer->fresh()->outstanding(), $json['due'], '⛔ দফা ১: পর্দার বকেয়া খাতার বকেয়া (Customer::outstanding) নয়');
        $this->assertSame(['INV-CF-OLD', 'INV-CF-NEW'], array_column(array_values(array_filter($json['items'], fn ($i) => $i['kind'] === 'bill')), 'no'),
            '⛔ দফা ১: বিল পুরনো আগে নয়');
        $this->assertSame(9, $json['items'][0]['overdue_days'], '⛔ দফা ১: দেরির দিন ভুল');
        $this->assertSame(9, $json['customer']['oldest_overdue_days'], '⛔ দফা ১: সবচেয়ে পুরনো বাকির দিন ভুল');
        $this->assertSame($older->public_id, $json['items'][0]['id'], '⛔ দফা ১: খোলা কাগজের id বাইরের (public_id) নয়');
        $this->assertSame($newer->public_id, $json['items'][1]['id']);
        $this->assertSame($this->dealer->fresh()->nameWithPoint(), $json['customer']['label'], '⛔ দফা ১: গ্রাহক নাম · পয়েন্ট · শাখা নয়');
    }

    /** দফা ২ — পদ্ধতি বাছলে কেবল তার ঘর থাকে: নগদে TrxID আর চার্জ সার্ভারেই মুছে যায় */
    public function test_item_2_cash_keeps_no_reference_and_no_charge(): void
    {
        $collection = $this->book(['amount' => '600', 'instrument' => 'cash', 'instrument_no' => 'LEFT-OVER', 'bank_charge' => '20', 'lines_given' => '1']);

        $this->assertSame(DocumentStatus::CONFIRMED, $collection->status, '⛔ দফা ২: নগদের আদায় পোস্ট হয়নি — '.json_encode(session('errors')?->all()));
        $this->assertNull($collection->instrument_no, '⛔ দফা ২: নগদে অন্য পদ্ধতির TrxID রয়ে গেল');
        $this->assertSame(0, bccomp((string) $collection->bank_charge, '0', 4), '⛔ দফা ২: নগদে ব্যাংক চার্জ রয়ে গেল');
        $this->assertSame('600.0000', $this->drOn($this->bank->id, $collection), '⛔ দফা ২: নগদে চার্জ কেটে খাতে কম এল');
    }

    /** দফা ৭ — একই খাতে একই রেফারেন্স (বড়-ছোট হাতের অক্ষর আর ফাঁকা বাদে) দ্বিতীয়বার নয়; আগের কাগজের নম্বর আর ঠিকানা বলে */
    public function test_item_7_the_same_reference_on_the_same_account_is_refused_with_the_earlier_paper(): void
    {
        $first = $this->book(['amount' => '300', 'instrument' => 'transfer', 'instrument_no' => 'np26-ref-1', 'lines_given' => '1']);
        $other = Customer::query()->create(['code' => 'CF-2', 'name_en' => 'Other Store', 'is_active' => true]);
        $before = Collection::query()->count();

        // ⓘ অন্য গ্রাহক, বড় হাতের অক্ষর, দুই পাশে ফাঁকা — তবু একই খাতে একই রেফারেন্স
        $this->send(['customer_id' => $other->id, 'amount' => '300', 'instrument' => 'transfer', 'instrument_no' => ' NP26-REF-1 ', 'lines_given' => '1']);

        $this->assertSame($before, Collection::query()->count(), '⛔ দফা ৭: একই খাতে একই রেফারেন্সে একই টাকা দুবার নেওয়া গেল');
        $said = $this->said('instrument_no');
        $this->assertStringContainsString($first->document_no, $said, '⛔ দফা ৭: আগের কাগজের নম্বর বলেনি');
        $this->assertStringContainsString(route('sales.collection.show', $first->id), $said, '⛔ দফা ৭: আগের কাগজের ঠিকানা দেয়নি');
    }

    /** দফা ৮ — আদায়ের কাগজে চেক নয়: চেক যায় চেকের খাতায় ("ক্লিয়ারিং-এ"), পাশের দিন খাতায় — বাংলায় ফেরত */
    public function test_item_8_a_cheque_is_sent_to_the_cheque_register(): void
    {
        $before = Collection::query()->count();

        $this->send(['amount' => '800', 'instrument' => 'cheque', 'instrument_no' => 'CHQ-1101', 'lines_given' => '1', 'post' => '1']);

        $this->assertSame($before, Collection::query()->count(), '⛔ দফা ৮: চেক পাশের আগেই আদায়ের কাগজে টাকা ধরা হলো');
        $this->assertSame(__('accounts::validation.cheque_only_through_register'), $this->said('instrument'), '⛔ দফা ৮: চেকের খাতায় পাঠানোর কথা বলেনি');
    }

    // ══ দফা ১২, ১৩, ১৪, ১৫, ১৬, ১৭, ১৮, ২২ ═══════════════════════════

    /** দফা ১২ — নিজের বানানো পাকা আদায় নিজে উল্টানো যায় না; দ্বিতীয় জন কারণ লিখে উল্টান; উল্টো দাখিলা উল্টানোর দিনে, আগের সারি অক্ষত */
    public function test_item_12_the_maker_cannot_reverse_a_posted_collection_and_a_second_person_reverses_on_the_day(): void
    {
        $day = now()->subDays(3)->toDateString();
        $collection = $this->book(['amount' => '600', 'lines_given' => '1', 'trx_date' => $day]);
        $this->assertSame(DocumentStatus::CONFIRMED, $collection->status, '⛔ দফা ১২: আদায়টাই পোস্ট হয়নি');

        $this->actingAs($this->maker)->post(route('sales.collection.cancel', $collection), ['reason' => 'ভুল খাত']);
        $this->assertSame(DocumentStatus::CONFIRMED, $collection->fresh()->status, '⛔ দফা ১২: যিনি বানালেন তিনিই উল্টে দিলেন');
        $this->assertSame(__('sales::collection_rules.second_person_cancels'), $this->said('reason'), '⛔ দফা ১২: দ্বিতীয় জন লাগবে — বাংলায় বলেনি');

        // ⛔ পোস্টের পরে সম্পাদনার দরজা বন্ধ ([[CollectionPolicy::update()]]; সেবাও ফেরায়)
        $this->actingAs($this->maker)->put(route('sales.collection.update', $collection), $this->form(['amount' => '6000', 'lines_given' => '1', 'trx_date' => $day]))
            ->assertForbidden();
        $this->assertSame(0, bccomp((string) $collection->fresh()->amount, '600', 4), '⛔ দফা ১২: পোস্টের পরে আদায় বদলানো গেল');

        $checker = $this->person(['sales.collection.view', 'sales.collection.cancel']);
        $this->actingAs($checker)->post(route('sales.collection.cancel', $collection), ['reason' => 'ভুল খাত'])->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::CANCELLED, $collection->fresh()->status, '⛔ দফা ১২: দ্বিতীয় জনও উল্টাতে পারলেন না');
        $rows = LedgerEntry::query()->where('source_type', Collection::drillSourceType())->where('source_id', $collection->id)->get();
        $this->assertSame(0, bccomp((string) $rows->sum('debit'), (string) $rows->sum('credit'), 4), '⛔ দফা ১২: উল্টানোর পরে কাগজের Dr ≠ Cr');
        $this->assertSame(2, $rows->filter(fn ($r) => $r->trx_date->toDateString() === $day)->count(), '⛔ দফা ১২: আগের দিনের সারি বদলে গেল বা সরল');
        $this->assertSame(2, $rows->filter(fn ($r) => $r->trx_date->toDateString() === now()->toDateString())->count(), '⛔ দফা ১২: উল্টো দাখিলা উল্টানোর দিনে বসেনি');
        $this->assertSame(0, bccomp($this->drOn($this->bank->id, $collection), '0', 4), '⛔ দফা ১২: উল্টানোর পরে ব্যাংকে টাকা রয়ে গেল');
    }

    /** দফা ১৩ — বন্ধ মাসে পোস্ট হয় না: প্রিভিউ কারণ বলে, পোস্ট খসড়ায় থামে, খাতায় কিছু নয়, খোলা কাগজ বলে মাস বন্ধ */
    public function test_item_13_a_closed_month_stops_the_post_and_the_preview_says_why(): void
    {
        $lock = PeriodLock::query()->create([
            'company_id' => $this->company->id, 'year' => (int) now()->year, 'month' => (int) now()->month,
            'reason' => 'মাস শেষের হিসাব জমা', 'locked_by' => $this->owner->id, 'locked_at' => now(),
        ]);
        $closed = __('core.period.month_closed', ['month' => $lock->label(), 'reason' => $lock->reason]);

        $preview = $this->actingAs($this->maker)->postJson(route('sales.collection.preview'), $this->form(['amount' => '500', 'lines_given' => '1']))
            ->assertOk()->json();
        $this->assertContains($closed, $preview['stops'], '⛔ দফা ১৩: বন্ধ মাসের কথা প্রিভিউ বলেনি');

        $this->assertFalse($this->actingAs($this->maker)->getJson(route('sales.collection.open_items', ['customer' => $this->dealer->id]))->json('month_open'),
            '⛔ দফা ১৩: খোলা কাগজ বলল মাস খোলা');

        $collection = $this->bookAllowingStops(['amount' => '500', 'lines_given' => '1']);
        $this->assertSame(DocumentStatus::DRAFT, $collection->status, '⛔ দফা ১৩: বন্ধ মাসে আদায় পোস্ট হলো');
        $this->assertSame(0, LedgerEntry::query()->where('source_type', Collection::drillSourceType())->where('source_id', $collection->id)->count(),
            '⛔ দফা ১৩: বন্ধ মাসের খাতায় সারি বসল');
    }

    /** দফা ১৪ — আদায় বসে গ্রাহকের শাখায়, মাথায় বাছা শাখায় নয়: কাগজ আর খাতার প্রতিটা সারি */
    public function test_item_14_the_collection_sits_in_the_customers_branch(): void
    {
        $other = $this->otherBranch();
        $this->dealer->forceFill(['branch_id' => $other->id])->save();

        $collection = $this->book(['amount' => '450', 'lines_given' => '1']);

        $this->assertSame(DocumentStatus::CONFIRMED, $collection->status, '⛔ দফা ১৪: আদায়টা পোস্ট হয়নি — '.json_encode(session('errors')?->all()));
        $this->assertSame((int) $other->id, (int) $collection->branch_id, '⛔ দফা ১৪: আদায় গ্রাহকের শাখায় বসেনি');
        $branches = LedgerEntry::query()->where('source_type', Collection::drillSourceType())->where('source_id', $collection->id)
            ->pluck('branch_id')->map(fn ($b) => (int) $b)->unique()->values()->all();
        $this->assertSame([(int) $other->id], $branches, '⛔ দফা ১৪: খাতার সারি গ্রাহকের শাখার বাইরে বসল');
    }

    /** দফা ২২ (বানানো হয়নি, D4) — অন্য শাখার টিলে আদায় আজকের মতো বাংলায় কারণসহ ফেরত; আন্তঃশাখার কোনো সারি নয় */
    public function test_item_22_is_not_built_so_another_branchs_till_is_refused_in_bengali(): void
    {
        $other = $this->otherBranch();
        $till = app(CashTillService::class)->create(['name_en' => 'Other branch box', 'holder_id' => $this->owner->id]);
        $till->forceFill(['branch_id' => $other->id])->save();
        $before = Collection::query()->count();

        $this->send(['account_id' => $till->account_id, 'amount' => '250', 'lines_given' => '1', 'post' => '1']);

        $this->assertSame($before, Collection::query()->count(), '⛔ দফা ২২: অন্য শাখার টিলে আদায় বসল — আন্তঃশাখার হিসাব মালিকের সিদ্ধান্তের অপেক্ষায়');
        $this->assertSame(__('sales::collection_rules.other_branch_account'), $this->said('account_id'), '⛔ দফা ২২: অন্য শাখার খাত — বাংলায় কারণ বলেনি');
    }

    /** দফা ১৫ — কোম্পানি স্লিপ বাধ্যতামূলক করলে স্লিপ ছাড়া পোস্ট নয়; স্লিপসহ পোস্ট হয় আর স্লিপ কাগজের সাথে থাকে */
    public function test_item_15_a_required_slip_stops_the_post_until_it_is_attached(): void
    {
        Storage::fake('local');
        app(SettingsService::class)->set('sales.collection.attachment_required', true);

        $without = $this->bookAllowingStops(['amount' => '350', 'lines_given' => '1']);
        $this->assertSame(DocumentStatus::DRAFT, $without->status, '⛔ দফা ১৫: স্লিপ ছাড়া পোস্ট হলো');
        $this->assertSame(__('sales::collection_rules.attachment_required'), (string) session('errors')?->first('slip'), '⛔ দফা ১৫: স্লিপ লাগবে — বলেনি');

        $with = $this->book(['amount' => '360', 'lines_given' => '1', 'slip' => UploadedFile::fake()->image('slip.png', 40, 30)]);
        $this->assertSame(DocumentStatus::CONFIRMED, $with->status, '⛔ দফা ১৫: স্লিপসহও পোস্ট হলো না — '.json_encode(session('errors')?->all()));
        $this->assertTrue(\App\Models\Attachment::query()->where('source_entity', Collection::drillSourceType())->where('source_entity_id', $with->id)->exists(),
            '⛔ দফা ১৫: স্লিপটা কাগজের সাথে রইল না');
    }

    /** দফা ১৬ — নিরীক্ষার খাতা: কে বানাল, কে পোস্ট করল, কে উল্টাল, কখন, কারণ, আর অবস্থার আগের-পরের মান */
    public function test_item_16_the_audit_names_who_made_posted_and_reversed_with_old_and_new(): void
    {
        $collection = $this->book(['amount' => '250', 'lines_given' => '1']);
        $checker = $this->person(['sales.collection.view', 'sales.collection.cancel']);
        $this->actingAs($checker)->post(route('sales.collection.cancel', $collection), ['reason' => 'দুবার লেখা'])->assertSessionHasNoErrors();

        $trail = fn (string $action) => AuditTrail::query()->where('auditable_type', Collection::class)
            ->where('auditable_id', $collection->id)->where('action', $action)->latest('id')->first();

        $this->assertSame((int) $this->maker->id, (int) $trail(AuditTrail::CREATED)?->user_id, '⛔ দফা ১৬: কে বানাল — অডিটে নেই');
        $this->assertSame((int) $this->maker->id, (int) $trail(AuditTrail::CONFIRMED)?->user_id, '⛔ দফা ১৬: কে পোস্ট করল — অডিটে নেই');
        $cancelled = $trail(AuditTrail::CANCELLED);
        $this->assertSame((int) $checker->id, (int) $cancelled?->user_id, '⛔ দফা ১৬: কে উল্টাল — অডিটে নেই');
        $this->assertSame('দুবার লেখা', (string) $cancelled?->reason, '⛔ দফা ১৬: উল্টানোর কারণ অডিটে নেই');
        $this->assertNotNull($cancelled?->created_at, '⛔ দফা ১৬: কখন — অডিটে নেই');
        $status = AuditFieldChange::query()->where('audit_trail_id', $cancelled?->id)->where('field', 'status')->first();
        $this->assertSame([DocumentStatus::CONFIRMED, DocumentStatus::CANCELLED], [(string) $status?->old_value, (string) $status?->new_value],
            '⛔ দফা ১৬: অবস্থার আগের আর পরের মান অডিটে নেই');
    }

    /** দফা ১৭ — টাকার রসিদ: টাকা কথায়, কোন বিলে কত, গ্রাহকের কাটা VDS/AIT আর অপ্রয়োগকৃত */
    public function test_item_17_the_receipt_prints_words_bills_withheld_and_unapplied(): void
    {
        $bill = $this->bill('INV-CF-RCPT', 10, '8000');
        $collection = $this->book(['amount' => '9000', 'vds' => '750', 'ait' => '250',
            'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '8000']]]);
        $this->assertSame(DocumentStatus::CONFIRMED, $collection->status, '⛔ দফা ১৭: আদায়টা পোস্ট হয়নি');

        $seen = [];
        View::composer('print.document', function ($view) use (&$seen) {
            $seen = $view->getData();
        });
        $this->actingAs($this->owner)->get(route('sales.print.receipt', $collection))->assertOk();
        $this->assertNotSame([], $seen, 'রসিদের ছাঁচ ডাকাই হয়নি');
        $html = view('print.document', $seen)->render();

        $this->assertTrue(str_contains($html, AmountInWords::of('9000.0000', 'bn')) || str_contains($html, AmountInWords::of('9000.0000', 'en')),
            '⛔ দফা ১৭: রসিদে টাকা কথায় নেই');
        $this->assertStringContainsString('INV-CF-RCPT', $html, '⛔ দফা ১৭: রসিদে কোন বিলে বসল তা নেই');
        $this->assertStringContainsString('8,000.00', $html, '⛔ দফা ১৭: রসিদে বিলের অঙ্ক নেই');
        $this->assertStringContainsString(__('sales::collection_form.receipt.vds'), $html, '⛔ দফা ১৭: রসিদে গ্রাহকের কাটা VDS নেই');
        $this->assertStringContainsString(__('sales::collection_form.receipt.ait'), $html, '⛔ দফা ১৭: রসিদে গ্রাহকের কাটা AIT নেই');
        $this->assertStringContainsString(__('sales::collection_form.receipt.unapplied'), $html, '⛔ দফা ১৭: রসিদে অপ্রয়োগকৃত (২,০০০) নেই');
        $this->assertStringContainsString('2,000.00', $html, '⛔ দফা ১৭: রসিদে অপ্রয়োগকৃতের অঙ্ক নেই');
    }

    /** দফা ১৮ — দেয়ালের SR কেবল নিজের ডিলারের খোলা কাগজ দেখেন; অন্যের ডিলার ৪০৪, কিছুই ফাঁস নয় */
    public function test_item_18_a_walled_salesman_opens_only_his_own_dealers_items(): void
    {
        app(SettingsService::class)->set(DealerScope::SWITCH, true);
        $stranger = Customer::query()->create(['code' => 'CF-X', 'name_en' => 'Not His Store', 'is_active' => true]);
        $this->bill('INV-CF-SR', 10, '900');

        $sr = $this->person(['sales.collection.create', 'sales.collection.view', DealerScope::OWN]);
        DealerBinding::query()->create(['company_id' => $this->company->id, 'user_id' => $sr->id, 'customer_id' => $this->dealer->id,
            'starts_on' => now()->subDay()->toDateString(), 'created_by' => $this->owner->id]);
        app(DealerScope::class)->forget();
        $this->actingAs($sr);
        $this->assertTrue(app(DealerScope::class)->walled(), 'প্রস্তুতিটাই ভুল — SR দেয়ালে নেই');

        $this->getJson(route('sales.collection.open_items', ['customer' => $this->dealer->id]))->assertOk()
            ->assertJsonPath('customer.id', (string) $this->dealer->public_id);
        $this->getJson(route('sales.collection.open_items', ['customer' => $stranger->id]))
            ->assertNotFound();
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $extra @return array<string, mixed> */
    private function form(array $extra): array
    {
        return [
            'customer_id' => $this->dealer->id,
            'account_id' => $this->bank->id,
            'trx_date' => now()->toDateString(),
            ...$extra,
        ];
    }

    /** "পোস্ট" — খসড়া লিখে খাতায়, কর্তা হিসেবে (মালিক নন); কাগজটাই না বসলে দাবি নিজের কথায় লাল (ডেমোর পুরনো আদায় নয়) */
    private function book(array $extra): Collection
    {
        $last = (int) Collection::acrossBranches()->max('id');

        $this->actingAs($this->maker)->post(route('sales.collection.store'), [...$this->form($extra), 'post' => '1'])->assertRedirect();

        // ⓘ শাখা পেরিয়ে — আদায় বসে গ্রাহকের শাখায় (দফা ১৪), মাথার শাখায় নয়
        $paper = Collection::acrossBranches()->where('id', '>', $last)->latest('id')->first();
        $this->assertNotNull($paper, '⛔ আদায়ের কাগজটাই বসেনি — '.json_encode(session('errors')?->all(), JSON_UNESCAPED_UNICODE));

        return $paper;
    }

    /** দরজায় পাঠানো, ফেরত হলেও — কারণ `session('errors')`-এ; দাবি নিজে মাপে কী বসল আর কী বলল */
    private function send(array $extra): void
    {
        $this->actingAs($this->maker)->post(route('sales.collection.store'), $this->form($extra));
    }

    private function said(string $field): string
    {
        return (string) session('errors')?->first($field);
    }

    /** "পোস্ট" — থামলে (সই, বন্ধ মাস, স্লিপ) খসড়াটাই ফেরে; থামার কারণ `session('errors')`-এ */
    private function bookAllowingStops(array $extra): Collection
    {
        return $this->book($extra);
    }

    private function bill(string $no, int $daysAgo, string $total, ?int $dueDaysAgo = null): SalesInvoice
    {
        return SalesInvoice::query()->create(['document_no' => $no, 'customer_id' => $this->dealer->id,
            'branch_id' => CompanyContext::branchId(),
            'trx_date' => now()->subDays($daysAgo)->toDateString(),
            'due_on' => $dueDaysAgo === null ? now()->addDays(10)->toDateString() : now()->subDays($dueDaysAgo)->toDateString(),
            'subtotal' => $total, 'discount' => '0', 'tax' => '0', 'total' => $total, 'status' => DocumentStatus::CONFIRMED]);
    }

    private function otherBranch(): Branch
    {
        return Branch::query()->where('company_id', $this->company->id)->whereKeyNot($this->company->defaultBranch()->id)->first()
            ?? Branch::query()->create(['company_id' => $this->company->id, 'code' => 'CFB2', 'name_en' => 'Collection branch two', 'is_active' => true]);
    }

    private function dr(string $code, Collection $collection): string
    {
        return $this->drOn((int) StandardChart::find($code)?->id, $collection);
    }

    private function drOn(int $accountId, Collection $collection): string
    {
        return bcadd((string) LedgerEntry::query()->where('source_type', Collection::drillSourceType())->where('source_id', $collection->id)
            ->where('account_id', $accountId)->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as n')->value('n'), '0', 4);
    }

    private function customerCr(Collection $collection): string
    {
        return bcadd((string) LedgerEntry::query()->where('source_type', Collection::drillSourceType())->where('source_id', $collection->id)
            ->where('party_type', 'customer')->where('party_id', $collection->customer_id)
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as n')->value('n'), '0', 4);
    }

    private function balanced(Collection $collection): void
    {
        $sum = LedgerEntry::query()->where('source_type', Collection::drillSourceType())->where('source_id', $collection->id)
            ->selectRaw('COALESCE(SUM(debit), 0) as dr, COALESCE(SUM(credit), 0) as cr')->first();

        $this->assertSame(0, bccomp((string) $sum->dr, (string) $sum->cr, 4), '⛔ দফা ২৫: কাগজে Dr ≠ Cr');
    }

    private function plReport(): object
    {
        return app(ReportEngine::class)->run('accounts.profit_loss', ['from' => now()->toDateString(), 'to' => now()->toDateString()], 1, 500);
    }

    private function plGross(): string
    {
        return bcadd((string) ($this->plReport()->totals['gross'] ?? '0'), '0', 4);
    }

    /** @param  list<string>  $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->owner->current_company_id]);
        $user->companies()->attach($this->owner->current_company_id, ['is_active' => true]);
        CompanyContext::forCompany((int) $this->owner->current_company_id, fn () => $user->givePermissionTo(
            array_map(fn (string $k) => \Spatie\Permission\Models\Permission::findOrCreate($k, 'web'), $keys)));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
