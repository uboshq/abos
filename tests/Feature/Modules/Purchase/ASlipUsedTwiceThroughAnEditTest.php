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
 * ⛔ সম্পাদনা দিয়ে একই স্লিপ দুবার (মালিকের নিয়ম ৫ সেপ্টেম্বর ২০২৬; Purchase লাইন-ধরা নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[PaymentService::update()]])।
 *
 * ⚠️ "একই স্লিপ দুইবার নয়" কেবল পরিশোধ তৈরির সময় দেখা হত। খ-কে স্লিপ ছাড়া লিখে পরে ক-এর TT-55 বসালে পার পেত। ⓘ নিজের স্লিপ
 * রেখে অন্য ঘর বদলানো চলে।
 */
final class ASlipUsedTwiceThroughAnEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_edit_cannot_give_a_payment_a_slip_another_payment_already_carries(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $bills = app(PurchaseBillService::class);
        $bill = $bills->confirm($bills->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'SLIP-1'],
            [['product_id' => Product::query()->where('track_batch', false)->where('track_serial', false)->orderBy('id')->firstOrFail()->id,
                'qty' => '10', 'rate' => '50']],
        ));

        $payments = app(PaymentService::class);
        $first = $payments->create(['supplier_id' => $supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '100', 'instrument_no' => 'TT-55'],
            [['purchase_bill_id' => $bill->id, 'amount' => '100']]);
        // ⛔ পদ্ধতি ছাড়া পাঠানো স্লিপ নম্বর থাকে — "নগদ" ধরে মুছে ফেলা নয় ([[MoneyMethod::onlyItsOwn()]])
        $this->assertSame('TT-55', $first->fresh()->instrument_no, '⛔ পদ্ধতি না দেওয়ায় স্লিপ নম্বর নীরবে মুছে গেল।');
        $second = $payments->create(['supplier_id' => $supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '100'],
            [['purchase_bill_id' => $bill->id, 'amount' => '100']]);

        try {
            $payments->update($second->fresh(), ['trx_date' => now()->toDateString(), 'amount' => '100', 'instrument_no' => 'TT-55'],
                [['purchase_bill_id' => $bill->id, 'amount' => '100']]);
            $this->fail('⛔ সম্পাদনা দিয়ে আরেকটা পরিশোধের TT-55 বসানো গেল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('instrument_no', $e->errors(), 'অন্য কারণে থামল।');
        }

        // ⓘ নিজের স্লিপ রেখে সম্পাদনা চলে
        $payments->update($second->fresh(), ['trx_date' => now()->toDateString(), 'amount' => '100', 'instrument_no' => 'TT-56'],
            [['purchase_bill_id' => $bill->id, 'amount' => '100']]);
        $payments->update($second->fresh(), ['trx_date' => now()->toDateString(), 'amount' => '90', 'instrument_no' => 'TT-56'],
            [['purchase_bill_id' => $bill->id, 'amount' => '90']]);
        $this->assertSame('TT-56', $second->fresh()->instrument_no);
    }
}
