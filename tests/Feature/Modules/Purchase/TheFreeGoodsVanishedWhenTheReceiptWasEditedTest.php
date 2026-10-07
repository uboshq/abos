<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * মাল গ্রহণের চালানে ফ্রি উধাও — মালিকের অভিযোগ, ৩ অক্টোবর ২০২৬ (স্টার লাইন):
 * *"পারচেস চালানে ফ্রি হারা গেছে … ফ্রি আসতেছে না"*।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * `receipt/form.blade.php`-র `$seed` সংরক্ষিত সারি থেকে পরিমাণ, দর, বিক্রয়মূল্য তুলত — ফ্রি নয়,
 * লট নম্বর/মেয়াদ/ছাপা দামও নয়। খসড়া চালান খুলে "সংরক্ষণ" চাপলেই ফ্রি ঘর খালি যেত, সেবা পড়ত ০,
 * আর নিশ্চিত করলে `:free` মজুদে কিছুই ঢুকত না। বিলের ফর্মে একই ফাঁক বন্ধ হয়েছিল dabaf411-এ
 * ([[TheFreeGoodsVanishedWhenTheBillWasEditedTest]])।
 *
 * ⭐ দাবিগুলো ব্রাউজারের পথেই: সম্পাদনার পাতা খোলা, পাতা যে সারি দেয় হুবহু সেগুলোই জমা দেওয়া
 * (মানুষটা কিছু না ছুঁয়ে "সংরক্ষণ" চাপলেন), তারপর নিশ্চিত করে বসানোর পর্দার ফ্রি মাপা।
 */
