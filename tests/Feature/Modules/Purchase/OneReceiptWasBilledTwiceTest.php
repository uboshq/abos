<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBillLine;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ একই রসিদ দুবার বিল হত (Purchase লাইন-ধরা নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[PurchaseBillService::resolveReceiptLine()]],
 * [[PurchaseReceiptLine::billedQty()]])।
 *
 * ⚠️ "কত বিল হয়েছে" গোনা হত দেখার শাখার দেয়াল দিয়ে, তালা ছাড়া। আগের বিলটা অন্য শাখার চোখে "নেই" — রসিদ আবার পুরো বাকি দেখাত,
 * দ্বিতীয় বিল হত, ২১৬০ দুবার কাটত, দেনা দ্বিগুণ। ⭐ এখন গোনা সব শাখা জুড়ে, রসিদের সারিতে তালা দিয়ে।
 */
final class OneReceiptWasBilledTwiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_receipt_billed_once_cannot_be_billed_again_from_another_branchs_view(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->whereNull('tax_id')->where('track_batch', false)->where('track_serial', false)
            ->where('qc_required', false)->orderBy('id')->firstOrFail();
        $receipts = app(PurchaseReceiptService::class);
        $receipt = $receipts->confirm($receipts->create(
            ['supplier_id' => Supplier::query()->orderBy('id')->firstOrFail()->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'received_qty' => '10', 'rate' => '50']],
        ));
        $line = $receipt->fresh('lines')->lines->first();

        $first = app(PurchaseBillService::class)->fromReceipt(PurchaseReceipt::query()->with('lines.product.tax')->findOrFail($receipt->id));
        $this->assertNotNull($first, 'দৃশ্যটাই বানানো যায়নি — প্রথম বিল হলো না');

        // ⓘ যিনি আবার চাপলেন তাঁর হেডারে অন্য শাখা — প্রথম বিলটা তাঁর দেখার দেয়ালের বাইরে
        $other = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)
            ->whereKeyNot((int) $first->branch_id)->orderBy('id')->firstOrFail();
        $owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $other->id])->save();
        CompanyContext::set($company->id, $other->id);
        app(DataScope::class)->forget();
        $this->actingAs($owner->fresh());

        $this->assertSame(0, bccomp($line->fresh()->unbilledQty(), '0', 4), '⛔ বিল হয়ে যাওয়া রসিদ অন্য শাখার চোখে আবার পুরো বাকি দেখায়।');

        try {
            $second = app(PurchaseBillService::class)->create(
                ['supplier_id' => $first->supplier_id, 'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'AGAIN-1'],
                [['product_id' => $product->id, 'qty' => '10', 'rate' => '50', 'purchase_receipt_line_id' => $line->id]],
            );
            $this->fail('⛔ একই রসিদের ১০টা আবার বিল হলো — বিল '.$second->document_no);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors(), 'অন্য কারণে থামল।');
        }

        $this->assertSame(1, PurchaseBillLine::query()->where('purchase_receipt_line_id', $line->id)->count(), '⛔ একই রসিদ-সারি দুই বিলে।');
    }
}
