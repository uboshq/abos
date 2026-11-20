<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ টাকা ঢোকার কাগজ একটাই — মালিক, ২০ নভেম্বর ২০২৬ (চূড়ান্ত: "one money-in paper")।
 *
 * ⓘ গ্রাহকের টাকা ঢোকে কেবল দুই দরজায় — কাউন্টারের সরাসরি বিক্রির রসিদ আর হিসাবের রসিদ ভাউচার (RCV-…)। পুরনো আদায়ের কাগজ
 *   (COL-…) ওয়েব থেকে আর লেখা বা বদলানো যায় না ([[CollectionController]]):
 *   · নতুন/বদলের পাতা রসিদের পর্দায় পাঠায় — গ্রাহক ভরা, বিল ধরে এলে বিল আর বকেয়াও, সাথে কারণের নোটিশ;
 *   · store কারণসহ রসিদের পর্দায় ফেরায়, update/confirm কাগজের পাতায় — কিছুই লেখা বা বদলানো হয় না;
 *   · preview, open_items, overview_new — ৪২২, কারণসহ;
 *   · আগের কাগজ তালিকায়, পাতায় আর ছাপায় আগের মতোই খোলে।
 */
final class NoNewCollectionIsWrittenFromTheWebTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
    }

    public function test_the_new_collection_page_sends_to_the_receipt_with_the_party_filled(): void
    {
        $receipt = fn (array $more = []) => route('accounts.voucher.create', ['type' => 'receipt', ...$more]);

        $this->get(route('sales.collection.create'))
            ->assertRedirect($receipt())
            ->assertSessionHas('warning', __('sales::collection_rules.now_on_receipt'));

        $party = ['party_type' => 'customer', 'party_id' => $this->customer->id];
        $this->get(route('sales.collection.create', ['customer' => $this->customer->id]))->assertRedirect($receipt($party));
        $this->get(route('sales.collection.create', ['customer' => (string) $this->customer->public_id]))->assertRedirect($receipt($party));

        // ⓘ বিল ধরে এলে — বিল আর তার বকেয়াও (বিলের তালিকার "⋯ → টাকা নিন"-এর একই ঠিকানা)
        $bill = $this->creditBill();
        $due = SalesInvoice::query()->withCollected()->whereKey($bill->id)->firstOrFail()->dueAmount();
        $this->assertSame(1, bccomp($due, '0', 4), 'প্রস্তুতিটাই ভুল — বিলে বকেয়া নেই');

        $this->get(route('sales.collection.create', ['sales_invoice_id' => $bill->id]))
            ->assertRedirect($receipt([...$party, 'against_type' => SalesInvoice::drillSourceType(), 'against_id' => $bill->id, 'amount' => $due]));
    }

    public function test_storing_a_new_collection_is_refused_and_writes_nothing(): void
    {
        $papers = Collection::acrossBranches()->count();
        $rows = LedgerEntry::query()->count();

        $this->post(route('sales.collection.store'), [
            'customer_id' => $this->customer->id, 'account_id' => $this->cashAccountId(), 'trx_date' => now()->toDateString(),
            'amount' => '500', 'post' => '1',
        ])->assertRedirect(route('accounts.voucher.create', ['type' => 'receipt']))
            ->assertSessionHasErrors(['collection' => __('sales::collection_rules.no_new_collection')]);

        $this->assertSame($papers, Collection::acrossBranches()->count(), '⛔ ওয়েব থেকে নতুন আদায়ের কাগজ লেখা হলো।');
        $this->assertSame($rows, LedgerEntry::query()->count(), '⛔ ফেরানো আদায় খাতায় সারি লিখল।');
    }

    public function test_an_existing_draft_cannot_be_changed_or_confirmed_from_the_web(): void
    {
        $draft = $this->draft();
        $rows = LedgerEntry::query()->count();

        $this->get(route('sales.collection.edit', $draft))
            ->assertRedirect(route('accounts.voucher.create', ['type' => 'receipt', 'party_type' => 'customer', 'party_id' => $this->customer->id]))
            ->assertSessionHas('warning', __('sales::collection_rules.now_on_receipt'));

        $this->put(route('sales.collection.update', $draft), [
            'customer_id' => $this->customer->id, 'account_id' => $this->cashAccountId(), 'trx_date' => now()->toDateString(), 'amount' => '9999',
        ])->assertRedirect(route('sales.collection.show', $draft))
            ->assertSessionHasErrors(['collection' => __('sales::collection_rules.no_new_collection')]);
        $this->assertSame(0, bccomp((string) $draft->fresh()->amount, '4000', 4), '⛔ ওয়েব থেকে খসড়া আদায়ের অঙ্ক বদলে গেল।');

        $this->post(route('sales.collection.confirm', $draft))
            ->assertRedirect(route('sales.collection.show', $draft))
            ->assertSessionHasErrors(['collection' => __('sales::collection_rules.no_new_collection')]);
        $this->assertSame(DocumentStatus::DRAFT, $draft->fresh()->status, '⛔ ওয়েব থেকে খসড়া আদায় পাকা হয়ে গেল।');
        $this->assertSame($rows, LedgerEntry::query()->count(), '⛔ ফেরানো পাকা-করা খাতায় সারি লিখল।');
    }

    public function test_the_form_helpers_answer_422_with_the_reason(): void
    {
        $papers = Collection::acrossBranches()->count();
        $form = ['customer_id' => $this->customer->id, 'account_id' => $this->cashAccountId(), 'trx_date' => now()->toDateString(), 'amount' => '500'];

        $answers = [
            'প্রিভিউ' => $this->postJson(route('sales.collection.preview'), $form),
            'খোলা কাগজ' => $this->getJson(route('sales.collection.open_items', ['customer' => $this->customer->id])),
            'নিশ্চিতের সারাংশ' => $this->postJson(route('sales.collection.overview_new'), $form),
        ];

        foreach ($answers as $door => $answer) {
            $this->assertSame(422, $answer->status(), "⛔ {$door}: পুরনো আদায়ের দরজা এখনো উত্তর দেয় (".$answer->status().')।');
            $this->assertSame(__('sales::collection_rules.no_new_collection'), $answer->json('message'), "⛔ {$door}: কারণ বলেনি।");
            $this->assertSame(__('sales::collection_rules.no_new_collection'), $answer->json('errors.collection.0'), "⛔ {$door}: ঘরের কারণ নেই।");
        }

        $this->assertSame($papers, Collection::acrossBranches()->count(), '⛔ পুরনো দরজার সাহায্যকারী কাগজ লিখল।');
    }

    public function test_an_old_collection_still_opens_in_the_list_its_page_and_its_print(): void
    {
        $draft = $this->draft();

        // ⓘ তালিকা কেবল খোলা মাপা — খসড়া বসে গ্রাহকের শাখায়, হেডারের শাখার ছাঁকনি তাকে লুকাতে পারে
        $this->get(route('sales.collection.index'))->assertOk();
        $this->get(route('sales.collection.show', $draft))->assertOk()->assertSee((string) $draft->document_no);
        $this->get(route('sales.print.receipt', $draft))->assertOk();
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** ⓘ আগে লেখা খসড়া আদায় — সেবা সরাসরি (কাউন্টারের POS যে পথে ডাকে), ওয়েবের দরজা নয় */
    private function draft(): Collection
    {
        return app(CollectionService::class)->create(
            ['customer_id' => $this->customer->id, 'trx_date' => now()->toDateString(), 'amount' => '4000',
                'account_id' => StandardChart::find(StandardChart::CASH_IN_HAND)->id],
            [],
        )->fresh();
    }

    private function cashAccountId(): int
    {
        return (int) StandardChart::find(StandardChart::CASH_IN_HAND)->id;
    }

    /** ⓘ একটা বাকির বিল — [[MoneyIsTakenOnTheReceiptVoucherTest]]-এর একই প্রস্তুতি */
    private function creditBill(): SalesInvoice
    {
        app(DirectSaleService::class)->complete(
            ['customer_id' => $this->customer->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'own_transport' => '1'],
            [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '5', 'rate' => '1250', 'free_qty' => '0']],
        );

        return SalesInvoice::query()->where('customer_id', $this->customer->id)->where('status', DocumentStatus::CONFIRMED)->latest('id')->firstOrFail();
    }
}
