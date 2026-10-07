<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\View\Components\Ui\Table;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * তালিকার নিচে সর্বমোট, আর প্রতিটা সারিতে "দেখুন" — মালিক, ১ অক্টোবর ২০২৬:
 * *"kono list er niche grand total nai keno"* আর *"sokol talikatei view botam ba icon dite dawni keno?"*
 *
 *   সর্বমোট   গোটা ছাঁকা তালিকার, সব পাতার — ৫১টা কাগজ (দুই পাতা) × ১০০ = ৫,১০০; উপরে "এই পাতা" ৫,০০০
 *   ছাঁকনি    খোঁজার শব্দ বদলালে সর্বমোটও বদলায়
 *   রপ্তানি   ফাইলের শেষে সর্বমোটের সারি
 *   দেখুন    প্রতিটা সারিতে চোখের লিংক, সেই কাগজেরই পাতা খোলে (একই মানুষ)
 * ⓘ চার তালিকা: ইনভয়েস, চালান, ডিও, বিক্রয় অর্ডার ([[x-ui.table]] `:grand`, `:view`)।
 */
final class EveryDocumentListShowsAGrandTotalAndAViewButtonTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    public function test_the_grand_total_covers_every_page_and_follows_the_filter(): void
    {
        foreach (['sal_invoices' => 'sales.invoice.index', 'sal_challans' => 'sales.challan.index', 'sal_orders' => 'sales.order.index'] as $table => $list) {
            $this->papers($table, 'ZQG', 51, '100');
            $this->papers($table, 'ZQH', 3, '7');

            $html = (string) $this->get(route($list, ['q' => 'ZQG']))->assertOk()->getContent();
            $this->assertSame([$this->money('5000'), $this->money('5100')], $this->footer($html), "{$list}: এই পাতা ৫,০০০ আর সর্বমোট ৫,১০০ নয়।");

            $narrow = (string) $this->get(route($list, ['q' => 'ZQH']))->assertOk()->getContent();
            $this->assertSame([$this->money('21')], $this->footer($narrow), "{$list}: ছাঁকনি বদলালেও সর্বমোট বদলায়নি (এক পাতায় কেবল সর্বমোট)।");

            $csv = (string) $this->get(route($list, ['q' => 'ZQG', 'export' => 'csv']))->assertOk()->getContent();
            $this->assertStringContainsString(__('core.table.grand_total'), $csv, "{$list}: রপ্তানিতে সর্বমোট নেই।");
            $this->assertStringContainsString($this->money('5100'), $csv);
        }
    }

    public function test_each_row_has_a_view_button_that_opens_its_own_paper(): void
    {
        $challan = $this->confirmedChallan();
        $this->papers('sal_invoices', 'ZQV', 1, '50');
        $this->papers('sal_orders', 'ZQV', 1, '50');

        foreach ([
            'sales.invoice.index' => ['ZQV', 'sales.invoice.show', 'sal_invoices'],
            'sales.challan.index' => [$challan->document_no, 'sales.challan.show', 'sal_challans'],
            // ⓘ পুরনো 'sales.do.index' এখন চালান-তালিকার ট্যাবে পাঠায় (৩ অক্টোবর ২০২৬) — নিজে তালিকা নয়
            'sales.order.index' => ['ZQV', 'sales.order.show', 'sal_orders'],
        ] as $list => [$q, $show, $table]) {
            $html = (string) $this->get(route($list, ['q' => $q]))->assertOk()->getContent();
            $id = (int) DB::table($table)->where('document_no', 'like', $q.'%')->orderBy('id')->value('id');
            $href = route($show, $id);

            $this->assertMatchesRegularExpression('/<a href="'.preg_quote($href, '/').'"[^>]*data-row-view/', $html,
                "{$list}: সারিতে \"দেখুন\" নেই, বা অন্য কাগজে নিয়ে যায়।");

            $paper = (string) $this->get($href)->assertOk()->getContent();
            $this->assertStringContainsString((string) DB::table($table)->where('id', $id)->value('document_no'), $paper,
                "{$list}: \"দেখুন\" খুলে অন্য কাগজ এল।");
        }
    }

    /**
     * ⭐ দ্বিতীয় দফা — ক্রয় বিল, পরিশোধ, ক্রয় ফেরত, আদায়, বিক্রয় ফেরত: সব পাতার সর্বমোট, আর সারির "দেখুন"।
     * ⓘ বিলে তিন ঘর: মোট, পরিশোধিত (সাব-কোয়েরির ঘর), বাকি (হিসাব করা) — শোধ নেই, তাই বাকি = মোট।
     */
    public function test_the_purchase_and_money_lists_carry_the_grand_total_and_the_view_button(): void
    {
        $supplier = (int) DB::table('suppliers')->value('id');
        $customer = (int) Customer::query()->value('id');
        $warehouse = (int) Warehouse::query()->where('is_default', true)->value('id');
        $account = (int) DB::table('accounts')->where('company_id', CompanyContext::id())->value('id');

        foreach ([
            'purchase.bill.index' => ['pur_bills', 'total', ['supplier_id' => $supplier], 'purchase.bill.show', 2],
            'purchase.payment.index' => ['pur_payments', 'amount', ['supplier_id' => $supplier, 'account_id' => $account], 'purchase.payment.show', 1],
            'purchase.return.index' => ['pur_returns', 'total', ['supplier_id' => $supplier, 'warehouse_id' => $warehouse], 'purchase.return.show', 1],
            'sales.collection.index' => ['sal_collections', 'amount', ['customer_id' => $customer, 'account_id' => $account], 'sales.collection.show', 1],
            'sales.return.index' => ['sal_returns', 'total', ['customer_id' => $customer, 'warehouse_id' => $warehouse], 'sales.return.show', 1],
        ] as $list => [$table, $column, $extra, $show, $cells]) {
            $this->rows($table, 'ZQG', 51, $column, '100', $extra);

            $html = (string) $this->get(route($list, ['q' => 'ZQG']))->assertOk()->getContent();
            $this->assertSame(array_fill(0, $cells, $this->money('5100')), $this->grandCells($html),
                "{$list}: সর্বমোট সব পাতার ৫,১০০ নয় (বিলে মোট, পরিশোধিত-বাদে বাকি)।");

            // ⓘ ৫১টার নতুন ৫০টা প্রথম পাতায় — সবচেয়ে নতুনটা (সবচেয়ে পুরনোটা দ্বিতীয় পাতায় পড়ে)
            $id = (int) DB::table($table)->where('document_no', 'like', 'ZQG-%')->max('id');
            $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route($show, $id), '/').'"[^>]*data-row-view/', $html,
                "{$list}: সারিতে \"দেখুন\" নেই, বা অন্য কাগজে নিয়ে যায়।");
        }
    }

    /**
     * ⭐ মজুদ — সর্বমোট গোটা তালিকার: প্রতিটা পাতা হেঁটে সারির নিজের তাক-পরিমাণ যোগ করলে যা হয়, ঠিক তাই
     * (আর পাতায় সেটাই লেখা)। সারির "দেখুন" পণ্যের পাতায়।
     */
    public function test_the_stock_list_totals_the_floor_of_every_page_and_opens_the_product(): void
    {
        $response = $this->get(route('inventory.stock.index'))->assertOk();
        $html = (string) $response->getContent();

        $floor = '0';
        $products = $response->viewData('products');
        for ($page = 1; $page <= $products->lastPage(); $page++) {
            $rows = $page === 1 ? $products : $this->get(route('inventory.stock.index', ['page' => $page]))->viewData('products');
            foreach ($rows->items() as $row) {
                $floor = bcadd($floor, (string) $row->floor_total, 4);
            }
        }

        $this->assertGreaterThan(0, bccomp($floor, '0', 4), 'দৃশ্যটাই বানানো যায়নি — ডেমোতে তাকে কোনো মাল নেই।');
        $this->assertSame(0, bccomp($floor, (string) ($response->viewData('grand')['floor'] ?? '-1'), 4),
            '⛔ মজুদের সর্বমোটে তাকের পরিমাণ সব পাতার সারির যোগ নয়।');
        $this->assertSame(Table::format($floor, 'quantity'), $this->grandCells($html)[0] ?? null,
            '⛔ পাতার সর্বমোটের সারিতে তাকের যোগটা লেখা নেই।');

        $product = (int) $products->items()[0]->id;
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('inventory.product.show', $product).'#movements', '/').'"[^>]*data-row-view/', $html,
            '⛔ মজুদের সারিতে "দেখুন" নেই, বা অন্য পণ্যে নিয়ে যায়।');
    }

    /**
     * ⭐ বাজেটের ছক — সর্বমোট মাস ধরে আর বছরের, সব খাত মিলে ([[BudgetService::planTotals()]]); সারির "দেখুন" খাতের খতিয়ানে।
     */
    public function test_the_budget_grid_totals_each_month_and_the_year(): void
    {
        $year = (int) now()->year;
        $heads = DB::table('accounts')->where('company_id', CompanyContext::id())
            ->where('is_group', false)->where('code', 'like', '52%')->orderBy('id')->limit(2)->pluck('id');
        $this->assertCount(2, $heads, 'দৃশ্যটাই বানানো যায়নি — দুইটা খরচের খাত নেই।');

        foreach ([[$heads[0], 1, '100'], [$heads[1], 1, '50'], [$heads[1], 12, '7']] as [$account, $month, $amount]) {
            DB::table('fin_budgets')->insert([
                'company_id' => CompanyContext::id(), 'year' => $year, 'month' => $month,
                'account_id' => $account, 'amount' => $amount, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $html = (string) $this->get(route('finance.budget.index', ['year' => $year]))->assertOk()->getContent();

        $this->assertSame([$this->money('150'), $this->money('7'), $this->money('157')], $this->grandCells($html),
            '⛔ বাজেটের সর্বমোট: জানুয়ারি ১৫০, ডিসেম্বর ৭, বছর ১৫৭ নয়।');
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('accounts.coa.show', $heads[0]).'#transactions', '/').'"[^>]*data-row-view/', $html,
            '⛔ বাজেটের সারিতে "দেখুন" খাতের খতিয়ানে নিয়ে যায় না।');
    }

    /**
     * ⭐ টাকার হেফাজত — সর্বমোট = প্রতিটা জায়গার টাকা + পথে থাকা টাকা; আর সেটা "পথে থাকা"-র সারির **নিচে**,
     * একই ফুটারে (দুইটা ফুটার হলে সর্বমোট মাঝে বসত)।
     */
    public function test_the_custody_grand_total_is_every_place_plus_the_road_and_sits_last(): void
    {
        // ⓘ পথে ২৫০ — নইলে ডেমোতে পথ শূন্য, আর পথ বাদ দেওয়া যোগও সবুজ থাকত (মিউট্যান্ট বেঁচেছিল)
        $transit = (int) DB::table('accounts')->where('company_id', CompanyContext::id())
            ->where('code', \App\Modules\Accounts\Services\StandardChart::CASH_IN_TRANSIT)->value('id');
        $this->assertGreaterThan(0, $transit, 'দৃশ্যটাই বানানো যায়নি — পথের খাত নেই।');
        DB::table('ledger_entries')->insert([
            'company_id' => CompanyContext::id(), 'branch_id' => CompanyContext::branchId(),
            'financial_year_id' => DB::table('financial_years')->where('company_id', CompanyContext::id())->orderByDesc('id')->value('id'),
            'account_id' => $transit, 'trx_date' => now()->toDateString(), 'debit' => '250', 'credit' => '0',
            'source_type' => 'zq_test', 'source_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->get(route('accounts.custody'))->assertOk();
        $this->assertSame(0, bccomp((string) $response->viewData('transitAmount'), '250', 4), 'দৃশ্যটাই বানানো যায়নি — পথে ২৫০ নেই।');
        $html = (string) $response->getContent();

        $sum = collect($response->viewData('rows'))
            ->reduce(fn (string $s, array $r) => bcadd($s, (string) $r['amount'], 4), (string) $response->viewData('transitAmount'));

        $this->assertSame(1, preg_match('/<tfoot[^>]*data-grand-total[^>]*>(.*?)<\/tfoot>/su', $html, $foot), '⛔ হেফাজতে সর্বমোটের ফুটার নেই।');
        $this->assertSame(1, substr_count($html, '<tfoot'), '⛔ দুইটা ফুটার — সর্বমোট আর "পথে থাকা" আলাদা হয়ে গেছে।');
        $this->assertLessThan(strpos($foot[1], 'data-grand-total-row'), strpos($foot[1], (string) __('accounts::custody.on_the_road')),
            '⛔ সর্বমোট "পথে থাকা"-র আগে বসেছে — অথচ সেটা উপরের সব কিছুর যোগ।');
        $this->assertContains(Table::format($sum, 'money'), $this->grandCells($html) ?: [Table::format('0', 'money')],
            '⛔ হেফাজতের সর্বমোট সব জায়গা আর পথে থাকা টাকার যোগ নয়।');
    }

    /** @return list<string> সর্বমোটের সারির যোগের ঘর — বাঁ থেকে ডানে, খালি বাদে */
    private function grandCells(string $html): array
    {
        $this->assertSame(1, preg_match('/<tr[^>]*data-grand-total-row[^>]*>(.*?)<\/tr>/su', $html, $m), 'সর্বমোটের সারিই নেই।');
        preg_match_all('/<td[^>]*class="[^"]*\bnum\b[^"]*"[^>]*>\s*([^<]*?)\s*<\/td>/su', $m[1], $cells);

        $values = array_values(array_filter($cells[1], fn ($v) => trim($v) !== ''));

        // ⓘ বিলের "পরিশোধিত" ঘরে শূন্য — শোধ নেই; দাবিটা মোট আর বাকির
        return array_values(array_filter($values, fn ($v) => $v !== $this->money('0')));
    }

    /** @param  array<string, mixed>  $extra */
    private function rows(string $table, string $prefix, int $count, string $column, string $amount, array $extra): void
    {
        for ($i = 1; $i <= $count; $i++) {
            DB::table($table)->insert([
                'company_id' => CompanyContext::id(),
                'branch_id' => CompanyContext::branchId(),
                'document_no' => sprintf('%s-%03d', $prefix, $i),
                'trx_date' => now()->toDateString(),
                $column => $amount,
                'status' => 'confirmed',
                'created_at' => now(),
                'updated_at' => now(),
                ...$extra,
            ]);
        }
    }

    /** @return list<string> ফুটারের যোগের ঘরগুলো — "এই পাতা" (থাকলে) আর সর্বমোট */
    private function footer(string $html): array
    {
        $this->assertSame(1, preg_match('/<tfoot[^>]*data-grand-total[^>]*>(.*?)<\/tfoot>/su', $html, $m), 'সর্বমোটের সারিই নেই।');
        preg_match_all('/<tr\b.*?<\/tr>/su', $m[1], $rows);

        return array_map(function (string $tr) {
            preg_match_all('/<td[^>]*class="[^"]*\bnum\b[^"]*"[^>]*>\s*([^<]*?)\s*<\/td>/su', $tr, $cells);

            return (string) collect($cells[1])->first(fn ($v) => trim($v) !== '');
        }, $rows[0]);
    }

    /** সারির মতোই সাজানো টাকা — পাতার ভাষায় ([[Table::format()]]) */
    private function money(string $amount): string
    {
        return Table::format($amount, 'money');
    }

    private function papers(string $table, string $prefix, int $count, string $total): void
    {
        $customer = (int) Customer::query()->value('id');
        $warehouse = (int) Warehouse::query()->where('is_default', true)->value('id');

        for ($i = 1; $i <= $count; $i++) {
            DB::table($table)->insert(array_filter([
                'company_id' => CompanyContext::id(),
                'branch_id' => CompanyContext::branchId(),
                'document_no' => sprintf('%s-%03d', $prefix, $i),
                'customer_id' => $customer,
                'warehouse_id' => $table === 'sal_challans' ? $warehouse : null,
                'trx_date' => now()->toDateString(),
                'total' => $total,
                'status' => 'confirmed',
                'created_at' => now(),
                'updated_at' => now(),
            ], fn ($v) => $v !== null));
        }
    }

    private function confirmedChallan(): DeliveryChallan
    {
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $challan = app(DeliveryChallanService::class)->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => $warehouse->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'delivered_qty' => '5', 'rate' => '10']]);

        return app(DeliveryChallanService::class)->confirm($challan);
    }
}
