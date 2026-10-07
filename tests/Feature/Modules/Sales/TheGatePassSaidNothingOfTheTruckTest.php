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
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\PaperToken;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ গেট পাসে পরিবহনের পুরো তথ্য — মালিক, ২ অক্টোবর ২০২৬: *"Gate Pass e transport driver details nai"*।
 *
 * দাবি: চালানে যা বসানো — ধরন, গাড়ির নম্বর, বাহক, চালকের নাম ও মোবাইল, ভাড়া — গেট পাসের কাগজে হুবহু তা;
 * ক্রেতা নিজে নিলে "গ্রাহক নিজে নিয়েছেন" আর যিনি নিলেন; অ্যাপের স্ক্যান-পর্দাও একই কথা বলে।
 */
final class TheGatePassSaidNothingOfTheTruckTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    /** @var array<string, string> */
    private array $meta = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        Customer::query()->firstOrFail()->forceFill(['credit_limit' => '100000'])->save();

        View::composer('print.document', function ($view): void {
            $this->meta = $view->getData()['doc']->meta;
        });
    }

    public function test_the_gate_pass_carries_exactly_what_the_challan_says_about_the_truck(): void
    {
        $challan = $this->challan([
            'vehicle_no' => 'ঢাকা মেট্রো ট ১১-২২৩৩', 'carrier_name' => 'রহমান ট্রান্সপোর্ট',
            'driver_name' => 'করিম চালক', 'driver_phone' => '01711000111', 'transport_cost' => '1500',
        ]);

        $meta = $this->printGatePass($challan);

        $this->assertSame(__('sales::field.transport_mode_vehicle'), $meta['sales::field.transport_mode'] ?? null, 'ধরন নেই।');
        $this->assertSame('ঢাকা মেট্রো ট ১১-২২৩৩', $meta['sales::field.vehicle_no'] ?? null, '⛔ গাড়ির নম্বর নেই।');
        $this->assertSame('রহমান ট্রান্সপোর্ট', $meta['sales::field.carrier'] ?? null, '⛔ বাহকের নাম নেই।');
        $this->assertSame('করিম চালক', $meta['sales::field.driver_name'] ?? null, '⛔ চালকের নাম নেই।');
        $this->assertSame('01711000111', $meta['sales::field.driver_phone'] ?? null, '⛔ চালকের মোবাইল নেই।');
        $this->assertStringContainsString('1,500', (string) ($meta['sales::field.transport_cost'] ?? ''), 'ভাড়া নেই।');

        // অ্যাপের স্ক্যান-পর্দা — একই কথা
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $t = $this->getJson('/api/v1/sales/scan/'.app(PaperToken::class)->for($challan))->assertOk()->json('transport');
        $this->assertSame(['vehicle', 'ঢাকা মেট্রো ট ১১-২২৩৩', 'করিম চালক', '01711000111', 'রহমান ট্রান্সপোর্ট'],
            [$t['mode'], $t['vehicle'], $t['driver'], $t['driver_phone'], $t['carrier']]);
    }

    public function test_a_customer_who_took_the_goods_himself_is_named_as_such(): void
    {
        $challan = $this->challan(['own_transport' => true, 'driver_name' => 'রহিম (দোকানের লোক)', 'driver_phone' => '01811000222']);

        $meta = $this->printGatePass($challan);

        $this->assertSame(__('sales::field.transport_mode_customer_self'), $meta['sales::field.transport_mode'] ?? null,
            '⛔ "গ্রাহক নিজে নিয়েছেন" লেখা নেই।');
        $this->assertSame('রহিম (দোকানের লোক)', $meta['sales::field.collected_by'] ?? null, '⛔ কে নিলেন, লেখা নেই।');
        $this->assertArrayNotHasKey('sales::field.driver_name', $meta, 'নিজে নিলে "চালক" নয়, "যিনি নিলেন"।');
    }

    /** ⛔ অফিসের চালান-ফর্ম বাহক, চালকের মোবাইল আর ভাড়া হারাত — এখন রাখে */
    public function test_the_office_challan_form_keeps_the_carrier_the_phone_and_the_fare(): void
    {
        // ⓘ আদেশ ছাড়া নতুন চালান ফর্ম ফেরায় ([[AChallanIsWrittenAgainstAnOrderTest]]) — তাই খসড়া চালানের "হালনাগাদ"
        $draft = app(DeliveryChallanService::class)->create([
            'customer_id' => Customer::query()->firstOrFail()->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->id, 'delivered_qty' => '5', 'rate' => '10']]);

        $this->put(route('sales.challan.update', $draft), [
            'customer_id' => $draft->customer_id,
            'warehouse_id' => $draft->warehouse_id,
            'trx_date' => now()->toDateString(),
            'vehicle_no' => 'ঢাকা-১২', 'driver_name' => 'করিম', 'driver_phone' => '01711000111',
            'carrier_name' => 'রহমান ট্রান্সপোর্ট', 'transport_cost' => '900',
            'lines' => [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->id, 'delivered_qty' => '5', 'rate' => '10']],
        ])->assertSessionHasNoErrors()->assertRedirect(route('sales.challan.show', $draft));

        $challan = $draft->fresh();
        $this->assertSame('রহমান ট্রান্সপোর্ট', $challan->carrier_name, '⛔ ফর্মের বাহক চালানে বসেনি।');
        $this->assertSame('01711000111', $challan->driver_phone);
        $this->assertSame(0, bccomp('900', (string) $challan->transport_cost, 4), '⛔ ভাড়া হারাল।');
    }

    /** @return array<string, string> */
    private function printGatePass(DeliveryChallan $challan): array
    {
        app(DeliveryStageService::class)->move($challan, DeliveryStage::DISPATCHED, ['note' => 'test']);
        $pass = GatePass::query()->where('delivery_challan_id', $challan->id)->firstOrFail();

        $this->meta = [];
        $this->actingAs($this->owner)->get(route('sales.print.gate_pass', $pass))->assertOk();
        $this->assertNotSame([], $this->meta, 'প্রস্তুতিটাই ভুল — গেট পাস print.document দিয়ে আঁকা হয়নি।');

        return $this->meta;
    }

    /** @param  array<string, mixed>  $transport */
    private function challan(array $transport): DeliveryChallan
    {
        $service = app(DeliveryChallanService::class);

        return $service->confirm($service->create([
            'customer_id' => Customer::query()->firstOrFail()->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            ...$transport,
        ], [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->id, 'delivered_qty' => '5', 'rate' => '10']]));
    }
}
