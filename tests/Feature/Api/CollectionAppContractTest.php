<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Sync\SyncService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\SyncChange;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\TransferMode;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ টাকা নেওয়া — ফোনের চুক্তি, সার্ভারের দিকে (a4/collect-easy a3a030302-এর ক্লায়েন্ট, `collection_entry.dart`; a5-র উত্তর ১–৬,
 * ২১ অক্টোবর ২০২৬; মালিকের নকশা ১০০-এর দফা ২, ৩, ৭, ৯, ১০, ১৩, ১৮, ১৯)।
 *
 * ⓘ অ্যাপ যা পাঠায় আর যা পড়ে, হুবহু সেই নামে:
 *   · `GET collections/setup` — খাতে `mine`, আর `transfer_modes: [{id, name}]`
 *   · `GET collections/open-bills?customer=` — `items` এক তালিকা, ক্রেডিট ঋণাত্মক
 *   · `POST collections/preview` — কিছু লেখে না; `stops` থাকলে সার্ভারও পোস্ট করে না
 *   · সিঙ্কের আদায়-বদল — `instrument` (cash|mfs|transfer|deposit|cheque), `instrument_no`, `transfer_mode_id` (setup-এর id),
 *     `deposit_slip_no`, `value_date`, `bank_charge`; `lines` না থাকলে পুরনো আগে, `lines: []` মানে কোনো বিলে নয়;
 *     ক্রেডিটের সারি ঋণাত্মক, চিহ্ন না মিললে ফেরত
 * ⓘ সিঙ্কের উত্তরের সারি আর স্লিপ আলাদা ফাইলে ([[CollectionSyncAnswerTest]])।
 */
