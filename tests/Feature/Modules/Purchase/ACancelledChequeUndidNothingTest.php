<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Services\AccountsSignature;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Services\PaymentService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ চেক-ফেরতের সই চালু থাকলে চেকে দেওয়া পরিশোধ বাতিল কিছুই উল্টাত না (fe, ১৩ অক্টোবর ২০২৬; [[ChequeService::cancel()]])।
 *
 * ⚠️ বাতিল ভিতরে "ফেরত" ডাকে; ফেরত সইয়ের অপেক্ষায় কিছু না করেই ফিরত, আর চেক তবু "বাতিল", পরিশোধও "বাতিল" — বিল আবার বাকি, অথচ
 * খাতায় শোধ (Dr দেনা / Cr ২১১৫) রয়ে গেল। ⭐ এখন বাতিলের উল্টো সবসময় বসে।
 */
final class ACancelledChequeUndidNothingTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelling_a_cheque_payment_reverses_it_even_with_the_bounce_signature_on(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $flow = ApprovalFlow::query()->create(['company_id' => $company->id, 'module' => AccountsSignature::MODULE,
            'action' => AccountsSignature::CHEQUE_BOUNCE, 'document_type' => '', 'threshold_amount' => null, 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user',
            'approver_id' => User::query()->where('email', 'accounts@abos.test')->value('id')]);
        $this->app->forgetInstance(\App\Core\Engines\Approval\ApprovalEngine::class);

        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $bills = app(PurchaseBillService::class);
        $bill = $bills->confirm($bills->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'CXL-1'],
            [['product_id' => Product::query()->where('track_batch', false)->where('track_serial', false)->orderBy('id')->firstOrFail()->id,
                'qty' => '10', 'rate' => '50']],
        ));
        $bank = Account::query()->firstOrCreate(['code' => '1102-CXLCHQ'], [
            'company_id' => $company->id, 'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id'),
            'name_en' => 'Cancel bank', 'name_bn' => 'বাতিলের ব্যাংক', 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'is_group' => false, 'money_kind' => Account::BANK,
        ]);

        $payments = app(PaymentService::class);
        $payment = $payments->confirm($payments->create(
            ['supplier_id' => $supplier->id, 'trx_date' => now()->toDateString(), 'amount' => '500', 'account_id' => $bank->id,
                'instrument' => 'cheque', 'instrument_no' => 'CHQ-CXL', 'instrument_date' => now()->toDateString()],
            [['purchase_bill_id' => $bill->id, 'amount' => '500']],
        ));
        $this->assertNotNull($payment->cheque_id, 'দৃশ্যটাই বানানো যায়নি — চেক লেখা হয়নি');

        $payments->cancel($payment->fresh(), 'ভুল চেক');

        $this->assertSame(DocumentStatus::CANCELLED, $payment->fresh()->status);
        $this->assertSame(Cheque::CANCELLED, Cheque::acrossBranches()->findOrFail($payment->cheque_id)->status);
        $this->assertTrue(DB::table('ledger_entries')->where('source_type', Cheque::STOCK_SOURCE.':bounced')
            ->where('source_id', $payment->cheque_id)->exists(),
            '⛔ ফেরতের সই চালু — চেক আর পরিশোধ "বাতিল", অথচ খাতায় শোধ উল্টানো হয়নি।');
    }
}
