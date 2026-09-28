<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Investigation: a bill raised from the ORDER and a goods receipt for the
 * same order line each bring the goods into stock.
 *
 * Claims state the CORRECT outcome (goods counted once, 1120 debited once),
 * so a red run means the double is real.
 */
final class TheBillAndTheReceiptBothBroughtTheGoodsTest extends TestCase
{
    use RefreshDatabase;

    private const QTY = '50';

    private const RATE = '125.50';

    private const VALUE = '6275.0000';

    private User $owner;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
        app(SettingsService::class)->set('purchase.over_receipt_percent', 0);

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()
            ->whereNull('tax_id')
            ->where('track_batch', false)
            ->where('track_serial', false)
            ->where('qc_required', false)
            ->orderBy('id')
            ->firstOrFail();
    }

    /**
     * Live sequence: PO confirmed -> GRN held for approval -> bill from the PO
     * confirmed -> GRN approved and confirmed.
     */
    public function test_a_receipt_approved_after_the_order_bill_does_not_bring_the_goods_again(): void
    {
        $this->receiptNeedsApproval();

        $base = $this->measure();

        $order = $this->confirmedOrder();
        $orderLine = $order->lines->first();

        // ── 1. GRN written and held ───────────────────────────────────────
        $receipt = app(PurchaseReceiptService::class)->create([
            'purchase_order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'purchase_order_line_id' => $orderLine->id,
            'received_qty' => self::QTY,
            'rate' => self::RATE,
        ]]);

        $held = false;

        try {
            app(PurchaseReceiptService::class)->confirmAndBill($receipt->fresh());
        } catch (HeldForApproval) {
            $held = true;
        }

        $this->assertTrue($held, 'setup: the receipt was supposed to be held for approval');
        $this->assertSame(DocumentStatus::DRAFT, $receipt->fresh()->status);

        $afterHold = $this->measure();

        // ── 2. Bill from the ORDER (not from the GRN), confirmed ───────────
        // ⓘ The refusal may land here (bill side: a GRN already stands on
        //   this order line) or at step 3 (receipt side: the bill already
        //   took the goods in). Either is correct; exactly one must happen.
        $bills = app(PurchaseBillService::class);
        $refusedBill = null;

        try {
            $bill = $bills->create([
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ], [[
                'product_id' => $this->product->id,
                'purchase_order_line_id' => $orderLine->id,
                'qty' => self::QTY,
                'rate' => self::RATE,
            ]]);

            $bill = $bills->confirm($bill->fresh());
            $this->assertSame(DocumentStatus::CONFIRMED, $bill->status);
        } catch (ValidationException $e) {
            $refusedBill = collect($e->errors())->flatten()->first();
        }

        $afterBill = $this->measure();

        // ── 3. Approve the GRN, then confirm it (the screen's confirm) ──────
        $approval = Approval::query()
            ->where('approvable_id', $receipt->id)
            ->where('module', 'purchase')
            ->where('action', 'receipt')
            ->where('status', Approval::PENDING)
            ->sole();

        app(ApprovalEngine::class)->approve($approval, $this->owner);

        $refused = null;
        $result = null;

        try {
            $result = app(PurchaseReceiptService::class)->confirmAndBill($receipt->fresh());
        } catch (ValidationException $e) {
            $refused = collect($e->errors())->flatten()->first();
        }

        $afterGrn = $this->measure();

        // ── Exactly one route was refused, and it said why ────────────────
        $this->assertTrue(($refusedBill === null) !== ($refused === null),
            'exactly one route must be refused; bill='.var_export($refusedBill, true).' grn='.var_export($refused, true));

        if ($refusedBill !== null) {
            $this->assertSame(__('purchase::order_line.bill_from_receipt', ['no' => $receipt->document_no]), $refusedBill);
            $this->assertSame(DocumentStatus::CONFIRMED, $receipt->fresh()->status);
        } else {
            $this->assertSame(__('purchase::order_line.over_ceiling', [
                'ordered' => '50', 'received' => '0', 'billed' => '50', 'qty' => '50',
            ]), $refused);
            $this->assertSame(DocumentStatus::DRAFT, $receipt->fresh()->status, 'a refused GRN stays a draft');
        }

        // ── Correct outcome: 50 on the shelf, one layer of 50, 1120 once ──
        $this->assertSame(0, bccomp(bcsub($afterGrn['on_hand'], $base['on_hand'], 4), self::QTY, 4),
            'DOUBLE STOCK: on_hand rose by '.bcsub($afterGrn['on_hand'], $base['on_hand'], 4).' for 50 goods');
        $this->assertSame(0, bccomp(bcsub($afterGrn['layer_qty'], $base['layer_qty'], 4), self::QTY, 4),
            'DOUBLE COST LAYERS: layers rose by '.bcsub($afterGrn['layer_qty'], $base['layer_qty'], 4));
        $this->assertSame(self::VALUE, bcsub($afterGrn['inv'], $base['inv'], 4),
            'DOUBLE 1120 DEBIT: inventory rose by '.bcsub($afterGrn['inv'], $base['inv'], 4));
        $this->assertSame('0.0000', bcsub($afterGrn['grni'], $base['grni'], 4),
            '2160 left hanging: '.bcsub($afterGrn['grni'], $base['grni'], 4));
    }

    /**
     * Reverse order: GRN confirmed (auto-billed from the receipt) first, then
     * someone presses "bill against order" on the PO for the same 50.
     */
    public function test_a_bill_from_the_order_after_the_receipt_does_not_bring_the_goods_again(): void
    {
        $base = $this->measure();

        $order = $this->confirmedOrder();
        $orderLine = $order->lines->first();

        $receipt = app(PurchaseReceiptService::class)->create([
            'purchase_order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'purchase_order_line_id' => $orderLine->id,
            'received_qty' => self::QTY,
            'rate' => self::RATE,
        ]]);

        $r = app(PurchaseReceiptService::class)->confirmAndBill($receipt->fresh());
        $this->assertNotNull($r['bill'], 'setup: auto-bill expected');

        $afterGrn = $this->measure();

        $refused = null;

        try {
            $bills = app(PurchaseBillService::class);
            $bill = $bills->create([
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
                'supplier_bill_no' => 'ORD-BILL-2',
            ], [[
                'product_id' => $this->product->id,
                'purchase_order_line_id' => $orderLine->id,
                'qty' => self::QTY,
                'rate' => self::RATE,
            ]]);
            $bills->confirm($bill->fresh());
        } catch (ValidationException $e) {
            $refused = collect($e->errors())->flatten()->first();
        }

        $after = $this->measure();

        $this->assertSame(0, bccomp(bcsub($after['on_hand'], $base['on_hand'], 4), self::QTY, 4),
            'DOUBLE STOCK (reverse): on_hand rose by '.bcsub($after['on_hand'], $base['on_hand'], 4));
        $this->assertSame(self::VALUE, bcsub($after['inv'], $base['inv'], 4),
            'DOUBLE 1120 DEBIT (reverse): '.bcsub($after['inv'], $base['inv'], 4));
        $this->assertSame(__('purchase::order_line.bill_from_receipt', ['no' => $receipt->document_no]), $refused);
    }

    /**
     * The receipt's ceiling is ordered − taken in by EITHER route: after a
     * bill against the order took 30 of 50, a GRN for 30 is refused with
     * nothing posted, and a GRN for exactly the remaining 20 goes through.
     */
    public function test_a_receipt_past_what_the_order_bill_left_is_refused_with_nothing_posted(): void
    {
        $order = $this->confirmedOrder();
        $orderLine = $order->lines->first();

        $bills = app(PurchaseBillService::class);
        $bill = $bills->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'purchase_order_line_id' => $orderLine->id,
            'qty' => '30',
            'rate' => self::RATE,
        ]]);
        $bills->confirm($bill->fresh());

        $before = $this->measure();
        $entries = LedgerEntry::query()->count();
        $receipts = PurchaseReceipt::query()->count();

        $refused = null;

        try {
            app(PurchaseReceiptService::class)->create([
                'purchase_order_id' => $order->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ], [[
                'product_id' => $this->product->id,
                'purchase_order_line_id' => $orderLine->id,
                'received_qty' => '30',
                'rate' => self::RATE,
            ]]);
        } catch (ValidationException $e) {
            $refused = collect($e->errors())->flatten()->first();
        }

        $this->assertSame(__('purchase::order_line.over_ceiling', [
            'ordered' => '50', 'received' => '0', 'billed' => '30', 'qty' => '30',
        ]), $refused);

        $after = $this->measure();
        $this->assertSame($before, $after, 'a refused receipt moved stock or the books');
        $this->assertSame($entries, LedgerEntry::query()->count(), 'a refused receipt posted ledger lines');
        $this->assertSame($receipts, PurchaseReceipt::query()->count(), 'a refused receipt left a document behind');

        // ── the boundary: exactly the remaining 20 is accepted ──────────
        $receipt = app(PurchaseReceiptService::class)->create([
            'purchase_order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'purchase_order_line_id' => $orderLine->id,
            'received_qty' => '20',
            'rate' => self::RATE,
        ]]);
        app(PurchaseReceiptService::class)->confirm($receipt->fresh());

        $final = $this->measure();
        $this->assertSame(0, bccomp(bcsub($final['on_hand'], $before['on_hand'], 4), '20', 4));
        $this->assertSame('2510.0000', bcsub($final['inv'], $before['inv'], 4));
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function receiptNeedsApproval(): void
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => CompanyContext::id(),
            'module' => 'purchase',
            'action' => 'receipt',
            'document_type' => '',
            'threshold_amount' => null,
            'is_active' => true,
        ]);

        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => 'user',
            'approver_id' => $this->owner->id,
        ]);
    }

    private function confirmedOrder(): PurchaseOrder
    {
        $service = app(PurchaseOrderService::class);

        $order = $service->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->product->id, 'ordered_qty' => self::QTY, 'rate' => self::RATE]]);

        return $service->confirm($order)->load('lines');
    }

    /** @return array{on_hand: string, layer_qty: string, layers: int, inv: string, grni: string, payable: string} */
    private function measure(): array
    {
        $layers = CostLayer::query()->where('product_id', $this->product->id);

        return [
            'on_hand' => bcadd((string) app(StockService::class)->statesFor($this->product, $this->warehouse)['on_hand'], '0', 4),
            'layer_qty' => bcadd((string) (clone $layers)->sum('qty_in'), '0', 4),
            'layers' => (clone $layers)->count(),
            'inv' => $this->net(StandardChart::INVENTORY),
            'grni' => $this->net(StandardChart::GOODS_RECEIVED_NOT_INVOICED),
            'payable' => $this->net(StandardChart::PAYABLE),
        ];
    }

    private function net(string $code): string
    {
        $id = (int) Account::query()->where('code', $code)->valueOrFail('id');

        return bcadd((string) LedgerEntry::query()->where('account_id', $id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as net')->value('net'), '0', 4);
    }
}
