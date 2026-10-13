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
use App\Modules\Purchase\Models\PaymentLine;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PaymentService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ এক পরিশোধে একই বিল দুবার — বিলের বেশি শোধ (Purchase লাইন-ধরা নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[PaymentService::assertStillFits()]])।
 *
 * ⚠️ বাকির যাচাই লাইন ধরে আলাদা করে হত: ৫০০ বাকির বিলে দুই লাইনে ৫০০ + ৫০০ — প্রতিটা ৫০০-এর ভেতরে, তাই দেনা থেকে কাটত ১,০০০।
 */
final class OnePaymentPaidOneBillTwiceTest extends TestCase
{
    use \Tests\Concerns\PutsMoneyInTheTill;
    use RefreshDatabase;

    private Supplier $supplier;

    private PurchaseBill $bill;

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
        $bills = app(PurchaseBillService::class);
        $this->bill = $bills->confirm($bills->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'TWICE-1'],
            [['product_id' => Product::query()->where('track_batch', false)->where('track_serial', false)->orderBy('id')->firstOrFail()->id,
                'qty' => '10', 'rate' => '50']],
        ));
        $this->assertSame(0, bccomp($this->bill->fresh()->dueAmount(), '500', 4), 'দৃশ্যটাই বানানো যায়নি — বিলের বাকি ৫০০ নয়');
    }

    public function test_writing_one_bill_on_two_lines_cannot_pay_more_than_it_owes(): void
    {
        try {
            app(PaymentService::class)->create(
                ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '1000'],
                [['purchase_bill_id' => $this->bill->id, 'amount' => '500'], ['purchase_bill_id' => $this->bill->id, 'amount' => '500']],
            );
            $this->fail('⛔ ৫০০ বাকির বিলে এক পরিশোধে ৫০০ + ৫০০ লেখা গেল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors(), 'অন্য কারণে থামল।');
        }
    }

    public function test_confirming_a_payment_that_holds_one_bill_twice_is_refused(): void
    {
        $payment = app(PaymentService::class)->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '1000'],
            [['purchase_bill_id' => $this->bill->id, 'amount' => '500']],
        );
        // ⓘ অন্য পথে আসা দ্বিতীয় লাইন (পুরনো খসড়া, API) — পাকা করার শেষ পাহারাটাই দেখার কথা
        PaymentLine::create(['company_id' => $payment->company_id, 'payment_id' => $payment->id,
            'purchase_bill_id' => $this->bill->id, 'amount' => '500', 'line_no' => 2]);
        $this->putMoneyIn($payment->account, '1000');

        try {
            app(PaymentService::class)->confirm($payment->fresh());
            $this->fail('⛔ একই বিল দুই লাইনে — পাকা হলো, বিলে ১,০০০ বসল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors(), 'অন্য কারণে থামল।');
        }

        $this->assertSame(DocumentStatus::DRAFT, $payment->fresh()->status);
        $this->assertSame(0, bccomp($this->bill->fresh()->paidAmount(), '0', 4));
    }
}
