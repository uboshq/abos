<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ক্রয়ের কাগজের খাতা — রিপোর্ট সেন্টার ধাপ ৬ ([[PurchaseRegisterReports]])।
 *
 * ১০টা আদেশ, ৬টা এল (মাল-গ্রহণেই বিল) → খাতায় তিন সারি: আদেশ (এসেছে ৬০%, বিল ৬০%), গ্রহণ (বিল ১০০%), বিল (১০০%)।
 * ⓘ খোলা কাগজের বয়স আছে; আর এক শাখা বাছলে অন্য শাখার কাগজ খাতায় নেই।
 */
final class ThePurchaseRegisterShowsEachPaperAndHowFarItGotTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_paper_is_one_row_with_its_match_and_age_and_the_branch_wall_holds(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->whereNull('tax_id')->where('track_batch', false)->where('track_serial', false)
            ->where('qc_required', false)->orderBy('id')->firstOrFail();

        $orders = app(PurchaseOrderService::class);
        $order = $orders->confirm($orders->create([
            'supplier_id' => Supplier::query()->orderBy('id')->value('id'),
            'warehouse_id' => $warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $product->id, 'ordered_qty' => '10', 'rate' => '100']]))->load('lines');

        $receipt = app(PurchaseReceiptService::class)->create([
            'purchase_order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $product->id, 'purchase_order_line_id' => $order->lines->first()->id, 'received_qty' => '6', 'rate' => '100']]);
        $this->post(route('purchase.receipt.confirm', $receipt))->assertSessionHasNoErrors();

        $bill = PurchaseBill::query()->where('status', '<>', DocumentStatus::CANCELLED)
            ->whereHas('lines.receiptLine', fn ($q) => $q->where('purchase_receipt_id', $receipt->id))->sole();

        $rows = $this->rows([]);

        $this->assertSame(['60', '60'], $this->pct($rows, $order->document_no), 'আদেশের সারি: এসেছে ৬০%, বিল ৬০% নয়।');
        $this->assertSame(['', '100'], $this->pct($rows, $receipt->fresh()->document_no), 'গ্রহণের সারি: বিল ১০০% নয়।');
        $this->assertSame(['', '100'], $this->pct($rows, $bill->document_no));
        $this->assertSame('0', (string) $this->row($rows, $order->document_no)['age_days'], 'খোলা আদেশের বয়স নেই।');

        // ⛔ অন্য শাখার কাগজ — এক শাখা বাছলে খাতায় নেই, "সব শাখা"-য় আছে
        $other = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)
            ->where('id', '<>', $company->defaultBranch()->id)->firstOrFail();
        $order->forceFill(['branch_id' => $other->id])->saveQuietly();

        $this->assertNull($this->row($this->rows(['branch_id' => $company->defaultBranch()->id]), $order->document_no, false),
            '⛔ অন্য শাখার আদেশ এই শাখার খাতায়।');
        $this->assertNotNull($this->row($this->rows([]), $order->document_no, false));
    }

    /** @return list<array<string, mixed>> */
    private function rows(array $extra): array
    {
        return app(ReportEngine::class)->run('purchase.register', [
            'from' => now()->subDay()->toDateString(), 'to' => now()->toDateString(), ...$extra,
        ], perPage: 500)->rows;
    }

    /** @param list<array<string, mixed>> $rows */
    private function row(array $rows, string $documentNo, bool $mustExist = true): ?array
    {
        foreach ($rows as $row) {
            if (($row['document_no'] ?? null) === $documentNo) {
                return $row;
            }
        }

        $mustExist && $this->fail("{$documentNo} খাতায় নেই।");

        return null;
    }

    /** @param list<array<string, mixed>> $rows */
    private function pct(array $rows, string $documentNo): array
    {
        $row = $this->row($rows, $documentNo);

        return array_map(fn ($v) => $v === null ? '' : (string) (int) $v, [$row['received_pct'], $row['billed_pct']]);
    }
}
