<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Posting\PostingException;
use App\Core\Engines\Sync\SyncService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
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
use App\Modules\Sales\Services\CollectionInput;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Sync\CollectionSync;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ টাকা নেওয়া — ফোনের চুক্তি, সার্ভারের দিকে (a4/collect-easy a3a030302-এর ক্লায়েন্ট, `collection_entry.dart`; a5-র উত্তর ১–৬,
 * ২১ অক্টোবর ২০২৬; মালিকের নকশা ১০০-এর দফা ২, ৩, ৭, ৯, ১০, ১৩, ১৮, ১৯)।
 *
 * ⛔ ২০ নভেম্বর ২০২৬ (মালিক, চূড়ান্ত: "টাকা ঢোকার কাগজ একটাই") — গ্রাহকের টাকা ঢোকে কেবল কাউন্টারের রসিদে আর হিসাবের রসিদ
 * ভাউচারে (RCV-…); ফোন আর আদায় লেখে না, মাঠ পাঠায় "টাকা জমার খবর"। তাই:
 *   · `GET collections/setup` আর `GET collections/open-bills` আগের মতোই (পড়া)
 *   · `POST collections/preview` — ৪২২, `message` আর `errors.collection` = "টাকা জমার খবর" পাঠান; কিছু লেখে না
 *   · সিঙ্কের আদায়-বদল — যে আকারেই আসুক (`lines`, ক্রেডিট, `instrument`, `transfer_mode_id`, `deposit_slip_no`, `bank_charge`, VDS/AIT,
 *     পার্থক্য, বন্ধ মাস, একই রেফারেন্স, অচেনা গ্রাহক) — একই কারণে REJECTED, কিছুই বসে না
 *   · ওয়েবের আদায়ের দরজা (জমা, প্রিভিউ, সারাংশ, খোলা কাগজ) — রসিদ ভাউচারে পাঠায় বা ৪২২
 * ⓘ আগের আদায় (খোলা বিলের ক্রেডিট ইত্যাদির জন্য) এখানে সেবা দিয়ে সরাসরি বানানো, ফোন আগে যেমন বসাত ([[paper()]])।
 * ⓘ রসিদের উত্তর আর স্লিপ আলাদা ফাইলে ([[CollectionSyncAnswerTest]]); নতুন দরজাগুলো একসাথে [[ThePhoneSendsADepositReportNotACollectionTest]]-এ।
 */
