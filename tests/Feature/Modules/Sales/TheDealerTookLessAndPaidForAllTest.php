<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ডিলার কম নিলেন, দেনা থাকল পুরো — মালিক, ২ অক্টোবর ২০২৬ ([[docs/বিক্রয়ের কাজের ধারা — ২ অক্টোবর.md]] §৪)।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * "আংশিক পৌঁছেছে" কেবল পরিমাণ লিখে রাখত: বিল পুরো, মাল খাতায় বেরিয়েই, ক্রেতার দেনা না-পাওয়া মালসহ।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * *"ডেলিভারি নিশ্চিতে কম পরিমাণ লিখলে বাকিটা নিজে ফেরত হয়"* — একই লেনদেনে একটা ফেরত, বিলের সারি ধরে
 * ([[ShortDeliveryReturn]])। ফেরতের নিজের নম্বর, বিক্রির নম্বর সূত্র।
 */
final class TheDealerTookLessAndPaidForAllTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_the_rest_comes_back_on_its_own(): void
    {
        $challan = $this->dispatched();
        [$biscuit, $tea] = $challan->lines()->orderBy('line_no')->get()->all();
        $onHand = $this->onHand((int) $biscuit->product_id);

        app(DeliveryStageService::class)->move($challan, DeliveryStage::PARTIALLY_DELIVERED, [
            'receiver_name' => 'রহিম',
            'lines' => [$biscuit->id => '2', $tea->id => '3'],
        ]);

        $invoice = SalesInvoice::query()->where('sale_no', $challan->fresh()->sale_no)->firstOrFail();
        $return = SalesReturn::query()->where('sales_invoice_id', $invoice->id)->with('lines')->first();

        $this->assertNotNull($return, '⛔ কম নেওয়ার পরেও কোনো ফেরত জন্মায়নি।');
        $this->assertSame(DocumentStatus::CONFIRMED, $return->status, '⛔ ফেরতটা খাতায় বসেনি।');
        $this->assertCount(1, $return->lines, '⛔ পুরো নেওয়া চা-ও ফেরতে উঠেছে।');
        $this->assertSame(0, bccomp('3', (string) $return->lines->first()->qty, 4), '⛔ ফেরতের পরিমাণ না-নেওয়া ৩ নয়।');
        $this->assertSame((string) $invoice->sale_no, (string) $return->sale_no, '⛔ ফেরতে বিক্রির নম্বর সূত্র নেই।');
        $this->assertSame(0, bccomp(bcadd($onHand, '3', 4), $this->onHand((int) $biscuit->product_id), 4),
            '⛔ না-নেওয়া মাল গুদামে ফেরেনি।');
    }

    /** ⛔ পাল্টা-দাবি: পুরো পৌঁছালে কোনো ফেরত নয় */
    public function test_a_full_delivery_makes_no_return(): void
    {
        $challan = $this->dispatched();

        app(DeliveryStageService::class)->move($challan, DeliveryStage::DELIVERED, ['receiver_name' => 'রহিম']);

        $this->assertSame(0, SalesReturn::query()->where('sale_no', $challan->fresh()->sale_no)->count());
    }

    private function dispatched(): DeliveryChallan
    {
        $biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $tea = Product::query()->where('name_en', 'Premium Tea 250gm')->firstOrFail();

        $service = app(DeliveryChallanService::class);
        $challan = $service->confirm($service->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [
            ['product_id' => $biscuit->id, 'delivered_qty' => '5', 'rate' => '10'],
            ['product_id' => $tea->id, 'delivered_qty' => '3', 'rate' => '165'],
        ]));

        app(DeliveryStageService::class)->move($challan, DeliveryStage::DISPATCHED);

        return $challan->fresh();
    }

    private function onHand(int $productId): string
    {
        return (string) DB::table('inv_stock_movements')
            ->where('product_id', $productId)
            ->where('warehouse_id', $this->warehouse->id)
            ->sum('floor_change');
    }
}
