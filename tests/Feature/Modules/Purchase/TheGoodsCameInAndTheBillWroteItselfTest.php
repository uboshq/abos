<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * মাল এল, আর বিলটা নিজেই লেখা হলো।
 *
 * ── ⭐ মালিকের নির্দেশ, ১৯ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"ক্রয় বিল থাকার দরকার নেই, এটা ক্রয় বিলের list হবে শুধু। Purchase
 * Order দিলে Goods Received-এ ঐ নম্বর ধরে Goods Received করবে, তখন অটো
 * purchase invoice জেনারেট হবে। Direct Purchase-এও অটো হয়।"*
 *
 * ⓘ সমন্বয়কারী (abos-8b) তিনটা জিনিস মাপতে বলেছিলেন, আর তিনটাই এখানে:
 *   · একই মাল গ্রহণে দুইবার বিল নয়।
 *   · মাল গ্রহণ বাতিল — বিল থাকলে আটকায়, বিল বাতিলের পরে চলে।
 *   · আপনা থেকে হওয়া বিলও নিশ্চিতের পরে সম্পাদনা হয় (861ad66a-এর পথ)।
 */
final class TheGoodsCameInAndTheBillWroteItselfTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->firstOrFail();
    }

    /**
     * ⭐ "নিশ্চিত" চাপলেই বিল — নিশ্চিত, মাল গ্রহণের দরে, সারির সাথে বাঁধা।
     */
    public function test_confirming_a_receipt_writes_its_bill(): void
    {
        $receipt = $this->receive();

        $this->post(route('purchase.receipt.confirm', $receipt))
            ->assertRedirect(route('purchase.receipt.show', $receipt))
            ->assertSessionHasNoErrors();

        $bills = $this->billsOf($receipt);

        $this->assertCount(1, $bills, 'মাল গ্রহণ নিশ্চিত হলো, অথচ বিল তৈরি হয়নি।');

        $bill = $bills->first();

        $this->assertSame(DocumentStatus::CONFIRMED, $bill->status);
        $this->assertSame($this->supplier->id, (int) $bill->supplier_id);

        $line = $bill->lines->first();

        $this->assertSame(0, bccomp((string) $line->qty, '10', 4));
        $this->assertSame(0, bccomp((string) $line->rate, '172.54', 4), 'বিলের দর মাল গ্রহণের দর নয়।');
        $this->assertSame($receipt->lines->first()->id, (int) $line->purchase_receipt_line_id);

        $this->assertStringContainsString($bill->document_no, (string) session('saved'));
    }

    /**
     * ⛔ একই মাল গ্রহণে দুইবার বিল নয় — "বাকি অংশের বিল" চাপলেও না।
     */
    public function test_the_same_receipt_is_never_billed_twice(): void
    {
        $receipt = $this->receive();

        $this->post(route('purchase.receipt.confirm', $receipt));
        $this->post(route('purchase.receipt.bill', $receipt))
            ->assertSessionHas('saved', __('purchase::message.auto_bill_nothing_left'));

        $this->assertCount(1, $this->billsOf($receipt), 'একই মালের বিল দুইবার হয়েছে।');

        // ⓘ পাতাতেও বোতামটা আর নেই — বাকি কিছু নেই
        $this->get(route('purchase.receipt.show', $receipt))
            ->assertOk()
            ->assertDontSee(route('purchase.receipt.bill', $receipt), escape: false);
    }

    /**
     * ⭐ পুরনো মাল গ্রহণ (বিল ছাড়া নিশ্চিত) — পাতার বোতামে বিল হয়।
     */
    public function test_an_old_receipt_is_billed_from_its_page(): void
    {
        // ⓘ সোজা `confirm()` — নতুন নিয়মের আগের মতো, বিল ছাড়া
        $receipt = app(PurchaseReceiptService::class)->confirm($this->receive());

        $this->assertCount(0, $this->billsOf($receipt));

        $this->get(route('purchase.receipt.show', $receipt))
            ->assertOk()
            ->assertSee(route('purchase.receipt.bill', $receipt), escape: false);

        $this->post(route('purchase.receipt.bill', $receipt))->assertSessionHasNoErrors();

        $this->assertCount(1, $this->billsOf($receipt));
    }

    /**
     * ⚠️ বিল থাকলে মাল গ্রহণ বাতিল আটকায়; বিল বাতিলের পরে চলে।
     *
     * ⓘ নিঃশব্দে দুইটাই বাতিল করা হয়নি — বিলটা আলাদা কাগজ, আর তার
     * বাতিলের কারণ আলাদা করে লেখা থাকা দরকার।
     */
    public function test_a_billed_receipt_waits_for_its_bill_to_be_cancelled(): void
    {
        $receipt = $this->receive();
        $this->post(route('purchase.receipt.confirm', $receipt));

        try {
            app(PurchaseReceiptService::class)->cancel($receipt->fresh(), 'ভুল মাল');
            $this->fail('বিল থাকা অবস্থায় মাল গ্রহণ বাতিল হয়ে গেছে — দায় খাতায় দুইবার উল্টাত।');
        } catch (ValidationException) {
            // ⓘ প্রত্যাশিত
        }

        app(PurchaseBillService::class)->cancel($this->billsOf($receipt)->first(), 'ভুল মাল');

        $cancelled = app(PurchaseReceiptService::class)->cancel($receipt->fresh(), 'ভুল মাল');

        $this->assertSame(DocumentStatus::CANCELLED, $cancelled->status);
    }

    /**
     * ⭐ সরবরাহকারীর বিলে দাম আলাদা — আপনা থেকে হওয়া বিলটা খুলে সম্পাদনা।
     */
    public function test_the_written_bill_can_still_be_edited(): void
    {
        $receipt = $this->receive();
        $this->post(route('purchase.receipt.confirm', $receipt));

        $bill = $this->billsOf($receipt)->first();

        /*
         * ⓘ দাম বদলানো — চালানের সাথে না মেলা বিল কোম্পানির একটা সুইচ
         * আটকায় (`purchase.block_price_mismatch`, Control Panel)। ⚠️ এখানে
         * প্রশ্নটা সুইচ নয়, সম্পাদনার পথ খোলা কি না — তাই সুইচটা বন্ধ।
         */
        app(\App\Core\Services\SettingsService::class)->set('purchase.block_price_mismatch', false);

        $edited = app(PurchaseBillService::class)->update($bill->fresh(), [
            'supplier_id' => $this->supplier->id,
            'trx_date' => $bill->trx_date->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'qty' => '10',
            'rate' => '180',
            'purchase_receipt_line_id' => $receipt->lines->first()->id,
        ]], repost: true);

        $this->assertSame(DocumentStatus::CONFIRMED, $edited->status);
        $this->assertSame(0, bccomp((string) $edited->lines->first()->rate, '180', 4),
            'আপনা থেকে হওয়া বিল সম্পাদনা করা গেল না।');
    }

    /**
     * ⭐ ক্রয় বিলের পাতা কেবল তালিকা; মেনুতে "পরিশোধ" নেই।
     */
    public function test_the_bill_page_is_a_list_and_payments_left_the_menu(): void
    {
        $page = $this->get(route('purchase.bill.index'))->assertOk();

        $page->assertDontSee(route('purchase.bill.create'), escape: false);
        $page->assertDontSee(route('purchase.payment.index'), escape: false);
    }

    private function receive(): PurchaseReceipt
    {
        return app(PurchaseReceiptService::class)->create(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => $this->product->id,
                'received_qty' => '10',
                'rate' => '172.54',
            ]],
        );
    }

    /** @return \Illuminate\Support\Collection<int, PurchaseBill> */
    private function billsOf(PurchaseReceipt $receipt): \Illuminate\Support\Collection
    {
        return PurchaseBill::query()
            ->with('lines')
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->whereHas('lines.receiptLine', fn ($q) => $q->where('purchase_receipt_id', $receipt->id))
            ->get();
    }
}
