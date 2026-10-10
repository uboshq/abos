<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryChallan;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ফোনের কাউন্টারেও ভাড়া বাছা খাত থেকে — পুরো ERP অডিট, ৯ অক্টোবর ২০২৬: *"ফোনের কাউন্টারে ভাড়া এখনো Main Counter থেকে;
 * সার্ভার `_id` ঘর ফেলে দেয়, তাই খাত পৌঁছাবে না"* ([[DirectSaleApiController::translate()]], [[FarePayment]])।
 *
 * দাবি:
 *  - ফোনের setup-এ "কে দিলেন"-এর তালিকা, public_id-তে (ক্রমিক id নয়)।
 *  - ফোন `fare_account` (public_id) পাঠালে বাছা টিল থেকে EV — Main Counter নয়; চালানের নিজের দাখিলায় ভাড়া নেই।
 *  - খাত ছাড়া "এখনই" — ৪২২, কিছুই বসে না; অন্য কোম্পানির মানুষকে "কে দিলেন" — ৪২২।
 *  - ভাড়ার ঘর ছাড়া পুরনো অ্যাপ — আলাদা দাবিতে ([[TheMainCounterNoLongerPaysAFareOnANewSaleTest]])।
 */
final class ThePhoneCounterPaysTheFareFromAChosenAccountTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    private Batch $lot;

    private User $seller;

    private Account $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
        $till = app(CashTillService::class)->create(['code' => 'PHN-F', 'name_en' => 'Phone fare till']);
        $this->till = Account::query()->findOrFail($till->account_id);
        $this->putMoneyIn($this->till, '1000');

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '1000000'])->save();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
        $this->product->update(['track_batch' => true]);
        $this->lot = Batch::query()->create([
            'company_id' => $this->company->id, 'product_id' => $this->product->id,
            'batch_no' => 'LOT-PFARE', 'expiry_date' => '2028-01-01',
        ]);
        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse, sourceType: 'opening', sourceId: $this->lot->id,
            floor: '100', date: now()->toDateString(), documentNo: 'TEST-LOT-PFARE', batch: $this->lot,
        );

        $this->seller = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $this->seller->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id,
            fn () => $this->seller->givePermissionTo(Permission::findOrCreate('sales.challan.create', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($this->seller->fresh(), [AuthController::APP]);
    }

    public function test_the_phone_setup_lists_who_can_pay_by_public_id(): void
    {
        $payers = collect($this->getJson('/api/v1/sales/direct/setup')->assertOk()->json('farePayers'));

        $this->assertNotEmpty($payers, '⛔ ফোনের setup-এ "কে দিলেন"-এর তালিকা নেই।');
        $this->assertContains((string) $this->seller->public_id, $payers->pluck('id')->all(), '⛔ তালিকায় বিক্রেতা নিজে নেই।');
        $this->assertNotContains((string) $this->seller->id, $payers->pluck('id')->all(), '⛔ তালিকায় ক্রমিক id গেল।');
    }

    public function test_a_phone_fare_paid_now_is_an_expense_voucher_from_the_chosen_till(): void
    {
        $before = $this->till->balanceOn();
        $this->postJson('/api/v1/sales/direct', $this->sale(['fare_when' => 'now', 'fare_account' => (string) $this->till->public_id]))->assertCreated();

        $challan = DeliveryChallan::query()->latest('id')->firstOrFail();
        $voucher = Voucher::query()->with('lines')->find($challan->fare_voucher_id);
        $this->assertNotNull($voucher, '⛔ ফোনের ভাড়ায় ভাউচার নেই — খাত সার্ভারে পৌঁছায়নি?');
        $this->assertSame([Voucher::EXPENSE, DocumentStatus::CONFIRMED], [$voucher->type, $voucher->status]);
        $this->assertSame(0, bccomp(bcsub($before, $this->till->fresh()->balanceOn(), 4), '100', 4), '⛔ ফোনের বাছা টিল থেকে ১০০ কমেনি (Main Counter?)।');
        $this->assertFalse(LedgerEntry::query()->where('source_type', DeliveryChallan::STOCK_SOURCE)->where('source_id', $challan->id)->exists(),
            '⛔ ফোনের ভাড়া পুরনো পথেও বসল — দুবার।');
    }

    public function test_no_account_and_a_payer_from_outside_are_refused(): void
    {
        $challans = DeliveryChallan::query()->count();

        $this->postJson('/api/v1/sales/direct', $this->sale(['fare_when' => 'now']))
            ->assertStatus(422)->assertJsonValidationErrors(['fare_account_id' => __('sales::fare.needs_account')]);

        $bank = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1102-PFARE', 'name_en' => 'Phone Fare Bank', 'name_bn' => 'ফোন ভাড়ার ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK, 'is_active' => true, 'status' => DocumentStatus::CONFIRMED,
        ]);
        $stranger = User::factory()->create(['is_active' => true]);
        $this->postJson('/api/v1/sales/direct', $this->sale(['fare_when' => 'now', 'fare_account' => (string) $bank->public_id,
            'fare_reference' => 'TRX-PH-1', 'fare_payer' => (string) $stranger->public_id, 'confirm_duplicate' => '1']))
            ->assertStatus(422)->assertJsonValidationErrors(['fare_payer_id' => __('sales::fare.unknown_payer')]);

        $this->assertSame($challans, DeliveryChallan::query()->count(), '⛔ থেমে যাওয়া ফোনের বিক্রির চালান থেকে গেল।');
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
            'lines' => [['product' => (string) $this->product->public_id, 'lot' => (string) $this->lot->public_id, 'qty' => '10', 'rate' => '100']],
            ...$extra,
        ];
    }
}
