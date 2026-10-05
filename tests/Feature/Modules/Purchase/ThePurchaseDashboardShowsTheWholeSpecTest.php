<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\DateRange;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Dashboard\PurchaseDashboard;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Purchase\Services\PurchaseReturnService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ক্রয়ের ড্যাশবোর্ড — মালিকের পুরো নকশা (৬ অক্টোবর ২০২৬): সইয়ের অপেক্ষায় বিল, এ মাসের ফেরত, ক্রয়ের ফানেল,
 * ক্রয়াদেশের অবস্থা, দামের ওঠানামা।
 *
 * ⓘ দাবি, একই মালিক: সুইচ বন্ধে নতুন একটা ঘরও নেই, আর প্রথম চার্ট (হোমে যায়) জায়গা বদলায় না; চালুতে আসল কাগজ
 * (ক্রয়াদেশ → মাল গ্রহণ → দুই বিল → ফেরত → সইয়ে আটকানো বিল) বসালে প্রতিটা সংখ্যা ঠিক ততটাই নড়ে, আর ফানেলের
 * প্রতিটা ধাপ টেবিলের কাঁচা গোনার সাথে হুবহু মেলে।
 * ⓘ শাখা: হেডারে শাখা A বাছা থাকলে শাখা B-র কাগজে কিছুই নড়ে না; "সব শাখা"-তে নড়ে (দাবিটা সত্যিই তাকায়)।
 */
