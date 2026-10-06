<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesReturnService;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Reports\PrincipalCommissionReport;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * প্রিন্সিপাল পায় কেবল বিক্রি হওয়া মালের কেনা দাম — "আসল" ভিত্তি (মালিক, ৬ অক্টোবর ২০২৬)।
 *
 * *"হোলসেলে একই পণ্য নানা দরে বিক্রি হয়, তাই কোম্পানি (প্রিন্সিপাল) পাবে শুধু তার ক্রয়মূল্য, বাকি বাড়তি টাকা সব কমিশন।"*
 * ⭐ অংশ = চক্রে, প্রিন্সিপালের শাখায় পাকা বিক্রিতে বেরোনো তাঁর কাছ থেকে কেনা মালের আসল ক্রয়মূল্য − পাকা ফেরত;
 * কমিশন = আদায় − অংশ ([[PrincipalCommission::costOfSales()]])।
 */
final class ThePrincipalGetsOnlyWhatTheGoodsCostTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $a;

    private Branch $b;

    private Supplier $principal;

    private Supplier $other;

    private Customer $dealer;

    private Account $bank;

    private Warehouse $storeA;

    private Warehouse $storeB;

    private Product $soap;

    private Product $oil;

    private int $source = 8100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Carbon::setTestNow('2026-10-05 10:00:00');

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->a = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $this->b = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();
        CompanyContext::set($this->company->id, $this->a->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        $this->bank = app(AccountService::class)->create(['name_en' => 'Actual basis bank', 'parent_id' => StandardChart::find(StandardChart::BANK)->id]);

        $suppliers = Supplier::query()->onlySuppliers()->orderBy('id')->take(2)->get();
        [$this->principal, $this->other] = [$suppliers->first(), $suppliers->last()];
        $this->principal->forceFill(['principal_branch_id' => $this->a->id, 'commission_basis' => 'actual', 'commission_rate' => null,
            'cycle_start_day' => 2, 'cycle_close_day' => 1])->save();
        $this->dealer = Customer::query()->orderBy('id')->firstOrFail();

        $this->storeA = Warehouse::query()->create(['code' => 'ACT-A', 'name_en' => 'Store A', 'is_active' => true, 'branch_id' => $this->a->id]);
        $this->storeB = Warehouse::query()->create(['code' => 'ACT-B', 'name_en' => 'Store B', 'is_active' => true, 'branch_id' => $this->b->id]);
        $unit = Unit::query()->orderBy('id')->firstOrFail()->id;
        $this->soap = Product::query()->create(['code' => 'ACT-SOAP', 'name_en' => 'Soap', 'name_bn' => 'সাবান', 'unit_id' => $unit, 'is_active' => true, 'sale_price' => '60']);
        $this->oil = Product::query()->create(['code' => 'ACT-OIL', 'name_en' => 'Oil', 'name_bn' => 'তেল', 'unit_id' => $unit, 'is_active' => true, 'sale_price' => '100']);

        // ⓘ সাবান প্রিন্সিপালের কাছ থেকে ৪০ টাকায়, তেল অন্য সরবরাহকারীর কাছ থেকে ৭০ টাকায়
        $this->bought($this->principal, $this->soap, '20', '40');
        $this->bought($this->other, $this->oil, '10', '70');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_share_is_exactly_the_purchase_cost_whatever_the_selling_price(): void
    {
        $this->sell($this->soap, '3', '60');   // ১৮০
        $this->sell($this->soap, '2', '50');   // ১০০ — একই পণ্য অন্য দরে
        $this->receipt('280');

        $row = $this->row();

        $this->assertSame('200.00', $row['share'], '⛔ অংশ পাঁচটা সাবানের কেনা দাম (৫ × ৪০) নয়।');
        $this->assertSame('80.00', $row['commission'], '⛔ কমিশন আদায় − কেনা দাম নয়।');
        $this->assertSame('আসল', $row['basis_rate']);
    }

    public function test_another_suppliers_goods_in_the_same_branch_are_not_counted(): void
    {
        $this->sell($this->soap, '1', '60');
        $this->sell($this->oil, '2', '100');

        $this->assertSame('40.00', $this->row()['share'], '⛔ অন্য সরবরাহকারীর তেলের কেনা দামও প্রিন্সিপালের অংশে ঢুকল।');
    }

    public function test_a_return_gives_the_cost_back(): void
    {
        $invoice = $this->sell($this->soap, '4', '60');
        $returns = app(SalesReturnService::class);
        $returns->confirm($returns->create(
            ['customer_id' => $this->dealer->id, 'warehouse_id' => $this->storeA->id, 'sales_invoice_id' => $invoice->id,
                'trx_date' => '2026-10-04', 'reason_code_id' => (int) ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->where('code', 'DAMAGE')->value('id')],
            [['product_id' => $this->soap->id, 'sales_invoice_line_id' => $invoice->lines->first()->id, 'qty' => '1']],
        ));

        $this->assertSame('120.00', $this->row()['share'], '⛔ ফেরত আসা সাবানের কেনা দাম অংশ থেকে বাদ গেল না।');
    }

    public function test_a_cancelled_sale_counts_nothing(): void
    {
        $invoice = $this->sell($this->soap, '5', '60');
        $service = app(SalesInvoiceService::class);
        DB::transaction(fn () => $service->takeBackForEdit($invoice, Carbon::today(), 'ACT'));
        $service->cancel($invoice->fresh(), 'ACT');

        $this->assertSame('0.00', $this->row()['share'], '⛔ বাতিল বিক্রির কেনা দাম অংশে থেকে গেল।');
    }

    public function test_another_branch_is_not_counted(): void
    {
        CompanyContext::set($this->company->id, $this->b->id);
        app(StockService::class)->move(product: $this->soap, warehouse: $this->storeB, sourceType: 'test.in', sourceId: 1, floor: '5');
        $this->sell($this->soap, '2', '60', $this->storeB);
        CompanyContext::set($this->company->id, $this->a->id);

        $this->assertSame('0.00', $this->row()['share'], '⛔ অন্য শাখার বিক্রির কেনা দাম প্রিন্সিপালের অংশে ঢুকল।');
    }

    public function test_a_loss_is_said_in_words_not_as_a_bare_minus(): void
    {
        $this->sell($this->soap, '5', '60');
        $this->receipt('150');

        $row = $this->row();
        $this->assertSame('-50.00', $row['commission']);

        $column = collect(app(ReportEngine::class)->get(PrincipalCommissionReport::KEY)->columns)->firstWhere('key', 'commission');
        $said = $column->signed($row['commission']);
        $this->assertStringContainsString(app()->getLocale() === 'bn' ? 'লোকসান' : 'Loss', $said, '⛔ ঋণাত্মক কমিশন কথায় নয়: '.$said);
        $this->assertStringNotContainsString('-', $said);
    }

    public function test_the_form_takes_the_actual_basis_without_a_rate(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $this->actingAs($owner)->get(route('supplier.edit', $this->other))->assertOk()
            ->assertSee(__('supplier::principal.basis_actual'))
            ->assertSee('data-commission-rate', false);

        $this->actingAs($owner)->put(route('supplier.update', $this->other), [
            'name_en' => $this->other->name_en,
            'commission_basis' => 'actual',
            'principal_branch_id' => $this->a->id,
            'cycle_start_day' => 2,
            'cycle_close_day' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame('actual', $this->other->fresh()->commission_basis, '⛔ "আসল" ভিত্তি হার ছাড়া বসল না।');

        // ⓘ অন্য ভিত্তিতে হার এখনো লাগে
        $this->actingAs($owner)->put(route('supplier.update', $this->other), [
            'name_en' => $this->other->name_en, 'commission_basis' => 'margin', 'principal_branch_id' => $this->a->id,
            'cycle_start_day' => 2, 'cycle_close_day' => 1,
        ])->assertSessionHasErrors('commission_rate');
    }

    private function bought(Supplier $supplier, Product $product, string $qty, string $cost): void
    {
        $bill = PurchaseBill::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->a->id,
            'document_no' => 'ACT-PB-'.$product->code, 'supplier_id' => $supplier->id, 'trx_date' => '2026-09-20']);
        app(StockService::class)->move(product: $product, warehouse: $this->storeA, sourceType: 'purchase_bill', sourceId: $bill->id, floor: $qty);
        app(CostLayerService::class)->receive(product: $product, qty: $qty, unitCost: $cost, sourceType: 'purchase_bill',
            sourceId: $bill->id, documentNo: $bill->document_no, date: '2026-09-20');
    }

    private function sell(Product $product, string $qty, string $rate, ?Warehouse $store = null): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create(
            ['customer_id' => $this->dealer->id, 'warehouse_id' => ($store ?? $this->storeA)->id, 'trx_date' => '2026-10-03'],
            [['product_id' => $product->id, 'qty' => $qty, 'rate' => $rate]],
        ))->fresh(['lines.product', 'lines.challanLine', 'warehouse']);
    }

    private function receipt(string $amount): void
    {
        app(PostingEngine::class)->post('collection', ++$this->source, '2026-10-04', [
            ['account_id' => $this->bank->id, 'debit' => $amount, 'branch_id' => $this->a->id],
            ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'credit' => $amount,
                'party_type' => 'customer', 'party_id' => $this->dealer->id, 'branch_id' => $this->a->id],
        ], 'T-'.$this->source, $this->a->id);
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        $row = collect(app(ReportEngine::class)->run(PrincipalCommissionReport::KEY, [])->rows)->firstWhere('supplier_id', $this->principal->id);
        $this->assertNotNull($row, '⛔ "আসল" ভিত্তির প্রিন্সিপালের সারিই নেই — হার না থাকায় বাদ পড়ল।');

        return $row;
    }
}
