<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\ChallanLoading;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\HiresTheLabour;
use Tests\TestCase;

/**
 * ⭐ ফোনের কাউন্টারেও লোডিং — মালিক, ১৮ নভেম্বর ২০২৬ ("ওকে"; ২০ নভেম্বর ২০২৬ দাবি; [[DirectSaleApiController::translate()]])।
 *
 * ⓘ ওয়েবের একই ঘর: `loading_mode`, `loading_amount`, `loading_account` / `loading_contractor` (public_id), `loading_reason`।
 *   setup-এ সুইচ, নিজের টিল আর ঠিকাদারের তালিকা।
 *
 * দাবি: setup-এ `loading` (সুইচের অবস্থাসহ); বাকি লোডিং ঠিকাদারের নামে ২১১৭-এ; অচেনা ঠিকাদার — ৪২২; সুইচ চালু থাকলে লোডিং ছাড়া
 *       বিক্রি থামে, কোনো চালান থাকে না।
 */
final class ThePhoneCounterWritesTheLoadingTooTest extends TestCase
{
    use HiresTheLabour;
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    private Batch $lot;

    private Account $till;

    private Supplier $contractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
        $this->till = $this->labourTill('PHN-L', '1000');
        $this->contractor = $this->labourContractor('LAB-PH', 'Phone Labour Gang');

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '1000000'])->save();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
        $this->product->update(['track_batch' => true]);
        $this->lot = Batch::query()->create([
            'company_id' => $this->company->id, 'product_id' => $this->product->id,
            'batch_no' => 'LOT-PLOAD', 'expiry_date' => '2028-01-01',
        ]);
        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse, sourceType: 'opening', sourceId: $this->lot->id,
            floor: '100', date: now()->toDateString(), documentNo: 'TEST-LOT-PLOAD', batch: $this->lot,
        );

        $seller = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $seller->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id,
            fn () => $seller->givePermissionTo(Permission::findOrCreate('sales.challan.create', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($seller->fresh(), [AuthController::APP]);
    }

    public function test_the_setup_tells_the_phone_about_loading(): void
    {
        $loading = $this->getJson('/api/v1/sales/direct/setup')->assertOk()->json('loading');

        $this->assertIsArray($loading, '⛔ ফোনের setup-এ লোডিংয়ের ঘর নেই।');
        $this->assertFalse($loading['mandatory'], 'সুইচ বন্ধ, অথচ ফোন বাধ্যতামূলক দেখল।');
        $this->assertIsArray($loading['tills']);
        $this->assertIsArray($loading['contractors']);
        $this->assertNotContains((string) $this->contractor->id, collect($loading['contractors'])->pluck('id')->all(), '⛔ তালিকায় ক্রমিক id গেল।');

        app(SettingsService::class)->set(ChallanLoading::MANDATORY_KEY, true);
        $this->assertTrue($this->getJson('/api/v1/sales/direct/setup')->assertOk()->json('loading.mandatory'), 'সুইচ চালু, ফোন জানল না।');
    }

    public function test_a_phone_sale_with_due_loading_owes_the_contractor(): void
    {
        $this->postJson('/api/v1/sales/direct', $this->sale([
            'loading_mode' => 'due', 'loading_amount' => '60', 'loading_contractor' => (string) $this->contractor->public_id,
        ]))->assertCreated();

        $challan = DeliveryChallan::query()->latest('id')->firstOrFail();

        $this->assertSame(['due', 0, $this->contractor->id], [$challan->loading_mode, bccomp((string) $challan->loading_amount, '60', 4), (int) $challan->loading_contractor_id],
            '⛔ ফোনের লোডিং চালানে বসেনি।');

        $due = LedgerEntry::query()->where('source_type', ChallanLoading::SOURCE)->where('source_id', $challan->id)
            ->where('account_id', StandardChart::find(StandardChart::LABOUR_PAYABLE)->id)->get();
        $this->assertCount(1, $due, '⛔ ফোনের বাকি লোডিং ২১১৭-এ বসেনি।');
        $this->assertSame([0, $this->contractor->id], [bccomp((string) $due->first()->credit, '60', 4), (int) $due->first()->party_id]);
    }

    public function test_an_unknown_contractor_is_refused_and_no_challan_stays(): void
    {
        $count = DeliveryChallan::query()->count();

        $this->postJson('/api/v1/sales/direct', $this->sale([
            'loading_mode' => 'due', 'loading_amount' => '60', 'loading_contractor' => (string) \Illuminate\Support\Str::uuid(),
        ]))->assertStatus(422)->assertJsonValidationErrors(['loading_contractor']);

        $this->assertSame($count, DeliveryChallan::query()->count(), '⛔ থেমে যাওয়া ফোনের বিক্রির চালান থেকে গেল।');
    }

    public function test_with_the_switch_on_a_phone_sale_without_loading_is_refused(): void
    {
        app(SettingsService::class)->set(ChallanLoading::MANDATORY_KEY, true);
        $count = DeliveryChallan::query()->count();

        $this->postJson('/api/v1/sales/direct', $this->sale())
            ->assertStatus(422)->assertJsonValidationErrors(['loading_mode']);

        $this->assertSame($count, DeliveryChallan::query()->count(), '⛔ লোডিং ছাড়া ফোনের বিক্রির চালান থেকে গেল।');
    }

    /** @return array<string, mixed> */
    private function sale(array $extra = []): array
    {
        return [
            'customer' => (string) $this->customer->public_id,
            'warehouse' => (string) $this->warehouse->public_id,
            'payment_term' => 'credit',
            'vehicle_owner' => 'hired',
            'vehicle_no' => 'ঢাকা মেট্রো ন ১২-৩৪৫৬',
            'driver_name' => 'রহিম',
            'transport_cost' => '100',
            'fare_paid_by' => 'us',
            'fare_when' => 'now',
            'fare_account' => (string) $this->till->public_id,
            'lines' => [['product' => (string) $this->product->public_id, 'lot' => (string) $this->lot->public_id, 'qty' => '10', 'rate' => '100']],
            ...$extra,
        ];
    }
}
