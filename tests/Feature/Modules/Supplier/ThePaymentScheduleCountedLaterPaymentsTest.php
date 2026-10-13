<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PaymentLine;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Reports\PartyReports;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ পরিশোধের সূচি তারিখের পরের পরিশোধও গুনত (Supplier নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[PartyReports::paymentSchedule()]])।
 *
 * ⚠️ বিল ধরা হত "শেষ তারিখ" পর্যন্ত, অথচ শোধ — পরিশোধ, ভাউচার, ফেরত — সব তারিখের: পাঁচ দিন আগের ৫,০০০-এর বিল আজ পুরো শোধ
 * হলে তিন দিন আগের তারিখের সূচিতেও বিলটা উধাও, যদিও সেদিন পুরো টাকাই বাকি ছিল। ⭐ এখন শোধও তারিখ পর্যন্ত।
 */
final class ThePaymentScheduleCountedLaterPaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_payment_after_the_date_does_not_settle_the_bill_on_that_date(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $supplier = Supplier::query()->firstOrFail();

        $bill = PurchaseBill::query()->create([
            'branch_id' => Branch::query()->firstOrFail()->id, 'financial_year_id' => FinancialYear::query()->firstOrFail()->id,
            'supplier_id' => $supplier->id, 'warehouse_id' => Warehouse::query()->firstOrFail()->id, 'document_no' => 'T-LATE-PAY',
            'trx_date' => now()->subDays(5)->toDateString(), 'due_on' => now()->subDays(4)->toDateString(),
            'subtotal' => '5000', 'discount' => '0', 'tax' => '0', 'total' => '5000', 'status' => DocumentStatus::CONFIRMED,
        ]);
        $payment = Payment::query()->create([
            'branch_id' => $bill->branch_id, 'financial_year_id' => $bill->financial_year_id, 'supplier_id' => $supplier->id,
            'account_id' => StandardChart::find('1101')->id, 'document_no' => 'PAY-T-LATE', 'trx_date' => now()->toDateString(),
            'amount' => '5000', 'instrument' => 'cash', 'status' => DocumentStatus::CONFIRMED,
        ]);
        PaymentLine::query()->create(['payment_id' => $payment->id, 'purchase_bill_id' => $bill->id, 'amount' => '5000', 'line_no' => 1]);

        $rows = collect((PartyReports::paymentSchedule()->query)([
            'company_id' => CompanyContext::id(), 'branch_id' => null, 'party_type_id' => null,
            'from' => now()->subYear()->toDateString(), 'to' => now()->subDays(3)->toDateString(),
        ])->get()->all())->keyBy('document_no');

        $this->assertArrayHasKey('T-LATE-PAY', $rows->all(), '⛔ তারিখের পরের পরিশোধে বিলটা সেদিনের সূচি থেকে উধাও');
        $this->assertSame(0, bccomp('5000', (string) $rows['T-LATE-PAY']->due_amount, 2));
    }
}
