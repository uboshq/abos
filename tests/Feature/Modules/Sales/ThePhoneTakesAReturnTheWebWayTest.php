<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনে বিক্রি ফেরত — কাউন্টারের "ফেরত" বোতাম (মালিক, ৪ অক্টোবর ২০২৬; [[SalesReturnApiController]])।
 *
 * দাবি:
 *   একই বিক্রেতা — ফেরতের চাবি ছাড়া ৪০৩, চাবি দিলে কারণ আর নিজের দেখার মতো বিল;
 *   বিল → সারি → খসড়া ফেরত → সারাংশ → নিশ্চিত: মাল গুদামে ফেরে, ফেরত পাকা হয়;
 *   বেচার বেশি ফেরত — সারাংশ "নিশ্চিত হবে না", নিশ্চিতের দরজা ৪২২, কিছুই নড়ে না;
 *   বিলের বাইরের সারি পাঠালে ৪২২।
 */
final class ThePhoneTakesAReturnTheWebWayTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $seller;

    private SalesInvoice $sale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->orderBy('id')->firstOrFail();
        $product->update(['track_batch' => false]);
        app(StockService::class)->move(
            product: $product, warehouse: $warehouse, sourceType: 'opening', sourceId: 1,
            floor: '50', date: now()->toDateString(), documentNo: 'TEST-RET-PHONE',
        );
        $customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $customer->forceFill(['credit_limit' => '1000000'])->save();

        $invoices = app(SalesInvoiceService::class);
        $this->sale = $invoices->confirm($invoices->create(
            ['customer_id' => $customer->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => '5', 'rate' => '100']],
        ));

        $this->seller = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $this->seller->companies()->attach($this->company->id, ['is_active' => true]);
    }

    public function test_the_same_seller_needs_the_return_key_then_sees_reasons_and_bills(): void
    {
        Sanctum::actingAs($this->seller->fresh(), [AuthController::APP]);
        $this->getJson('/api/v1/sales/returns/setup')->assertForbidden();

        foreach (['sales.return.create', 'sales.return.view', 'sales.invoice.view'] as $key) {
            $this->grant($key);
        }
        Sanctum::actingAs($this->seller->fresh(), [AuthController::APP]);

        $setup = $this->getJson('/api/v1/sales/returns/setup')->assertOk()->json();
        $this->assertNotEmpty($setup['reasons']);
        $this->assertContains((string) $this->sale->public_id, array_column($setup['invoices'], 'id'));
    }

    public function test_bill_lines_draft_overview_confirm_bring_the_goods_back(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail(), [AuthController::APP]);
        $setup = $this->getJson('/api/v1/sales/returns/setup')->assertOk()->json();

        $bill = $this->getJson('/api/v1/sales/returns/invoice/'.$this->sale->public_id)->assertOk()->json();
        $this->assertCount(1, $bill['lines']);

        $draft = $this->postJson('/api/v1/sales/returns', [
            'invoice' => $bill['id'], 'reason' => $setup['reasons'][0]['id'],
            'lines' => [['line' => $bill['lines'][0]['id'], 'qty' => '2']],
        ])->assertCreated()->json();

        $this->getJson("/api/v1/sales/returns/{$draft['id']}/overview")->assertOk()->assertJson(['blocks' => false]);

        $moves = StockMovement::query()->count();
        $this->postJson("/api/v1/sales/returns/{$draft['id']}/confirm")->assertOk()->assertJson(['status' => 'done']);

        $this->assertSame(DocumentStatus::CONFIRMED, SalesReturn::query()->where('public_id', $draft['id'])->value('status'));
        $this->assertGreaterThan($moves, StockMovement::query()->count(), '⛔ ফেরত পাকা হলো, অথচ মাল গুদামে ফিরল না।');
    }

    public function test_more_than_was_sold_is_stopped_and_a_line_from_another_bill_is_refused(): void
    {
        Sanctum::actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail(), [AuthController::APP]);
        $reason = $this->getJson('/api/v1/sales/returns/setup')->json('reasons.0.id');
        $bill = $this->getJson('/api/v1/sales/returns/invoice/'.$this->sale->public_id)->json();

        $this->postJson('/api/v1/sales/returns', [
            'invoice' => $bill['id'], 'reason' => $reason, 'lines' => [['line' => (string) \Illuminate\Support\Str::uuid(), 'qty' => '1']],
        ])->assertStatus(422);

        $draft = $this->postJson('/api/v1/sales/returns', [
            'invoice' => $bill['id'], 'reason' => $reason, 'lines' => [['line' => $bill['lines'][0]['id'], 'qty' => '2']],
        ])->assertCreated()->json();
        SalesReturn::query()->where('public_id', $draft['id'])->firstOrFail()->lines()->update(['qty' => '7']);

        $this->getJson("/api/v1/sales/returns/{$draft['id']}/overview")->assertOk()->assertJson(['blocks' => true]);
        $moves = StockMovement::query()->count();
        $this->postJson("/api/v1/sales/returns/{$draft['id']}/confirm")->assertStatus(422);
        $this->assertSame($moves, StockMovement::query()->count(), '⛔ বেচার বেশি ফেরত, তবু মাল নড়ল।');
    }

    private function grant(string $key): void
    {
        CompanyContext::forCompany($this->company->id,
            fn () => $this->seller->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
