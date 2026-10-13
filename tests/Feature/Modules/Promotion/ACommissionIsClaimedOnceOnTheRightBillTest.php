<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\CommissionClaim;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CommissionClaimService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ কমিশনের দাবি — ডিলারের নিজের পাকা বিলে, আর এক বিলে একবারই (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, প্রমোশন ১৯;
 * [[CommissionClaimService::create()]])।
 *
 * ⓘ বিল খোঁজা হত কেবল নম্বরে: অন্য গ্রাহকের বড় বিল দেখিয়ে এই ডিলারের কমিশন লেখা যেত, আর একই বিলে বারবার দাবি — প্রতিবার
 * ডিলারের পাওনা কমত আর কোম্পানির কাছে নতুন দাবি জন্মাত।
 */
final class ACommissionIsClaimedOnceOnTheRightBillTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $principal;

    private Customer $dealer;

    private Customer $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SettingsService::class)->set('sales.commission_max_amount', 100000);
        app(SettingsService::class)->set('sales.commission_max_percent', 60);

        $this->principal = Supplier::query()->firstOrFail();
        [$this->dealer, $this->other] = Customer::query()->where('is_active', true)->orderBy('id')->take(2)->get()->all();
        Customer::query()->whereIn('id', [$this->dealer->id, $this->other->id])->update(['credit_limit' => '100000000']);
    }

    public function test_a_claim_on_another_customers_bill_is_refused(): void
    {
        $theirs = $this->bill($this->other);

        $this->assertRefused(fn () => $this->claim($theirs), '⛔ অন্য গ্রাহকের বিলে এই ডিলারের কমিশন বসল');
        $this->assertSame(0, CommissionClaim::query()->count());
    }

    public function test_a_bill_is_claimed_once(): void
    {
        $bill = $this->bill($this->dealer);

        $this->claim($bill);
        $this->assertRefused(fn () => $this->claim($bill), '⛔ একই বিলে দ্বিতীয়বার কমিশনের দাবি বসল');
        $this->assertSame(1, CommissionClaim::query()->where('sales_invoice_id', $bill->id)->count());
    }

    /**
     * ⛔ দেয়ালের আড়ালের বিল — পড়া যেত না, বিলের যাচাই বাদ পড়ত, আর দাবি বসত বিল ছাড়াই (cloud/people-fixes রিভিউ, ১০ অক্টোবর ২০২৬;
     * [[CommissionClaimService::create()]])। ⭐ বিলের আইডি দিয়ে বিল না পেলে থামে।
     */
    public function test_a_bill_hidden_behind_the_wall_is_refused_not_dropped(): void
    {
        $bill = $this->bill($this->dealer);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $other = \App\Models\Branch::query()->withoutGlobalScopes()->where('company_id', CompanyContext::id())
            ->where('id', '!=', (int) $bill->branch_id)->orderBy('id')->firstOrFail();
        $this->post(route('branch.switch'), ['branch_id' => (string) $other->id])->assertRedirect();
        CompanyContext::set(CompanyContext::id(), $owner->fresh()->current_branch_id);
        app(\App\Core\Services\DataScope::class)->forget();
        $this->app->forgetScopedInstances();
        $this->actingAs($owner->fresh());
        $this->assertNull(SalesInvoice::query()->find($bill->id), 'দৃশ্যটাই বানানো যায়নি — হেডারের শাখায় বিলটা দেখা যাচ্ছে');

        $this->assertRefused(fn () => $this->claim($bill), '⛔ দেয়ালের আড়ালের বিলে দাবি বসল — বিল ছাড়া');
        $this->assertSame(0, CommissionClaim::query()->withoutGlobalScopes()->count());
    }

    private function claim(SalesInvoice $bill): CommissionClaim
    {
        return app(CommissionClaimService::class)->create([
            'customer_id' => $this->dealer->id, 'supplier_id' => $this->principal->id, 'sales_invoice_id' => $bill->id,
            'rate_percent' => '5', 'trx_date' => now()->toDateString(),
        ]);
    }

    private function bill(Customer $customer): SalesInvoice
    {
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->where('track_batch', false)->where('is_active', true)->where('sale_price', '>', 0)->orderBy('id')->firstOrFail();
        app(StockService::class)->move(product: $product, warehouse: $warehouse, sourceType: StockService::ADJUSTMENT, sourceId: $product->id, floor: '50');

        return app(SalesInvoiceService::class)->confirm(app(SalesInvoiceService::class)->create(
            ['customer_id' => $customer->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => '2', 'rate' => (string) $product->sale_price]],
        ))->fresh();
    }

    private function assertRefused(callable $what, string $why): void
    {
        try {
            $what();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sales_invoice_id', $e->errors(), $why.' — অন্য ঘরে আটকাল: '.implode(', ', array_keys($e->errors())));

            return;
        }

        $this->fail($why);
    }
}
