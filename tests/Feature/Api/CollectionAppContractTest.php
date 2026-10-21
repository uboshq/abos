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

    /** দফা ১৮ — দেয়ালের SR অন্যের ডিলারের খোলা বিল আর প্রিভিউ পান না (৪০৪, কিছুই ফাঁস নয়) */
    public function test_item_18_a_walled_salesman_gets_no_other_dealers_bills(): void
    {
        app(\App\Core\Services\SettingsService::class)->set(\App\Core\Services\DealerScope::SWITCH, true);
        $sr = $this->person(['sales.collection.create', 'sales.collection.view', \App\Core\Services\DealerScope::OWN]);
        app(\App\Core\Services\DealerScope::class)->forget();
        $this->bill('INV-APP-SR', 5, '900');

        $this->phone($sr);
        $this->getJson('/api/v1/sales/collections/open-bills?customer='.$this->dealer->public_id)->assertNotFound();
        // ⓘ গ্রাহক দেয়ালের ওপারে — "গ্রাহক চেনা নেই" (৪২২) বা ৪০৪; দুই পথেই খাতার কোনো লাইন বা বকেয়া ফেরে না
        $preview = $this->postJson('/api/v1/sales/collections/preview', $this->payload(['amount' => '100']));
        $this->assertContains($preview->status(), [404, 422], '⛔ দফা ১৮: অন্যের ডিলারের প্রিভিউ খুলে গেল ('.$preview->status().')');
        $this->assertArrayNotHasKey('lines', (array) $preview->json(), '⛔ দফা ১৮: অন্যের ডিলারের খাতার লাইন ফাঁস হলো');
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
