<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryEvent;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\PaperToken;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ ফোনে "আজকের ডেলিভারি" — মালিকের বিক্রয় পরিকল্পনা (সংস্করণ ২) ধাপ ৭, ৬ অক্টোবর ২০২৬ ([[DeliveryRunApiController]])।
 *
 * ⭐ দাবি: পথে থাকা চালান আসে, পৌঁছানোরটা নয়; প্রতিটার টোকেনে QR-এর একই "পৌঁছেছে" দরজা; পৌঁছানোর পরে তালিকা থেকে নামে;
 * পৌঁছানো লেখার চাবি ছাড়া ৪০৩।
 */
final class TheDriverSeesTodaysDeliveriesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    /** ⭐ আজকের ডেলিভারি — পথে থাকা চালান, প্রতিটার টোকেনে সেই QR-এর দরজা; পৌঁছানোরটা আর থাকে না; চাবি ছাড়া নয় */
    public function test_todays_deliveries_list_what_is_on_the_way_and_its_token_confirms_it(): void
    {
        $onTheWay = $this->dispatched();
        $done = $this->dispatched();
        app(DeliveryStageService::class)->move($done, DeliveryStage::DELIVERED, ['receiver_name' => 'করিম']);
        $this->phone();

        $rows = collect($this->getJson('/api/v1/sales/deliveries')->assertOk()->json('rows'));
        $this->assertContains($onTheWay->document_no, $rows->pluck('document_no')->all(), '⛔ পথে থাকা চালান তালিকায় নেই।');
        $this->assertNotContains($done->document_no, $rows->pluck('document_no')->all(), '⛔ পৌঁছে যাওয়া চালান এখনো তালিকায়।');

        $row = $rows->firstWhere('document_no', $onTheWay->document_no);
        $this->assertSame([1, 2], array_column($row['lines'], 'line'));
        $this->postJson('/api/v1/sales/scan/'.$row['token'].'/deliver', ['receiver_name' => 'রহিম', 'receiver_phone' => '01711-000000'])
            ->assertOk()->assertJsonPath('stage', DeliveryStage::DELIVERED);
        $this->assertNotContains($onTheWay->document_no, $this->getJson('/api/v1/sales/deliveries')->json('rows.*.document_no') ?? []);

        $viewer = User::factory()->create(['is_active' => true, 'current_company_id' => $this->owner->current_company_id]);
        $viewer->companies()->attach($this->owner->current_company_id, ['is_active' => true]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($viewer->fresh(), [AuthController::APP]);
        $this->getJson('/api/v1/sales/deliveries')->assertForbidden();
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function dispatched(): DeliveryChallan
    {
        $service = app(DeliveryChallanService::class);
        $challan = $service->confirm($service->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
        ], [
            ['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->id, 'delivered_qty' => '5', 'rate' => '10'],
            ['product_id' => Product::query()->where('name_en', 'Premium Tea 250gm')->firstOrFail()->id, 'delivered_qty' => '3', 'rate' => '165'],
        ]));

        app(DeliveryStageService::class)->move($challan, DeliveryStage::DISPATCHED);

        return $challan->fresh();
    }

    private function returnOf(DeliveryChallan $challan): SalesReturn
    {
        $invoice = SalesInvoice::query()->where('sale_no', $challan->fresh()->sale_no)->firstOrFail();
        $return = SalesReturn::query()->where('sales_invoice_id', $invoice->id)->with('lines')->first();
        $this->assertNotNull($return, '⛔ কম বা ভাঙা থাকার পরেও কোনো ফেরত জন্মায়নি।');

        return $return;
    }

    private function phone(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
    }
}
