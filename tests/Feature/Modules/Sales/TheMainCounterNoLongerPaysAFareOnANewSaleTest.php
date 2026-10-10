<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * পুরনো "Main Counter" পথ নতুন বিক্রিতে বন্ধ — মালিক, ৭ অক্টোবর ২০২৬: *"যা-ই করো, সব জায়গায় একই রকম"* (fe-র ক্রম: ফোনের পরে)।
 *
 * ⓘ খাতের ঘর না পাঠালে (পুরনো ফোনের অ্যাপ, বা ঘর ছাড়া যেকোনো অনুরোধ), ভাড়া আমাদের আর বাহক নেই — আগে চালান পাকা হলে টাকা
 * কোম্পানির প্রধান টিল থেকে নিজে থেকে কাটত ([[DeliveryChallanService::postTransportCost()]])।
 *
 * দাবি:
 *  - এমন নতুন বিক্রি ফোনে আর ওয়েবে — দুই দরজাতেই থামে, কারণসহ ("কোন খাত থেকে"), কিছুই বসে না; প্রধান টিল ছোঁয় না।
 *  - বাহক থাকলে চলে — বাহকের নামে ২১১৬-এ দেনা (সিদ্ধান্ত খ), টিল ছোঁয় না।
 *  - "ক্রেতা দেবেন" বা "ভাড়া নেই" — আগের মতোই চলে, খাতায় কিছু নয়।
 */
final class TheMainCounterNoLongerPaysAFareOnANewSaleTest extends TestCase
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
        $this->customer->forceFill(['credit_limit' => '1000000'])->save();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
        $this->product->update(['track_batch' => true]);
        $this->lot = Batch::query()->create([
            'company_id' => $this->company->id, 'product_id' => $this->product->id,
            'batch_no' => 'LOT-MAINC', 'expiry_date' => '2028-01-01',
        ]);
        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse, sourceType: 'opening', sourceId: $this->lot->id,
            floor: '100', date: now()->toDateString(), documentNo: 'TEST-LOT-MAINC', batch: $this->lot,
        );

        $this->seller = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $this->seller->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id,
            fn () => $this->seller->givePermissionTo(Permission::findOrCreate('sales.challan.create', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_an_old_phone_without_fare_fields_stops_and_a_carrier_lets_it_through_as_the_carriers_due(): void
    {
        Sanctum::actingAs($this->seller->fresh(), [AuthController::APP]);
        $main = $this->mainTillBalance();
        $challans = DeliveryChallan::query()->count();

        $this->postJson('/api/v1/sales/direct', $this->phoneSale())
            ->assertStatus(422)->assertJsonValidationErrors(['fare_account_id' => __('sales::fare.needs_account')]);
        $this->assertSame($challans, DeliveryChallan::query()->count(), '⛔ থেমে যাওয়া বিক্রির চালান থেকে গেল।');

        $carrier = $this->carrier();
        $this->postJson('/api/v1/sales/direct', $this->phoneSale(['carrier' => (string) $carrier->public_id]))->assertCreated();

        $challan = DeliveryChallan::query()->latest('id')->firstOrFail();
        $this->assertTrue($this->dueTo($carrier, $challan), 'বাহকসহ ভাড়া বাহকের দেনায় বসেনি — দাবি অন্ধ।');
        $this->assertSame(0, bccomp($main, $this->mainTillBalance(), 4), '⛔ প্রধান টিল থেকে ভাড়া কাটল — পুরনো পথ খোলা।');
    }

    public function test_the_web_counter_without_fare_fields_stops_too_but_customer_pays_still_goes(): void
    {
        $this->actingAs($this->seller->fresh());
        $main = $this->mainTillBalance();
        $challans = DeliveryChallan::query()->count();

        $this->post(route('sales.direct.store'), $this->webSale())
            ->assertSessionHasErrors(['fare_account_id' => __('sales::fare.needs_account')]);
        $this->assertSame($challans, DeliveryChallan::query()->count(), '⛔ থেমে যাওয়া বিক্রির চালান থেকে গেল।');

        $this->post(route('sales.direct.store'), $this->webSale(['fare_paid_by' => 'customer']))->assertSessionHasNoErrors();
        $challan = DeliveryChallan::query()->latest('id')->firstOrFail();
        $this->assertFalse(LedgerEntry::query()->where('source_type', DeliveryChallan::STOCK_SOURCE)->where('source_id', $challan->id)->exists(),
            '⛔ ক্রেতার ভাড়া আমাদের খাতায় বসল।');
        $this->assertSame(0, bccomp($main, $this->mainTillBalance(), 4), '⛔ প্রধান টিল থেকে ভাড়া কাটল।');
    }

    // ── যন্ত্রপাতি ──

    /** @return array<string, mixed> */
    private function phoneSale(array $extra = []): array
    {
        return [
            'customer' => (string) $this->customer->public_id, 'warehouse' => (string) $this->warehouse->public_id,
            'payment_term' => 'credit', 'vehicle_owner' => 'hired', 'vehicle_no' => 'ঢাকা-ম ১২', 'driver_name' => 'রহিম',
            'transport_cost' => '100', 'fare_paid_by' => 'us',
            'lines' => [['product' => (string) $this->product->public_id, 'lot' => (string) $this->lot->public_id, 'qty' => '10', 'rate' => '100']],
            ...$extra,
        ];
    }

    /** @return array<string, mixed> */
    private function webSale(array $extra = []): array
    {
        return [
            'customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString(),
            'payment_term' => 'credit', 'vehicle_owner' => 'hired', 'vehicle_no' => 'ঢাকা-ম ১৩', 'driver_name' => 'করিম',
            'transport_cost' => '100', 'fare_paid_by' => 'us',
            'lines' => [['product_id' => $this->product->id, 'batch_id' => $this->lot->id, 'qty' => '10', 'rate' => '100', 'free_qty' => '0']],
            ...$extra,
        ];
    }

    private function carrier(): Supplier
    {
        return Supplier::query()->create(['code' => 'TR-MAINC', 'name_en' => 'Main Counter Carrier',
            'party_type_id' => PartyType::query()->where('code', 'TRANSPORT')->firstOrFail()->id]);
    }

    private function dueTo(Supplier $carrier, DeliveryChallan $challan): bool
    {
        return LedgerEntry::query()->join('accounts as a', 'a.id', '=', 'ledger_entries.account_id')
            ->where('a.code', StandardChart::TRANSPORT_PAYABLE)->where('ledger_entries.source_type', DeliveryChallan::STOCK_SOURCE)
            ->where('ledger_entries.source_id', $challan->id)->where('ledger_entries.party_id', $carrier->id)->exists();
    }

    private function mainTillBalance(): string
    {
        return app(CashTillService::class)->ensurePrimaryTill()->account->balanceOn();
    }
}
