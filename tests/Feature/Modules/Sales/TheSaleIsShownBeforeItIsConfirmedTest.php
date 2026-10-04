<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Approval\Services\OwnerSignsDiscounts;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ নিশ্চিতের আগে সারাংশ — মালিক, ৪ অক্টোবর ২০২৬: *"নিশ্চিত করুন botam caple ekta overvew dekhabe … tar por nischit
 * korbe ba khosora rakbe"* ([[ConfirmOverview]], [[DirectSaleOverview]])।
 *
 * দাবি:
 *   একই মানুষ — কাউন্টারের চাবি ছাড়া ৪০৩, চাবি দিলে সারাংশ;
 *   সারির হিসাব আর নিট বিল ঠিক, আর কিছুই লেখা হয় না (বিল, চালান, মজুদ — কিছু নয়);
 *   সীমা পার হলে "নিশ্চিত হবে না" (`blocks`), সুইচ বন্ধ থাকলে সীমার কথাই নেই;
 *   ছাড় থাকলে "মালিকের সই লাগবে"।
 */
final class TheSaleIsShownBeforeItIsConfirmedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    private Batch $lot;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '100000'])->save();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
        $this->product->update(['track_batch' => true]);
        $this->lot = Batch::query()->create([
            'company_id' => $this->company->id, 'product_id' => $this->product->id,
            'batch_no' => 'LOT-OV', 'expiry_date' => '2028-01-01',
        ]);
        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse, sourceType: 'opening', sourceId: $this->lot->id,
            floor: '100', date: now()->toDateString(), documentNo: 'TEST-LOT-OV', batch: $this->lot,
        );

        $this->seller = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $this->seller->companies()->attach($this->company->id, ['is_active' => true]);
    }

    public function test_the_same_seller_needs_the_key_and_the_overview_writes_nothing(): void
    {
        Sanctum::actingAs($this->seller->fresh(), [AuthController::APP]);
        $this->postJson('/api/v1/sales/direct/overview', $this->sale())->assertForbidden();

        $this->grant('sales.challan.create');
        Sanctum::actingAs($this->seller->fresh(), [AuthController::APP]);
        $before = [SalesInvoice::query()->count(), DeliveryChallan::query()->count(), StockMovement::query()->count()];

        $o = $this->postJson('/api/v1/sales/direct/overview', $this->sale())->assertOk()->json();

        $this->assertSame([SalesInvoice::query()->count(), DeliveryChallan::query()->count(), StockMovement::query()->count()], $before,
            '⛔ সারাংশ দেখতে গিয়েই কাগজ বা মজুদ লেখা হয়ে গেল।');
        $this->assertCount(1, $o['lines']);
        $this->assertContains('লট: LOT-OV', $o['lines'][0]['details']);
        $net = collect($o['totals'])->firstWhere('strong', true);
        $this->assertStringContainsString('1,000', (string) $net['amount'], '⛔ ১০ × ১০০ = ১,০০০ নয়: '.json_encode($o['totals']));
        $this->assertFalse($o['blocks']);
        $this->assertContains($this->customer->name(), array_column($o['head'], 'value'));
    }

    public function test_over_the_limit_it_says_it_will_not_confirm_and_switched_off_it_says_nothing(): void
    {
        $this->customer->forceFill(['credit_limit' => '100'])->save();
        $this->asSeller();

        $on = $this->postJson('/api/v1/sales/direct/overview', $this->sale())->assertOk()->json();
        $this->assertTrue($on['blocks'], '⛔ সীমা পার, অথচ সারাংশ "নিশ্চিত হবে না" বলল না।');
        $this->assertContains('stop', array_column($on['notes'], 'tone'));

        app(SettingsService::class)->set('customer.credit_limit_enabled', false);
        app()->forgetInstance(\App\Modules\Sales\Services\CreditExposure::class);
        $off = $this->postJson('/api/v1/sales/direct/overview', $this->sale())->assertOk()->json();
        $this->assertFalse($off['blocks'], '⛔ সীমার সুইচ বন্ধ, তবু সারাংশ আটকাচ্ছে।');
        $this->assertNotContains(__('sales::overview_confirm.limit'), array_column($off['money'], 'label'));
    }

    public function test_a_discount_says_the_owner_will_sign(): void
    {
        app(OwnerSignsDiscounts::class)->ensure($this->company);
        $this->asSeller();

        $o = $this->postJson('/api/v1/sales/direct/overview', $this->sale(['discount_percent' => '5']))->assertOk()->json();

        $this->assertContains(__('sales::overview_confirm.discount_signature'), array_column($o['notes'], 'text'),
            '⛔ ছাড় আছে, অথচ সারাংশ মালিকের সইয়ের কথা বলল না।');
    }

    /** ⭐ ওয়েবের পপ-আপের ভিতর — একই যাচাই আর একই সারাংশ, ফর্মের ভেতরের id দিয়ে; চাবি ছাড়া ৪০৩ */
    public function test_the_web_popup_draws_the_same_overview_and_needs_the_counter_key(): void
    {
        $form = [
            'customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'own_transport' => '1',
            'lines' => [['product_id' => $this->product->id, 'batch_id' => $this->lot->id, 'qty' => '10', 'rate' => '100']],
        ];

        $this->actingAs($this->seller->fresh())->post(route('sales.direct.overview'), $form)->assertForbidden();

        $this->grant('sales.challan.create');
        $this->actingAs($this->seller->fresh())->post(route('sales.direct.overview'), $form)->assertOk()
            ->assertSee('data-overview-blocks="0"', false)
            ->assertSee('LOT-OV')
            ->assertSee('1,000');
    }

    /** ⛔ ফর্মে ভুল — পপ-আপে পুরো পাতা নয় (পেছনে ফেরার ৩০২ নয়), ভুলের কথা আর "নিশ্চিত হবে না" */
    public function test_a_broken_form_shows_its_mistakes_in_the_popup_not_a_whole_page(): void
    {
        $this->grant('sales.challan.create');

        $response = $this->actingAs($this->seller->fresh())
            ->post(route('sales.direct.overview'), ['warehouse_id' => $this->warehouse->id], ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html'])
            ->assertOk()
            ->assertSee('data-overview-blocks="1"', false)
            ->assertSee(__('overview.not_ready'));

        $this->assertStringNotContainsString('<html', $response->getContent(), '⛔ পপ-আপে পুরো পাতা ঢুকল।');
    }

    /** ⭐ কাউন্টার-পাতায় পপ-আপ বসানো — ফর্ম সারাংশের ঠিকানা জানে, পাতায় পপ-আপের খোলস আছে */
    public function test_the_counter_page_carries_the_popup_and_the_overview_address(): void
    {
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail())
            ->get(route('sales.direct.create'))->assertOk()
            ->assertSee('data-confirm-overview="'.route('sales.direct.overview').'"', false)
            ->assertSee('data-confirm-overview-dialog', false)
            ->assertSee('data-overview-trigger', false);
    }

    /** @param  array<string, mixed>  $line */
    private function sale(array $line = []): array
    {
        return [
            'customer' => (string) $this->customer->public_id,
            'warehouse' => (string) $this->warehouse->public_id,
            'own_transport' => '1',
            'lines' => [[
                'product' => (string) $this->product->public_id, 'lot' => (string) $this->lot->public_id,
                'qty' => '10', 'rate' => '100', ...$line,
            ]],
        ];
    }

    private function asSeller(): void
    {
        $this->grant('sales.challan.create');
        Sanctum::actingAs($this->seller->fresh(), [AuthController::APP]);
    }

    private function grant(string $key): void
    {
        CompanyContext::forCompany($this->company->id,
            fn () => $this->seller->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
