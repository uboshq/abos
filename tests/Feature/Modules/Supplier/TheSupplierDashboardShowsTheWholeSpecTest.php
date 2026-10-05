<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\DateRange;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Series;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\ApprovalFlow;
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
use App\Modules\Supplier\Dashboard\SupplierDashboard;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * সরবরাহকারীর ড্যাশবোর্ড — মালিকের পুরো নকশা (৬ অক্টোবর ২০২৬): এ মাসে কেনা, ছয় মাসের কেনা-ফেরতের ধারা,
 * এ মাসের শীর্ষ পাঁচ সরবরাহকারীর কাজের খাতা (দেরিতে মাল, ফেরত)।
 *
 * ⓘ দাবি, একই মালিক: সুইচ বন্ধে নতুন একটা ঘরও নেই, আর প্রথম চার্ট জায়গা বদলায় না; চালুতে আসল কাগজ (বিল, আসার
 * তারিখ পেরিয়ে আসা মাল, ফেরত) বসালে প্রতিটা সংখ্যা ঠিক ততটা নড়ে, আর "এ মাসে কেনা" ক্রয়ের ড্যাশবোর্ড ও বিলের
 * টেবিলের যোগফলের সাথে হুবহু মেলে।
 * ⓘ শাখা: হেডারে শাখা A বাছা থাকলে শাখা B-র বিলে কিছুই নড়ে না; "সব শাখা"-তে নড়ে (দাবিটা সত্যিই তাকায়)।
 */
