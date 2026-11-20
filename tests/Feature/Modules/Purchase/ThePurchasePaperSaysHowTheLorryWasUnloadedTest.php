<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\LabourCost;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\Concerns\HiresTheLabour;
use Tests\TestCase;

/**
 * ⭐ মাল গ্রহণ আর ক্রয় বিলের কাগজে আনলোডিং এক লাইনে — মালিক, ১৮ নভেম্বর ২০২৬ ("ওকে"; ২০ নভেম্বর ২০২৬ দাবি)।
 *
 * ⓘ মালিকের কারণ: গুদামের লোক নামিয়েছেন আর দিয়েছেন — "দামহীন" মাল গ্রহণের কাগজেও কীভাবে আর কত লেখা থাকে
 *   ([[PurchasePrintController::receipt()]])। বাছা না থাকলে ঘরটা খালি, কাগজে বসে না।
 */
final class ThePurchasePaperSaysHowTheLorryWasUnloadedTest extends TestCase
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

        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->whereNull('tax_id')->where('track_batch', false)->where('track_serial', false)
            ->where('qc_required', false)->orderBy('id')->firstOrFail();
    }

    public function test_the_goods_receipt_paper_carries_the_unloading_line(): void
    {
        $contractor = $this->labourContractor();
        $receipt = app(PurchaseReceiptService::class)->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString(),
                'unload_mode' => 'due', 'unload_amount' => '85', 'unload_contractor_id' => $contractor->id],
            [['product_id' => $this->product->id, 'received_qty' => '10', 'rate' => '100']],
        );

        $doc = $this->printed('print.document', route('purchase.print.receipt', $receipt));
        $line = (string) ($doc->meta['supplier::labour.unloading'] ?? '');

        $this->assertSame(app(LabourCost::class)->describe($receipt->fresh(), 'unload_'), $line, '⛔ মাল গ্রহণের কাগজে আনলোডিংয়ের লাইন নেই।');
        $this->assertStringContainsString(__('supplier::labour.mode_due'), $line);
        $this->assertStringContainsString($contractor->name(), $line, '⛔ কাগজে ঠিকাদারের নাম নেই।');
        $this->assertStringContainsString('85', $line, '⛔ কাগজে মজুরির অঙ্ক নেই।');
    }

    public function test_a_receipt_without_a_choice_prints_no_unloading_line(): void
    {
        $receipt = app(PurchaseReceiptService::class)->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'received_qty' => '10', 'rate' => '100']],
        );

        $doc = $this->printed('print.document', route('purchase.print.receipt', $receipt));

        $this->assertSame('', (string) ($doc->meta['supplier::labour.unloading'] ?? ''), 'বাছা নেই, তবু কাগজে আনলোডিংয়ের লেখা বসল।');
    }

    public function test_the_direct_purchase_bill_paper_carries_the_unloading_line(): void
    {
        $this->post(route('purchase.direct.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'UNL-PRINT-1',
            'lines' => [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '60']],
            'unload_mode' => 'none',
            'unload_reason' => 'Supplier crew unloaded',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $bill = PurchaseBill::query()->latest('id')->firstOrFail();

        // ⓘ বিলের ডিফল্ট নতুন কাগজ ([[TheNewPurchaseBillPaperTest]])
        $doc = $this->printed('purchase::print.bill-modern', route('purchase.print.bill', $bill));
        $line = (string) ($doc->meta['supplier::labour.unloading'] ?? '');

        $this->assertSame(app(LabourCost::class)->describe($bill, 'unload_'), $line, '⛔ ক্রয় বিলের কাগজে আনলোডিংয়ের লাইন নেই।');
        $this->assertStringContainsString('Supplier crew unloaded', $line, '⛔ "লাগেনি"-র কারণ কাগজে নেই।');
    }

    private function printed(string $view, string $url): \App\Core\Engines\Print\PrintableDocument
    {
        $seen = [];
        View::composer($view, function ($v) use (&$seen) {
            $seen = $v->getData();
        });

        $this->get($url)->assertOk();
        $this->assertArrayHasKey('doc', $seen, "কাগজটা {$view} দিয়ে আঁকা হয়নি — দাবি অন্ধ।");

        return $seen['doc'];
    }
}
