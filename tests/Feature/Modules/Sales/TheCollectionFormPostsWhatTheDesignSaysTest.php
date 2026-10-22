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

        $this->assertSame(DocumentStatus::CONFIRMED, $collection->status, '⛔ দফা ৫: আদায়টা পোস্ট হয়নি — '.json_encode($this->errors()?->all()));
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

        $this->assertSame(DocumentStatus::CONFIRMED, $collection->status, '⛔ দফা ৯: আদায়টা পোস্ট হয়নি — '.json_encode($this->errors()?->all()));
        $this->assertSame('4975.0000', $this->drOn($this->bank->id, $collection), '⛔ দফা ৯: ব্যাংকে চার্জ বাদে আসেনি');
        $this->assertSame('25.0000', $this->dr(StandardChart::BANK_CHARGES, $collection), '⛔ দফা ৯: চার্জ ব্যাংক চার্জের খাতে নয়');
        $this->assertSame('5000.0000', $this->customerCr($collection), '⛔ দফা ৯: গ্রাহকের পাওনা পুরো অঙ্কে কমেনি');
        $this->balanced($collection);
    }

    /** দফা ২৫ — চার ঘরের পয়সা পর্যন্ত হুবহু (bcmath), প্রতিটা কাগজে Dr = Cr */
    public function test_item_25_money_is_exact_to_four_places_and_every_paper_balances(): void
    {
        $collection = $this->book(['amount' => '1000.1234', 'vds' => '0.0001', 'lines_given' => '1']);

        $this->assertSame(DocumentStatus::CONFIRMED, $collection->status, '⛔ দফা ২৫: আদায়টা পোস্ট হয়নি — '.json_encode($this->errors()?->all()));
        $this->assertSame('1000.1234', $this->drOn($this->bank->id, $collection), '⛔ দফা ২৫: ব্যাংকের অঙ্ক চার ঘর পর্যন্ত হুবহু নয় (float-এর গোলমাল?)');
        $this->assertSame('1000.1235', $this->customerCr($collection), '⛔ দফা ২৫: গ্রাহকের Cr = টাকা + VDS, পয়সা পর্যন্ত হুবহু নয়');
        $this->balanced($collection);
    }

    // ══ দফা ৪ — কম বা বেশি টাকা ═════════════════════════════════════════

    /**
     * দফা ৪ — রাউন্ডিং ঠিক ৳০.৫০ ৫৩০৫-এ, সই ছাড়াই পোস্ট (মালিক, ১ অক্টোবর ২০২৬: "রাউন্ডিং 0.5 mane 50 poisa porzonto accept";
     * fe, ২২ অক্টোবর ২০২৬)। ⓘ ছক চালু থাকলেও সই চাওয়া হয় না — সীমাটা ৳০.৫০ পর্যন্ত সই-মুক্ত।
     */
    public function test_item_4_rounding_of_fifty_paisa_posts_to_5305_without_a_signature(): void
    {
        app(OwnerSignsDiscounts::class)->ensure($this->company);
        $bill = $this->bill('INV-CF-2', 10, '1000.50');

        $collection = $this->book(['amount' => '1000', 'difference' => ['kind' => 'rounding', 'amount' => '0.50'],
            'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '1000.50']]]);

        $this->assertSame(DocumentStatus::CONFIRMED, $collection->status, '⛔ দফা ৪: ৳০.৫০ রাউন্ডিং সই ছাড়া পোস্ট হয়নি — '.json_encode($this->errors()?->all()));
        $this->assertSame(0, Approval::query()->where('action', 'discount')->count(), '⛔ দফা ৪: ৳০.৫০ রাউন্ডিংয়ে সই চাওয়া হলো — ৳০.৫০ পর্যন্ত সই-মুক্ত');
        $this->assertSame('0.5000', $this->dr(StandardChart::ROUNDING_DIFFERENCE, $collection), '⛔ দফা ৪: রাউন্ডিং ৫৩০৫-এ Dr হয়নি');
        $this->assertSame('0.0000', $this->dr(StandardChart::COLLECTION_DISCOUNT, $collection), '⛔ দফা ৪: রাউন্ডিং ছাড়ের খাতে (৪১২০) গেল');
        $this->assertSame('1000.5000', $this->customerCr($collection), '⛔ দফা ৪: রাউন্ডিংসহ গ্রাহকের পাওনা কমেনি');
        $this->balanced($collection);
    }

    /**
     * দফা ৪ — ৳০.৫১ রাউন্ডিং মালিকের সই চায়, ছাড়ের একই পথে আর ঠিক সেই অঙ্কের: কর্তার "পোস্ট" খসড়ায় থামে, মালিক নন এমন কেউ সই দিতে
     * পারেন না, কর্তা আবার চাপলেও থামে; মালিক সই দিলে ৫৩০৫-এ পোস্ট।
     */
    public function test_item_4_rounding_above_fifty_paisa_waits_for_the_owners_signature(): void
    {
        app(OwnerSignsDiscounts::class)->ensure($this->company);
        $bill = $this->bill('INV-CF-R51', 10, '1000.51');

        $collection = $this->bookAllowingStops(['amount' => '1000', 'difference' => ['kind' => 'rounding', 'amount' => '0.51'],
            'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '1000.51']]]);

        $this->assertSame(DocumentStatus::DRAFT, $collection->status, '⛔ দফা ৪: ৳০.৫১ রাউন্ডিং মালিকের সই ছাড়াই পোস্ট হলো');
        $this->assertSame('0.0000', $this->dr(StandardChart::ROUNDING_DIFFERENCE, $collection), '⛔ দফা ৪: সইয়ের আগেই রাউন্ডিং খাতায় উঠল');
        $approval = Approval::query()->where('action', 'discount')->where('status', Approval::PENDING)->latest('id')->first();
        $this->assertNotNull($approval, '⛔ দফা ৪: ৳০.৫১ রাউন্ডিংয়ে সইয়ের অনুরোধ যায়নি');
        $this->assertSame(0, bccomp((string) $approval->amount, '0.51', 4), '⛔ দফা ৪: সইয়ের অনুরোধ রাউন্ডিংয়ের ঠিক অঙ্কের (৳০.৫১) নয়');
        $this->assertSame(__('sales::collection_rules.rounding_awaiting', ['amount' => '0.51', 'free' => '0.50']), $this->said('difference'),
            '⛔ দফা ৪: রাউন্ডিংয়ে মালিকের সই লাগবে — বাংলায় বলেনি');

        $manager = $this->person(['approval.decide', 'approval.view', 'sales.collection.view']);
        $refused = false;
        try {
            app(ApprovalEngine::class)->approve($approval->fresh(), $manager);
        } catch (\RuntimeException) {
            $refused = true;
        }
        $this->assertTrue($refused, '⛔ দফা ৪: মালিক নন এমন একজনের সইয়ে রাউন্ডিং পার হলো');
        $this->assertSame(Approval::PENDING, $approval->fresh()->status, '⛔ দফা ৪: অন্যের সইয়ে রাউন্ডিংয়ের অনুরোধ মিটে গেল');

        $this->actingAs($this->maker)->post(route('sales.collection.confirm', $collection));
        $this->assertSame(DocumentStatus::DRAFT, $collection->fresh()->status, '⛔ দফা ৪: কর্তা নিজে আবার চেপে সই ছাড়া রাউন্ডিং পার করলেন');

        app(ApprovalEngine::class)->approve($approval->fresh(), $this->owner);
        if ($collection->fresh()->status === DocumentStatus::DRAFT) {
            $this->actingAs($this->maker)->post(route('sales.collection.confirm', $collection));
        }

        $collection = $collection->fresh();
        $this->assertSame(DocumentStatus::CONFIRMED, $collection->status, '⛔ দফা ৪: মালিকের সইয়ের পরেও রাউন্ডিংয়ের আদায় পোস্ট হলো না');
        $this->assertSame('0.5100', $this->dr(StandardChart::ROUNDING_DIFFERENCE, $collection), '⛔ দফা ৪: সইয়ের পরে রাউন্ডিং ৫৩০৫-এ Dr হয়নি');
        $this->balanced($collection);
    }

    /** দফা ৪ — অনুমোদন বন্ধ কোম্পানিতে ৳০.৫১ রাউন্ডিং ফেরত (fail-closed, ছাড়ের মতোই): খসড়া, খাতায় কিছু নয়, বাংলায় কারণ */
    public function test_item_4_rounding_above_fifty_paisa_with_approvals_off_is_refused(): void
    {
        app(OwnerSignsDiscounts::class)->ensure($this->company);
        ApprovalFlow::query()->update(['is_active' => false]);
        $bill = $this->bill('INV-CF-ROFF', 10, '1000.51');

        $collection = $this->bookAllowingStops(['amount' => '1000', 'difference' => ['kind' => 'rounding', 'amount' => '0.51'],
            'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '1000.51']]]);

        $this->assertSame(DocumentStatus::DRAFT, $collection->status, '⛔ দফা ৪: অনুমোদন বন্ধে ৳০.৫১ রাউন্ডিং সই ছাড়াই পোস্ট হলো');
        $this->assertSame(0, LedgerEntry::query()->where('source_type', Collection::drillSourceType())->where('source_id', $collection->id)->count(),
            '⛔ দফা ৪: সই ছাড়া রাউন্ডিংয়ের আদায় খাতায় উঠল');
        $this->assertSame(__('sales::collection_rules.rounding_no_signer', ['amount' => '0.51', 'free' => '0.50']), $this->said('difference'),
            '⛔ দফা ৪: সই দেওয়ার কেউ নেই — রাউন্ডিংয়ের বাংলা কারণ বলেনি');
    }

    /** দফা ৪ — সীমার (৳১০) এক পয়সা বেশি রাউন্ডিং ফেরত, বাংলায় সীমা বলে; কোনো কাগজ বা খাতার সারি বসে না */
    public function test_item_4_rounding_above_the_limit_is_refused_in_bengali(): void
    {
        $bill = $this->bill('INV-CF-3', 10, '1010.01');
        $before = Collection::query()->count();

        $this->send(['amount' => '1000', 'difference' => ['kind' => 'rounding', 'amount' => '10.01'], 'post' => '1',
            'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '1010.01']]]);

        $this->assertSame($before, Collection::query()->count(), '⛔ দফা ৪: সীমার বেশি (৳১০.০১) রাউন্ডিং নিয়েও কাগজ বসল');
        $this->assertSame(__('sales::collection_rules.rounding_over_limit', ['amount' => '10.01', 'limit' => '10.00']), $this->said('difference'),
            '⛔ দফা ৪: সীমার বেশি রাউন্ডিংয়ের বাংলা কারণ (সীমা ৳১০) বলেনি');
    }

    /**
     * দফা ৪ — ওয়েবের পা: ঋণাত্মক পার্থক্য (−৳০.৫১) [[CollectionRequest]]-এই ফেরত (`difference.amount` `min:0`), বাংলায় কারণ বলে;
     * কাগজ, খাতার সারি বা সইয়ের অনুরোধ — কিছুই বসে না, তাই চিহ্ন বদলে মালিকের সই এড়ানোর পথ নেই (fe, ২২ অক্টোবর ২০২৬)।
     * ⓘ সংশোধন (a6, ২২ অক্টোবর ২০২৬; Mac-এর লাল 21c633e60): আগের দাবি ধরে নিত ওয়েবও −০.৫১-কে ০.৫১ পড়ে সইয়ের অপেক্ষায় রাখে — ওটা
     * ফোনের প্রিভিউর পথ ([[CollectionInput::difference()]]-এর abs); সেই পা এখন
     * [[CollectionAppContractTest::test_item_4_a_negative_rounding_on_the_phone_is_read_as_its_size_and_needs_the_owner]]।
     */
    public function test_item_4_a_negative_rounding_of_fifty_one_paisa_is_refused_on_the_web_and_writes_nothing(): void
    {
        app(OwnerSignsDiscounts::class)->ensure($this->company);
        $bill = $this->bill('INV-CF-RN51', 10, '1000.51');
        $before = [Collection::acrossBranches()->count(), LedgerEntry::query()->count(), Approval::query()->count()];

        $this->send(['amount' => '1000', 'difference' => ['kind' => 'rounding', 'amount' => '-0.51'], 'post' => '1',
            'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '1000.51']]]);

        // ⓘ বার্তাটা Mac-এর লগের হুবহু (validation.min.numeric, ঘরের নাম অনুবাদহীন)
        $this->assertSame('difference.amount 0-এর কম হতে পারবে না।', $this->said('difference.amount'),
            '⛔ দফা ৪: ওয়েবে −৳০.৫১ রাউন্ডিং বাংলায় কারণসহ ফেরত হয়নি');
        $this->assertSame($before, [Collection::acrossBranches()->count(), LedgerEntry::query()->count(), Approval::query()->count()],
            '⛔ দফা ৪: ফেরত −৳০.৫১ রাউন্ডিং তবু কাগজ, খাতার সারি বা সইয়ের অনুরোধ লিখল');
    }

    /**
     * দফা ৪ — গ্রাহক ৳৫ বেশি দিলেন: সেটা রাউন্ডিং নয়, খাতা থেকে মোছা হয় না — গ্রাহকের নিজের খাতায় Cr থাকে, তাই সইও লাগে না
     * (fe-র প্রশ্ন, ২২ অক্টোবর ২০২৬)। ⓘ রাউন্ডিং কেবল কম পাওয়া টাকা মোছে (৫৩০৫ Dr); বাড়তি টাকা কখনো ৫৩০৫-এ যায় না।
     */
    public function test_item_4_five_taka_paid_extra_is_kept_as_credit_not_written_off(): void
    {
        $bill = $this->bill('INV-CF-X5', 10, '1000');

        $collection = $this->book(['amount' => '1005', 'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '1000']]]);

        $this->assertSame(DocumentStatus::CONFIRMED, $collection->status, '⛔ দফা ৪: ৳৫ বেশি দেওয়া আদায় পোস্ট হলো না');
        $this->assertSame(0, bccomp((string) $collection->unapplied, '5', 4), '⛔ দফা ৪: বাড়তি ৳৫ গ্রাহকের ক্রেডিট হিসেবে রইল না');
        $this->assertSame('0.0000', $this->dr(StandardChart::ROUNDING_DIFFERENCE, $collection), '⛔ দফা ৪: বাড়তি ৳৫ রাউন্ডিং সেজে খাতা থেকে মুছে গেল');
        $this->assertSame(0, Approval::query()->where('action', 'discount')->count(), '⛔ দফা ৪: বাড়তি টাকায় সইয়ের অনুরোধ গেল — মোছার কিছু নেই');
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

        $this->assertSame(DocumentStatus::CONFIRMED, $second->status, '⛔ দফা ৩: ক্রেডিটসহ আদায় পোস্ট হয়নি — '.json_encode($this->errors()?->all()));
        $this->assertSame(0, bccomp($bill->fresh()->dueAmount(), '0', 4), '⛔ দফা ৩: ক্রেডিট (৫০০) আর টাকায় (৭০০) বিলটা শোধ দেখায় না');
        $this->assertSame('700.0000', $this->customerCr($second), '⛔ দফা ৩: ক্রেডিটের কাটাকাটি নতুন টাকা হিসেবে খাতায় বসল');
        $this->assertSame(1, CollectionApplication::query()->where('collection_id', $second->id)->where('kind', 'credit')
            ->where('target_invoice_id', $bill->id)->count(), '⛔ দফা ৩: ক্রেডিটটা বিলে বসার লেখা নেই');

        $open = $this->actingAs($this->maker)->getJson(route('sales.collection.open_items', ['customer' => $this->dealer->id]))->json('items');
        $this->assertSame([], array_values(array_filter($open, fn ($i) => $i['id'] === $first->public_id)), '⛔ দফা ৩: খরচ হওয়া ক্রেডিট এখনো খোলা দেখায়');
    }

    /**
     * দফা ১৪, ১৬ — ক্রেডিটের প্রয়োগের অডিট-সারি আদায়টার কোম্পানি আর শাখায় বসে, হেডারের বা কর্তার শাখায় নয়
     * ([[CollectionApplication::auditCompanyId()]] / auditBranchId(); আগে মডেলটা AnAuditedModelMustSayWhoseBooksItBelongsToTest-এর
     * ছাড়ের তালিকায় ছিল, তাই প্রসঙ্গের শাখা বসত)। গ্রাহক অন্য শাখার, তাই আদায়ও সেখানে (দফা ১৪)।
     */
    public function test_item_16_a_credit_application_is_audited_in_the_collections_own_branch(): void
    {
        $other = $this->otherBranch();
        $this->dealer->forceFill(['branch_id' => $other->id])->save();

        $first = $this->book(['amount' => '500', 'lines_given' => '1']);
        $bill = SalesInvoice::query()->create(['document_no' => 'INV-CF-APPA', 'customer_id' => $this->dealer->id, 'branch_id' => $other->id,
            'trx_date' => now()->subDays(2)->toDateString(), 'due_on' => now()->addDays(10)->toDateString(),
            'subtotal' => '900', 'discount' => '0', 'tax' => '0', 'total' => '900', 'status' => DocumentStatus::CONFIRMED]);
        $second = $this->book(['amount' => '400', 'lines' => [
            ['kind' => 'credit', 'id' => $first->public_id, 'amount' => '-500'],
            ['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '900'],
        ]]);

        $this->assertSame(DocumentStatus::CONFIRMED, $second->status, 'প্রস্তুতিটাই ভুল — ক্রেডিটসহ আদায় পোস্ট হয়নি: '.json_encode($this->errors()?->all()));
        $this->assertSame((int) $other->id, (int) $second->branch_id, 'প্রস্তুতিটাই ভুল — আদায় গ্রাহকের শাখায় বসেনি');
        $this->assertNotSame((int) $other->id, (int) CompanyContext::branchId(), 'প্রস্তুতিটাই ভুল — হেডারের শাখাই আদায়ের শাখা, দাবিটা কিছু মাপে না');

        $application = CollectionApplication::query()->where('collection_id', $second->id)->where('kind', 'credit')->firstOrFail();
        $trail = AuditTrail::query()->where('auditable_type', CollectionApplication::class)->where('auditable_id', $application->id)
            ->where('action', AuditTrail::CREATED)->latest('id')->first();

        $this->assertNotNull($trail, '⛔ দফা ১৬: ক্রেডিটের প্রয়োগের অডিট-সারি লেখা হয়নি');
        $this->assertSame((int) $this->company->id, (int) $trail->company_id, '⛔ দফা ১৬: প্রয়োগের অডিট আদায়ের কোম্পানির খাতায় নয়');
        $this->assertSame((int) $other->id, (int) $trail->branch_id, '⛔ দফা ১৪/১৬: প্রয়োগের অডিট আদায়ের শাখায় নয় (হেডারের বা কর্তার শাখায় বসল)');
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
    /**
     * দফা ১০ — পার্থক্য থাকলে প্রিভিউ আর আসল পোস্ট দুটোতেই Dr-এর যোগ = Cr-এর যোগ = বিলে প্রয়োগ, আর পার্থক্যের নিজের সারি
     * (রাউন্ডিং ০.৫১ → Dr ৫৩০৫, ছাড় → Dr ৪১২০) — fe, ২২ অক্টোবর ২০২৬ (অ্যাপের নকল প্রিভিউতে ৫৩০৫ ছিল না; সার্ভারেরটা বেঁধে রাখা)।
     */
    public function test_item_10_with_a_difference_the_preview_and_the_post_both_balance_to_what_was_applied(): void
    {
        app(OwnerSignsDiscounts::class)->ensure($this->company);

        foreach ([['rounding', '0.51', StandardChart::ROUNDING_DIFFERENCE, 'R'], ['discount', '50', StandardChart::COLLECTION_DISCOUNT, 'D']] as [$kind, $diff, $code, $tag]) {
            $applied = bcadd('1000', $diff, 4);
            $bill = $this->bill('INV-CF-B'.$tag, 10, $applied);
            $extra = ['amount' => '1000', 'difference' => ['kind' => $kind, 'amount' => $diff],
                'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => $applied]]];

            $preview = $this->actingAs($this->maker)->postJson(route('sales.collection.preview'), $this->form($extra))->assertOk()->json();
            $own = collect($preview['lines'])->first(fn (array $l) => str_starts_with($l['account'], $code.' '));
            $this->assertNotNull($own, "⛔ দফা ১০: {$kind}-এর প্রিভিউতে {$code}-এর সারি নেই");
            $this->assertSame(0, bccomp((string) $own['dr'], $diff, 4), "⛔ দফা ১০: {$kind}-এর প্রিভিউতে {$code} Dr {$diff} নয়");
            $this->assertSame(0, bccomp((string) $preview['total_dr'], $applied, 4), "⛔ দফা ১০: {$kind}-এর প্রিভিউতে Dr-এর যোগ বিলে প্রয়োগের সমান নয়");
            $this->assertSame(0, bccomp((string) $preview['total_cr'], $applied, 4), "⛔ দফা ১০: {$kind}-এর প্রিভিউতে Cr-এর যোগ বিলে প্রয়োগের সমান নয়");

            $collection = $this->bookAllowingStops($extra);
            $approval = Approval::query()->where('action', 'discount')->where('status', Approval::PENDING)->latest('id')->first();
            $this->assertNotNull($approval, "⛔ দফা ১০: {$kind}-এ মালিকের সইয়ের অনুরোধ যায়নি");
            app(ApprovalEngine::class)->approve($approval->fresh(), $this->owner);
            if ($collection->fresh()->status === DocumentStatus::DRAFT) {
                $this->actingAs($this->maker)->post(route('sales.collection.confirm', $collection));
            }
            $this->assertSame(DocumentStatus::CONFIRMED, $collection->fresh()->status, "⛔ দফা ১০: {$kind}-এর আদায় সইয়ের পরেও পোস্ট হলো না");

            $rows = LedgerEntry::query()->where('source_type', Collection::drillSourceType())->where('source_id', $collection->id)->get();
            $dr = $rows->reduce(fn (string $s, LedgerEntry $e) => bcadd($s, (string) $e->debit, 4), '0');
            $cr = $rows->reduce(fn (string $s, LedgerEntry $e) => bcadd($s, (string) $e->credit, 4), '0');
            $this->assertSame(0, bccomp($dr, $applied, 4), "⛔ দফা ১০: {$kind}-এর পোস্টে Dr-এর যোগ বিলে প্রয়োগের সমান নয়");
            $this->assertSame(0, bccomp($cr, $applied, 4), "⛔ দফা ১০: {$kind}-এর পোস্টে Cr-এর যোগ বিলে প্রয়োগের সমান নয়");
            $this->assertSame(0, bccomp($this->dr($code, $collection->fresh()), $diff, 4), "⛔ দফা ১০: {$kind}-এর পোস্টে {$code} Dr {$diff} নয়");
        }
    }

    public function test_item_10_the_preview_shows_exactly_the_lines_the_post_writes(): void
    {
        // ⓘ রাউন্ডিং ৳০.৫০ — সই-মুক্ত, তাই পোস্ট এক চাপেই খাতায়
        $bill = $this->bill('INV-CF-6', 10, '2000.50');
        $form = $this->form(['amount' => '1800', 'vds' => '150', 'ait' => '50', 'difference' => ['kind' => 'rounding', 'amount' => '0.50'],
            'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '2000.50']]]);
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

        $this->assertSame(DocumentStatus::CONFIRMED, $collection->status, '⛔ দফা ২: নগদের আদায় পোস্ট হয়নি — '.json_encode($this->errors()?->all()));
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

    /**
     * দফা ১২ — নিজের বানানো পাকা আদায় নিজে উল্টানো যায় না; দ্বিতীয় জন কারণ লিখে উল্টান; উল্টো দাখিলা উল্টানোর দিনে, আগের সারি অক্ষত।
     * ⓘ আদায় গত মাসে, আর সেই মাস বন্ধ (দফা ১৩-এর একই তালা): উল্টানো তবু হয়, আজকের খোলা মাসে — বন্ধ মাস ছোঁয়া হয় না (fe, ২২ অক্টোবর ২০২৬)।
     * ⓘ সংশোধন (a6, ২২ অক্টোবর ২০২৬; Mac-এর লাল 21c633e60): উল্টো সারি বসে নিজের উৎসে `<আদায়>:reversal` ([[PostingEngine::reverse()]]),
     * আদায়ের উৎসে নয় — আগের দাবি কেবল আদায়ের উৎস গুনত, তাই "আজ ০ সারি" বলত; কোডের তারিখ ঠিকই ছিল (আজ, [[CollectionService::cancel()]])।
     */
    public function test_item_12_the_maker_cannot_reverse_a_posted_collection_and_a_second_person_reverses_on_the_day(): void
    {
        $day = now()->subMonthNoOverflow()->startOfMonth()->addDays(4)->toDateString();
        // ⓘ আদায়টা সত্যিই সেই দিনে নেওয়া (ঘড়ি সেখানে) — আজ থেকে পেছনের তারিখ লিখলে ৭ দিনের বেশি পেছনের নিয়মে ফেরে
        // (Mac-এর লাল a59a63dc2: "7 দিনের বেশি পেছনের তারিখে এন্ট্রি নেওয়া যায় না"); তারপর আজে ফিরে মাসটা বন্ধ
        $this->travelTo(\Illuminate\Support\Carbon::parse($day)->setTime(11, 0));
        $collection = $this->book(['amount' => '600', 'lines_given' => '1', 'trx_date' => $day]);
        $this->travelBack();
        $this->assertSame(DocumentStatus::CONFIRMED, $collection->status, '⛔ দফা ১২: আদায়টাই পোস্ট হয়নি — '.json_encode($this->errors()?->all(), JSON_UNESCAPED_UNICODE));
        $original = fn () => LedgerEntry::query()->where('source_type', Collection::drillSourceType())->where('source_id', $collection->id)
            ->orderBy('id')->get()->map(fn ($r) => [(int) $r->id, $r->trx_date->toDateString(), (int) $r->account_id,
                bcadd((string) $r->debit, '0', 4), bcadd((string) $r->credit, '0', 4)])->all();
        $posted = $original();
        $this->assertCount(2, $posted, 'প্রস্তুতিটাই ভুল — আদায়ের খাতায় দুই সারি নেই');
        PeriodLock::query()->create([
            'company_id' => $this->company->id, 'year' => (int) \Illuminate\Support\Carbon::parse($day)->year, 'month' => (int) \Illuminate\Support\Carbon::parse($day)->month,
            'reason' => 'গত মাসের হিসাব জমা', 'locked_by' => $this->owner->id, 'locked_at' => now(),
        ]);

        $this->actingAs($this->maker)->post(route('sales.collection.cancel', $collection), ['reason' => 'ভুল খাত']);
        $this->assertSame(DocumentStatus::CONFIRMED, $collection->fresh()->status, '⛔ দফা ১২: যিনি বানালেন তিনিই উল্টে দিলেন');
        $this->assertSame(__('sales::collection_rules.second_person_cancels'), $this->said('reason'), '⛔ দফা ১২: দ্বিতীয় জন লাগবে — বাংলায় বলেনি');

        // ⛔ পোস্টের পরে সম্পাদনার দরজা বন্ধ ([[CollectionPolicy::update()]]; সেবাও ফেরায়)
        $this->actingAs($this->maker)->put(route('sales.collection.update', $collection), $this->form(['amount' => '6000', 'lines_given' => '1', 'trx_date' => $day]))
            ->assertForbidden();
        $this->assertSame(0, bccomp((string) $collection->fresh()->amount, '600', 4), '⛔ দফা ১২: পোস্টের পরে আদায় বদলানো গেল');

        $checker = $this->person(['sales.collection.view', 'sales.collection.cancel']);
        $this->actingAs($checker)->post(route('sales.collection.cancel', $collection), ['reason' => 'ভুল খাত']);

        $this->assertSame(DocumentStatus::CANCELLED, $collection->fresh()->status,
            '⛔ দফা ১২: দ্বিতীয় জনও উল্টাতে পারলেন না (মূল মাস বন্ধ, আজকের মাস খোলা) — '.json_encode($this->errors()?->all(), JSON_UNESCAPED_UNICODE));
        $this->assertSame($posted, $original(), '⛔ দফা ১২: আগের দিনের সারি বদলে গেল বা সরল (একই সারি, একই তারিখ, একই অঙ্ক নয়)');

        $reversal = LedgerEntry::query()->where('source_type', Collection::drillSourceType().':reversal')->where('source_id', $collection->id)->get();
        $this->assertSame([now()->toDateString(), now()->toDateString()], $reversal->map(fn ($r) => $r->trx_date->toDateString())->values()->all(),
            '⛔ দফা ১২: উল্টো দাখিলা উল্টানোর দিনে বসেনি (দুই সারি, দুটোই আজ)');
        $rows = LedgerEntry::query()->whereIn('source_type', [Collection::drillSourceType(), Collection::drillSourceType().':reversal'])
            ->where('source_id', $collection->id)->get();
        $this->assertSame(0, bccomp((string) $rows->sum('debit'), (string) $rows->sum('credit'), 4), '⛔ দফা ১২: উল্টানোর পরে কাগজের Dr ≠ Cr');
        $bank = $rows->where('account_id', $this->bank->id);
        $this->assertSame(0, bccomp(bcsub((string) $bank->sum('debit'), (string) $bank->sum('credit'), 4), '0', 4), '⛔ দফা ১২: উল্টানোর পরে ব্যাংকে টাকা রয়ে গেল');
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

        $this->assertSame(DocumentStatus::CONFIRMED, $collection->status, '⛔ দফা ১৪: আদায়টা পোস্ট হয়নি — '.json_encode($this->errors()?->all()));
        $this->assertSame((int) $other->id, (int) $collection->branch_id, '⛔ দফা ১৪: আদায় গ্রাহকের শাখায় বসেনি');
        $branches = LedgerEntry::query()->where('source_type', Collection::drillSourceType())->where('source_id', $collection->id)
            ->pluck('branch_id')->map(fn ($b) => (int) $b)->unique()->values()->all();
        $this->assertSame([(int) $other->id], $branches, '⛔ দফা ১৪: খাতার সারি গ্রাহকের শাখার বাইরে বসল');
    }

    /**
     * দফা ২২ (বানানো হয়নি, D4) — অন্য শাখার টিলে আদায় আজকের মতো বাংলায় কারণসহ ফেরত; আন্তঃশাখার কোনো সারি নয়।
     * ⓘ দুই পা (Mac, ২২ অক্টোবর ২০২৬): (১) কর্তার কাজের শাখা নেই আর গ্রাহক শাখাহীন — আদায়ের শাখাই নেই; আগে নিয়মটা তখন বাদ পড়ত
     * আর আদায় শাখাহীন হয়ে অন্য শাখার টিলে বসত (Mac-এর লাল); (২) কর্তার কাজের শাখা প্রধান শাখা — গ্রাহকের শাখা সেটাই, টিল অন্য শাখার।
     * ⓘ সব আদায়ের কাগজ গোনা হয় শাখা পেরিয়ে — শাখাহীন কাগজ যেন গোনার বাইরে না পড়ে।
     */
    public function test_item_22_is_not_built_so_another_branchs_till_is_refused_in_bengali(): void
    {
        $other = $this->otherBranch();
        $till = app(CashTillService::class)->create(['name_en' => 'Other branch box', 'holder_id' => $this->owner->id]);
        $till->forceFill(['branch_id' => $other->id])->save();
        $before = Collection::acrossBranches()->count();

        $this->assertNull($this->maker->current_branch_id, 'প্রস্তুতিটাই ভুল — কর্তার কাজের শাখা আছে');
        $this->send(['account_id' => $till->account_id, 'amount' => '250', 'lines_given' => '1', 'post' => '1']);

        $this->assertSame($before, Collection::acrossBranches()->count(), '⛔ দফা ২২: শাখাহীন আদায় অন্য শাখার টিলে বসল — আদায়ের শাখা না থাকলে নিয়মটাই বাদ পড়ল');
        $this->assertSame(__('sales::collection_rules.other_branch_account'), $this->said('account_id'), '⛔ দফা ২২: শাখাহীন আদায়, অন্য শাখার খাত — বাংলায় কারণ বলেনি');

        $this->maker->forceFill(['current_branch_id' => $this->company->defaultBranch()->id])->save();
        $this->send(['account_id' => $till->account_id, 'amount' => '250', 'lines_given' => '1', 'post' => '1']);

        $this->assertSame($before, Collection::acrossBranches()->count(), '⛔ দফা ২২: প্রধান শাখার আদায় অন্য শাখার টিলে বসল — আন্তঃশাখার হিসাব মালিকের সিদ্ধান্তের অপেক্ষায়');
        $this->assertSame(__('sales::collection_rules.other_branch_account'), $this->said('account_id'), '⛔ দফা ২২: অন্য শাখার খাত — বাংলায় কারণ বলেনি');
    }

    /** দফা ১৫ — কোম্পানি স্লিপ বাধ্যতামূলক করলে স্লিপ ছাড়া পোস্ট নয়; স্লিপসহ পোস্ট হয় আর স্লিপ কাগজের সাথে থাকে */
    public function test_item_15_a_required_slip_stops_the_post_until_it_is_attached(): void
    {
        Storage::fake('local');
        app(SettingsService::class)->set('sales.collection.attachment_required', true);

        $without = $this->bookAllowingStops(['amount' => '350', 'lines_given' => '1']);
        $this->assertSame(DocumentStatus::DRAFT, $without->status, '⛔ দফা ১৫: স্লিপ ছাড়া পোস্ট হলো');
        $this->assertSame(__('sales::collection_rules.attachment_required'), (string) $this->errors()?->first('slip'), '⛔ দফা ১৫: স্লিপ লাগবে — বলেনি');

        $with = $this->book(['amount' => '360', 'lines_given' => '1', 'slip' => UploadedFile::fake()->image('slip.png', 40, 30)]);
        $this->assertSame(DocumentStatus::CONFIRMED, $with->status, '⛔ দফা ১৫: স্লিপসহও পোস্ট হলো না — '.json_encode($this->errors()?->all()));
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

    /**
     * দফা ১৭ — টাকার রসিদ: টাকা কথায়, কোন বিলে কত, গ্রাহকের কাটা VDS/AIT আর অপ্রয়োগকৃত।
     * ⓘ মাপা হয় যে ছাঁচে রসিদ সত্যিই ছাপে — কোম্পানি কিছু না বাছলে ডিফল্ট নকশা ট্যালি ক্লাসিক ([[PaperDesigns::defaultFor()]]),
     * চলতি `print.document` নয় (Mac, ২২ অক্টোবর ২০২৬: আগের দাবি কেবল `print.document` শুনত, তাই "ছাঁচ ডাকাই হয়নি" — আর
     * নকশার কাগজে কাটা অঙ্ক আর অপ্রয়োগকৃত সত্যিই ছাপা হত না)।
     */
    public function test_item_17_the_receipt_prints_words_bills_withheld_and_unapplied(): void
    {
        $bill = $this->bill('INV-CF-RCPT', 10, '8000');
        $collection = $this->book(['amount' => '9000', 'vds' => '750', 'ait' => '250',
            'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '8000']]]);
        $this->assertSame(DocumentStatus::CONFIRMED, $collection->status, '⛔ দফা ১৭: আদায়টা পোস্ট হয়নি');

        [$template, $html] = $this->receiptAs($this->owner, $collection);
        $this->assertNotSame('', $html, 'রসিদের ছাঁচ ডাকাই হয়নি');
        $this->assertStringStartsWith('sales::print.receipt-', $template, 'প্রস্তুতিটাই ভুল — রসিদ নকশার ছাঁচে ছাপেনি (ডিফল্ট নকশা আছে)');

        $this->assertTrue(str_contains($html, AmountInWords::of('9000.0000', 'bn')) || str_contains($html, AmountInWords::of('9000.0000', 'en')),
            '⛔ দফা ১৭: রসিদে টাকা কথায় নেই');
        $this->assertStringContainsString('INV-CF-RCPT', $html, '⛔ দফা ১৭: রসিদে কোন বিলে বসল তা নেই');
        $this->assertStringContainsString('8,000.00', $html, '⛔ দফা ১৭: রসিদে বিলের অঙ্ক নেই');
        $label = fn (string $key) => str_contains($html, e(__($key, [], 'bn'))) || str_contains($html, e(__($key, [], 'en')));
        $this->assertTrue($label('sales::collection_form.receipt.vds'), '⛔ দফা ১৭: রসিদে গ্রাহকের কাটা VDS নেই');
        $this->assertStringContainsString('750.00', $html, '⛔ দফা ১৭: রসিদে VDS-এর অঙ্ক নেই');
        $this->assertTrue($label('sales::collection_form.receipt.ait'), '⛔ দফা ১৭: রসিদে গ্রাহকের কাটা AIT নেই');
        $this->assertStringContainsString('250.00', $html, '⛔ দফা ১৭: রসিদে AIT-এর অঙ্ক নেই');
        $this->assertTrue($label('sales::collection_form.receipt.unapplied'), '⛔ দফা ১৭: রসিদে অপ্রয়োগকৃত (২,০০০) নেই');
        $this->assertStringContainsString('2,000.00', $html, '⛔ দফা ১৭: রসিদে অপ্রয়োগকৃতের অঙ্ক নেই');
    }

    /** দফা ১৮ — দেয়ালের SR কেবল নিজের ডিলারের খোলা কাগজ দেখেন; অন্যের ডিলার ৪০৪, কিছুই ফাঁস নয় */
    /**
     * দফা ১৮ — দেয়ালের SR ওয়েবেও আদায় নেন না (মালিক: *"বিক্রয়কর্মী কিছুই তৈরি করতে পারবেন না — কেবল অর্ডার দেবেন"*,
     * [[DealerScope::MAY_CREATE]]): আদায়ের চাবি থাকলেও পর্দা, খোলা কাগজ আর প্রিভিউ ৪০৩ — নিজের ডিলারেও।
     * ⓘ সংশোধন (a6, ২২ অক্টোবর ২০২৬): আগের দাবি ধরে নিত SR নিজের ডিলারের কাগজ খোলেন — নিয়মের উল্টো; Mac-এ লাল হত।
     * ⓘ তুলনার জন্য: দেয়ালের বাইরের কর্তা ([[maker]]) একই গ্রাহকে ২০০ — দাবিটা কিছু মাপে।
     */
    public function test_item_18_a_walled_salesman_takes_no_collection_on_the_web(): void
    {
        app(SettingsService::class)->set(DealerScope::SWITCH, true);
        $this->bill('INV-CF-SR', 10, '900');

        $sr = $this->person(['sales.collection.create', 'sales.collection.view', DealerScope::OWN]);
        DealerBinding::query()->create(['company_id' => $this->company->id, 'user_id' => $sr->id, 'customer_id' => $this->dealer->id,
            'starts_on' => now()->subDay()->toDateString(), 'created_by' => $this->owner->id]);
        app(DealerScope::class)->forget();
        $this->actingAs($sr);
        $this->assertTrue(app(DealerScope::class)->walled(), 'প্রস্তুতিটাই ভুল — SR দেয়ালে নেই');

        $this->assertSame(403, $this->get(route('sales.collection.create'))->status(), '⛔ দফা ১৮: দেয়ালের SR আদায়ের পর্দা খুললেন');
        $items = $this->getJson(route('sales.collection.open_items', ['customer' => $this->dealer->id]));
        $this->assertSame(403, $items->status(), '⛔ দফা ১৮: দেয়ালের SR নিজের ডিলারের খোলা কাগজ আদায়ের জন্য খুললেন');
        $preview = $this->postJson(route('sales.collection.preview'), $this->form(['amount' => '100']));
        $this->assertSame(403, $preview->status(), '⛔ দফা ১৮: দেয়ালের SR আদায়ের প্রিভিউ খুললেন');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->maker)->getJson(route('sales.collection.open_items', ['customer' => $this->dealer->id]))->assertOk();
    }

    /**
     * দফা ১৮ — "গোপন অঙ্ক ঢাকা থাকে": বিক্রয়ের গোপন অঙ্ক বিলের ক্রয়মূল্য (`cost_of_goods`, [[FieldSecurity]]-এ `sales.cost.view`-এর
     * পেছনে; গ্রাহক বা আদায়ের কোনো ঘর গোপন ঘোষিত নয়)। চাবিহীন কর্তার আদায়ের ছয় দরজার কোনোটায় বিলের ক্রয়মূল্য আসে না —
     * পর্দা, খোলা কাগজ, ওয়েবের প্রিভিউ, ফোনের open-bills, সিঙ্কের উত্তর, আর টাকার রসিদ।
     */
    public function test_item_18_a_bills_hidden_cost_never_reaches_any_collection_door(): void
    {
        $bill = $this->bill('INV-CF-COST', 10, '9000');
        $bill->forceFill(['cost_of_goods' => '4321.9876'])->save();
        $this->assertFalse($this->maker->can('sales.cost.view'), 'প্রস্তুতিটাই ভুল — কর্তার ক্রয়মূল্যের চাবি আছে');
        $line = ['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '9000'];

        $doors = [
            'পর্দা' => $this->actingAs($this->maker)->get(route('sales.collection.create', ['customer' => $this->dealer->id]))->assertOk()->getContent(),
            'খোলা কাগজ' => $this->getJson(route('sales.collection.open_items', ['customer' => $this->dealer->id]))->assertOk()->getContent(),
            'ওয়েবের প্রিভিউ' => $this->postJson(route('sales.collection.preview'), $this->form(['amount' => '9000', 'lines' => [$line]]))->assertOk()->getContent(),
        ];

        $clerk = $this->person(['sales.collection.create', 'sales.collection.view', 'accounts.voucher.create']);
        $this->app['auth']->forgetGuards();
        // ⓘ আসল ফোনের টোকেনের দুই চাবিই ([[AuthController]]: ACCESS = sync, APP) — শুধু APP দিলে সিঙ্কের দরজা `abilities:sync`-এ ৪০৩ দিত
        \Laravel\Sanctum\Sanctum::actingAs($clerk, [\App\Http\Controllers\Api\AuthController::ACCESS, \App\Http\Controllers\Api\AuthController::APP]);
        $doors['ফোনের open-bills'] = $this->getJson('/api/v1/sales/collections/open-bills?customer='.$this->dealer->public_id)->assertOk()->getContent();
        $doors['সিঙ্কের উত্তর'] = $this->postJson('/api/v1/sync/sales/push?deviceId=phone-cost', [[
            'changeId' => 'cost-1', 'entityType' => 'Collection', 'entityId' => null, 'operation' => 'CREATE', 'clientVersion' => 1,
            'payloadJson' => json_encode(['customerId' => (string) $this->dealer->public_id, 'trxDate' => now()->toDateString(),
                'accountId' => (string) $this->bank->public_id, 'amount' => '9000', 'confirm' => true,
                'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '9000.00']]]),
        ]])->assertOk()->getContent();
        $this->app['auth']->forgetGuards();

        $paper = Collection::acrossBranches()->latest('id')->firstOrFail();
        $doors['টাকার রসিদ'] = $this->receiptAs($this->maker, $paper)[1];

        foreach ($doors as $door => $body) {
            $this->assertNotSame('', $body, "প্রস্তুতিটাই ভুল — {$door} খোলেনি");
            // ⓘ ঘরের নাম কেবল JSON আর রসিদে খোঁজা — পর্দার খোলসে অন্য কাজে শব্দটা থাকতে পারে; অঙ্ক সব দরজায়
            $secrets = ['4321.98', '4,321.98', '4321.99', '4,321.99', ...($door === 'পর্দা' ? [] : ['cost_of_goods'])];
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $body, "⛔ দফা ১৮: {$door}-এ বিলের ক্রয়মূল্য ({$secret}) ফাঁস — চাবি ছাড়া গোপন অঙ্ক দেখা গেল");
            }
        }
    }

    /** দফা ১৮ — "শুধু অনুমতি যাঁর আছে তিনি দেখেন": আদায়ের চাবি (`sales.collection.create`) ছাড়া পর্দা, খোলা কাগজ, প্রিভিউ আর ফোনের দুই দরজা ৪০৩ */
    public function test_item_18_without_the_collection_key_no_collection_door_opens(): void
    {
        $this->bill('INV-CF-KEY', 10, '700');
        $viewer = $this->person(['sales.collection.view']);

        $this->actingAs($viewer);
        $this->assertSame(403, $this->get(route('sales.collection.create'))->status(), '⛔ দফা ১৮: চাবি ছাড়া টাকা নেওয়ার পর্দা খুলল');
        $this->assertSame(403, $this->getJson(route('sales.collection.open_items', ['customer' => $this->dealer->id]))->status(),
            '⛔ দফা ১৮: চাবি ছাড়া গ্রাহকের খোলা বিল আর বকেয়া দেখা গেল');
        $this->assertSame(403, $this->postJson(route('sales.collection.preview'), $this->form(['amount' => '700', 'lines_given' => '1']))->status(),
            '⛔ দফা ১৮: চাবি ছাড়া খাতার প্রিভিউ খুলল');

        $this->app['auth']->forgetGuards();
        // ⓘ আসল ফোনের টোকেন (দুই চাবি) — ৪০৩ যেন টোকেনের নয়, আদায়ের চাবির
        \Laravel\Sanctum\Sanctum::actingAs($viewer, [\App\Http\Controllers\Api\AuthController::ACCESS, \App\Http\Controllers\Api\AuthController::APP]);
        $this->assertSame(403, $this->getJson('/api/v1/sales/collections/open-bills?customer='.$this->dealer->public_id)->status(),
            '⛔ দফা ১৮: চাবি ছাড়া ফোনে খোলা বিল দেখা গেল');
        $this->assertSame(403, $this->postJson('/api/v1/sales/collections/preview', ['customerId' => (string) $this->dealer->public_id,
            'trxDate' => now()->toDateString(), 'amount' => '700'])->status(), '⛔ দফা ১৮: চাবি ছাড়া ফোনে প্রিভিউ খুলল');
    }

    /**
     * দফা ১৮ — ওয়েবের খোলা কাগজ হেডারে বাছা শাখা মানে, ফোনের open-bills-এর মতো (EveryPartyListFollowsTheViewedBranchTest-এর লাল,
     * Mac-এ 3d336ee4f): এক শাখায় বাঁধা কর্তা অন্য শাখার গ্রাহকের id দিলে খোলা কাগজ ৪০৪ — না-থাকা গ্রাহকের একই উত্তর — আর পর্দা
     * `?customer=` ধরে তাঁর বিল আঁকে না; নিজের শাখার গ্রাহকে দুটোই খোলে (দাবিটা কিছু মাপে)।
     */
    public function test_item_18_the_web_open_items_follow_the_viewed_branch(): void
    {
        $home = (int) $this->company->defaultBranch()->id;
        $away = $this->otherBranch();
        $mine = Customer::query()->create(['code' => 'CF-HOME', 'name_en' => 'Home Store', 'branch_id' => $home, 'is_active' => true]);
        $there = Customer::query()->create(['code' => 'CF-AWAY', 'name_en' => 'Away Store', 'branch_id' => $away->id, 'is_active' => true]);

        foreach ([['INV-CF-HOME', $mine, $home], ['INV-CF-AWAY', $there, (int) $away->id]] as [$no, $customer, $branchId]) {
            SalesInvoice::query()->create(['document_no' => $no, 'customer_id' => $customer->id, 'branch_id' => $branchId,
                'trx_date' => now()->subDays(3)->toDateString(), 'due_on' => now()->addDays(10)->toDateString(),
                'subtotal' => '800', 'discount' => '0', 'tax' => '0', 'total' => '800', 'status' => DocumentStatus::CONFIRMED]);
        }

        $this->maker->forceFill(['current_branch_id' => $home, 'view_all_branches' => false])->save();
        $this->actingAs($this->maker->fresh());

        $own = $this->getJson(route('sales.collection.open_items', ['customer' => $mine->id]))->assertOk()->getContent();
        $this->assertStringContainsString('INV-CF-HOME', (string) $own, 'প্রস্তুতিটাই ভুল — নিজের শাখার গ্রাহকের বিলও আসে না');

        $other = $this->getJson(route('sales.collection.open_items', ['customer' => $there->id]));
        $this->assertSame(404, $other->status(), '⛔ দফা ১৮: শাখায় বাঁধা কর্তা অন্য শাখার গ্রাহকের খোলা কাগজ পেলেন ('.$other->status().')');
        $this->assertStringNotContainsString('INV-CF-AWAY', (string) $other->getContent(), '⛔ দফা ১৮: অন্য শাখার গ্রাহকের বিল ফাঁস হলো');
        $this->assertSame(404, $this->getJson(route('sales.collection.open_items', ['customer' => (string) $there->public_id]))->status(),
            '⛔ দফা ১৮: public_id ধরে অন্য শাখার গ্রাহকের খোলা কাগজ খুলল');
        /*
         * ⓘ উত্তর মাপা হয় অবস্থা আর ভুলের ধরন-কথায়, JSON-এর `message`-এ নয় (a6, ২২ অক্টোবর ২০২৬; Mac-এর লাল 21c633e60): ওয়েবের
         * ভুল কেবল `api/*`-এ JSON হয় (bootstrap/app.php-এর shouldRenderJsonWhen), তাই এখানে ৪০৪ HTML-এর পাতা — আর TestResponse::json()
         * অ-JSON দেহে জমা ৪০৪-টাই আবার ছোঁড়ে। দরজা ঠিক ছিল (৪০৪ যেখানে চাওয়া), দাবির পড়াটা ভুল।
         */
        $answer = fn ($r) => [$r->status(), $r->exception === null ? null : $r->exception::class, (string) $r->exception?->getMessage()];
        $missing = $this->getJson(route('sales.collection.open_items', ['customer' => (int) Customer::query()->withoutGlobalScopes()->max('id') + 1000]));
        $this->assertSame([404, \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class, ''], $answer($other),
            '⛔ দফা ১৮: অন্য শাখার গ্রাহকের উত্তর খালি ৪০৪ নয়');
        $this->assertSame($answer($missing), $answer($other),
            '⛔ দফা ১৮: অন্য শাখার গ্রাহক আর না-থাকা গ্রাহক আলাদা উত্তর পান — কে আছেন জানা যায়');
        $this->assertStringNotContainsString('Away Store', (string) $other->getContent(), '⛔ দফা ১৮: ৪০৪-এর পাতায় অন্য শাখার গ্রাহকের নাম');

        $this->assertStringContainsString('INV-CF-HOME', (string) $this->get(route('sales.collection.create', ['customer' => $mine->id]))->assertOk()->getContent(),
            'প্রস্তুতিটাই ভুল — পর্দা নিজের শাখার গ্রাহকের বিল আঁকে না');
        $this->assertStringNotContainsString('INV-CF-AWAY', (string) $this->get(route('sales.collection.create', ['customer' => $there->id]))->assertOk()->getContent(),
            '⛔ দফা ১৮: পর্দা ?customer= ধরে অন্য শাখার গ্রাহকের বিল আঁকল');
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
        $this->assertNotNull($paper, '⛔ আদায়ের কাগজটাই বসেনি — '.json_encode($this->errors()?->all(), JSON_UNESCAPED_UNICODE));

        return $paper;
    }

    /** দরজায় পাঠানো, ফেরত হলেও — কারণ `session('errors')`-এ; দাবি নিজে মাপে কী বসল আর কী বলল */
    private function send(array $extra): void
    {
        $this->actingAs($this->maker)->post(route('sales.collection.store'), $this->form($extra));
    }

    private function said(string $field): string
    {
        return (string) $this->errors()?->first($field);
    }

    /**
     * শেষ অনুরোধের ভুলের থলে। ⚠️ `session('errors')` সরাসরি নয়: সেশন JSON-এ সাজানো (`config/session.php` serialization = json),
     * তাই অনুরোধ শেষে `Store::save()` থলেটাকে array বানিয়ে রাখে — `->all()`/`->first()` তখন "on array" Error (Mac, ২২ অক্টোবর ২০২৬,
     * ১৪টা দাবি)। সেশন আবার চালু করলে `marshalErrorBag()` থলে ফেরায় — TestResponse::session()-ও ঠিক এটাই করে।
     */
    private function errors(): ?\Illuminate\Support\ViewErrorBag
    {
        $store = app('session.store');
        if (! $store->isStarted()) {
            $store->start();
        }

        return $store->get('errors');
    }

    /**
     * রসিদ যে ছাঁচে সত্যিই ছাপে তার HTML — চলতি কাগজ (`print.document`) বা রসিদের কোনো নকশা (`sales::print.receipt-*`, কোম্পানি কিছু
     * না বাছলে ডিফল্ট ট্যালি ক্লাসিক)। ⓘ PDF-এর বাইট পড়া যায় না, তাই ছাঁচের তথ্য ধরে আবার আঁকা।
     *
     * @return array{0: string, 1: string} [ছাঁচের নাম, HTML]; ছাঁচ না ডাকলে ['', '']
     */
    private function receiptAs(User $user, Collection $collection): array
    {
        $seen = ['', []];
        View::composer(['print.document', 'sales::print.receipt-*'], function ($view) use (&$seen) {
            $seen = [$view->name(), $view->getData()];
        });
        $this->actingAs($user)->get(route('sales.print.receipt', $collection))->assertOk();

        return $seen[0] === '' ? ['', ''] : [$seen[0], view($seen[0], $seen[1])->render()];
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
