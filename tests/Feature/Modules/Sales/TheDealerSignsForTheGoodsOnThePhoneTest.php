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
 * ⭐ ফোনে পৌঁছানোর প্রমাণ — মালিকের বিক্রয় পরিকল্পনা (সংস্করণ ২) ধাপ ৭, ৬ অক্টোবর ২০২৬: *"ডিলার বুঝে নিলেন — নাম, ফোন;
 * QR, ফোন বা বোতাম। কম বা ভাঙা মাল → ফেরত বা দাবি"* ([[QrScanController::deliver()]])।
 *
 * ⭐ দাবি:
 *   · নাম আর ফোন দুইটাই লাগে; জমা হয় পৌঁছানোর ঘটনায়, সময়সহ
 *   · ফোন থেকে আংশিক: সারির ক্রমিক ধরে ভালো আর ভাঙা — কম বিক্রয়যোগ্য ফেরত, ভাঙা আটকে রাখা মজুদে (ওয়েবের একই সেবা)
 *   · সব সারি পুরো আর ভাঙা নেই — "পৌঁছেছে", কোনো ফেরত নয়
 *   · অচেনা ক্রমিক — ফেরত
 *   · স্ক্যানের উত্তরে সারির ক্রমিক আছে, ভেতরের id নয়
 */
final class TheDealerSignsForTheGoodsOnThePhoneTest extends TestCase
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

    public function test_a_partial_delivery_from_the_phone_sends_short_back_sellable_and_broken_back_held(): void
    {
        $challan = $this->dispatched();
        $token = app(PaperToken::class)->for($challan);
        $this->phone();

        $lines = $this->getJson('/api/v1/sales/scan/'.$token)->assertOk()->json('lines');
        $this->assertSame([1, 2], array_column($lines, 'line'), 'স্ক্যানের উত্তরে সারির ক্রমিক নেই।');
        $this->assertArrayNotHasKey('id', $lines[0], '⛔ ভেতরের id ফোনে গেল।');

        $this->postJson('/api/v1/sales/scan/'.$token.'/deliver', [
            'receiver_name' => 'রহিম', 'receiver_phone' => '01711-000000',
            'lines' => ['1' => '3'], 'damaged' => ['1' => '1'],
        ])->assertOk()->assertJsonPath('stage', DeliveryStage::PARTIALLY_DELIVERED);

        $event = DeliveryEvent::query()->where('delivery_challan_id', $challan->id)->latest('id')->firstOrFail();
        $this->assertSame(['রহিম', '01711-000000'], [$event->receiver_name, $event->receiver_phone], '⛔ কে বুঝে নিলেন, লেখা নেই।');
        $this->assertNotNull($event->occurred_at);

        $return = $this->returnOf($challan);
        $this->assertSame(0, bccomp('1', (string) $return->lines->where('to_hold', true)->sum('qty'), 4), '⛔ ভাঙা আটকে রাখা মজুদে ফেরেনি।');
        $this->assertSame(0, bccomp('1', (string) $return->lines->where('to_hold', false)->sum('qty'), 4), '⛔ কম ১টা বিক্রয়যোগ্য হয়ে ফেরেনি।');
    }

    public function test_everything_full_is_delivered_and_nothing_comes_back(): void
    {
        $challan = $this->dispatched();
        $token = app(PaperToken::class)->for($challan);
        $this->phone();

        $this->postJson('/api/v1/sales/scan/'.$token.'/deliver', [
            'receiver_name' => 'রহিম', 'receiver_phone' => '01711-000000', 'lines' => ['1' => '5', '2' => '3'],
        ])->assertOk()->assertJsonPath('stage', DeliveryStage::DELIVERED);

        $this->assertSame(0, SalesReturn::query()->where('sale_no', $challan->fresh()->sale_no)->count(), '⛔ পুরো পৌঁছালেও ফেরত জন্মাল।');
    }

    public function test_a_line_not_on_the_challan_is_refused(): void
    {
        $challan = $this->dispatched();
        $token = app(PaperToken::class)->for($challan);
        $this->phone();

        $this->postJson('/api/v1/sales/scan/'.$token.'/deliver', [
            'receiver_name' => 'রহিম', 'receiver_phone' => '01711-000000', 'lines' => ['9' => '1'],
        ])->assertUnprocessable();
        $this->assertSame(DeliveryStage::DISPATCHED, (string) app(DeliveryStageService::class)->ensure($challan->fresh())->stage);
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
