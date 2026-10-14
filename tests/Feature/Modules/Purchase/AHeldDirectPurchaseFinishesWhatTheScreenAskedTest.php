<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⭐ সইয়ের জন্য থামা সরাসরি ক্রয় শেষ সইয়ে পুরোটাই শেষ হয় — পর্দায় দেওয়া টাকা আর উপহারের মালসহ (হিসাবের নিরীক্ষা, ১৩ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে: বিলের ছক থাকলে সরাসরি ক্রয় খসড়ায় থামত; শেষ সইয়ে কেবল বিলটা পাকা হত ([[ConfirmThePurchasePaperOnTheLastSignature]])।
 * পর্দায় লেখা "এখনই ৬০০ দিলাম" আর মিলের দেওয়া উপহারের মাল চুপচাপ হারাত — সরবরাহকারীর পাওনা পুরো, গুদামে উপহার নেই।
 */
final class AHeldDirectPurchaseFinishesWhatTheScreenAskedTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        $till = app(CashTillService::class)->ensurePrimaryTill();
        $this->putMoneyIn(Account::query()->findOrFail($till->account_id), '5000', now()->toDateString());

        $this->signer = User::factory()->create(['is_active' => true, 'current_company_id' => $company->id]);
        $this->signer->companies()->attach($company->id, ['is_active' => true]);
        $flow = ApprovalFlow::query()->create(['company_id' => $company->id, 'module' => 'purchase', 'action' => 'bill',
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->signer->id]);
    }

    public function test_the_last_signature_pays_what_the_screen_paid_and_brings_in_the_gift(): void
    {
        [$product, $gift] = Product::query()->orderBy('id')->take(2)->get()->all();

        try {
            app(DirectPurchaseService::class)->complete([
                'supplier_id' => Supplier::query()->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(),
                'supplier_bill_no' => 'HELD-DIRECT-1',
                'paid_now' => '600',
            ], [['product_id' => $product->id, 'qty' => '10', 'rate' => '100', 'sales_price' => '100', 'tax' => '0']],
                [['product_id' => $gift->id, 'qty' => '3', 'against_product_id' => $product->id, 'remarks' => 'মিলের উপহার']]);
            $this->fail('প্রস্তুতি — বিল সইয়ের জন্য থামার কথা');
        } catch (HeldForApproval) {
        }

        $bill = PurchaseBill::query()->latest('id')->firstOrFail();
        $this->assertSame(DocumentStatus::DRAFT, $bill->status);

        $this->actingAs($this->signer);
        app(ApprovalEngine::class)->approve(app(ApprovalEngine::class)->latestFor($bill, 'bill'), $this->signer);

        $this->assertSame(DocumentStatus::CONFIRMED, $bill->fresh()->status, 'প্রস্তুতি — শেষ সইয়ে বিল পাকা হওয়ার কথা');

        $paid = Voucher::query()->where('type', Voucher::PAYMENT)->where('against_id', $bill->id)->get();
        $this->assertCount(1, $paid, '⛔ পর্দায় দেওয়া ৬০০ টাকা শেষ সইয়ের পরে কোথাও নেই — সরবরাহকারীর পাওনা পুরো দেখাচ্ছে।');
        $this->assertSame(0, bccomp('600', (string) $paid->first()->amount, 4));

        $this->assertTrue(StockMovement::query()->where('product_id', $gift->id)
            ->where('source_type', PurchaseBill::STOCK_SOURCE.':gift')->where('source_id', $bill->id)->exists(),
            '⛔ মিলের উপহার বিলে লেখা, অথচ গুদামে ঢোকেনি।');
        $this->assertSame(Approval::APPROVED, app(ApprovalEngine::class)->latestFor($bill, 'bill')?->status);
    }
}