final class TheSupplierDashboardShowsTheWholeSpecTest extends TestCase
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
        ApprovalFlow::query()->where('module', 'purchase')->delete();
    }

    public function test_switch_off_shows_nothing_new_and_the_first_chart_stays_first(): void
    {
        config(['abos.dashboards_v2' => false]);
        $off = SupplierDashboard::dashboard();
        $this->assertNull($this->stat($off), '⛔ সুইচ বন্ধেও "এ মাসে কেনা"।');
        $this->assertNull($this->trend($off), '⛔ সুইচ বন্ধেও কেনার ধারা।');
        $this->assertNull($this->performance($off), '⛔ সুইচ বন্ধেও সরবরাহকারীর কাজের খাতা।');

        config(['abos.dashboards_v2' => true]);
        $on = SupplierDashboard::dashboard();
        $this->assertNotNull($this->stat($on));
        $this->assertNotNull($this->performance($on));
        $trend = $this->trend($on);
        $this->assertNotNull($trend, 'চালুতে কেনার ধারা নেই।');
        $this->assertSame('area', $trend->chart);
        $this->assertCount(6, $trend->points, '⛔ ধারায় ছয় মাস নেই।');
        $this->assertSame(DateRange::label(Carbon::today()->startOfMonth()->subMonths(5), Carbon::today()), $trend->range, '⛔ ধারা বলে না কবে থেকে কবে।');
        $this->assertNotSame(__('supplier::dashboard.trend'), $on->panels[0]->label, '⛔ নতুন চার্টটা প্রথমে বসেছে — প্রথম চার্টের জায়গা বদলেছে।');
    }

    public function test_every_new_figure_moves_with_real_papers_and_matches_the_bills(): void
    {
        config(['abos.dashboards_v2' => true]);
        $this->pick('all');
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = $this->freshProduct('SDS');

        $before = $this->read();
        $this->assertSame(0, bccomp($before['bought'], $this->rawBought(), 2), '⛔ "এ মাসে কেনা" বিলের টেবিলের যোগফল নয়।');
        $this->assertSame($this->purchaseBoughtThisMonth(), Money::format($before['bought']), '⛔ সরবরাহকারী আর ক্রয়ের ড্যাশবোর্ড "এ মাসে কেনা" দুই কথা বলে।');

        // ── বড় একটা বিল — সরবরাহকারীটা এ মাসের শীর্ষ পাঁচে উঠবেই ──
        $bill = $this->bill($supplier, $product, '1000000', $warehouse);

        // ── ক্রয়াদেশে গতকাল আসার কথা, মাল এল আজ — দেরিতে মাল ──
        $orders = app(PurchaseOrderService::class);
        $order = $orders->confirm($orders->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'trx_date' => Carbon::yesterday()->toDateString(),
                'expected_on' => Carbon::yesterday()->toDateString()],
            [['product_id' => $product->id, 'ordered_qty' => '3', 'rate' => '40']],
        ))->load('lines');
        $receipts = app(PurchaseReceiptService::class);
        $receipts->confirm($receipts->create(
            ['purchase_order_id' => $order->id, 'warehouse_id' => $warehouse->id, 'trx_date' => Carbon::today()->toDateString()],
            [['product_id' => $product->id, 'purchase_order_line_id' => $order->lines->first()->id, 'received_qty' => '3', 'rate' => '40']],
        ));

        // ── একই দিনে আসা মাল দেরি নয়: আজ আসার কথা, আজই এল ──
        $onTime = $orders->confirm($orders->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'trx_date' => Carbon::today()->toDateString(),
                'expected_on' => Carbon::today()->toDateString()],
            [['product_id' => $product->id, 'ordered_qty' => '2', 'rate' => '40']],
        ))->load('lines');
        $receipts->confirm($receipts->create(
            ['purchase_order_id' => $onTime->id, 'warehouse_id' => $warehouse->id, 'trx_date' => Carbon::today()->toDateString()],
            [['product_id' => $product->id, 'purchase_order_line_id' => $onTime->lines->first()->id, 'received_qty' => '2', 'rate' => '40']],
        ));

        // ── বিলের একটা ফেরত ──
        $returns = app(PurchaseReturnService::class);
        $return = $returns->confirm($returns->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'trx_date' => Carbon::today()->toDateString()],
            [['product_id' => $product->id, 'purchase_bill_line_id' => $bill->lines->first()->id, 'qty' => '1']],
        ));

        $after = $this->read();

        $this->assertSame(0, bccomp(bcsub($after['bought'], $before['bought'], 2), (string) $bill->total, 2), '⛔ "এ মাসে কেনা" ঠিক বিলের টাকাটা নড়েনি।');
        $this->assertSame(0, bccomp($after['bought'], $this->rawBought(), 2), '⛔ কাগজের পরে "এ মাসে কেনা" বিলের টেবিলের সাথে মেলে না।');
        $this->assertSame($this->purchaseBoughtThisMonth(), Money::format($after['bought']), '⛔ কাগজের পরে দুই ড্যাশবোর্ড দুই কথা বলে।');

        $this->assertSame(0, bccomp(bcsub($after['trend_bought'], $before['trend_bought'], 2), (string) $bill->total, 2), '⛔ ধারার এ মাসের কেনা ঠিক বিলের টাকাটা নড়েনি।');
        $this->assertSame(0, bccomp(bcsub($after['trend_returned'], $before['trend_returned'], 2), (string) $return->total, 2), '⛔ ধারার এ মাসের ফেরত ঠিক ফেরতের টাকাটা নড়েনি।');

        $was = $before['rows'][$supplier->name()] ?? ['late' => '0', 'returned' => Money::format('0')];
        $now = $after['rows'][$supplier->name()] ?? null;
        $this->assertNotNull($now, '⛔ এ মাসের সবচেয়ে বড় বিলের সরবরাহকারী কাজের খাতায় নেই।');
        $this->assertSame((int) $was['late'] + 1, (int) $now['late'], '⛔ আসার তারিখ পেরিয়ে আসা মাল +১ নয় (একই দিনে আসাটাও গোনা হলে +২)।');
        $this->assertSame(0, bccomp(bcsub(str_replace(',', '', $now['returned']), str_replace(',', '', $was['returned']), 2), (string) $return->total, 2),
            '⛔ সরবরাহকারীর ফেরত ঠিক ফেরতের টাকাটা নড়েনি।');
        $this->assertSame(Money::format($this->rawBought($supplier->id)), $now['bought'], '⛔ সরবরাহকারীর কেনা তাঁর বিলের যোগফল নয়।');
    }

    public function test_a_bill_in_another_branch_moves_nothing_while_one_branch_is_picked(): void
    {
        config(['abos.dashboards_v2' => true]);
        $a = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $b = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $product = $this->freshProduct('SDB');

        $this->pick((string) $a->id);
        $beforeA = $this->read();
        $this->pick('all');
        $beforeAll = $this->read();

        // ── শাখা B-তে একটা বড় বিল আর তার ফেরত ──
        CompanyContext::set($this->company->id, $b->id);
        $warehouseB = Warehouse::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('branch_id', $b->id)->orderBy('id')->firstOrFail();
        $bill = $this->bill($supplier, $product, '1000000', $warehouseB, $b);
        $returns = app(PurchaseReturnService::class);
        $returns->confirm($returns->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouseB->id, 'branch_id' => $b->id, 'trx_date' => Carbon::today()->toDateString()],
            [['product_id' => $product->id, 'purchase_bill_line_id' => $bill->lines->first()->id, 'qty' => '1']],
        ));
        $this->assertSame($b->id, (int) $bill->branch_id, 'বিলটা শাখা B-তে বসেনি — দাবির ভিত নেই।');

        $this->pick((string) $a->id);
        $afterA = $this->read();
        foreach ($beforeA as $what => $was) {
            $this->assertSame($was, $afterA[$what], "⛔ শাখা A বাছা, অথচ শাখা B-র বিলে \"{$what}\" বদলেছে।");
        }

        $this->pick('all');
        $afterAll = $this->read();
        foreach (['bought', 'trend_bought', 'trend_returned', 'rows'] as $what) {
            $this->assertNotSame($beforeAll[$what], $afterAll[$what], "\"সব শাখা\"-তেও \"{$what}\" বদলায়নি — দাবিটা অন্ধ।");
        }
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function read(): array
    {
        app(DataScope::class)->forget();
        $d = SupplierDashboard::dashboard();
        $trend = $this->trend($d);
        $last = $trend->points[array_key_last($trend->points)];
        $listing = $this->performance($d);
        $render = fn ($row, string $key) => (collect($listing->columns)->firstWhere('key', $key)['render'])($row);

        return [
            'bought' => str_replace(',', '', (string) $this->stat($d)->value),
            'trend_bought' => $last['first'],
            'trend_returned' => $last['second'],
            'rows' => $listing->rows->mapWithKeys(fn ($r) => [$render($r, 'name') => [
                'bought' => $render($r, 'bought'),
                'late' => $render($r, 'late'),
                'returned' => $render($r, 'returned'),
            ]])->all(),
        ];
    }

    /** এ মাসের পাকা বিলের যোগফল, টেবিল থেকে — কোম্পানি, মোছা বাদ (মালিক "সব শাখা" দেখছেন) */
    private function rawBought(?int $supplierId = null): string
    {
        return (string) DB::table('pur_bills')->where('company_id', $this->company->id)
            ->whereIn('status', DocumentStatus::POSTED)->whereNull('deleted_at')
            ->where('trx_date', '>=', Carbon::today()->startOfMonth()->toDateString())
            ->when($supplierId !== null, fn ($q) => $q->where('supplier_id', $supplierId))
            ->sum('total');
    }

    private function purchaseBoughtThisMonth(): string
    {
        return collect(PurchaseDashboard::dashboard()->stats)
            ->first(fn (Stat $s) => $s->label === __('purchase::dashboard.bought_this_month'))->value;
    }

    private function bill(Supplier $supplier, Product $product, string $rate, Warehouse $warehouse, ?Branch $branch = null): PurchaseBill
    {
        $service = app(PurchaseBillService::class);

        return $service->confirm($service->create(
            array_filter(['supplier_id' => $supplier->id, 'trx_date' => Carbon::today()->toDateString(),
                'warehouse_id' => $warehouse->id, 'branch_id' => $branch?->id]),
            [['product_id' => $product->id, 'qty' => '10', 'rate' => $rate]],
        ))->load('lines');
    }

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

    private function stat(DashboardDefinition $d): ?Stat
    {
        return collect($d->stats)->first(fn (Stat $s) => $s->label === __('supplier::dashboard.bought_this_month'));
    }

    private function trend(DashboardDefinition $d): ?Series
    {
        return collect($d->panels)->first(fn ($p) => $p instanceof Series && $p->label === __('supplier::dashboard.trend'));
    }

    private function performance(DashboardDefinition $d): ?Listing
    {
        return collect($d->listings)->first(fn (Listing $l) => $l->label === __('supplier::dashboard.performance'));
    }
}
