<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HiresTheLabour;
use Tests\TestCase;

/**
 * ⛔ আনলোডিং বাছা না হলে মাল গ্রহণ আর সরাসরি ক্রয় পাকা হয় না — মালিক, ১৮ নভেম্বর ২০২৬ ("ওকে"; ২০ নভেম্বর ২০২৬ দাবি)।
 *
 * ⓘ মালিকের কারণ: লরি থেকে মাল নামানোর মজুরি এতদিন কোথাও লেখা হত না — হাতের টাকা থেকে চুপচাপ যেত, আর মালের দাম ততটা কম
 *   দেখাত। এখন চারটার একটা (নগদ · ঠিকাদারের কাছে বাকি · সরবরাহকারী দিলেন · লাগেনি, কারণসহ) না বাছলে কাগজ থামে।
 * ⚠️ TestCase নিয়মটা বন্ধ রাখে (পুরনো পরীক্ষার জন্য) — এই দাবি নিজে চালু করে (`abos.unloading_required`)।
 *
 * দাবি: সেবায় আর পর্দার "নিশ্চিত" দরজায় থামে, কিছুই খাতায় বা গুদামে বসে না; পাতার কার্ডে বাছাই সংরক্ষণের পরে পাকা হয়;
 *       সরাসরি ক্রয়ের দরজায় বাছাই ছাড়া কোনো বিলই থাকে না, বাছাই দিলে বিল পাকা আর মজুরি খাতায়।
 */
final class TheLorryCannotBeConfirmedUntilTheUnloadingIsChosenTest extends TestCase
{
    use HiresTheLabour;
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

