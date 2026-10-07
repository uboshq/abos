<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryOrderService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনের প্রতিটা দরজায় গ্রাহকের পাশে পয়েন্ট — মালিক, ৭ অক্টোবর ২০২৬: *"app e sob jaygay customer er pase obosoi point
 * dibe nahoy cina zayna buja zayna"* ([[Customer::pointName()]])।
 *
 * ⭐ দাবি: আদেশ, DO, দরপত্র (তালিকা আর একটা), আদায়, আজকের ডেলিভারি, ফেরতের বিল, ট্র্যাকিং আর সই-বাক্সের সারি — সবখানে
 * দোকানের পয়েন্ট; রুটে বসা দোকান তার পয়েন্টের নাম পায়; পয়েন্টে না বসা দোকানে null (ফোন তখন কিছু যোগ করে না)।
 */
final class EveryPhoneDoorNamesTheShopsPointTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Customer $shop;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(StandardChart::class)->install();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(SettingsService::class)->set('customer.credit_limit_enabled', false);

        $point = $this->place('PT-EV', 'Kawran Bazar', 'কারওয়ান বাজার', Location::POINT);
        // ⓘ দোকানটা রুটে বসে — নাম আসে তার মা পয়েন্টের
        $route = $this->place('RT-EV', 'Route 9', null, Location::ROUTE, $point);
        $this->shop = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->shop->forceFill(['location_id' => $route->id])->save();
        $this->product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
    }

    public function test_the_order_the_delivery_order_and_the_quotation_name_the_point_in_the_list_and_on_the_paper(): void
    {
        $body = ['customer' => (string) $this->shop->public_id, 'lines' => [['product' => (string) $this->product->public_id, 'qty' => '2']]];
        $this->phone($this->owner);

        foreach (['orders' => 'orders', 'delivery-orders' => 'orders', 'quotations' => 'quotations'] as $door => $key) {
            $id = $this->postJson('/api/v1/sales/'.$door, $body)->assertCreated()->json('id');
            $this->assertSame('কারওয়ান বাজার', $this->getJson('/api/v1/sales/'.$door)->assertOk()->json($key.'.0.customer.point'), "⛔ {$door}: তালিকায় পয়েন্ট নেই।");
            $this->assertSame('কারওয়ান বাজার', $this->getJson('/api/v1/sales/'.$door.'/'.$id)->assertOk()->json('customer.point'), "⛔ {$door}: খোলা কাগজে পয়েন্ট নেই।");
        }
    }

    public function test_money_in_todays_deliveries_the_return_bills_and_tracking_name_the_point(): void
    {
        Collection::query()->create([
            'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => 'COL-EV-1', 'customer_id' => $this->shop->id,
            'account_id' => \App\Modules\Accounts\Models\Account::query()->postable()->firstOrFail()->id,
            'trx_date' => now()->toDateString(), 'amount' => '100', 'status' => DocumentStatus::CONFIRMED,
        ]);
        SalesInvoice::query()->create([
            'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => 'INV-EV-1', 'customer_id' => $this->shop->id,
            'trx_date' => now()->toDateString(), 'due_on' => now()->addDays(30)->toDateString(),
            'subtotal' => '500', 'discount' => '0', 'tax' => '0', 'total' => '500', 'status' => DocumentStatus::CONFIRMED,
        ]);
        $challans = app(DeliveryChallanService::class);
        $challan = $challans->confirm($challans->create([
            'customer_id' => $this->shop->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->product->id, 'delivered_qty' => '1', 'rate' => '10']]));
        app(DeliveryStageService::class)->move($challan->fresh(), DeliveryStage::DISPATCHED);

        $this->phone($this->owner);
        $money = collect($this->getJson('/api/v1/sales/collections?from='.now()->subDay()->toDateString().'&to='.now()->toDateString())->assertOk()->json('rows'))
            ->firstWhere('no', 'COL-EV-1');
        $this->assertSame('কারওয়ান বাজার', $money['customer_point'] ?? null, '⛔ আদায়ের সারিতে পয়েন্ট নেই।');

        $run = collect($this->getJson('/api/v1/sales/deliveries')->assertOk()->json('rows'))->firstWhere('document_no', $challan->document_no);
        $this->assertSame('কারওয়ান বাজার', $run['customer_point'] ?? null, '⛔ আজকের ডেলিভারিতে পয়েন্ট নেই।');

        $bill = collect($this->getJson('/api/v1/sales/returns/setup')->assertOk()->json('invoices'))->firstWhere('no', 'INV-EV-1');
        $this->assertSame('কারওয়ান বাজার', $bill['customer_point'] ?? null, '⛔ ফেরতের বিলের তালিকায় পয়েন্ট নেই।');

        $this->assertContains('কারওয়ান বাজার', collect($this->getJson('/api/v1/sales/tracking')->assertOk()->json('rows'))->pluck('customer_point')->all(),
            '⛔ ট্র্যাকিং-এ পয়েন্ট নেই।');
    }

    public function test_the_signers_inbox_row_names_the_shop_with_its_point_and_a_shop_on_no_point_names_none(): void
    {
        $supervisor = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $supervisor->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $supervisor->givePermissionTo(Permission::findOrCreate('approval.decide', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => 'sales', 'action' => DeliveryOrderService::APPROVAL_ACTION, 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $supervisor->id]);

        $other = Customer::query()->whereKeyNot($this->shop->id)->orderBy('id')->firstOrFail();
        $other->forceFill(['location_id' => null])->save();
        $service = app(DeliveryOrderService::class);
        foreach ([$this->shop, $other] as $customer) {
            $service->submit($service->create(['customer_id' => $customer->id, 'trx_date' => now()->toDateString()],
                [['product_id' => $this->product->id, 'qty' => '2']], $this->owner), $this->owner);
        }

        $this->phone($supervisor);
        $summaries = array_column($this->getJson('/api/v1/approvals/pending')->assertOk()->json('rows'), 'summary');
        $mine = collect($summaries)->first(fn ($s) => str_starts_with((string) $s, $this->shop->name()));
        $theirs = collect($summaries)->first(fn ($s) => str_starts_with((string) $s, $other->name()));
        $this->assertStringStartsWith($this->shop->name().' · কারওয়ান বাজার', (string) $mine, '⛔ সই-বাক্সের সারিতে দোকানের পয়েন্ট নেই।');
        $this->assertStringNotContainsString('কারওয়ান', (string) $theirs, '⛔ পয়েন্ট ছাড়া দোকানে অন্যের পয়েন্ট বসল।');
        $this->assertNotNull($theirs);
        // ⛔ পয়েন্ট নেই — ফাঁকা অংশ নয় ("নাম ·  · …")
        $this->assertNotContains('', array_map('trim', explode(' · ', (string) $theirs)), '⛔ পয়েন্ট ছাড়া দোকানে ফাঁকা "·" বসল।');
    }

    private function place(string $code, string $en, ?string $bn, string $level, ?Location $parent = null): Location
    {
        return Location::query()->create([
            'company_id' => $this->company->id, 'parent_id' => $parent?->id, 'code' => $code,
            'name_en' => $en, 'name_bn' => $bn, 'level' => $level, 'is_active' => true,
        ]);
    }

    private function phone(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);
    }
}
