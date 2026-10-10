<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Executive;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Executive\Reports\ExecutiveReports;
use App\Modules\Executive\Services\Board;
use App\Modules\Inventory\Models\CostLayerUse;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesReturnService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ক্রেতা ধরে লাভ = বিক্রি − খাতায় লেখা আসল FIFO খরচ, ফেরত তার নিজের খরচে বাদ।
 *
 * ⭐ খরচটা এখানে আলাদা পথে মাপা হয়: স্তর থেকে কোন বিলের নামে কত টানা হয়েছিল, তার খাতা
 * (`inv_cost_layer_uses`) — রিপোর্ট যে ঘর পড়ে (বিলের সারির `unit_cost`) সেটা নয়। দুইটা না মিললে লাল।
 */
final class ProfitByCustomerIsTheBooksOwnCostTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->alpha = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->actingAs($this->owner);
        CompanyContext::set((int) $this->alpha->id, (int) $this->owner->current_branch_id);
    }

    public function test_profit_is_revenue_less_the_fifo_cost_the_layers_gave(): void
    {
        $rahim = $this->sell('Rahim Traders', 'Cosmos Biscuit 40gm', '4', '1300');
        $this->sell('Rahim Traders', 'Miniket Rice 50kg', '2', '3600');
        $alam = $this->sell('Alam Store', 'Miniket Rice 50kg', '1', '3100');

        // ⓘ রহিমের বিস্কুটের অর্ধেক ফেরত — ফেরত তার নিজের খরচে বাদ যায়
        $return = $this->giveBack($rahim, '2');

        // ⚠️ লাইভের মতো কড়া MariaDB — GROUP BY যা বাছে তা-ই দলে
        DB::statement("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        $rows = collect(app(ReportEngine::class)->run(ExecutiveReports::PROFIT_BY_CUSTOMER, [], 1, 500)->rows)->keyBy('customer_id');

        foreach (['Rahim Traders', 'Alam Store'] as $name) {
            $customer = Customer::acrossDealers()->where('name_en', $name)->firstOrFail();
            $row = $rows[$customer->id] ?? null;
            $this->assertNotNull($row, "⛔ {$name}-এর সারি নেই।");

            $invoices = SalesInvoice::query()->where('customer_id', $customer->id)->get();
            $returns = SalesReturn::query()->where('customer_id', $customer->id)->get();

            $revenue = DB::table('sal_invoice_lines')->whereIn('sales_invoice_id', $invoices->pluck('id'))->sum(DB::raw('amount - tax'));
            $returned = $returns->reduce(fn (string $s, SalesReturn $r) => bcadd($s, bcsub((string) $r->total, (string) $r->tax, 4), 4), '0');

            // ⭐ স্তরের খাতা থেকে — বিলের নামে কত টানা হয়েছিল
            $drawn = CostLayerUse::query()->whereIn('document_no', $invoices->pluck('document_no'))->sum('amount');
            $cameBack = $returns->reduce(fn (string $s, SalesReturn $r) => bcadd($s, (string) $r->cost_of_goods, 4), '0');

            $net = bcsub((string) $revenue, $returned, 4);
            $cost = bcsub((string) $drawn, $cameBack, 4);

            $this->assertSame(0, bccomp($net, (string) $row['net_sales'], 4), "⛔ {$name}: নিট বিক্রি {$row['net_sales']}, হওয়ার কথা {$net}।");
            $this->assertSame(0, bccomp($cost, (string) $row['cost'], 4),
                "⛔ {$name}: রিপোর্টের খরচ {$row['cost']}, অথচ স্তর থেকে টানা খরচ (ফেরত বাদে) {$cost}।");
            $this->assertSame(0, bccomp(bcsub($net, $cost, 4), (string) $row['gross_profit'], 4), "⛔ {$name}: লাভ ≠ বিক্রি − খরচ।");
            $this->assertSame(1, bccomp($cost, '0', 4), "প্রস্তুতিটাই ভুল — {$name}-এর কোনো খরচ টানা হয়নি।");
        }

        $this->assertSame(1, bccomp((string) $return->cost_of_goods, '0', 4), 'প্রস্তুতিটাই ভুল — ফেরতের খরচ শূন্য, তাই "ফেরত বাদ" কিছুই প্রমাণ করে না।');
        $this->assertNotNull($alam);

        // ⓘ লোকসান আগে — রিপোর্টের নিজের ক্রম
        $profits = array_map(fn ($r) => (string) $r['gross_profit'], array_values($rows->all()));
        $sorted = $profits;
        usort($sorted, fn ($a, $b) => bccomp($a, $b, 4));
        $this->assertSame($sorted, $profits, '⛔ কম লাভের ক্রেতা আগে আসার কথা।');
    }

    public function test_cost_and_profit_stay_hidden_without_the_cost_key(): void
    {
        $this->sell('Rahim Traders', 'Cosmos Biscuit 40gm', '1', '1300');

        $accountant = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        CompanyContext::forCompany((int) $this->alpha->id, function () use ($accountant) {
            foreach ($accountant->roles as $role) {
                Role::findById($role->id)->givePermissionTo('executive.view');
                Role::findById($role->id)->revokePermissionTo(ExecutiveReports::COST_KEY);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($accountant->fresh())
            ->get(route('executive.report.show', ['slug' => 'profit-by-customer']))
            ->assertOk()
            ->assertSee(__('executive::analysis.col_net_sales'))
            ->assertDontSee(__('executive::analysis.col_cost'))
            ->assertDontSee(__('executive::analysis.col_margin'));

        // ⓘ "লাভ" শব্দটা মেনুতেও আছে ("ক্রেতা ধরে লাভ"), তাই কলামটা রিপোর্টের নিজের হিসাবে মাপা
        $this->actingAs($accountant->fresh());
        CompanyContext::set((int) $this->alpha->id, null);
        $visible = array_map(fn ($c) => $c->key, app(ReportEngine::class)->run(ExecutiveReports::PROFIT_BY_CUSTOMER)->columnsFor($accountant->fresh()));
        $this->assertNotContains('gross_profit', $visible, '⛔ খরচের চাবি ছাড়া লাভের কলাম দেখা যাচ্ছে।');
        $this->assertContains('net_sales', $visible);

        $this->actingAs($this->owner)
            ->get(route('executive.report.show', ['slug' => 'profit-by-customer']))
            ->assertOk()
            ->assertSee(__('executive::analysis.col_cost'));
    }

    public function test_the_report_door_asks_for_the_key(): void
    {
        $salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $this->actingAs($salesman)->get(route('executive.report.show', ['slug' => 'profit-by-customer']))->assertForbidden();
        $this->actingAs($this->owner)->get(route('executive.report.show', ['slug' => 'nothing']))->assertNotFound();
    }

    public function test_the_analysis_page_lists_what_the_reports_say(): void
    {
        $this->sell('Rahim Traders', 'Cosmos Biscuit 40gm', '4', '1300');
        $this->sell('Alam Store', 'Miniket Rice 50kg', '1', '3100');

        $page = $this->actingAs($this->owner)->get(route('executive.analysis'))->assertOk();

        // ⓘ সেরা ক্রেতা = sales.by_customer-এর সবচেয়ে বড় সারি
        $top = collect(app(ReportEngine::class)->run('sales.by_customer', [], 1, 50)->rows)
            ->sortByDesc(fn ($r) => (float) $r['total'])->first();
        $page->assertSee($top['customer_name'])
            ->assertSee(Money::format($top['total'], 0));

        foreach (['customers', 'products', 'areas', 'salespeople', 'losing', 'thin', 'dead'] as $list) {
            $page->assertSee('data-list="'.$list.'"', false);
        }
    }

    private function sell(string $customer, string $product, string $qty, string $rate): SalesInvoice
    {
        return CompanyContext::forCompany((int) $this->alpha->id, function () use ($customer, $product, $qty, $rate) {
            $code = $product === 'Miniket Rice 50kg' ? 'WH-NTK' : 'WH-MMS';
            $warehouse = Warehouse::query()->where('code', $code)->firstOrFail();
            CompanyContext::set((int) $this->alpha->id, (int) $warehouse->branch_id);

            $result = app(DirectSaleService::class)->complete(
                ['customer_id' => Customer::acrossDealers()->where('name_en', $customer)->firstOrFail()->id,
                    'warehouse_id' => $warehouse->id, 'own_transport' => '1', DirectSaleService::REPEAT_FIELD => '1'],
                [['product_id' => Product::query()->where('name_en', $product)->firstOrFail()->id,
                    'qty' => $qty, 'rate' => $rate, 'free_qty' => '0']],
            );

            app(Board::class)->refresh($this->owner);

            return $result['invoice']->fresh('lines');
        });
    }

    private function giveBack(SalesInvoice $bill, string $qty): SalesReturn
    {
        return CompanyContext::forCompany((int) $this->alpha->id, function () use ($bill, $qty) {
            CompanyContext::set((int) $this->alpha->id, (int) $bill->branch_id);
            $returns = app(SalesReturnService::class);

            return $returns->confirm($returns->create([
                'customer_id' => $bill->customer_id,
                'warehouse_id' => $bill->warehouse_id,
                'sales_invoice_id' => $bill->id,
                'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->value('id'),
                'trx_date' => now()->toDateString(),
            ], $bill->lines->map(fn ($line) => [
                'product_id' => $line->product_id,
                'sales_invoice_line_id' => $line->id,
                'qty' => $qty,
            ])->all()))->fresh();
        });
    }
}