final class TheFreeGoodsVanishedWhenTheReceiptWasEditedTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Supplier $supplier;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->product = Product::query()->where('track_batch', false)->whereNotNull('unit_id')->firstOrFail();
        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    /** ⓘ নিয়ন্ত্রণ: সম্পাদনা ছাড়া চালান নিজে ফ্রি হারায় না — এটা লাল হলে নিচের দাবিগুলো ভুল জিনিস মাপত */
    public function test_a_receipt_confirmed_without_an_edit_keeps_its_free_goods(): void
    {
        $receipt = $this->receivedAtTheGate(null);

        $this->post(route('purchase.receipt.confirm', $receipt))->assertSessionHasNoErrors();

        $this->assertFreeKept($receipt, 'সম্পাদনা ছাড়া');
    }

    /** ⭐ ক্রয় আদেশ ছাড়া মাল গ্রহণ: খসড়া খুলে কিছু না বদলে "সংরক্ষণ", তারপর নিশ্চিত */
    public function test_saving_a_receipt_untouched_keeps_its_free_goods_and_its_lot(): void
    {
        $receipt = $this->receivedAtTheGate(null);

        $this->saveTheEditFormUntouched($receipt);
        $this->post(route('purchase.receipt.confirm', $receipt))->assertSessionHasNoErrors();

        $this->assertFreeKept($receipt, 'চালানের সম্পাদনা');
    }

    /** ⭐ ক্রয় আদেশ ধরে মাল গ্রহণ: আদেশে ফ্রি নেই, ফ্রি লেখা হয় চালানে — সম্পাদনায় তা টেকে, আদেশের যোগও */
    public function test_saving_a_receipt_from_an_order_untouched_keeps_its_free_goods_and_its_order_line(): void
    {
        $order = app(PurchaseOrderService::class)->confirm(app(PurchaseOrderService::class)->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'ordered_qty' => '10', 'rate' => '100', 'tax' => '0']],
        ));

        $receipt = $this->receivedAtTheGate($order);

        $this->saveTheEditFormUntouched($receipt);
        $this->post(route('purchase.receipt.confirm', $receipt))->assertSessionHasNoErrors();

        $this->assertFreeKept($receipt, 'আদেশ ধরা চালানের সম্পাদনা');
        $this->assertSame((int) $order->lines()->value('id'), (int) $receipt->fresh(['lines'])->lines->first()->purchase_order_line_id,
            '⛔ সম্পাদনায় চালানের সারি আদেশের সারি থেকে ছিটকে গেছে।');
    }

    private function receivedAtTheGate(?PurchaseOrder $order): PurchaseReceipt
    {
        $this->post(route('purchase.receipt.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_order_id' => $order?->id,
            'trx_date' => now()->toDateString(),
            'supplier_challan_no' => 'SL-77',
            'lines' => [[
                'product_id' => $this->product->id,
                'purchase_order_line_id' => $order?->lines()->value('id'),
                'received_qty' => '10',
                'free_qty' => '5',
                'batch_no' => 'LOT-SL-77',
                'expiry_date' => now()->addYear()->toDateString(),
                'mrp' => '150',
                'rate' => '100',
            ]],
        ])->assertSessionHasNoErrors();

        $receipt = PurchaseReceipt::query()->latest('id')->firstOrFail();
        $this->assertSame(DocumentStatus::DRAFT, $receipt->status);

        return $receipt;
    }

    /**
     * সম্পাদনার পাতা খুলে, পাতার নিজের সারিগুলোই জমা — ব্রাউজার যা পাঠাত।
     *
     * ⓘ সারিগুলো আসে `purchaseLineEditor({ rows: … })` থেকে; জমা যায় কেবল নাম-ধরা ঘর
     * ([[line-editor.blade.php]]) — এখানে `qty` যায় `received_qty` নামে, `link` যায় `purchase_order_line_id` নামে।
     */
    private function saveTheEditFormUntouched(PurchaseReceipt $receipt): void
    {
        $html = (string) $this->get(route('purchase.receipt.edit', $receipt))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/rows:\s*(\[.*?\]),\s*packs:/s', $html, $m),
            'সম্পাদনার পাতায় সারির তালিকাটাই পাওয়া গেল না — দাবিটা কিছু মাপছে না।');

        $rows = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true, 512, JSON_THROW_ON_ERROR);

        $this->assertNotEmpty($rows);

        $lines = array_map(fn (array $row) => [
            'product_id' => $row['product_id'] ?? '',
            'purchase_order_line_id' => $row['link'] ?? '',
            'received_qty' => $row['qty'] ?? '',
            'free_qty' => $row['free_qty'] ?? '',
            'unit_id' => $row['unit_id'] ?? '',
            'batch_no' => $row['batch_no'] ?? '',
            'expiry_date' => $row['expiry_date'] ?? '',
            'mrp' => $row['mrp'] ?? '',
            'rate' => $row['rate'] ?? '',
            'sales_price' => $row['sales_price'] ?? '',
        ], $rows);

        $this->put(route('purchase.receipt.update', $receipt), [
            'supplier_id' => $receipt->supplier_id,
            'warehouse_id' => $receipt->warehouse_id,
            'purchase_order_id' => $receipt->purchase_order_id,
            'trx_date' => $receipt->trx_date->toDateString(),
            'supplier_challan_no' => $receipt->supplier_challan_no,
            'lines' => $lines,
        ])->assertSessionHasNoErrors()->assertRedirect(route('purchase.receipt.show', $receipt));
    }

    /** চালানের সারিতে ৫ ফ্রি আর লট অক্ষত, আর নিশ্চিত চালানের `:free` মজুদে বসানোর পর্দায় ৫ ফ্রি */
    private function assertFreeKept(PurchaseReceipt $receipt, string $when): void
    {
        $receipt = $receipt->fresh(['lines']);
        $this->assertSame(DocumentStatus::CONFIRMED, $receipt->status);

        $line = $receipt->lines->firstOrFail();

        $this->assertSame(0, bccomp('5', (string) $line->free_qty, 4),
            "⛔ {$when}: চালানের সারিতে ফ্রি {$line->free_qty} — থাকার কথা ৫ (\"পারচেস চালানে ফ্রি হারা গেছে\")।");

        $this->assertSame('LOT-SL-77', $line->batch_no, "⛔ {$when}: চালানের লট নম্বর মুছে গেছে।");
        $this->assertSame(0, bccomp('150', (string) $line->mrp, 4), "⛔ {$when}: চালানের ছাপা দাম মুছে গেছে।");

        $free = (string) StockMovement::query()
            ->where('source_type', PurchaseReceipt::STOCK_SOURCE.':free')
            ->where('source_id', $receipt->id)
            ->sum('unplaced_free_change');

        $this->assertSame(0, bccomp('5', $free, 4), "⛔ {$when}: বসানোর পর্দায় ফ্রি {$free} — থাকার কথা ৫ (\"ফ্রি আসতেছে না\")।");
    }
}