final class ThePurchaseDashboardShowsTheWholeSpecTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        // ⓘ সইয়ের ছক এখানে কেবল যেখানে চাওয়া — বাকি কাগজ এক ধাপেই খাতায় বসুক
        ApprovalFlow::query()->where('module', 'purchase')->delete();
    }

    public function test_switch_off_shows_nothing_new_and_the_first_chart_stays_first(): void
    {
        config(['abos.dashboards_v2' => false]);
        $off = PurchaseDashboard::dashboard();

        foreach (['returns_this_month', 'awaiting_approval'] as $key) {
            $this->assertNull($this->stat($off, $key), "⛔ সুইচ বন্ধেও '{$key}'।");
        }
        foreach (['funnel', 'order_status'] as $key) {
            $this->assertNull($this->panel($off, $key), "⛔ সুইচ বন্ধেও '{$key}' চার্ট।");
        }
        foreach (['awaiting_approval', 'price_changes'] as $key) {
            $this->assertNull($this->listing($off, $key), "⛔ সুইচ বন্ধেও '{$key}' তালিকা।");
        }
        $this->assertSame(__('purchase::dashboard.bought_against_paid'), $off->panels[0]->label, '⛔ সুইচ বন্ধে প্রথম চার্ট বদলেছে।');

        config(['abos.dashboards_v2' => true]);
        $on = PurchaseDashboard::dashboard();
        $this->assertSame(__('purchase::dashboard.bought_against_paid_year', ['year' => Carbon::today()->year]), $on->panels[0]->label,
            '⛔ প্রথম চার্টটা আর প্রথম নয় — হোমে অন্য চার্ট যাবে।');
        $this->assertNotNull($this->stat($on, 'returns_this_month'));
        $this->assertNotNull($this->stat($on, 'awaiting_approval'));
        $this->assertNotNull($this->listing($on, 'awaiting_approval'));
        $this->assertNotNull($this->listing($on, 'price_changes'));

        $funnel = $this->panel($on, 'funnel');
        $this->assertNotNull($funnel, 'চালুতে ফানেল নেই।');
        $this->assertSame('funnel', $funnel->chart);
        $this->assertCount(7, $funnel->parts, '⛔ ফানেলে সাতটা ধাপ নেই।');
        $this->assertSame(DateRange::label(Carbon::today()->startOfMonth(), Carbon::today()), $funnel->range, '⛔ ফানেল বলে না কবে থেকে কবে।');
    }

    public function test_every_new_figure_moves_with_real_papers_and_matches_the_tables(): void
    {
        config(['abos.dashboards_v2' => true]);
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = $this->freshProduct('PDS');

        $this->pick('all');
        $before = $this->read();
        $returnsBefore = $this->rawReturns();
        $this->assertFunnelMatchesTheTables($before);

        // ── ক্রয়াদেশ (গতকাল আসার কথা) → তার মাল গ্রহণ ──
        $orders = app(PurchaseOrderService::class);
        $order = $orders->confirm($orders->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'trx_date' => Carbon::today()->toDateString(),
                'expected_on' => Carbon::yesterday()->toDateString()],
            [['product_id' => $product->id, 'ordered_qty' => '5', 'rate' => '40']],
        ))->load('lines');
        $receipts = app(PurchaseReceiptService::class);
        $receipts->confirm($receipts->create(
            ['purchase_order_id' => $order->id, 'warehouse_id' => $warehouse->id, 'trx_date' => Carbon::today()->toDateString()],
            [['product_id' => $product->id, 'purchase_order_line_id' => $order->lines->first()->id, 'received_qty' => '5', 'rate' => '40']],
        ));

        // ── দুইটা বিল, একই পণ্য: ৫০ টাকা, তারপর ৫০০ টাকা ──
        $first = $this->bill($supplier, $product, '50');
        $second = $this->bill($supplier, $product, '500');

        // ── দ্বিতীয় বিলের দুইটা ফেরত ──
        $returns = app(PurchaseReturnService::class);
        $return = $returns->confirm($returns->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'trx_date' => Carbon::today()->toDateString()],
            [['product_id' => $product->id, 'purchase_bill_line_id' => $second->lines->first()->id, 'qty' => '2']],
        ));
        $this->assertGreaterThan(0, bccomp((string) $return->total, '0', 4), 'প্রস্তুতিটাই ভুল — ফেরতের টাকা শূন্য।');

        // ── সইয়ে আটকানো একটা বিল ──
        $held = $this->heldBill($supplier, $product, null);

        $after = $this->read();
        $this->assertFunnelMatchesTheTables($after);

        $funnel = fn (string $step) => (int) $after['funnel'][$step] - (int) $before['funnel'][$step];
        $this->assertSame(1, $funnel('funnel_orders'), '⛔ নিশ্চিত ক্রয়াদেশ ফানেলে +১ নয়।');
        $this->assertSame(1, $funnel('funnel_receipts'), '⛔ নিশ্চিত মাল গ্রহণ ফানেলে +১ নয়।');
        $this->assertSame(2, $funnel('funnel_bills'), '⛔ দুইটা নিশ্চিত বিল ফানেলে +২ নয় (সইয়ে আটকানো খসড়াও গোনা হলে +৩)।');
        $this->assertSame(0, $funnel('funnel_payments'), '⛔ কোনো পরিশোধ হয়নি, অথচ পরিশোধের ধাপ নড়েছে।');

        $this->assertSame($before['status_total'] + 1, $after['status_total'], '⛔ ক্রয়াদেশের অবস্থার চার্টে নতুন আদেশটা গোনা হয়নি।');

        $this->assertSame(0, bccomp(bcsub($after['returns'], $before['returns'], 2), (string) $return->total, 2),
            '⛔ এ মাসের ফেরত ঠিক ফেরতের কাগজের টাকাটা নড়েনি।');
        $this->assertSame(__('purchase::dashboard.returns_hint', ['count' => $returnsBefore]), $before['returns_hint'], '⛔ ফেরতের সংখ্যা টেবিলের গোনার সাথে মেলে না।');
        $this->assertSame($returnsBefore + 1, $this->rawReturns(), 'প্রস্তুতিটাই ভুল — ফেরতের কাগজ একটা বাড়েনি।');
        $this->assertSame(__('purchase::dashboard.returns_hint', ['count' => $returnsBefore + 1]), $after['returns_hint'], '⛔ ফেরতের সংখ্যা +১ নয়।');
        $this->assertSame(0, bccomp($after['returns'], $this->rawReturnsTotal(), 2), '⛔ এ মাসের ফেরত টেবিলের যোগফলের সাথে মেলে না।');

        $this->assertSame($before['awaiting'] + 1, $after['awaiting'], '⛔ সইয়ে আটকানো বিল গোনা হয়নি।');
        $this->assertContains($held->document_no, $after['awaiting_rows'], '⛔ সইয়ের অপেক্ষার তালিকায় বিলটা নেই।');
        $this->assertNotContains($first->document_no, $after['awaiting_rows'], '⛔ নিশ্চিত বিলও সইয়ের অপেক্ষায় দেখাচ্ছে।');

        $row = collect($after['prices'])->firstWhere('product', $product->name());
        $this->assertNotNull($row, '⛔ দর ৫০ থেকে ৫০০ হলো, অথচ দামের ওঠানামায় পণ্যটা নেই।');
        $this->assertSame([\App\Core\Support\Money::format('50'), \App\Core\Support\Money::format('500'), '+'.\App\Core\Support\Money::format('900').'%'],
            [$row['last'], $row['current'], $row['change']], '⛔ আগের দর, এখনকার দর বা বদলের শতাংশ ভুল।');
    }

    public function test_a_paper_in_another_branch_moves_nothing_while_one_branch_is_picked(): void
    {
        config(['abos.dashboards_v2' => true]);
        $a = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $b = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $product = $this->freshProduct('PDB');

        $this->pick((string) $a->id);
        $beforeA = $this->read();
        $this->pick('all');
        $beforeAll = $this->read();

        // ── শাখা B-তে: একটা ক্রয়াদেশ, দুইটা বিল (দর বদলে), একটা ফেরত, সইয়ে আটকানো একটা বিল ──
        CompanyContext::set($this->company->id, $b->id);
        $warehouseB = Warehouse::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('branch_id', $b->id)->orderBy('id')->firstOrFail();
        $orders = app(PurchaseOrderService::class);
        $orders->confirm($orders->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouseB->id, 'branch_id' => $b->id, 'trx_date' => Carbon::today()->toDateString()],
            [['product_id' => $product->id, 'ordered_qty' => '5', 'rate' => '40']],
        ));
        $this->bill($supplier, $product, '50', $b, $warehouseB);
        $second = $this->bill($supplier, $product, '500', $b, $warehouseB);
        $returns = app(PurchaseReturnService::class);
        $returns->confirm($returns->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouseB->id, 'branch_id' => $b->id, 'trx_date' => Carbon::today()->toDateString()],
            [['product_id' => $product->id, 'purchase_bill_line_id' => $second->lines->first()->id, 'qty' => '2']],
        ));
        $held = $this->heldBill($supplier, $product, $b, $warehouseB);
        $this->assertSame($b->id, (int) $held->branch_id, 'বিলটা শাখা B-তে বসেনি — দাবির ভিত নেই।');

        // ── A বাছা: কিছুই নড়ে না ──
        $this->pick((string) $a->id);
        $afterA = $this->read();
        foreach ($beforeA as $what => $was) {
            $this->assertSame($was, $afterA[$what], "⛔ শাখা A বাছা, অথচ শাখা B-র কাগজে \"{$what}\" বদলেছে।");
        }

        // ── সব শাখা: নড়ে — দাবিটা সত্যিই তাকায় ──
        $this->pick('all');
        $afterAll = $this->read();
        foreach (['funnel', 'status_total', 'returns', 'awaiting', 'prices'] as $what) {
            $this->assertNotSame($beforeAll[$what], $afterAll[$what], "\"সব শাখা\"-তেও \"{$what}\" বদলায়নি — দাবিটা অন্ধ।");
        }
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /**
     * পর্দার নতুন সংখ্যাগুলো — তুলনার মতো করে।
     *
     * @return array<string, mixed>
     */
    private function read(): array
    {
        app(DataScope::class)->forget();
        $d = PurchaseDashboard::dashboard();

        $funnel = $this->panel($d, 'funnel');
        $status = $this->panel($d, 'order_status');
        $awaiting = $this->listing($d, 'awaiting_approval');
        $prices = $this->listing($d, 'price_changes');
        $render = fn (Listing $l, $row, string $key) => (collect($l->columns)->firstWhere('key', $key)['render'])($row);

        return [
            'funnel' => collect($funnel->parts)->mapWithKeys(fn (array $p, int $i) => [
                ['funnel_requisitions', 'funnel_rfqs', 'funnel_quotations', 'funnel_orders', 'funnel_receipts', 'funnel_bills', 'funnel_payments'][$i] => $p['value'],
            ])->all(),
            'status_total' => $status === null ? 0 : array_sum(array_map(fn (array $p) => (int) $p['value'], $status->parts)),
            'returns' => str_replace(',', '', (string) $this->stat($d, 'returns_this_month')->value),
            'returns_hint' => $this->stat($d, 'returns_this_month')->hint,
            'awaiting' => (int) $this->stat($d, 'awaiting_approval')->value,
            'awaiting_rows' => $awaiting->rows->map(fn ($r) => $render($awaiting, $r, 'no'))->all(),
            'prices' => $prices->rows->map(fn ($r) => [
                'product' => $render($prices, $r, 'product'),
                'last' => $render($prices, $r, 'last'),
                'current' => $render($prices, $r, 'current'),
                'change' => $render($prices, $r, 'change'),
            ])->all(),
        ];
    }

    /**
     * ⓘ ফানেলের প্রতিটা ধাপ = টেবিলের কাঁচা গোনা (এই কোম্পানি, পাকা, মোছা বাদ, এ মাস) — মালিক "সব শাখা" দেখছেন।
     *
     * @param  array<string, mixed>  $read
     */
    private function assertFunnelMatchesTheTables(array $read): void
    {
        $from = Carbon::today()->startOfMonth()->toDateString();
        $to = Carbon::today()->toDateString();

        foreach ([
            'funnel_requisitions' => ['pur_requisitions', 'trx_date'],
            'funnel_rfqs' => ['pur_rfqs', 'trx_date'],
            'funnel_quotations' => ['pur_quotations', 'quoted_on'],
            'funnel_orders' => ['pur_orders', 'trx_date'],
            'funnel_receipts' => ['pur_receipts', 'trx_date'],
            'funnel_bills' => ['pur_bills', 'trx_date'],
            'funnel_payments' => ['pur_payments', 'trx_date'],
        ] as $step => [$table, $date]) {
            $raw = DB::table($table)->where('company_id', $this->company->id)
                ->whereIn('status', DocumentStatus::POSTED)->whereNull('deleted_at')
                ->whereBetween($date, [$from, $to])->count();
            $this->assertSame((string) $raw, $read['funnel'][$step], "⛔ ফানেলের '{$step}' ধাপ {$table} টেবিলের গোনার সাথে মেলে না।");
        }
    }

    /** এ মাসের পাকা ফেরতের কাগজ, টেবিল থেকে — কোম্পানি, মোছা বাদ */
    private function rawReturns(): int
    {
        return $this->returnsTable()->count();
    }

    private function rawReturnsTotal(): string
    {
        return (string) $this->returnsTable()->sum('total');
    }

    private function returnsTable(): \Illuminate\Database\Query\Builder
    {
        return DB::table('pur_returns')->where('company_id', $this->company->id)
            ->whereIn('status', DocumentStatus::POSTED)->whereNull('deleted_at')
            ->whereBetween('trx_date', [Carbon::today()->startOfMonth()->toDateString(), Carbon::today()->toDateString()]);
    }

    private function bill(Supplier $supplier, Product $product, string $rate, ?Branch $branch = null, ?Warehouse $warehouse = null): PurchaseBill
    {
        $service = app(PurchaseBillService::class);

        return $service->confirm($service->create(
            array_filter(['supplier_id' => $supplier->id, 'trx_date' => Carbon::today()->toDateString(),
                'branch_id' => $branch?->id, 'warehouse_id' => $warehouse?->id]),
            [['product_id' => $product->id, 'qty' => '10', 'rate' => $rate]],
        ))->load('lines');
    }

    /** সইয়ের ছক বসিয়ে একটা বিল — নিশ্চিত করতে গেলে আটকায়, খসড়া আর অনুরোধ থেকে যায়; তারপর ছকটা আবার সরানো */
    private function heldBill(Supplier $supplier, Product $product, ?Branch $branch, ?Warehouse $warehouse = null): PurchaseBill
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'module' => 'purchase', 'action' => 'bill', 'document_type' => '',
            'threshold_amount' => null, 'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->owner->id]);
        // ⓘ ইঞ্জিন এক অনুরোধের জন্য ছকগুলো মনে রাখে (scoped) — পরীক্ষায় অনুরোধ একটাই, তাই নতুন ছক দেখাতে ভুলিয়ে দেওয়া
        app()->forgetInstance(\App\Core\Engines\Approval\ApprovalEngine::class);

        $service = app(PurchaseBillService::class);
        $bill = $service->create(
            array_filter(['supplier_id' => $supplier->id, 'trx_date' => Carbon::today()->toDateString(),
                'branch_id' => $branch?->id, 'warehouse_id' => $warehouse?->id]),
            [['product_id' => $product->id, 'qty' => '1', 'rate' => '70']],
        );

        try {
            $service->confirm($bill);
            $this->fail('প্রস্তুতিটাই ভুল — সইয়ের ছক বসানো, তবু বিল আটকায়নি।');
        } catch (ValidationException) {
            // ⓘ আটকানোটাই চাওয়া
        }

        ApprovalFlowStep::query()->where('approval_flow_id', $flow->id)->delete();
        $flow->delete();
        app()->forgetInstance(\App\Core\Engines\Approval\ApprovalEngine::class);

        $this->assertDatabaseHas('approvals', ['approvable_id' => $bill->id, 'module' => 'purchase', 'action' => 'bill', 'status' => 'pending']);

        return $bill->fresh();
    }

    /** নতুন একটা পণ্য — কোনো আগের বিলের দর যেন দাবিতে না ঢোকে */
    private function freshProduct(string $prefix): Product
    {
        $template = Product::query()->where('is_active', true)->where('track_batch', false)->orderBy('id')->firstOrFail();
        $product = $template->replicate(['public_id']);
        $product->forceFill(['code' => $prefix.'-'.random_int(1000, 9999), 'name_en' => $prefix.' spec item', 'name_bn' => $prefix.' নকশার পণ্য', 'barcode' => null])->save();

        return $product;
    }

    private function pick(string $branch): void
    {
        $this->actingAs($this->owner)->post(route('branch.switch'), ['branch_id' => $branch])->assertRedirect();
        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->actingAs($this->owner);
    }

    private function stat(DashboardDefinition $d, string $key): ?Stat
    {
        return collect($d->stats)->first(fn (Stat $s) => $s->label === __('purchase::dashboard.'.$key));
    }

    private function panel(DashboardDefinition $d, string $key): ?Breakdown
    {
        $label = $key === 'order_status' ? __('purchase::dashboard.order_status', ['year' => Carbon::today()->year]) : __('purchase::dashboard.'.$key);

        return collect($d->panels)->first(fn ($p) => $p instanceof Breakdown && $p->label === $label);
    }

    private function listing(DashboardDefinition $d, string $key): ?Listing
    {
        return collect($d->listings)->first(fn (Listing $l) => $l->label === __('purchase::dashboard.'.$key));
    }
}
