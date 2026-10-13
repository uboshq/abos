<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\ChequeService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Services\PaymentService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ সরবরাহকারীর চেক ফেরত এলেও বিল "পরিশোধিত" থাকত (fe, ১৩ অক্টোবর ২০২৬; [[UndoThePaymentOfABouncedCheque]])।
 *
 * ⚠️ খাতা উল্টাত (দেনা ফেরত), কিন্তু পরিশোধ "নিশ্চিত" থাকত — বিলে বাকি ০, আবার শোধ করা যেত না, আর পরিশোধটা বাতিলও না।
 * ⭐ এখন পরিশোধ "চেক ফেরত", বিল আবার পুরো বাকি, আর পরিশোধটা বাতিল করা যায়।
 */
final class ASupplierChequeBouncedAndTheBillStayedPaidTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bounced_supplier_cheque_puts_the_bill_back_to_owing(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $bills = app(PurchaseBillService::class);
        $bill = $bills->confirm($bills->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'BNC-1'],
            [['product_id' => Product::query()->where('track_batch', false)->where('track_serial', false)->orderBy('id')->firstOrFail()->id,
                'qty' => '10', 'rate' => '50']],
        ));

        $bank = Account::query()->firstOrCreate(['code' => '1102-BOUNCE'], [
            'company_id' => $company->id, 'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id'),
            'name_en' => 'Bounce bank', 'name_bn' => 'ফেরতের ব্যাংক', 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'is_group' => false, 'money_kind' => Account::BANK,
        ]);

        $payments = app(PaymentService::class);
        $payment = $payments->confirm($payments->create(
            ['supplier_id' => $supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '500', 'account_id' => $bank->id,
                'instrument' => 'cheque', 'instrument_no' => 'CHQ-9', 'instrument_date' => now()->toDateString()],
            [['purchase_bill_id' => $bill->id, 'amount' => '500']],
        ));
        $this->assertNotNull($payment->cheque_id, 'দৃশ্যটাই বানানো যায়নি — চেক লেখা হয়নি');
        $this->assertSame(0, bccomp($bill->fresh()->dueAmount(), '0', 4), 'দৃশ্যটাই বানানো যায়নি — বিল শোধ হয়নি');

        app(ChequeService::class)->bounce($payment->cheque()->firstOrFail(), 'তহবিল নেই');

        $this->assertSame(Payment::BOUNCED, $payment->fresh()->status, '⛔ চেক ফেরত, অথচ পরিশোধ "নিশ্চিত"।');
        $this->assertSame(0, bccomp($bill->fresh()->dueAmount(), '500', 4), '⛔ চেক ফেরত, অথচ বিল "পরিশোধিত"।');

        // ⓘ ফেরত পরিশোধ বাতিল করা যায় — আর কিছু দ্বিতীয়বার উল্টায় না
        $payments->cancel($payment->fresh(), 'চেক ফেরত');
        $this->assertSame(DocumentStatus::CANCELLED, $payment->fresh()->status);
        $this->assertSame(0, bccomp($bill->fresh()->dueAmount(), '500', 4));
    }
}