final class CollectionAppContractTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $clerk;

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

        // ⓘ অফিসের হিসাবরক্ষক — আদায়ের চাবি আর খাতায় টাকা তোলার চাবি ([[CollectionSync::officeMayCollect()]])
        $this->clerk = $this->person(['sales.collection.create', 'sales.collection.view', 'accounts.voucher.create']);

        $this->bank = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1102-APP', 'name_en' => 'App bank', 'name_bn' => 'অ্যাপের ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
        ]);

        $this->dealer = Customer::query()->create(['code' => 'APP-1', 'name_en' => 'Karim Store', 'name_bn' => 'করিম স্টোর', 'is_active' => true]);
    }

    /** দফা ১৯ (a5-র উত্তর ৪) — setup-এর খাতে `mine` (নিজের হাতের নগদ সত্য, বাকিরা মিথ্যা) আর `transfer_modes: [{id, name}]` */
    public function test_item_19_setup_accounts_carry_mine_and_the_transfer_modes(): void
    {
        $till = app(CashTillService::class)->create(['name_en' => 'Clerk box', 'holder_id' => $this->clerk->id]);
        $mode = $this->mode();

        $this->phone($this->clerk);
        $setup = $this->getJson('/api/v1/sales/collections/setup')->assertOk()->json();
        $accounts = collect($setup['accounts']);

        $own = $accounts->firstWhere('id', (string) $till->account->public_id);
        $this->assertNotNull($own, '⛔ দফা ১৯: নিজের হাতের নগদের খাতটাই setup-এ নেই');
        $this->assertTrue($own['mine'], '⛔ দফা ১৯: নিজের হাতের নগদে `mine: true` নেই — ফোন আগে থেকে বাছতে পারে না');
        $this->assertFalse($accounts->firstWhere('id', (string) $this->bank->public_id)['mine'], '⛔ দফা ১৯: অন্যের/ব্যাংকের খাতে `mine: true`');
        $this->assertTrue($accounts->every(fn (array $a) => is_bool($a['mine'] ?? null)), '⛔ দফা ১৯: কোনো খাতে `mine` নেই বা সত্য-মিথ্যা নয় — অ্যাপ `== true` পড়ে');

        $sent = collect($setup['transfer_modes'])->firstWhere('id', (string) $mode->public_id);
        $this->assertNotNull($sent, '⛔ দফা ১৯: setup-এ `transfer_modes` নেই, বা id বাইরের (public_id) নয়');
        $this->assertSame($mode->name(), $sent['name'], '⛔ দফা ১৯: ট্রান্সফার মোডের নাম নেই');
    }

    /**
     * setup-এ রাউন্ডিংয়ের দুই সীমা (a4-এর অ্যাপ 5802d83c9, ২২ অক্টোবর ২০২৬) — ফোনের আগাম সতর্কতা আর ৳১০ হাতে-লেখা নয়: ডিফল্ট
     * কোম্পানিতে `rounding_limit` "10.0000", সীমা ২৫ করলে "25.0000"; `rounding_sign_above` "0.5000" ([[Settlement]])।
     */
    public function test_setup_carries_the_companys_rounding_limit_and_the_unsigned_ceiling(): void
    {
        $this->phone($this->clerk);
        $default = $this->getJson('/api/v1/sales/collections/setup')->assertOk()->json();
        $this->assertSame('10.0000', $default['rounding_limit'] ?? null, '⛔ ডিফল্ট কোম্পানির রাউন্ডিং সীমা ১০ নয়');
        $this->assertSame('0.5000', $default['rounding_sign_above'] ?? null, '⛔ সই-ছাড়া রাউন্ডিংয়ের সীমা ০.৫০ নয়');

        app(\App\Core\Services\SettingsService::class)->set(\App\Core\Support\Settlement::LIMIT_KEY, 25);
        $this->phone($this->clerk);
        $set = $this->getJson('/api/v1/sales/collections/setup')->assertOk()->json();
        $this->assertSame('25.0000', $set['rounding_limit'] ?? null, '⛔ কোম্পানির নিজের রাউন্ডিং সীমা (২৫) setup-এ আসেনি');
        $this->assertSame('0.5000', $set['rounding_sign_above'] ?? null, '⛔ সীমা বদলালে সই-ছাড়ার সীমাও বদলাল');
    }

    /** দফা ৩ — open-bills: এক `items` তালিকা, পুরনো আগে, ক্রেডিট ঋণাত্মক (অ্যাপ `open.abs()` পড়ে), বকেয়া খাতার বকেয়া */
    public function test_item_3_open_bills_is_one_list_oldest_first_with_credits_negative(): void
    {
        $prior = $this->landed($this->push('prior-1', $this->payload(['amount' => '400', 'lines' => [], 'confirm' => true])));
        $bill = $this->bill('INV-APP-1', 6, '1000');

        $this->phone($this->clerk);
        $json = $this->getJson('/api/v1/sales/collections/open-bills?customer='.$this->dealer->public_id)->assertOk()->json();

        $this->assertSame($this->dealer->fresh()->outstanding(), $json['due'], '⛔ দফা ৩: open-bills-এর বকেয়া খাতার বকেয়া নয়');
        $byId = collect($json['items'])->keyBy('id');
        $this->assertSame('bill', $byId[$bill->public_id]['kind'] ?? null, '⛔ দফা ৩: বিলটা `items`-এ নেই');
        $this->assertSame('1000.0000', $byId[$bill->public_id]['open'], '⛔ দফা ৩: বিলের বাকি ধনাত্মক নয়');
        $this->assertSame('credit', $byId[$prior->public_id]['kind'] ?? null, '⛔ দফা ৩: আগের অপ্রয়োগকৃত জমা `credit` হয়ে আসেনি');
        $this->assertSame('-400.0000', $byId[$prior->public_id]['open'], '⛔ দফা ৩: ক্রেডিট ঋণাত্মক নয় — a5-র উত্তর ১');
        $this->assertSame([$bill->public_id, $prior->public_id], collect($json['items'])->pluck('id')->all(), '⛔ দফা ৩: পুরনো আগে নয়');
        $this->assertSame(0, bccomp($json['credit'], '400', 4), '⛔ দফা ৩: গ্রাহকের খাতায় জমা (`credit`) ৪০০ নয়');
    }

    /** দফা ৩ (a5-র উত্তর ২) — `lines` না পাঠালে পুরনো বিল আগে; `lines: []` মানে কোনো বিলে নয়, সবটা অপ্রয়োগকৃত */
    public function test_item_3_lines_absent_pays_the_oldest_bill_but_an_empty_list_applies_nothing(): void
    {
        $bill = $this->bill('INV-APP-2', 6, '300');

        $auto = $this->landed($this->push('auto-1', $this->payload(['amount' => '300', 'confirm' => true])));
        $this->assertSame([(int) $bill->id], $auto->lines->pluck('sales_invoice_id')->map(fn ($i) => (int) $i)->all(),
            '⛔ দফা ৩: `lines` না পাঠালে পুরনো বিলে বসেনি — পুরনো অ্যাপের আচরণ ভাঙল');
        $this->assertSame(0, bccomp($bill->fresh()->dueAmount(), '0', 4));

        $other = $this->bill('INV-APP-3', 5, '300');
        $none = $this->landed($this->push('none-1', $this->payload(['amount' => '300', 'lines' => [], 'confirm' => true])));
        $this->assertSame([], $none->lines->all(), '⛔ দফা ৩: `lines: []` পাঠালেও বিলে বসল — ফোনে সব টিক তোলা মানে কোনো বিলে নয়');
        $this->assertSame(0, bccomp((string) $none->unapplied, '300', 4), '⛔ দফা ৩: `lines: []`-এ সবটা অপ্রয়োগকৃত হয়নি');
        $this->assertSame(0, bccomp($other->fresh()->dueAmount(), '300', 4), '⛔ দফা ৩: `lines: []`-এ বিলের বাকি কমল');
    }

    /** দফা ৩ (a5-র উত্তর ১) — ক্রেডিটের সারি ঋণাত্মক চলে আর বিল ঢাকে; ধনাত্মক ক্রেডিট বাংলায় ফেরত (REJECTED), কিছুই বসে না */
    public function test_item_3_credit_lines_are_negative_and_a_plus_credit_is_refused(): void
    {
        $prior = $this->landed($this->push('cr-0', $this->payload(['amount' => '500', 'lines' => [], 'confirm' => true])));
        $bill = $this->bill('INV-APP-4', 5, '1200');
        $before = Collection::query()->count();

        $out = $this->push('cr-bad', $this->payload(['amount' => '700', 'confirm' => true, 'lines' => [
            ['kind' => 'credit', 'id' => $prior->public_id, 'amount' => '500.00'],
            ['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '1200.00'],
        ]]));
        $this->assertSame(SyncChange::REJECTED, $out['status'], '⛔ দফা ৩: ধনাত্মক ক্রেডিটের সারি নীরবে মেনে নেওয়া হলো');
        $this->assertSame(__('sales::collection_rules.sign_mismatch', ['kind' => 'credit', 'amount' => '500.00']), $out['message'] ?? null,
            '⛔ দফা ৩: চিহ্ন ভুলের বাংলা কারণ নেই');
        $this->assertSame($before, Collection::query()->count(), '⛔ দফা ৩: ফেরত বদলেও কাগজ বসল');

        $good = $this->landed($this->push('cr-good', $this->payload(['amount' => '700', 'confirm' => true, 'lines' => [
            ['kind' => 'credit', 'id' => $prior->public_id, 'amount' => '-500.00'],
            ['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '1200.00'],
        ]])));
        $this->assertSame(DocumentStatus::CONFIRMED, $good->status, '⛔ দফা ৩: ঋণাত্মক ক্রেডিটের আদায় পোস্ট হয়নি');
        $this->assertSame(0, bccomp($bill->fresh()->dueAmount(), '0', 4), '⛔ দফা ৩: ক্রেডিট (৫০০) আর টাকায় (৭০০) বিল শোধ হয়নি');
    }

    /** দফা ২, ৯ (a5-র উত্তর ৬) — `instrument` = পদ্ধতি, `transfer_mode_id` = setup-এর id, `instrument_no`, `value_date`, `bank_charge` কাগজে বসে */
    public function test_item_2_a_bank_transfer_brings_its_mode_reference_value_date_and_charge(): void
    {
        $mode = $this->mode();
        $bill = $this->bill('INV-APP-5', 5, '5000');

        $c = $this->landed($this->push('tr-1', $this->payload(['amount' => '5000', 'confirm' => true,
            'instrument' => 'transfer', 'transfer_mode_id' => (string) $mode->public_id,
            'instrument_no' => 'NP2610210457812', 'instrumentNo' => 'NP2610210457812',
            'value_date' => now()->subDay()->toDateString(), 'bank_charge' => '15.00',
            'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '5000.00']]])));

        $this->assertSame(DocumentStatus::CONFIRMED, $c->status, '⛔ দফা ২: ব্যাংক ট্রান্সফারের আদায় পোস্ট হয়নি');
        $this->assertSame(['transfer', (int) $mode->id, 'NP2610210457812', now()->subDay()->toDateString()],
            [$c->instrument, (int) $c->transfer_mode_id, $c->instrument_no, $c->value_date?->toDateString()],
            '⛔ দফা ২: পদ্ধতি, মোড, রেফারেন্স বা মূল্য-তারিখ কাগজে বসেনি — অ্যাপের নামগুলো সার্ভার পড়েনি');
        $this->assertSame(0, bccomp((string) $c->bank_charge, '15', 4), '⛔ দফা ৯: ব্যাংক চার্জ কাগজে বসেনি');
        $this->assertSame('4985.0000', $this->drOn($this->bank->id, $c), '⛔ দফা ৯: ব্যাংকে চার্জ বাদে আসেনি');
        $this->assertSame('15.0000', $this->drOn((int) StandardChart::find(StandardChart::BANK_CHARGES)->id, $c), '⛔ দফা ৯: চার্জ চার্জের খাতে নয়');
    }

    /** দফা ২ (a5-র উত্তর ৬) — নগদ জমা: `deposit_slip_no` কাগজে বসে আর পোস্ট হয়; স্লিপ নম্বর ছাড়া পোস্ট নয় (খসড়া) */
    public function test_item_2_a_cash_deposit_brings_its_slip_number_and_needs_it_to_post(): void
    {
        $with = $this->landed($this->push('dep-1', $this->payload(['amount' => '2000', 'lines' => [], 'confirm' => true,
            'instrument' => 'deposit', 'deposit_slip_no' => 'DS-7781'])));
        $this->assertSame(DocumentStatus::CONFIRMED, $with->status, '⛔ দফা ২: স্লিপ নম্বরসহ নগদ জমা পোস্ট হয়নি');
        $this->assertSame(['deposit', 'DS-7781'], [$with->instrument, $with->deposit_slip_no], '⛔ দফা ২: `deposit_slip_no` কাগজে বসেনি');

        $without = $this->landed($this->push('dep-2', $this->payload(['amount' => '2000', 'lines' => [], 'confirm' => true, 'instrument' => 'deposit'])));
        $this->assertSame(DocumentStatus::DRAFT, $without->status, '⛔ দফা ২: স্লিপ নম্বর ছাড়া নগদ জমা পোস্ট হলো');
    }

    /** দফা ১০ — প্রিভিউ কিছু লেখে না, আর তার লাইন ওয়েবের প্রিভিউর হুবহু (দফা ১৯: একই ঘর, একই হিসাব) */
    public function test_item_10_the_phone_preview_writes_nothing_and_says_what_the_web_preview_says(): void
    {
        $bill = $this->bill('INV-APP-6', 5, '2010');
        $papers = Collection::query()->count();
        $rows = LedgerEntry::query()->count();

        $this->phone($this->clerk);
        $phone = $this->postJson('/api/v1/sales/collections/preview', $this->payload(['amount' => '1800', 'vds' => '150', 'ait' => '50',
            'difference' => ['kind' => 'rounding', 'amount' => '10'],
            'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '2010.00']]]))->assertOk()->json();

        $this->assertSame([$papers, $rows], [Collection::query()->count(), LedgerEntry::query()->count()], '⛔ দফা ১০: ফোনের প্রিভিউ কিছু লিখল');
        $this->assertSame([], $phone['stops'], '⛔ দফা ১০: বৈধ আদায়ের প্রিভিউ থামার কারণ বলল');
        $this->assertTrue($phone['summary']['balanced'], '⛔ দফা ১০: মিলেছে বলেনি');
        $this->assertSame($phone['total_dr'], $phone['total_cr'], '⛔ দফা ১০: Dr ≠ Cr');
        $this->assertSame(\App\Core\Support\AmountInWords::of('1800.0000', 'bn'), $phone['summary']['amount_words'], '⛔ দফা ১৭: কথায় টাকা নেই');

        $this->app['auth']->forgetGuards();
        $web = $this->actingAs($this->clerk)->postJson(route('sales.collection.preview'), [
            'customer_id' => $this->dealer->id, 'account_id' => $this->bank->id, 'trx_date' => now()->toDateString(),
            'amount' => '1800', 'vds' => '150', 'ait' => '50', 'difference' => ['kind' => 'rounding', 'amount' => '10'],
            'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '2010']],
        ])->assertOk()->json();

        $this->assertSame($web['lines'], $phone['lines'], '⛔ দফা ১৯: ফোন আর ওয়েবের "খাতায় যা যাবে" আলাদা');
        $this->assertSame($web['applied'], $phone['applied'], '⛔ দফা ১৯: ফোন আর ওয়েবের প্রয়োগ আলাদা');
    }

    /**
     * দফা ৪ — ফোনের পা: পার্থক্যের চিহ্ন যাই আসুক, অঙ্কটাই মাপা হয় (fe, ২২ অক্টোবর ২০২৬; মালিকের নিয়ম: যেকোনো দিকে ৳০.৫০-এর বেশি হলে সই)।
     * ফোনের প্রিভিউ −৳০.৫১ রাউন্ডিংকে ৳০.৫১ পড়ে ([[CollectionInput::difference()]]-এর abs) আর বলে মালিকের সই লাগবে; কিছু লেখে না।
     * সিঙ্কের পুশ ঋণাত্মক পার্থক্য ওয়েবের মতোই ফেরায় ([[CollectionSync]]-এর `difference.amount` নিয়ম) — কাগজ বসে না, তাই চিহ্ন বদলে সই
     * এড়ানোর পথ নেই। ⓘ ওয়েবের পা: [[TheCollectionFormPostsWhatTheDesignSaysTest::test_item_4_a_negative_rounding_of_fifty_one_paisa_is_refused_on_the_web_and_writes_nothing]]।
     */
    public function test_item_4_a_negative_rounding_on_the_phone_is_read_as_its_size_and_needs_the_owner(): void
    {
        $bill = $this->bill('INV-APP-RN51', 5, '1000.51');
        $before = [Collection::acrossBranches()->count(), LedgerEntry::query()->count()];
        $negative = $this->payload(['amount' => '1000', 'confirm' => true, 'difference' => ['kind' => 'rounding', 'amount' => '-0.51'],
            'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '1000.51']]]);

        $this->phone($this->clerk);
        $preview = $this->postJson('/api/v1/sales/collections/preview', $negative)->assertOk()->json();

        $this->assertSame('0.5100', $preview['summary']['difference'] ?? null, '⛔ দফা ৪: ফোনের প্রিভিউ −৳০.৫১ রাউন্ডিংকে ৳০.৫১ পড়েনি');
        $this->assertTrue($preview['needs_signature'] ?? null, '⛔ দফা ৪: −৳০.৫১ রাউন্ডিংয়ে ফোনের প্রিভিউ মালিকের সই চায়নি — চিহ্ন বদলে নিয়ম এড়ানো গেল');
        $this->assertTrue($preview['summary']['balanced'] ?? null, '⛔ দফা ৪: ৳১,০০০ + ৳০.৫১ রাউন্ডিং = ৳১,০০০.৫১ বিল — প্রিভিউ মিলেছে বলেনি');

        $this->assertSame(SyncChange::REJECTED, $this->push('neg-r51', $negative)['status'] ?? null, '⛔ দফা ৪: সিঙ্কের পুশ ঋণাত্মক পার্থক্য নিল');
        $this->assertSame($before, [Collection::acrossBranches()->count(), LedgerEntry::query()->count()],
            '⛔ দফা ৪: −৳০.৫১ রাউন্ডিং — প্রিভিউ বা ফেরত পুশ কাগজ বা খাতার সারি লিখল');
    }

    /** প্রিভিউর কথায় টাকাও যিনি চাইছেন তাঁর ভাষায় (a4-এর অ্যাপ 5802d83c9, ২২ অক্টোবর ২০২৬) — ইংরেজির মানুষ ইংরেজি, বাংলার মানুষ বাংলা */
    public function test_the_preview_words_follow_the_askers_own_language(): void
    {
        $this->clerk->forceFill(['locale' => 'en'])->save();
        $this->phone($this->clerk);
        $english = $this->postJson('/api/v1/sales/collections/preview', $this->payload(['amount' => '1800', 'lines' => []]))->assertOk()->json();
        $this->assertSame(\App\Core\Support\AmountInWords::of('1800.0000', 'en'), $english['summary']['amount_words'], '⛔ ইংরেজির মানুষ প্রিভিউর কথা ইংরেজিতে পাননি');

        $this->clerk->forceFill(['locale' => 'bn'])->save();
        $this->phone($this->clerk);
        $bangla = $this->postJson('/api/v1/sales/collections/preview', $this->payload(['amount' => '1800', 'lines' => []]))->assertOk()->json();
        $this->assertSame(\App\Core\Support\AmountInWords::of('1800.0000', 'bn'), $bangla['summary']['amount_words'], '⛔ বাংলার মানুষ প্রিভিউর কথা বাংলায় পাননি');
    }

    /**
     * দফা ১৩, ৭ — প্রিভিউর `stops` সার্ভারেও পোস্ট আটকায়, কেবল অ্যাপে নয়: বন্ধ মাসে "পোস্ট" চাইলেও খসড়া, খাতায় কিছু নয়; আর একই
     * খাতে একই রেফারেন্স প্রিভিউ বলে আর সিঙ্ক ফেরায়।
     */
    public function test_items_13_and_7_what_the_preview_stops_the_server_does_not_post(): void
    {
        $this->landed($this->push('ref-0', $this->payload(['amount' => '100', 'lines' => [], 'confirm' => true,
            'instrument' => 'transfer', 'instrument_no' => 'NP-SAME-1', 'instrumentNo' => 'NP-SAME-1'])));

        $this->phone($this->clerk);
        $again = $this->payload(['amount' => '100', 'lines' => [], 'confirm' => true,
            'instrument' => 'transfer', 'instrument_no' => 'np-same-1', 'instrumentNo' => 'np-same-1']);
        $this->assertNotSame([], $this->postJson('/api/v1/sales/collections/preview', $again)->assertOk()->json('stops'),
            '⛔ দফা ৭: একই রেফারেন্স দ্বিতীয়বার — প্রিভিউ থামার কারণ বলেনি');
        $before = Collection::query()->count();
        $this->assertSame(SyncChange::REJECTED, $this->push('ref-1', $again)['status'], '⛔ দফা ৭: একই রেফারেন্সে ফোন থেকে দ্বিতীয় আদায় বসল');
        $this->assertSame($before, Collection::query()->count());

        $lock = PeriodLock::query()->create([
            'company_id' => $this->company->id, 'year' => (int) now()->year, 'month' => (int) now()->month,
            'reason' => 'মাস শেষ', 'locked_by' => $this->owner->id, 'locked_at' => now(),
        ]);
        $closed = $this->payload(['amount' => '250', 'lines' => [], 'confirm' => true]);

        $this->phone($this->clerk);
        $this->assertContains(__('core.period.month_closed', ['month' => $lock->label(), 'reason' => $lock->reason]),
            $this->postJson('/api/v1/sales/collections/preview', $closed)->assertOk()->json('stops'), '⛔ দফা ১৩: বন্ধ মাসের কথা প্রিভিউ বলেনি');

        $c = $this->landed($this->push('closed-1', $closed));
        $this->assertSame(DocumentStatus::DRAFT, $c->status, '⛔ দফা ১৩: প্রিভিউ থামাল, অথচ সার্ভার বন্ধ মাসে পোস্ট করল');
        $this->assertSame(0, LedgerEntry::query()->where('source_type', Collection::drillSourceType())->where('source_id', $c->id)->count(),
            '⛔ দফা ১৩: বন্ধ মাসের খাতায় সারি বসল');
    }

    /**
     * দফা ১৮ — দেয়ালের SR আদায় নেন না: মালিকের নিয়ম *"বিক্রয়কর্মী কিছুই তৈরি করতে পারবেন না — কেবল অর্ডার দেবেন"*
     * ([[DealerScope::MAY_CREATE]] কেবল `sales.order.create`), আর ৭ অক্টোবরের ফোন-আদায়ে "দেয়ালের SR-এর ফোনে লুকানো"।
     * তাই আদায়ের চাবি থাকলেও ফোনের দুই দরজাই ৪০৩ — নিজের ডিলারেও; খাতার কোনো লাইন বা বকেয়া ফেরে না।
     * ⓘ সংশোধন (a6, ২২ অক্টোবর ২০২৬): আগের দাবি ৪০৪/৪২২ ধরত — দেয়াল আগেই ৪০৩ দেয়, তাই Mac-এ লাল হত।
     */
    public function test_item_18_a_walled_salesman_takes_no_collection_on_the_phone(): void
    {
        app(\App\Core\Services\SettingsService::class)->set(\App\Core\Services\DealerScope::SWITCH, true);
        $sr = $this->person(['sales.collection.create', 'sales.collection.view', \App\Core\Services\DealerScope::OWN]);
        \App\Modules\Customer\Models\DealerBinding::query()->create(['company_id' => $this->company->id, 'user_id' => $sr->id,
            'customer_id' => $this->dealer->id, 'starts_on' => now()->subDay()->toDateString(), 'created_by' => $sr->id]);
        app(\App\Core\Services\DealerScope::class)->forget();
        $this->bill('INV-APP-SR', 5, '900');
        $this->assertTrue(app(\App\Core\Services\DealerScope::class)->walled($sr->fresh()), 'প্রস্তুতিটাই ভুল — SR দেয়ালে নেই');

        $this->phone($sr);
        $bills = $this->getJson('/api/v1/sales/collections/open-bills?customer='.$this->dealer->public_id);
        $this->assertSame(403, $bills->status(), '⛔ দফা ১৮: দেয়ালের SR নিজের ডিলারের বকেয়া বিল আদায়ের জন্য খুললেন ('.$bills->status().')');
        $this->assertArrayNotHasKey('bills', (array) $bills->json(), '⛔ দফা ১৮: দেয়ালের SR-এর কাছে বকেয়া বিল ফাঁস হলো');

        $preview = $this->postJson('/api/v1/sales/collections/preview', $this->payload(['amount' => '100']));
        $this->assertSame(403, $preview->status(), '⛔ দফা ১৮: দেয়ালের SR আদায়ের প্রিভিউ খুললেন ('.$preview->status().')');
        $this->assertArrayNotHasKey('lines', (array) $preview->json(), '⛔ দফা ১৮: দেয়ালের SR-এর কাছে খাতার লাইন ফাঁস হলো');
    }

    /**
     * দফা ১৮ — নাগালের বাইরের গ্রাহক আর না-থাকা গ্রাহক একই উত্তর পান, open-bills-এ আর প্রিভিউতে (fe, ২২ অক্টোবর ২০২৬: কে আছেন
     * সেটা জানা যাবে না)। ⓘ আজকের আচরণ বাঁধা, কিছু বদলায়নি:
     *   · শাখায় বাঁধা অফিসের লোক — অন্য শাখার গ্রাহক আর না-থাকা গ্রাহক: open-bills দুটোতেই ৪০৪, প্রিভিউ দুটোতেই একই ৪২২
     *     ("গ্রাহক চেনা নেই", [[CollectionInput::fromPhone()]]); নিজের শাখার গ্রাহকে দুটোই ২০০ (দাবিটা কিছু মাপে)।
     *   · দেয়ালের SR — অন্যের ডিলার আর না-থাকা গ্রাহক প্রতিটা দরজায় একই অবস্থা আর একই কথা, কোনোটাই খোলে না।
     */
    public function test_item_18_an_out_of_reach_customer_reads_exactly_like_one_that_does_not_exist(): void
    {
        $home = (int) $this->company->defaultBranch()->id;
        $away = \App\Models\Branch::query()->create(['company_id' => $this->company->id, 'code' => 'APP-AWAY', 'name_en' => 'Away branch', 'is_active' => true]);
        $mine = Customer::query()->create(['code' => 'APP-HOME', 'name_en' => 'Home Store', 'branch_id' => $home, 'is_active' => true]);
        $there = Customer::query()->create(['code' => 'APP-AWAY', 'name_en' => 'Away Store', 'branch_id' => $away->id, 'is_active' => true]);
        $missing = (string) \Illuminate\Support\Str::uuid7();
        $this->assertFalse(Customer::query()->withoutGlobalScopes()->where('public_id', $missing)->exists(), 'না-থাকা গ্রাহক আছে — দাবিটা কিছু মাপছে না');

        $openBills = fn (string $id) => $this->getJson('/api/v1/sales/collections/open-bills?customer='.$id);
        $preview = fn (string $id) => $this->postJson('/api/v1/sales/collections/preview', [...$this->payload(['amount' => '100']), 'customerId' => $id]);
        $said = fn ($response): array => [$response->status(), $response->json('message'), $response->json('errors')];

        // ── শাখায় বাঁধা অফিসের লোক
        $office = $this->person(['sales.collection.create', 'sales.collection.view', 'accounts.voucher.create']);
        $office->forceFill(['current_branch_id' => $home, 'view_all_branches' => false])->save();
        $this->phone($office);

        $openBills((string) $mine->public_id)->assertOk();
        $preview((string) $mine->public_id)->assertOk();

        $this->assertSame(404, $openBills((string) $there->public_id)->status(), '⛔ দফা ১৮: অন্য শাখার গ্রাহকের খোলা বিল ৪০৪ নয়');
        $this->assertSame($said($openBills($missing)), $said($openBills((string) $there->public_id)),
            '⛔ দফা ১৮: open-bills-এ অন্য শাখার গ্রাহক আর না-থাকা গ্রাহক আলাদা উত্তর পান — কে আছেন জানা যায়');
        $this->assertSame(422, $preview((string) $there->public_id)->status(), '⛔ দফা ১৮: অন্য শাখার গ্রাহকের প্রিভিউ "গ্রাহক চেনা নেই" নয়');
        $this->assertSame($said($preview($missing)), $said($preview((string) $there->public_id)),
            '⛔ দফা ১৮: প্রিভিউতে অন্য শাখার গ্রাহক আর না-থাকা গ্রাহক আলাদা উত্তর পান — কে আছেন জানা যায়');

        // ── দেয়ালের SR — নিজের ডিলার $mine, অন্যের ডিলার $this->dealer
        app(\App\Core\Services\SettingsService::class)->set(\App\Core\Services\DealerScope::SWITCH, true);
        $sr = $this->person(['sales.collection.create', 'sales.collection.view', \App\Core\Services\DealerScope::OWN]);
        \App\Modules\Customer\Models\DealerBinding::query()->create(['company_id' => $this->company->id, 'user_id' => $sr->id,
            'customer_id' => $mine->id, 'starts_on' => now()->subDay()->toDateString(), 'created_by' => $this->owner->id]);
        app(\App\Core\Services\DealerScope::class)->forget();
        $this->phone($sr);

        foreach (['open-bills' => $openBills, 'preview' => $preview] as $door => $ask) {
            $walled = $said($ask((string) $this->dealer->public_id));
            $this->assertGreaterThanOrEqual(400, $walled[0], "⛔ দফা ১৮: {$door}-এ অন্যের ডিলার খুলে গেল");
            $this->assertSame($said($ask($missing)), $walled,
                "⛔ দফা ১৮: {$door}-এ দেয়ালের ওপারের গ্রাহক আর না-থাকা গ্রাহক আলাদা উত্তর পান — কে আছেন জানা যায়");
        }
    }

    /**
     * দফা ১০ — কোনো প্রিভিউ-দরজা কিছু লেখে না (fe, ২২ অক্টোবর ২০২৬: ছাড়ের ছাদ ৪৫৭ → ৪৫৯-এর শর্ত, [[EveryExcuseWeGrantedIsCountedTest]])।
     * ওয়েবের প্রিভিউ (দুবার), নিশ্চিতের আগের সারাংশ (`sales.collection.overview_new`) আর ফোনের প্রিভিউ — আগের জমা বিলে বসানো, VDS/AIT,
     * আর মালিকের সই লাগে এমন ছাড়সহ, অর্থাৎ যে পথে আদায়, তার সারি, প্রয়োগ, খাতা, অনুমোদন, নিরীক্ষা বা নম্বর লেখা হতে পারত। প্রতিটা
     * টেবিলের সারি গোনা আগে আর পরে একই।
     */
    public function test_item_10_no_preview_door_writes_anything_web_overview_or_phone(): void
    {
        $bill = $this->bill('INV-APP-NW', 5, '2000');
        $prior = $this->landed($this->push('nw-0', $this->payload(['amount' => '300', 'lines' => [], 'confirm' => true])));

        $tables = ['sal_collections', 'sal_collection_lines', 'sal_collection_applications', 'ledger_entries', 'approvals', 'audit_trails', 'issued_numbers'];
        $count = fn (): array => array_combine($tables, array_map(fn (string $t) => \Illuminate\Support\Facades\DB::table($t)->count(), $tables));
        $before = $count();
        $this->assertGreaterThan(0, $before['sal_collections'], 'প্রস্তুতিটাই ভুল — আগের জমা বসেনি');

        $web = ['customer_id' => $this->dealer->id, 'account_id' => $this->bank->id, 'trx_date' => now()->toDateString(),
            'amount' => '1600', 'vds' => '30', 'ait' => '20', 'difference' => ['kind' => 'discount', 'amount' => '50'],
            'lines' => [['kind' => 'credit', 'id' => $prior->public_id, 'amount' => '-300'], ['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '2000']]];

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->clerk->fresh());
        $first = $this->postJson(route('sales.collection.preview'), $web)->assertOk()->json();
        $this->postJson(route('sales.collection.preview'), $web)->assertOk();
        $this->post(route('sales.collection.overview_new'), $web)->assertOk();

        $this->phone($this->clerk);
        $this->postJson('/api/v1/sales/collections/preview', $this->payload(['amount' => '1600', 'vds' => '30', 'ait' => '20',
            'difference' => ['kind' => 'discount', 'amount' => '50'],
            'lines' => [['kind' => 'credit', 'id' => $prior->public_id, 'amount' => '-300.00'], ['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '2000.00']]]))
            ->assertOk();

        $this->assertNotSame([], $first['lines'] ?? [], 'প্রস্তুতিটাই ভুল — প্রিভিউ কোনো লাইন বানায়নি, দাবিটা কিছু মাপে না');
        $this->assertSame($before, $count(), '⛔ দফা ১০: প্রিভিউ বা সারাংশ কিছু লিখল — আদায়, সারি, প্রয়োগ, খাতা, অনুমোদন, নিরীক্ষা বা নম্বরের গোনা বদলেছে');
    }

    /**
     * দফা ১৮ — নাগালের একটাই নিয়ম, ওয়েব আর ফোনে (fe, ২২ অক্টোবর ২০২৬)।
     *  (ক) শাখায় বাঁধা মানুষ অন্য শাখার গ্রাহকের টাকা নিতে পারেন না: ওয়েবের প্রিভিউ, জমা আর খোলা কাগজ, ফোনের open-bills, প্রিভিউ আর
     *      সিঙ্ক — সবখানে ফেরত, যেখানে বার্তা আছে সেখানে একই বাংলা বাক্য, আর কিছুই লেখা হয় না।
     *  (খ) সব-শাখার মানুষ শাখা B-র গ্রাহকের টাকা নেন — হেডারে অন্য শাখা থাকলেও কাগজ আর খাতার প্রতিটা সারি শাখা B-র (দফা ১৪),
     *      ওয়েবে আর ফোনে।
     */
    public function test_item_18_one_reach_rule_on_the_web_and_the_phone(): void
    {
        $home = (int) $this->company->defaultBranch()->id;
        $away = \App\Models\Branch::query()->create(['company_id' => $this->company->id, 'code' => 'APP-B', 'name_en' => 'Branch B', 'is_active' => true]);
        $there = Customer::query()->create(['code' => 'APP-B1', 'name_en' => 'B Store', 'branch_id' => $away->id, 'is_active' => true]);
        $refusal = __('sales::sync.unknown_customer');

        // ── (ক) শাখায় বাঁধা
        $this->clerk->forceFill(['current_branch_id' => $home, 'view_all_branches' => false])->save();
        $tables = ['sal_collections', 'sal_collection_lines', 'sal_collection_applications', 'ledger_entries', 'approvals', 'audit_trails', 'issued_numbers'];
        $count = fn (): array => array_combine($tables, array_map(fn (string $t) => \Illuminate\Support\Facades\DB::table($t)->count(), $tables));
        $before = $count();
        $form = ['customer_id' => $there->id, 'account_id' => $this->bank->id, 'trx_date' => now()->toDateString(), 'amount' => '600', 'lines_given' => '1'];

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->clerk->fresh());
        $webPreview = $this->postJson(route('sales.collection.preview'), $form);
        $this->assertSame(422, $webPreview->status(), '⛔ দফা ১৮: ওয়েবের প্রিভিউ অন্য শাখার গ্রাহক নিল ('.$webPreview->status().')');
        $this->assertSame($refusal, $webPreview->json('errors.customer_id.0'), '⛔ দফা ১৮: ওয়েবের প্রিভিউ ফোনের বাক্যে ফেরায়নি');
        $webSave = $this->postJson(route('sales.collection.store'), [...$form, 'post' => '1']);
        $this->assertSame(422, $webSave->status(), '⛔ দফা ১৮: ওয়েবে অন্য শাখার গ্রাহকের আদায় জমা হলো ('.$webSave->status().')');
        $this->assertSame($refusal, $webSave->json('errors.customer_id.0'), '⛔ দফা ১৮: ওয়েবের জমা ফোনের বাক্যে ফেরায়নি');
        $this->assertSame(404, $this->getJson(route('sales.collection.open_items', ['customer' => $there->id]))->status(), '⛔ দফা ১৮: ওয়েবে অন্য শাখার গ্রাহকের খোলা কাগজ খুলল');

        $this->phone($this->clerk);
        $this->assertSame(404, $this->getJson('/api/v1/sales/collections/open-bills?customer='.$there->public_id)->status(), '⛔ দফা ১৮: ফোনে অন্য শাখার গ্রাহকের খোলা বিল খুলল');
        $phonePreview = $this->postJson('/api/v1/sales/collections/preview', [...$this->payload(['amount' => '600', 'lines' => []]), 'customerId' => (string) $there->public_id]);
        $this->assertSame(422, $phonePreview->status(), '⛔ দফা ১৮: ফোনের প্রিভিউ অন্য শাখার গ্রাহক নিল');
        $this->assertSame($refusal, $phonePreview->json('errors.customer_id.0'), '⛔ দফা ১৮: ফোনের প্রিভিউ আর ওয়েবের বাক্য আলাদা');
        $pushed = $this->push('reach-a', [...$this->payload(['amount' => '600', 'lines' => [], 'confirm' => true]), 'customerId' => (string) $there->public_id]);
        $this->assertNotSame(SyncChange::APPLIED, $pushed['status'] ?? null, '⛔ দফা ১৮: ফোনের সিঙ্কে অন্য শাখার গ্রাহকের আদায় বসল');
        $this->assertSame($refusal, $pushed['message'] ?? null, '⛔ দফা ১৮: সিঙ্কের ফেরত ওয়েবের বাক্যে নয়');

        $this->assertSame($before, $count(), '⛔ দফা ১৮: ফেরত দেওয়া আদায়ের কিছু লেখা হলো');

        // ── (খ) সব শাখা — হেডারে প্রধান শাখা, গ্রাহক শাখা B-র
        $this->clerk->forceFill(['current_branch_id' => $home, 'view_all_branches' => true])->save();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->clerk->fresh());
        $last = (int) Collection::acrossBranches()->max('id');
        $this->post(route('sales.collection.store'), [...$form, 'post' => '1'])->assertRedirect();
        $web = Collection::acrossBranches()->where('id', '>', $last)->where('customer_id', $there->id)->first();
        $this->assertNotNull($web, '⛔ দফা ১৮: সব-শাখার মানুষ শাখা B-র গ্রাহকের আদায় ওয়েবে বসাতে পারলেন না');

        $phone = $this->landed($this->push('reach-b', [...$this->payload(['amount' => '700', 'lines' => [], 'confirm' => true]), 'customerId' => (string) $there->public_id]));

        foreach (['ওয়েব' => $web, 'ফোন' => $phone] as $door => $paper) {
            $this->assertSame(DocumentStatus::CONFIRMED, $paper->status, "প্রস্তুতিটাই ভুল — {$door}-এর আদায় পোস্ট হয়নি");
            $this->assertSame((int) $away->id, (int) $paper->branch_id, "⛔ দফা ১৪/১৮: {$door}-এর আদায় গ্রাহকের শাখা B-তে বসেনি (হেডারের শাখায় বসল)");
            $branches = LedgerEntry::query()->where('source_type', Collection::drillSourceType())->where('source_id', $paper->id)
                ->pluck('branch_id')->map(fn ($b) => (int) $b)->unique()->values()->all();
            $this->assertSame([(int) $away->id], $branches, "⛔ দফা ১৪/১৮: {$door}-এর খাতার সারি শাখা B-র বাইরে বসল");
        }
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $extra @return array<string, mixed> — অ্যাপের `CollectionDraft.toPayload()`-এর আকারে */
    private function payload(array $extra): array
    {
        return [
            'customerId' => (string) $this->dealer->public_id,
            'trxDate' => now()->toDateString(),
            'accountId' => (string) $this->bank->public_id,
            ...$extra,
        ];
    }

    /** @param  array<string, mixed>  $payload @return array<string, mixed> এই বদলের উত্তর-সারি */
    private function push(string $changeId, array $payload): array
    {
        $out = app(SyncService::class)->push($this->clerk->fresh(), 'phone-app', 'sales', [[
            'changeId' => $changeId, 'entityType' => 'Collection', 'entityId' => null, 'operation' => 'CREATE',
            'payloadJson' => json_encode($payload), 'clientVersion' => 1,
        ]]);

        return $out[0];
    }

    /** @param  array<string, mixed>  $outcome */
    private function landed(array $outcome): Collection
    {
        $this->assertSame(SyncChange::APPLIED, $outcome['status'] ?? null, 'ফোনের আদায় বসেনি: '.json_encode($outcome));

        return Collection::acrossBranches()->where('public_id', $outcome['entityId'])->with('lines')->firstOrFail();
    }

    private function bill(string $no, int $daysAgo, string $total): SalesInvoice
    {
        return SalesInvoice::query()->create(['document_no' => $no, 'customer_id' => $this->dealer->id,
            'branch_id' => CompanyContext::branchId(), 'trx_date' => now()->subDays($daysAgo)->toDateString(),
            'due_on' => now()->addDays(10)->toDateString(),
            'subtotal' => $total, 'discount' => '0', 'tax' => '0', 'total' => $total, 'status' => DocumentStatus::CONFIRMED]);
    }

    private function mode(): TransferMode
    {
        return TransferMode::query()->create(['company_id' => $this->company->id, 'code' => 'NPSB-APP', 'name_en' => 'NPSB',
            'name_bn' => 'এনপিএসবি', 'applies_to' => 'bank', 'is_active' => true]);
    }

    private function drOn(int $accountId, Collection $collection): string
    {
        return bcadd((string) LedgerEntry::query()->where('source_type', Collection::drillSourceType())->where('source_id', $collection->id)
            ->where('account_id', $accountId)->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as n')->value('n'), '0', 4);
    }

    private function phone(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);
    }

    /** @param  list<string>  $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany((int) $this->company->id, fn () => $user->givePermissionTo(
            array_map(fn (string $k) => \Spatie\Permission\Models\Permission::findOrCreate($k, 'web'), $keys)));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