        // ⛔ নিয়মটা চালু — এই দাবির পুরো বিষয়
        config(['abos.unloading_required' => true]);

        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->whereNull('tax_id')->where('track_batch', false)->where('track_serial', false)
            ->where('qc_required', false)->orderBy('id')->firstOrFail();
    }

    public function test_the_service_refuses_a_receipt_without_a_choice_and_confirms_once_one_is_saved(): void
    {
        $receipts = app(PurchaseReceiptService::class);
        $receipt = $this->draft();

        $this->refusedOn(fn () => $receipts->confirm($receipt->fresh()), 'unload_mode',
            __('supplier::labour.unloading_required', ['no' => $receipt->document_no]));

        $this->assertSame(DocumentStatus::DRAFT, $receipt->fresh()->status, '⛔ বাছাই ছাড়াই মাল গ্রহণ পাকা হলো।');
        $this->assertFalse(LedgerEntry::query()->where('source_type', PurchaseReceipt::drillSourceType())->where('source_id', $receipt->id)->exists(),
            '⛔ থেমে যাওয়া মাল গ্রহণের কিছু খাতায় বসল।');

        // ⓘ "লাগেনি" — কারণসহ; তারপর পাকা
        $receipts->setUnloading($receipt->fresh(), ['unload_mode' => 'none', 'unload_reason' => 'Supplier staff unloaded']);
        $confirmed = $receipts->confirm($receipt->fresh());

        $this->assertSame(DocumentStatus::CONFIRMED, $confirmed->status, 'পূর্ণ বাছাইয়ের পরেও পাকা হলো না।');
    }

    public function test_the_confirm_button_stops_without_a_choice_and_the_unloading_card_lets_it_through(): void
    {
        $receipt = $this->draft();

        $this->from(route('purchase.receipt.show', $receipt))
            ->post(route('purchase.receipt.confirm', $receipt))
            ->assertSessionHasErrors('unload_mode');

        $this->assertSame(DocumentStatus::DRAFT, $receipt->fresh()->status, '⛔ পর্দার বোতামে বাছাই ছাড়াই পাকা হলো।');
        $this->assertSame(0, PurchaseBill::query()->whereHas('lines', fn ($q) => $q->whereIn('purchase_receipt_line_id', $receipt->lines()->pluck('id')))->count(),
            '⛔ থেমে যাওয়া মাল গ্রহণের বিল লেখা হলো।');

        // ⓘ পাতার কার্ড — "সরবরাহকারী দিয়েছেন"
        $this->put(route('purchase.receipt.unloading', $receipt), ['unload_mode' => 'supplier'])
            ->assertRedirect(route('purchase.receipt.show', $receipt))
            ->assertSessionHasNoErrors();
        $this->assertSame('supplier', $receipt->fresh()->unload_mode, 'কার্ডের বাছাই কাগজে বসেনি।');

        $this->post(route('purchase.receipt.confirm', $receipt))->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::CONFIRMED, $receipt->fresh()->status, 'বাছাইয়ের পরে বোতামে পাকা হলো না।');
    }

    public function test_an_incomplete_choice_on_the_card_is_refused_where_it_was_typed(): void
    {
        $receipt = $this->draft();

        $this->from(route('purchase.receipt.show', $receipt))
            ->put(route('purchase.receipt.unloading', $receipt), ['unload_mode' => 'cash', 'unload_amount' => '40'])
            ->assertSessionHasErrors('unload_account_id');

        $this->assertNull($receipt->fresh()->unload_mode, '⛔ টিল ছাড়া নগদের বাছাই কাগজে বসল।');
    }

    public function test_a_direct_purchase_without_a_choice_leaves_no_bill_and_with_one_books_the_labour(): void
    {
        $bills = PurchaseBill::query()->count();

        $this->post(route('purchase.direct.store'), $this->direct())
            ->assertSessionHasErrors('unload_mode');

        $this->assertSame($bills, PurchaseBill::query()->count(), '⛔ বাছাই ছাড়া সরাসরি ক্রয়ের বিল থেকে গেল — পুরো লেনদেন ফেরার কথা।');

        $till = $this->labourTill();

        $this->post(route('purchase.direct.store'), $this->direct([
            'unload_mode' => 'cash', 'unload_amount' => '40', 'unload_account_id' => $till->id,
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $bill = PurchaseBill::query()->latest('id')->firstOrFail();

        $this->assertSame(DocumentStatus::CONFIRMED, $bill->status, 'বাছাইসহ সরাসরি ক্রয় পাকা হয়নি।');
        $this->assertSame(['cash', 0, $till->id], [$bill->unload_mode, bccomp((string) $bill->unload_amount, '40', 4), (int) $bill->unload_account_id]);
        $this->assertSame('40.0000', $this->booked(PurchaseBill::drillSourceType(), $bill->id, (int) $till->id, 'credit'),
            '⛔ নগদের মজুরি টিল থেকে কাটেনি (বিলের দাখিলায়)।');

        // ⭐ মজুরি মালের দামে — সরাসরি সারির স্তর = মাল + ৪০
        $this->assertSame(0, bccomp($this->layerValue(PurchaseBill::STOCK_SOURCE, $bill->id), bcadd(bcmul('100', '60', 4), '40', 4), 4),
            '⛔ সরাসরি ক্রয়ের মজুরি মজুদের দামে বসেনি।');
    }

    public function test_with_the_rule_off_an_old_style_receipt_still_confirms(): void
    {
        config(['abos.unloading_required' => false]);

        $confirmed = app(PurchaseReceiptService::class)->confirm($this->draft());

        $this->assertSame(DocumentStatus::CONFIRMED, $confirmed->status, 'নিয়ম বন্ধ, তবু বাছাই চাইল — পুরনো পথ ভাঙল।');
    }

    // ── যন্ত্রপাতি ──

    private function draft(): PurchaseReceipt
    {
        return app(PurchaseReceiptService::class)->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'received_qty' => '10', 'rate' => '100']],
        );
    }

    /** @return array<string, mixed> */
    private function direct(array $extra = []): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'UNL-'.fake()->unique()->numberBetween(1000, 9999),
            // ⓘ বিক্রয়দর ছাড়া সরাসরি ক্রয়ের সারি নয় — মালিক, ৩ অক্টোবর ২০২৬ (DirectPurchaseRules); আনলোডিংয়ের দাবি কেবল সেই নিয়ম মেনে
            'lines' => [['product_id' => $this->product->id, 'qty' => '100', 'rate' => '60', 'sales_price' => '75']],
            ...$extra,
        ];
    }
}
