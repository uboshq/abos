<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ মালিক, ২৩ নভেম্বর ২০২৬: *"স্লিম ছাঁকনি সব পাতায় পৌঁছায়নি, রিপোর্টে ছাঁকা যায় না — প্রতিটা পাতা একটা একটা করে দেখো"*।
 *
 * ⓘ প্রতিটা তালিকা আর রিপোর্টের পাতা — তিন সাজেই (ABOS/rose, navy, blue):
 *   · ডান পাশের ছাঁকনির প্যানেল আছে (`data-filter-layout`, `data-filter-panel`) আর বারের "ছাঁকনি" বোতাম (`data-filter-toggle`);
 *   · পুরোনো ছাঁকনির সারি (`id="toolbar-filters"`) আর নেই — একই ঘর দুই জায়গায় নয়;
 *   · ⛔ কোনো পুরোনো ঘর হারায়নি: পাতার আগের প্রতিটা চাবি প্যানেলের ভিতরে একই নামে (`name="…"`) আছে।
 *     চাবির তালিকা রূপান্তরের আগের পাতা থেকে নেওয়া (fe/filters, ভিত্তি 3b945e15e) — কন্ট্রোলার আগের মতোই এগুলো পড়ে।
 */
final class EveryListAndReportHasTheFilterPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
    }

    /**
     * পাতা => [রুট, রুটের মান, আগের ছাঁকনির চাবি]
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: list<string>}>
     */
    public static function pages(): array
    {
        $pages = [
            // ── ইঞ্জিনের রিপোর্ট (সব মডিউলের রিপোর্ট একই পাতায়) আর হিসাবের নিজের রিপোর্ট ──
            'report: ledger' => ['accounts.report.show', ['slug' => 'ledger'], ['from', 'to']],
            'report: trial balance' => ['accounts.report.show', ['slug' => 'trial-balance'], ['from', 'to']],
            'report: balance sheet (as on)' => ['accounts.report.show', ['slug' => 'balance-sheet'], ['to']],
            'report: stock position' => ['inventory.report.show', ['slug' => 'stock-position'], ['warehouse_id', 'product_id', 'brand_id', 'category_id']],
            'balance sheet' => ['accounts.balance_sheet', [], ['as_of']],
            'day book' => ['accounts.daybook', [], ['date']],
            'day closing' => ['executive.dayclose', [], ['date']],
            'equity statement' => ['accounts.equity_statement', [], ['from', 'to']],
            'group report' => ['accounts.group_report', [], ['from', 'to']],
            'year-end trial balance' => ['accounts.year_end_trial_balance', [], ['year_id']],

            // ── তালিকা ──
            'accounts: assets' => ['accounts.asset.index', [], ['category', 'status']],
            'accounts: cheques' => ['accounts.cheque.index', [], ['direction', 'status']],
            'accounts: chart' => ['accounts.coa.index', [], ['inactive']],
            'accounts: tills' => ['accounts.till.index', [], ['inactive']],
            'accounts: receipts' => ['accounts.voucher.index', ['type' => 'receipt'], ['from', 'to', 'party', 'account', 'method', 'state', 'by', 'min', 'max', 'origin']],
            'accounts: payments' => ['accounts.voucher.index', ['type' => 'payment'], ['from', 'to', 'party', 'account', 'method', 'state', 'by', 'min', 'max']],
            'accounts: voucher list' => ['accounts.voucher.list', [], ['from', 'to']],
            'approval: inbox' => ['approval.inbox.index', [], ['module', 'amount']],
            'assistant: audit' => ['assistant.audit', [], ['from', 'to', 'kind', 'user_id', 'ip', 'bar:q']],
            'customer: binding' => ['customer.binding.index', [], ['ended', 'user_id']],
            'documents: centre' => ['documents.index', [], ['doc_type', 'status', 'date_from', 'date_to']],
            'finance: account analysis' => ['finance.account_analysis.index', [], ['account_id', 'from', 'to']],
            'finance: bank charges' => ['finance.bank_charge.index', [], ['from', 'to']],
            'finance: budget' => ['finance.budget.index', [], ['year', 'center']],
            'finance: budget vs actual' => ['finance.budget.actual', [], ['year', 'center', 'month']],
            'finance: carrier and labour' => ['finance.carrier_labour.index', [], ['from', 'to']],
            'finance: expenses' => ['finance.expense.index', [], ['from', 'to']],
            'finance: income' => ['finance.income.index', [], ['from', 'to']],
            'finance: withdrawals (standing)' => ['finance.withdrawal.index', ['tab' => 'standing'], ['month']],
            'governance: audit' => ['governance.audit.index', [], ['user', 'action', 'module', 'from', 'to']],
            'governance: errors' => ['governance.error.index', [], ['only']],
            'governance: exports' => ['governance.export.index', [], ['user', 'route', 'from', 'to']],
            'governance: logins' => ['governance.login.index', [], ['user', 'only', 'from', 'to']],
            'hr: employees' => ['hr.employee.index', [], ['left']],
            'hr: leave' => ['hr.leave.index', [], ['pending']],
            'hr: salary heads' => ['hr.salary_head.index', [], ['inactive']],
            'inventory: counts' => ['inventory.count.index', [], ['from', 'to']],
            'inventory: products' => ['inventory.product.index', [], ['inactive']],
            'inventory: quality' => ['inventory.qc.index', [], ['from', 'to']],
            'inventory: serials' => ['inventory.serial.index', [], ['serial']],
            'inventory: stock' => ['inventory.stock.index', [], ['stock', 'warehouse_id']],
            'inventory: transfers' => ['inventory.transfer.index', [], ['from', 'to', 'cancelled']],
            'inventory: warehouses' => ['inventory.warehouse.index', [], ['inactive']],
            'master data: brands' => ['master_data.brand.index', [], ['inactive']],
            'master data: locations' => ['master_data.location.index', [], ['inactive']],
            'notification: archive' => ['notification.archive.index', [], ['module', 'priority', 'from', 'to']],
            'notification: audit' => ['notification.audit.index', [], ['action', 'outcome', 'from', 'to']],
            'notification: centre' => ['notification.center.index', [], ['module', 'category', 'priority', 'from', 'to']],
            'notification: delivery logs' => ['notification.deliveries.logs', [], ['channel', 'outcome', 'from', 'to']],
            'notification: delivery queue' => ['notification.deliveries.queue', [], ['channel']],
            'notification: escalations' => ['notification.escalations.index', [], ['state']],
            'notification: party messages' => ['notification.party_messages.index', [], ['from', 'to', 'document_type', 'sent_by']],
            'notification: rules' => ['notification.rules.index', [], ['module', 'state']],
            'notifications' => ['notifications.index', [], ['category', 'priority', 'module']],
            'promotions' => ['promotion.index', [], ['status', 'type']],
            'purchase: bills' => ['purchase.bill.index', [], ['from', 'to', 'cancelled']],
            'purchase: orders' => ['purchase.order.index', [], ['from', 'to', 'cancelled']],
            'purchase: payments' => ['purchase.payment.index', [], ['from', 'to', 'cancelled']],
            'purchase: receipts' => ['purchase.receipt.index', [], ['from', 'to', 'cancelled']],
            'purchase: requisitions' => ['purchase.requisition.index', [], ['from', 'to']],
            'purchase: returns' => ['purchase.return.index', [], ['from', 'to', 'cancelled']],
            'purchase: rfq' => ['purchase.rfq.index', [], ['from', 'to']],
            'restaurant: kitchen' => ['restaurant.kitchen.index', [], ['warehouse']],
            'restaurant: production' => ['restaurant.production.index', [], ['from', 'to']],
            'restaurant: recipes' => ['restaurant.recipe.index', [], ['inactive', 'kind']],
            'sales: collections' => ['sales.collection.index', [], ['from', 'to', 'cancelled']],
            'sales: leads' => ['sales.lead.index', [], ['status']],
            'sales: opportunities' => ['sales.opportunity.index', [], ['stage']],
            'sales: delivery performance' => ['sales.delivery_performance.index', [], ['from', 'to']],
            'sales: delivery orders' => ['sales.delivery_order.index', [], ['from', 'to']],
            'sales: gate passes' => ['sales.gate_pass.index', [], ['cancelled']],
            'sales: quotations' => ['sales.quotation.index', [], ['from', 'to']],
            'sales: returns' => ['sales.return.index', [], ['from', 'to', 'cancelled']],
            'sales: routes' => ['sales.route.index', [], ['month']],
            'sales: schemes' => ['sales.scheme.index', [], ['status']],
            'sales: shipments' => ['sales.shipment.index', [], ['from', 'to', 'cancelled']],
            'sales: tracking' => ['sales.tracking.index', [], ['from', 'to', 'customer', 'branch']],
            'sales: transport' => ['sales.transport.index', [], ['from', 'to', 'driver', 'customer']],
            'suppliers' => ['supplier.index', [], ['credit', 'inactive']],
            'branches' => ['system_admin.branch.index', [], ['company']],
        ];

        // ⓘ প্রতিটা পাতা তিন সাজে
        $out = [];
        foreach ($pages as $label => $page) {
            foreach (['rose', 'navy', 'blue'] as $look) {
                $out[$label.' · '.$look] = [...$page, $look];
            }
        }

        return $out;
    }

    /**
     * @dataProvider pages
     *
     * @param  array<string, mixed>  $params
     * @param  list<string>  $keys
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_the_page_has_the_right_panel_and_keeps_every_old_filter(string $route, array $params, array $keys, string $look): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['ui' => $look])->save();
        $this->actingAs($owner);

        $html = (string) $this->get(route($route, $params))->assertOk()->getContent();

        $this->assertStringContainsString('data-filter-layout', $html, "⛔ {$route} ({$look}): ডান পাশের ছাঁকনির প্যানেল নেই।");
        $this->assertStringContainsString('data-filter-toggle', $html, "⛔ {$route} ({$look}): বারে \"ছাঁকনি\" বোতাম নেই।");
        $this->assertStringNotContainsString('id="toolbar-filters"', $html, "⛔ {$route} ({$look}): পুরোনো ছাঁকনির সারি রয়ে গেছে — একই ঘর দুই জায়গায়।");

        $panel = $this->panelOf($html);
        foreach ($keys as $key) {
            // ⓘ `bar:` — ঘরটা প্যানেল থেকে বারে উঠেছে (এক সারির মাথা, ২৪ নভেম্বর ২০২৬): বারে ঠিক একবার, প্যানেলে আর নয়
            if (str_starts_with($key, 'bar:')) {
                $name = preg_quote(substr($key, 4), '/');
                $this->assertSame(1, preg_match_all('/<input type="search" name="'.$name.'"/', $html), "⛔ {$route} ({$look}): বারে খোঁজার ঘর ঠিক একটা নয়।");
                $this->assertDoesNotMatchRegularExpression('/\bname="'.$name.'"/', $panel, "⛔ {$route} ({$look}): একই ঘর দুই জায়গায়।");

                continue;
            }

            $this->assertMatchesRegularExpression('/\bname="'.preg_quote($key, '/').'(\[\])?"/', $panel,
                "⛔ {$route} ({$look}): পুরোনো ছাঁকনি '{$key}' প্যানেলে নেই — ঘরটা হারাল।");
        }
    }

    /** ⓘ রিপোর্টের প্যানেলের বোতাম "দেখান" — রিপোর্ট চলে কেবল ওটা বা Enter-এ (fe/search-এর নিয়ম) */
    public function test_a_report_panel_runs_on_show(): void
    {
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        foreach ([['accounts.report.show', ['slug' => 'ledger']], ['accounts.daybook', []]] as [$route, $params]) {
            $panel = $this->panelOf((string) $this->get(route($route, $params))->assertOk()->getContent());
            $this->assertStringContainsString(e(__('core.action.show')), $panel, "⛔ {$route}: প্যানেলের বোতাম \"দেখান\" নয়।");
            $this->assertStringContainsString('data-no-counts', $this->get(route($route, $params))->getContent(),
                "⛔ {$route}: প্যানেল টিকের সাথে সার্ভারে গোনার প্রশ্ন পাঠাবে — রিপোর্ট আবার চলবে।");
        }
    }

    /** ⓘ "বাতিলসহ" টিক পুরোনো একক চাবিতে — `cancelled=1`, অ্যারে নয়; তালিকা `boolean()` পড়ে */
    public function test_a_single_tick_keeps_its_flat_key(): void
    {
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $panel = $this->panelOf((string) $this->get(route('sales.collection.index', ['cancelled' => 1]))->assertOk()->getContent());

        $this->assertMatchesRegularExpression('/name="cancelled"\s+value="1"\s+checked/', $panel);
        $this->assertStringNotContainsString('name="cancelled[]"', $panel);
    }

    private function panelOf(string $html): string
    {
        $start = strpos($html, 'data-filter-panel');

        if ($start === false) {
            return '';
        }

        $end = strpos($html, '</aside>', $start);

        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }
}
