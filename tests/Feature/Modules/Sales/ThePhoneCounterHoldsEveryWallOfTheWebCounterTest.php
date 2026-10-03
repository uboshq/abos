<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

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
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনের কাউন্টার — মালিক, ৪ অক্টোবর ২০২৬: *"direct sales er counter banaw app e"*।
 *
 * ⛔ ফোনের দরজা নিজে কোনো নিয়ম জানে না — ওয়েবের একই যাচাই আর একই দরজা ([[DirectSaleApiController]])।
 * দাবি: প্রতিটা দেয়াল ফোন দিয়েও আটকায় —
 *   একই মানুষ: কাউন্টারের চাবি ছাড়া ৪০৩, চাবি দিলে খোলে;
 *   লট-ধরা পণ্যে লট ছাড়া ৪২২; শূন্য দর ৪২২; অচেনা public id ৪২২;
 *   যেকোনো হাতের ছাড় মালিকের সইয়ের অপেক্ষায়; ঋণসীমা পেরোলে বিক্রি ৪২২, অথচ খসড়া আটকায় না;
 *   আর ঠিকঠাক বিক্রিতে বাছা লটটাই বেরোয়।
 */
final class ThePhoneCounterHoldsEveryWallOfTheWebCounterTest extends TestCase
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
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
        $this->product->update(['track_batch' => true]);

        $this->lot = Batch::query()->create([
            'company_id' => $this->company->id, 'product_id' => $this->product->id,
            'batch_no' => 'LOT-PHONE', 'expiry_date' => '2028-01-01',
        ]);
        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse, sourceType: 'opening', sourceId: $this->lot->id,
            floor: '100', date: now()->toDateString(), documentNo: 'TEST-LOT-PHONE', batch: $this->lot,
        );

        $this->seller = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $this->seller->companies()->attach($this->company->id, ['is_active' => true]);
    }

    public function test_the_same_seller_needs_the_counter_key_and_then_sees_the_lots_and_accounts(): void
    {
        Sanctum::actingAs($this->seller->fresh(), [AuthController::APP]);
        $this->getJson('/api/v1/sales/direct/setup')->assertForbidden();
        $this->postJson('/api/v1/sales/direct', $this->sale())->assertForbidden();

        $this->grant('sales.challan.create');
        Sanctum::actingAs($this->seller->fresh(), [AuthController::APP]);
        $setup = $this->getJson('/api/v1/sales/direct/setup')->assertOk()->json();

        $lots = $setup['lots'][(string) $this->product->public_id] ?? [];
        $this->assertSame([(string) $this->lot->public_id], array_column($lots, 'id'), 'লটের তালিকায় বাছার লট নেই, বা ক্রমিক id গেছে।');
        $this->assertNotSame([], $setup['moneyAccounts'], 'টাকার খাত নেই।');
        $this->assertNotSame([], $setup['paymentTerms']);
    }

    public function test_a_good_sale_goes_through_and_the_chosen_lot_leaves(): void
    {
        $this->asSeller();

        $out = $this->postJson('/api/v1/sales/direct', $this->sale())->assertCreated()->json();

        $this->assertContains($out['status'], ['done', 'held'], json_encode($out));
        $this->assertNotSame('', $out['invoice']['no'] ?? '');
        $this->assertSame((int) $this->lot->id, (int) StockMovement::query()->where('product_id', $this->product->id)
            ->where('floor_change', '<', 0)->latest('id')->value('batch_id'), '⛔ বাছা লটটা বেরোয়নি।');
    }

    public function test_a_tracked_product_without_a_lot_a_zero_price_and_an_unknown_id_are_refused(): void
    {
        $this->asSeller();

        $this->postJson('/api/v1/sales/direct', $this->sale(['lot' => null]))->assertStatus(422);
        $this->postJson('/api/v1/sales/direct', $this->sale(['rate' => '0']))->assertStatus(422)->assertJsonValidationErrors('lines.0.rate');
        $this->postJson('/api/v1/sales/direct', $this->sale(['product' => '00000000-0000-0000-0000-000000000000']))
            ->assertStatus(422)->assertJsonValidationErrors('lines');
        $this->assertSame(0, StockMovement::query()->where('product_id', $this->product->id)->where('floor_change', '<', 0)->count(),
            '⛔ ফেরানো বিক্রিতেও মাল বেরিয়েছে।');
    }

    public function test_any_hand_discount_waits_for_the_owner(): void
    {
        app(OwnerSignsDiscounts::class)->ensure($this->company);
        $this->asSeller();

        $out = $this->postJson('/api/v1/sales/direct', $this->sale(['discount_percent' => '5']))->json();

        $this->assertSame('held', $out['status'] ?? null, '⛔ ফোনের ছাড় মালিকের সই ছাড়াই গেল: '.json_encode($out));
        $this->assertNotSame('', (string) ($out['notice'] ?? ''));
    }

    public function test_over_the_credit_limit_a_sale_is_refused_but_a_draft_is_kept(): void
    {
        $this->customer->forceFill(['credit_limit' => '100'])->save();
        $this->asSeller();

        $this->postJson('/api/v1/sales/direct', $this->sale())->assertStatus(422);

        $draft = $this->postJson('/api/v1/sales/direct', [...$this->sale(), 'save_as_draft' => '1'])->assertCreated()->json();
        $this->assertSame('parked', $draft['status'], '⛔ সীমা পেরোনো খসড়াও আটকে গেল — খসড়ায় দেয়াল নেই (মালিক, ২৭ সেপ্টেম্বর)।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $line */
    private function sale(array $line = []): array
    {
        return [
            'customer' => (string) $this->customer->public_id,
            'warehouse' => (string) $this->warehouse->public_id,
            'own_transport' => '1',
            'lines' => [[
                'product' => (string) $this->product->public_id,
                'lot' => (string) $this->lot->public_id,
                'qty' => '10',
                'rate' => '100',
                ...$line,
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
