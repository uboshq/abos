<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\DataScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\HeldCounterSaleFinisher;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ⛔ সইয়ের পরে কাগজ পাকা হয় তার নিজের শাখায় — বানানেওয়ালা বা সইকারীর হেডারে যে শাখাই থাকুক (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬,
 * বিক্রয় ১; [[HeldCounterSaleFinisher::finish()]], [[SignedChallanConfirmer::confirm()]], [[CompanyContext::inBranch()]])।
 *
 * ⓘ কাজটা চলে বানানেওয়ালার নামে, আর শাখার দেয়াল দেখে হেডারের শাখা। ময়মনসিংহে বানানো বিক্রি — হেডার নেত্রকোনায় থাকলে বিলটাই "নেই"
 * ("No query results for model SalesInvoice"), বিক্রি আটকে; অফিসের চালানে আদেশ "নেই", আর আদেশের ধরা মাল কখনো ছাড়া হত না।
 */
final class AHeldSaleFinishesInItsOwnBranchTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $maker;

    private User $signer;

    private Branch $home;

    private Branch $elsewhere;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->home = $this->company->defaultBranch();
        $this->elsewhere = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->whereKeyNot($this->home->id)->orderBy('id')->firstOrFail();
        CompanyContext::set($this->company->id, $this->home->id);

        $this->maker = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->maker);
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '1000000000'])->save();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('track_batch', false)->where('is_active', true)->where('sale_price', '>', 0)->orderBy('id')->firstOrFail();
        app(StockService::class)->move(product: $this->product, warehouse: $this->warehouse, sourceType: StockService::ADJUSTMENT, sourceId: $this->product->id, floor: '100');

        $this->signer = User::query()->create(['name' => 'Far Signer', 'email' => 'far-signer@abos.test', 'password' => Hash::make('secret-secret'), 'is_active' => true]);
        $this->signer->companies()->attach($this->company->id, ['is_active' => true]);
    }

    public function test_a_counter_sale_finishes_while_the_makers_header_shows_another_branch(): void
    {
        $this->flow(VoucherApproval::MODULE, VoucherApproval::COUNTER_DEPOSIT);
        $invoice = $this->heldSale();
        $this->assertSame((int) $this->home->id, (int) $invoice->branch_id);

        $this->lookElsewhere();
        $this->sign($this->depositApprovals($invoice));

        $this->assertSame('confirmed', SalesInvoice::acrossBranches()->findOrFail($invoice->id)->status,
            '⛔ হেডারে অন্য শাখা — সইয়ের পরেও বিক্রি শেষ হলো না ("No query results for model SalesInvoice")');
    }

    public function test_a_stuck_sale_returns_to_draft_from_another_branchs_header(): void
    {
        $this->flow(VoucherApproval::MODULE, VoucherApproval::COUNTER_DEPOSIT);
        $invoice = $this->heldSale('500');
        $this->customer->forceFill(['credit_limit' => '100'])->save();
        $this->sign($this->depositApprovals($invoice));
        $this->assertTrue(HeldCounterSaleFinisher::signedNotFinished()->whereKey($invoice->id)->exists(), 'দৃশ্যটাই বানানো যায়নি — বিক্রিটা আটকে থাকার কথা');

        $this->lookElsewhere();
        app(HeldCounterSaleFinisher::class)->returnToDraft(SalesInvoice::acrossBranches()->findOrFail($invoice->id), $this->maker->fresh());

        $this->assertNotNull(SalesInvoice::acrossBranches()->findOrFail($invoice->id)->counter_draft, '⛔ অন্য শাখার হেডার থেকে খসড়ায় ফেরানো গেল না');
    }

    public function test_an_office_challan_from_an_order_releases_the_orders_hold(): void
    {
        app(SettingsService::class)->set('sales.reserve_on_order', true);
        $orders = app(SalesOrderService::class);
        $order = $orders->confirm($orders->create(['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'ordered_qty' => '4', 'rate' => (string) $this->product->sale_price]])->fresh(['lines']))->fresh(['lines']);
        $this->assertSame('4.0000', $orders->heldByThisOrder($order)[$this->product->id] ?? '0', 'দৃশ্যটাই বানানো যায়নি — আদেশ মাল ধরেনি');

        $this->flow('sales', 'challan');
        $challan = app(DeliveryChallanService::class)->create(['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id,
            'sales_order_id' => $order->id, 'trx_date' => now()->toDateString(), 'own_transport' => true],
            [['product_id' => $this->product->id, 'sales_order_line_id' => $order->lines->first()->id, 'delivered_qty' => '4', 'rate' => (string) $this->product->sale_price]]);
        $this->post(route('sales.challan.confirm', $challan))->assertRedirect();
        $approval = Approval::query()->where('approvable_type', $challan->getMorphClass())->where('approvable_id', $challan->id)
            ->where('status', Approval::PENDING)->firstOrFail();

        $this->lookElsewhere();
        $this->sign([$approval]);

        $this->assertSame(DocumentStatus::CONFIRMED, DeliveryChallan::acrossBranches()->findOrFail($challan->id)->status, '⛔ অন্য শাখার হেডারে চালান পাকা হলো না');
        CompanyContext::set($this->company->id, $this->home->id);
        $this->assertSame('0.0000', $orders->heldByThisOrder(SalesOrder::acrossBranches()->findOrFail($order->id))[$this->product->id] ?? '0',
            '⛔ চালান পাকা, অথচ আদেশের ধরা মাল ছাড়া হলো না');
    }

    // ── যন্ত্রপাতি ─────────────────────────────────────────────────────

    /** ⓘ বানানেওয়ালার হেডারে নেত্রকোনা, আর সইকারীর অনুরোধও নেত্রকোনার প্রসঙ্গে (মিডলওয়্যার যা বসায়) */
    private function lookElsewhere(): void
    {
        $this->maker->forceFill(['view_all_branches' => false, 'current_branch_id' => $this->elsewhere->id])->save();
        $this->signer->forceFill(['view_all_branches' => false, 'current_branch_id' => $this->elsewhere->id])->save();
        CompanyContext::set($this->company->id, $this->elsewhere->id);
        app(DataScope::class)->forget();
    }

    private function heldSale(string $deposit = '1000'): SalesInvoice
    {
        $bank = Account::query()->ofMoneyKind(Account::BANK)->postable()->active()->orderBy('id')->first();

        if ($bank === null) {
            $bank = Account::query()->ofMoneyKind(Account::CASH)->postable()->orderBy('id')->firstOrFail()->replicate(['public_id']);
            $bank->forceFill(['code' => 'BANK-AUTO', 'name_en' => 'BANK-AUTO', 'name_bn' => 'BANK-AUTO', 'money_kind' => Account::BANK])->save();
        }

        $this->actingAs($this->maker)->post(route('sales.direct.store'), [
            'own_transport' => '1', 'customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id,
            'vehicle_no' => 'DHA-GA-11-2233',
            'deposits' => [['amount' => $deposit, 'account_id' => $bank->id, 'reference' => 'TRX-'.uniqid()]],
            'lines' => [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100']],
        ])->assertSessionHasNoErrors();

        $invoice = SalesInvoice::acrossBranches()->latest('id')->firstOrFail();
        $this->assertSame('draft', $invoice->status, 'দৃশ্যটাই বানানো যায়নি — বিক্রি সইয়ের অপেক্ষায় থাকার কথা');

        return $invoice;
    }

    /** @return list<Approval> */
    private function depositApprovals(SalesInvoice $invoice): array
    {
        $ids = Voucher::acrossBranches()->where('against_type', SalesInvoice::drillSourceType())->where('against_id', $invoice->id)->pluck('id');

        return Approval::query()->where('approvable_type', Voucher::class)->whereIn('approvable_id', $ids)
            ->where('status', Approval::PENDING)->orderBy('id')->get()->all();
    }

    /** @param  list<Approval>  $approvals */
    private function sign(array $approvals): void
    {
        $this->assertNotSame([], $approvals, 'দৃশ্যটাই বানানো যায়নি — কোনো সই চাওয়া হয়নি');
        $this->actingAs($this->signer->fresh());

        foreach ($approvals as $approval) {
            app(ApprovalEngine::class)->approve($approval->fresh(), $this->signer->fresh());
        }
    }

    private function flow(string $module, string $action): void
    {
        $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'module' => $module, 'action' => $action,
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->signer->id]);
        $this->app->forgetInstance(ApprovalEngine::class);
        $this->app->forgetScopedInstances();
    }
}
