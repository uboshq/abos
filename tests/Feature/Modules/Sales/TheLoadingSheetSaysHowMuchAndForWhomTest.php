<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Http\Controllers\LoadingSheetController;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\ShipmentService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * ⭐ লোডিং শিট — মালিকের বিক্রয় পরিকল্পনা (সংস্করণ ২) ধাপ ৪, ৬ অক্টোবর ২০২৬: *"পণ্য ধরে কত তুলতে হবে, চালান ধরে কার
 * জন্য"*।
 *
 * ⭐ দাবি — দুই দোকানের দুই চালানে একই পণ্য (২ আর ৩):
 *   · পণ্য ধরে যোগ একটাই সারি, ৫; কার জন্য কত — দুই দোকান আলাদা; লট থাকলে লট ধরে ভাগ
 *   · ছাপাতেও একই: একটা সারি ৫, নামের নিচে দুই দোকানের ভাগ, বিবরণে দুই চালান — আগে সারিগুলো সমান করে বিছানো ছিল
 */
final class TheLoadingSheetSaysHowMuchAndForWhomTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    public function test_one_row_per_product_with_its_lots_and_who_it_is_for_on_screen_and_on_paper(): void
    {
        [$shopA, $shopB] = Customer::query()->orderBy('id')->take(2)->get()->all();
        $first = $this->challan($shopA, '2');
        $second = $this->challan($shopB, '3');
        $trip = app(ShipmentService::class)->create([
            'trx_date' => now()->toDateString(), 'warehouse_id' => $this->warehouse->id, 'vehicle_no' => 'DM-T 11-0001',
            'driver_name' => 'করিম', 'driver_phone' => '01811-222333',
        ], [$first->id, $second->id]);

        $trip->load(['lines.challan.customer', 'lines.challan.lines.product.unit', 'lines.challan.lines.batch']);
        $totals = collect(LoadingSheetController::productTotals($trip));
        $biscuit = $totals->firstWhere('product', Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->name());

        $this->assertCount(1, $totals, '⛔ একই পণ্য দুই সারিতে।');
        $this->assertSame(0, bccomp('5', $biscuit['qty'], 4), '⛔ পণ্য ধরে যোগ ২ + ৩ নয়।');
        $this->assertCount(2, $biscuit['for'], '⛔ কার জন্য কত — দুই দোকান আলাদা নয়।');
        $this->assertSame(0, bccomp('5', (string) array_sum(array_map('floatval', $biscuit['for'])), 2));
        if ($biscuit['lots'] !== []) {
            $this->assertSame(0, bccomp('5', (string) array_sum(array_map('floatval', $biscuit['lots'])), 2), '⛔ লটের ভাগ মোটের সমান নয়।');
        }

        $seen = null;
        View::composer('print.document', function ($view) use (&$seen) {
            $seen = $view->getData()['doc'] ?? null;
        });
        $this->get(route('sales.print.loading_sheet', $trip))->assertOk();
        View::getFacadeRoot()->getDispatcher()->forget('composing: print.document');

        $this->assertNotNull($seen, 'লোডিং শিট print.document দিয়ে আঁকা হয়নি।');
        $this->assertCount(1, $seen->lines, '⛔ ছাপায় একই পণ্য বারবার — যোগ নেই।');
        $this->assertSame('5', $seen->lines[0]['qty'], '⛔ ছাপার পরিমাণ পণ্য ধরে যোগ নয়।');
        $this->assertStringContainsString($shopA->name(), $seen->lines[0]['note'], '⛔ ছাপায় কার জন্য কত নেই।');
        $this->assertStringContainsString($shopB->name(), $seen->lines[0]['note']);
        $this->assertStringContainsString($first->document_no, (string) $seen->narration, '⛔ ছাপায় চালান ধরে ভাগ নেই।');
        $this->assertStringContainsString($second->document_no, (string) $seen->narration);
        $this->assertSame('01811-222333', $seen->meta['sales::field.driver_phone'] ?? null);
    }

    private function challan(Customer $customer, string $qty): DeliveryChallan
    {
        $service = app(DeliveryChallanService::class);

        return $service->confirm($service->create([
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'delivered_qty' => $qty, 'rate' => '10']]));
    }
}
