<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Services\PaymentService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ সরবরাহকারীর পরিশোধ সহকর্মীর ক্যাশবাক্স থেকে (মালিক ২১/২৮ সেপ্টেম্বর ২০২৬; Purchase লাইন-ধরা নিরীক্ষা, ১৩ অক্টোবর ২০২৬;
 * [[PaymentService::resolveMoneyAccount()]])।
 *
 * ⚠️ ভাউচার নিজের বাক্স ছাড়া নগদ নেয় না, অথচ ক্রয়ের পরিশোধ কেবল দেখত খাতটা টাকার খাত কি না — সহকর্মীর বাক্সের খাত পাঠালে তাঁর
 * অজান্তে তাঁর বাক্স খালি হত। ⓘ নিজের বাক্সে চলে।
 */
final class ASupplierWasPaidFromSomeoneElsesTillTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_supplier_payment_takes_cash_only_from_the_writers_own_till(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        $colleague = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $tills = app(CashTillService::class);
        $mine = $tills->create(['name_en' => 'My counter', 'holder_id' => $owner->id]);
        $theirs = $tills->create(['name_en' => 'Their counter', 'holder_id' => $colleague->id]);

        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $bills = app(PurchaseBillService::class);
        $bill = $bills->confirm($bills->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'TILL-1'],
            [['product_id' => Product::query()->where('track_batch', false)->where('track_serial', false)->orderBy('id')->firstOrFail()->id,
                'qty' => '10', 'rate' => '50']],
        ));

        try {
            app(PaymentService::class)->create(
                ['supplier_id' => $supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '100', 'account_id' => $theirs->account_id],
                [['purchase_bill_id' => $bill->id, 'amount' => '100']],
            );
            $this->fail('⛔ সহকর্মীর ক্যাশবাক্স থেকে সরবরাহকারীর পরিশোধ লেখা গেল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('account_id', $e->errors(), 'অন্য কারণে থামল।');
        }

        $ok = app(PaymentService::class)->create(
            ['supplier_id' => $supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '100', 'account_id' => $mine->account_id],
            [['purchase_bill_id' => $bill->id, 'amount' => '100']],
        );
        $this->assertSame((int) $mine->account_id, (int) $ok->account_id);
    }
}