final class CollectionAppContractTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['sal_collections', 'sal_collection_lines', 'sal_collection_applications', 'ledger_entries', 'approvals', 'audit_trails', 'issued_numbers'];

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

        // ⓘ অফিসের হিসাবরক্ষক — আদায়ের চাবি আর খাতায় টাকা তোলার চাবি (আগে [[CollectionSync::officeMayCollect()]]-এর দুই চাবি)
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
        $prior = $this->paper(['amount' => '400', 'lines' => []]);
        $this->assertSame(DocumentStatus::CONFIRMED, $prior->status, 'প্রস্তুতিটাই ভুল — আগের আদায় পাকা হয়নি');
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

    /**
     * ⛔ ২০ নভেম্বর ২০২৬ (মালিক, চূড়ান্ত: "টাকা ঢোকার কাগজ একটাই") — পুরনো অ্যাপ যে আকারেই আদায় পাঠাক, সিঙ্কে একই কারণে REJECTED
     * ("টাকা জমার খবর" পাঠান) আর ফোনের প্রিভিউ একই কথায় ৪২২; আদায়, সারি, প্রয়োগ, খাতা, অনুমোদন, নিরীক্ষা বা নম্বরের কিছুই লেখা হয় না।
     * ⓘ আগে এই ফাইলের আলাদা দাবিগুলো (দফা ২: ট্রান্সফারের মোড/রেফারেন্স/চার্জ, নগদ জমার স্লিপ, রেফারেন্স ছাড়া পোস্ট নয়; দফা ৩: `lines`
     * না থাকা/খালি, ক্রেডিটের চিহ্ন; দফা ৪: ঋণাত্মক রাউন্ডিং; দফা ৫: ব্যাংকে-বসা ১১৩৫-এ AIT; দফা ৭ আর ১৩: একই রেফারেন্স, বন্ধ মাস)
     * ফোনের লেখা মাপত — সেই লেখাই আর নেই, তাই প্রতিটা আকার এখানে একই ফেরতে। ⓘ কারণ গ্রাহক বা বিল খোঁজার আগেই, তাই অচেনা গ্রাহকেরও একই কথা।
     */
    public function test_every_payload_the_old_app_sent_is_refused_for_the_deposit_report_and_writes_nothing(): void
    {
        $prior = $this->paper(['amount' => '500', 'lines' => []]);
        $this->paper(['amount' => '100', 'lines' => [], 'instrument' => 'transfer', 'instrument_no' => 'NP-SAME-1', 'instrumentNo' => 'NP-SAME-1']);
        $mode = $this->mode();
        $bill = $this->bill('INV-APP-ALL', 5, '1200');
        $said = CollectionSync::useTheDepositReport();

        $payloads = [
            'lines absent' => ['amount' => '300'],
            'lines empty' => ['amount' => '300', 'lines' => []],
            'credit negative' => ['amount' => '700', 'lines' => [
                ['kind' => 'credit', 'id' => $prior->public_id, 'amount' => '-500.00'], ['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '1200.00']]],
            'credit positive' => ['amount' => '700', 'lines' => [
                ['kind' => 'credit', 'id' => $prior->public_id, 'amount' => '500.00'], ['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '1200.00']]],
            'bank transfer' => ['amount' => '1200', 'instrument' => 'transfer', 'transfer_mode_id' => (string) $mode->public_id,
                'instrument_no' => 'NP2610210457812', 'instrumentNo' => 'NP2610210457812', 'value_date' => now()->subDay()->toDateString(),
                'bank_charge' => '15.00', 'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '1200.00']]],
            'cash deposit' => ['amount' => '2000', 'lines' => [], 'instrument' => 'deposit', 'deposit_slip_no' => 'DS-7781'],
            'no reference' => ['amount' => '500', 'lines' => [], 'instrument' => 'transfer'],
            'vds ait rounding' => ['amount' => '1000', 'vds' => '150', 'ait' => '40', 'difference' => ['kind' => 'rounding', 'amount' => '10'],
                'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '1200.00']]],
            'negative rounding' => ['amount' => '1199.49', 'difference' => ['kind' => 'rounding', 'amount' => '-0.51'],
                'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '1200.00']]],
            'same reference' => ['amount' => '100', 'lines' => [], 'instrument' => 'transfer', 'instrument_no' => 'np-same-1', 'instrumentNo' => 'np-same-1'],
            'unknown customer' => ['amount' => '100', 'lines' => [], 'customerId' => (string) \Illuminate\Support\Str::uuid7()],
            'closed month' => ['amount' => '250', 'lines' => []],
        ];

        $before = $this->rows();

        foreach ($payloads as $shape => $extra) {
            if ($shape === 'closed month') {
                PeriodLock::query()->create([
                    'company_id' => $this->company->id, 'year' => (int) now()->year, 'month' => (int) now()->month,
                    'reason' => 'মাস শেষ', 'locked_by' => $this->owner->id, 'locked_at' => now(),
                ]);
            }

            $payload = $this->payload([...$extra, 'confirm' => true]);

            $out = $this->push('old-'.str_replace(' ', '-', $shape), $payload);
            $this->assertSame(SyncChange::REJECTED, $out['status'] ?? null, "⛔ [{$shape}] ফোনের আদায় ফেরত হয়নি: ".json_encode($out));
            $this->assertSame($said, $out['message'] ?? null, "⛔ [{$shape}] সিঙ্কের ফেরতের কথা \"টাকা জমার খবর\" নয়");
            $this->assertArrayNotHasKey('entityId', $out, "⛔ [{$shape}] ফেরত উত্তরে কাগজের id এল");

            $this->phone($this->clerk);
            $preview = $this->postJson('/api/v1/sales/collections/preview', $payload);
            $this->assertSame(422, $preview->status(), "⛔ [{$shape}] ফোনের প্রিভিউ ৪২২ নয় ({$preview->status()})");
            $this->assertSame($said, $preview->json('message'), "⛔ [{$shape}] প্রিভিউর ফেরতের কথা সিঙ্কের কথা নয়");
            $this->assertSame([$said], $preview->json('errors.collection'), "⛔ [{$shape}] প্রিভিউর `errors.collection` নেই");
            $this->assertArrayNotHasKey('lines', (array) $preview->json(), "⛔ [{$shape}] প্রিভিউ খাতার লাইন দেখাল");
        }

        $this->assertSame($before, $this->rows(), '⛔ ফেরত আদায় বা প্রিভিউ কিছু লিখল — আদায়, সারি, প্রয়োগ, খাতা, অনুমোদন, নিরীক্ষা বা নম্বরের গোনা বদলেছে');
    }

    /**
     * প্রিভিউর কথা যিনি চাইছেন তাঁর ভাষায় (a4-এর অ্যাপ 5802d83c9, ২২ অক্টোবর ২০২৬) — ⓘ ২০ নভেম্বর ২০২৬ থেকে প্রিভিউ কেবল ফেরতের কথা
     * বলে ("টাকা জমার খবর" পাঠান); সেটাও ইংরেজির মানুষ ইংরেজিতে, বাংলার মানুষ বাংলায় পান।
     */
    public function test_the_preview_refusal_speaks_the_askers_own_language(): void
    {
        $english = (string) __('sales::sync.collection_use_deposit_report', [], 'en');
        $bangla = (string) __('sales::sync.collection_use_deposit_report', [], 'bn');
        $this->assertNotSame($english, $bangla, 'দুই ভাষার কথা একই — দাবিটা কিছু মাপছে না');

        $this->clerk->forceFill(['locale' => 'en'])->save();
        $this->phone($this->clerk);
        $this->assertSame($english, $this->postJson('/api/v1/sales/collections/preview', $this->payload(['amount' => '1800', 'lines' => []]))
            ->assertStatus(422)->json('message'), '⛔ ইংরেজির মানুষ প্রিভিউর কথা ইংরেজিতে পাননি');

        $this->clerk->forceFill(['locale' => 'bn'])->save();
        $this->phone($this->clerk);
        $this->assertSame($bangla, $this->postJson('/api/v1/sales/collections/preview', $this->payload(['amount' => '1800', 'lines' => []]))
            ->assertStatus(422)->json('message'), '⛔ বাংলার মানুষ প্রিভিউর কথা বাংলায় পাননি');
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
     * সেটা জানা যাবে না)।
     *   · শাখায় বাঁধা অফিসের লোক — অন্য শাখার গ্রাহক আর না-থাকা গ্রাহক: open-bills দুটোতেই ৪০৪ (নিজের শাখার গ্রাহকে ২০০, দাবিটা কিছু
     *     মাপে); প্রিভিউ ⛔ ২০ নভেম্বর ২০২৬ থেকে সবার জন্য একই ৪২২ ("টাকা জমার খবর") — নিজের, অন্য শাখার, না-থাকা গ্রাহক এক।
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

        $this->assertSame(404, $openBills((string) $there->public_id)->status(), '⛔ দফা ১৮: অন্য শাখার গ্রাহকের খোলা বিল ৪০৪ নয়');
        $this->assertSame($said($openBills($missing)), $said($openBills((string) $there->public_id)),
            '⛔ দফা ১৮: open-bills-এ অন্য শাখার গ্রাহক আর না-থাকা গ্রাহক আলাদা উত্তর পান — কে আছেন জানা যায়');

        $refused = [422, CollectionSync::useTheDepositReport(), ['collection' => [CollectionSync::useTheDepositReport()]]];
        $this->assertSame($refused, $said($preview((string) $mine->public_id)), '⛔ নিজের শাখার গ্রাহকের প্রিভিউ "টাকা জমার খবর"-এর ৪২২ নয়');
        $this->assertSame($refused, $said($preview((string) $there->public_id)), '⛔ দফা ১৮: অন্য শাখার গ্রাহকের প্রিভিউ নিজের শাখার উত্তর থেকে আলাদা');
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
     * আর মালিকের সই লাগে এমন ছাড়সহ। প্রতিটা টেবিলের সারি গোনা আগে আর পরে একই। ⛔ ২০ নভেম্বর ২০২৬ থেকে তিন দরজাই ৪২২ — ওয়েবের
     * দুটো "রসিদ ভাউচারে নিন", ফোনেরটা "টাকা জমার খবর" পাঠান (মালিক: "টাকা ঢোকার কাগজ একটাই")।
     */
    public function test_item_10_no_preview_door_writes_anything_web_overview_or_phone(): void
    {
        $bill = $this->bill('INV-APP-NW', 5, '2000');
        $prior = $this->paper(['amount' => '300', 'lines' => []]);

        $before = $this->rows();
        $this->assertGreaterThan(0, $before['sal_collections'], 'প্রস্তুতিটাই ভুল — আগের জমা বসেনি');

        $web = ['customer_id' => $this->dealer->id, 'account_id' => $this->bank->id, 'trx_date' => now()->toDateString(),
            'amount' => '1600', 'vds' => '30', 'ait' => '20', 'difference' => ['kind' => 'discount', 'amount' => '50'],
            'lines' => [['kind' => 'credit', 'id' => $prior->public_id, 'amount' => '-300'], ['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '2000']]];
        $onWeb = (string) __('sales::collection_rules.no_new_collection');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->clerk->fresh());
        $first = $this->postJson(route('sales.collection.preview'), $web)->assertStatus(422);
        $this->assertSame([$onWeb], $first->json('errors.collection'), '⛔ দফা ১০: ওয়েবের প্রিভিউর ফেরত রসিদ ভাউচারের কথা বলেনি');
        $this->postJson(route('sales.collection.preview'), $web)->assertStatus(422);
        $this->post(route('sales.collection.overview_new'), $web)->assertStatus(422);

        $this->phone($this->clerk);
        $phone = $this->postJson('/api/v1/sales/collections/preview', $this->payload(['amount' => '1600', 'vds' => '30', 'ait' => '20',
            'difference' => ['kind' => 'discount', 'amount' => '50'],
            'lines' => [['kind' => 'credit', 'id' => $prior->public_id, 'amount' => '-300.00'], ['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '2000.00']]]))
            ->assertStatus(422);
        $this->assertSame([CollectionSync::useTheDepositReport()], $phone->json('errors.collection'), '⛔ দফা ১০: ফোনের প্রিভিউর ফেরত "টাকা জমার খবর" বলেনি');

        $this->assertSame($before, $this->rows(), '⛔ দফা ১০: প্রিভিউ বা সারাংশ কিছু লিখল — আদায়, সারি, প্রয়োগ, খাতা, অনুমোদন, নিরীক্ষা বা নম্বরের গোনা বদলেছে');
    }

    /**
     * দফা ১৮ — নাগালের একটাই নিয়ম, ওয়েব আর ফোনে (fe, ২২ অক্টোবর ২০২৬): শাখায় বাঁধা মানুষ অন্য শাখার গ্রাহকের টাকা নিতে পারেন না —
     * ওয়েবের প্রিভিউ, জমা আর খোলা কাগজ, ফোনের open-bills, প্রিভিউ আর সিঙ্ক সবখানে ফেরত, আর কিছুই লেখা হয় না।
     * ⛔ ২০ নভেম্বর ২০২৬ থেকে (মালিক: "টাকা ঢোকার কাগজ একটাই") লেখার দরজাগুলো গ্রাহক দেখার আগেই ফেরায় — ওয়েবের জমা রসিদ ভাউচারে
     * পাঠায়, প্রিভিউ/খোলা কাগজ ৪২২, ফোনের প্রিভিউ আর সিঙ্ক "টাকা জমার খবর"; open-bills (পড়া) আগের মতোই ৪০৪।
     * ⓘ আগের (খ) অংশ — সব-শাখার মানুষের আদায় গ্রাহকের শাখায় বসে — বাদ: ওয়েব বা ফোনে নতুন আদায় আর বসেই না।
     */
    public function test_item_18_one_reach_rule_on_the_web_and_the_phone(): void
    {
        $home = (int) $this->company->defaultBranch()->id;
        $away = \App\Models\Branch::query()->create(['company_id' => $this->company->id, 'code' => 'APP-B', 'name_en' => 'Branch B', 'is_active' => true]);
        $there = Customer::query()->create(['code' => 'APP-B1', 'name_en' => 'B Store', 'branch_id' => $away->id, 'is_active' => true]);
        $onWeb = (string) __('sales::collection_rules.no_new_collection');
        $onPhone = CollectionSync::useTheDepositReport();

        $this->clerk->forceFill(['current_branch_id' => $home, 'view_all_branches' => false])->save();
        $before = $this->rows();
        $form = ['customer_id' => $there->id, 'account_id' => $this->bank->id, 'trx_date' => now()->toDateString(), 'amount' => '600', 'lines_given' => '1'];

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->clerk->fresh());
        $webPreview = $this->postJson(route('sales.collection.preview'), $form);
        $this->assertSame(422, $webPreview->status(), '⛔ দফা ১৮: ওয়েবের প্রিভিউ অন্য শাখার গ্রাহক নিল ('.$webPreview->status().')');
        $this->assertSame($onWeb, $webPreview->json('errors.collection.0'), '⛔ ওয়েবের প্রিভিউ রসিদ ভাউচারের কথায় ফেরায়নি');
        $this->post(route('sales.collection.store'), [...$form, 'post' => '1'])
            ->assertRedirect(route('accounts.voucher.create', ['type' => 'receipt']))
            ->assertSessionHasErrors('collection');
        $this->assertSame(422, $this->getJson(route('sales.collection.open_items', ['customer' => $there->id]))->status(), '⛔ ওয়েবের খোলা কাগজের দরজা এখনো খোলা');

        $this->phone($this->clerk);
        $this->assertSame(404, $this->getJson('/api/v1/sales/collections/open-bills?customer='.$there->public_id)->status(), '⛔ দফা ১৮: ফোনে অন্য শাখার গ্রাহকের খোলা বিল খুলল');
        $phonePreview = $this->postJson('/api/v1/sales/collections/preview', [...$this->payload(['amount' => '600', 'lines' => []]), 'customerId' => (string) $there->public_id]);
        $this->assertSame(422, $phonePreview->status(), '⛔ দফা ১৮: ফোনের প্রিভিউ অন্য শাখার গ্রাহক নিল');
        $this->assertSame($onPhone, $phonePreview->json('errors.collection.0'), '⛔ ফোনের প্রিভিউ "টাকা জমার খবর"-এর কথায় ফেরায়নি');
        $pushed = $this->push('reach-a', [...$this->payload(['amount' => '600', 'lines' => [], 'confirm' => true]), 'customerId' => (string) $there->public_id]);
        $this->assertSame(SyncChange::REJECTED, $pushed['status'] ?? null, '⛔ দফা ১৮: ফোনের সিঙ্কে অন্য শাখার গ্রাহকের আদায় ফেরত হয়নি');
        $this->assertSame($onPhone, $pushed['message'] ?? null, '⛔ সিঙ্কের ফেরত প্রিভিউর কথায় নয়');

        $this->assertSame($before, $this->rows(), '⛔ দফা ১৮: ফেরত দেওয়া আদায়ের কিছু লেখা হলো');
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

    /**
     * ⓘ আগের আদায় — ফোন ২০ নভেম্বর ২০২৬-এর আগে যেমন বসাত: অ্যাপের আকারের আবেদন ([[CollectionInput::fromPhone()]]), একই সেবা, আর
     * "এখনই নিশ্চিত" — অনুমোদন বা টাকার নিয়মে আটকালে খসড়া (সিঙ্কের পুরনো আচরণ)।
     *
     * @param  array<string, mixed>  $extra
     */
    private function paper(array $extra): Collection
    {
        $service = app(CollectionService::class);
        $collection = $service->createFrom(CollectionInput::fromPhone($this->payload($extra)));

        try {
            $collection = $service->confirm($collection->fresh(['lines']));
        } catch (ValidationException|PostingException) {
            // ⓘ খসড়া থাকল — আগের সিঙ্কের একই আচরণ
        }

        return Collection::acrossBranches()->whereKey($collection->id)->with('lines')->firstOrFail();
    }

    /** @return array<string, int> প্রতিটা আদায়-ছোঁয়া টেবিলের সারি গোনা */
    private function rows(): array
    {
        return array_combine(self::TABLES, array_map(fn (string $t) => \Illuminate\Support\Facades\DB::table($t)->count(), self::TABLES));
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
