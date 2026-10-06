<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * পরিশোধের নিচে থাকা বিলও বাতিল হত — পুরো ERP অডিট, ক্রয় ⚠️৭, ৬ অক্টোবর ২০২৬।
 *
 * ⛔ পরিশোধ বসা বিল বাতিল হলে পরিশোধগুলো বাতিল বিলে ঝুলে থাকত — সরবরাহকারীর খাতায় ব্যাখ্যাহীন অগ্রিম। ⭐ এখন পরিশোধ থাকলে
 * বাতিল থামে (আগে পরিশোধ বাতিল), আর বাকিতে কেনা বিল আগের মতোই বাতিল হয়।
 */
final class APaidBillWasCancelledUnderItsPaymentTest extends TestCase
{
    use \Tests\Concerns\PutsMoneyInTheTill;
    use RefreshDatabase;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('track_batch', false)->where('track_serial', false)->orderBy('id')->firstOrFail();
    }

    public function test_a_bill_paid_in_cash_cannot_be_cancelled(): void
    {
        $till = (int) app(CashTillService::class)->ensurePrimaryTill()->account_id;
        $this->putMoneyIn(\App\Modules\Accounts\Models\Account::query()->findOrFail($till), '1000');
        $bill = $this->buy(['deposits' => [[
            'payment_method_id' => (int) PaymentMethod::query()->where('code', 'CASH')->value('id'),
            'account_id' => $till, 'amount' => '600', 'reference' => null, 'ref_date' => now()->toDateString(),
        ]]]);
        $this->assertSame(0, bccomp($bill->fresh()->paidAmount(), '600', 4), 'প্রস্তুতি: বিলে পরিশোধ বসেনি।');
        $entries = DB::table('ledger_entries')->count();

        try {
            app(PurchaseBillService::class)->cancel($bill->fresh(), 'ভুল সরবরাহকারী');
            $this->fail('⛔ পরিশোধ বসা বিল বাতিল হলো।');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($bill->document_no, implode(' ', $e->validator->errors()->all()));
        }

        $this->assertSame(DocumentStatus::CONFIRMED, $bill->fresh()->status);
        $this->assertSame($entries, DB::table('ledger_entries')->count(), '⛔ থামার পরেও খাতায় উল্টো সারি।');
    }

    public function test_a_bill_bought_on_credit_still_cancels(): void
    {
        $bill = $this->buy();

        app(PurchaseBillService::class)->cancel($bill->fresh(), 'ভুল সরবরাহকারী');

        $this->assertSame(DocumentStatus::CANCELLED, $bill->fresh()->status);
    }

    private function buy(array $extra = [])
    {
        return app(DirectPurchaseService::class)->complete([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'PAID-'.fake()->unique()->numberBetween(10000, 99999),
            ...$extra,
        ], [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '60', 'sales_price' => '60', 'tax' => '0']])['bill'];
    }
}
