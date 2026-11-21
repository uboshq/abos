<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseCostReport;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ক্রয়ের খরচ — ক্রয় ইনভয়েস/GRN অনুযায়ী, আর বিলের নতুন ঘর "অন্যান্য খরচ" (মালিকের অনুমোদিত নমুনা v6, ট্যাব গ; ২১ নভেম্বর ২০২৬)।
 *
 * ── এই ফাইল যা পাহারা দেয় ────────────────────────────────────────────────
 *   ১. "অন্যান্য খরচ" ঐচ্ছিক, ডিফল্ট ০; ভাড়ার মতোই মালের দামে ঢোকে; বিলের পাতা আর ছাপায় দেখায়
 *   ২. রিপোর্টের নিজের চাবি
 *   ৩. সারির অঙ্ক: কার্টন, মূল্য, লেবার, ভাড়া, অন্যান্য, মোট, খরচ %, প্রতি কার্টন
 *   ৪. সরবরাহকারী নামালে লেবার ০; খসড়া গোনা হয় না; GRN থেকে আসা বিল একবারই (GRN-এর সারিতে)
 *   ৫. পণ্য ধরে ভাগ — যোগফল মোটের সমান; সীমা সেটিংস থেকে (ডিফল্ট ৩); Excel-এ সংখ্যা
 *
 * ⓘ ডেমোর কাগজ একই দিনে থাকতে পারে — তাই অঙ্ক নিজের কাগজের সারি ধরে, বা আগে-পরের ফারাকে মাপা।
 */
final class APurchaseCostReportShowsWhatBringingTheGoodsInCostTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $cheap;

    private Product $dear;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app()->setLocale('bn');

        app(StandardChart::class)->install();
        app(SettingsService::class)->set('purchase.receipt_needs_order', false);
        app(SettingsService::class)->set('purchase.over_receipt_percent', 0);

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        [$this->cheap, $this->dear] = Product::query()
            ->whereNull('tax_id')->where('track_batch', false)->where('track_serial', false)->where('qc_required', false)
            ->orderBy('id')->take(2)->get()->all();
    }

    // ── ১ · অন্যান্য খরচ ───────────────────────────────────────────────────

    public function test_other_cost_defaults_to_zero_and_goes_into_the_cost_of_the_goods_like_the_fare(): void
    {
        $plain = $this->directBill('0', null);
        $this->assertSame(0, bccomp((string) $plain->other_cost, '0', 4), '⛔ না দিলে অন্যান্য খরচ ০ হওয়ার কথা।');

        $before = $this->valueOnHand($this->cheap);
        $bill = $this->directBill('0', '500');

        $this->assertSame(0, bccomp((string) $bill->other_cost, '500', 4), '⛔ অন্যান্য খরচ বিলে বসেনি।');
        // ⓘ সস্তা সারি ১০০০ / মোট ৫০০০ — ৫০০-র এক-পঞ্চমাংশ, ১০০
        $this->assertSame(0, bccomp(bcsub($this->valueOnHand($this->cheap), $before, 4), '1100', 4),
            '⛔ অন্যান্য খরচ ভাড়ার মতো মূল্যের অনুপাতে মালের দামে ঢোকেনি।');
    }

    public function test_the_bill_page_and_its_print_show_the_other_cost(): void
    {
        $bill = $this->directBill('0', '750');

        $this->get(route('purchase.bill.show', $bill))->assertOk()
            ->assertSee(__('purchase::field.other_cost'))->assertSee('750');

        $this->get(route('purchase.print.bill', $bill))->assertOk()
            ->assertSee(__('purchase::bill_paper.other_cost'));
    }

    // ── ২ · চাবি ─────────────────────────────────────────────────────────────

    public function test_the_report_needs_its_own_key(): void
    {
        $clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);

        $this->actingAs($clerk);
        $this->get(route('purchase.cost_report.index'))->assertForbidden();

        CompanyContext::forCompany($this->company->id,
            fn () => $clerk->givePermissionTo(Permission::findOrCreate('purchase.cost.report', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($clerk->fresh());
        $this->get(route('purchase.cost_report.index'))->assertOk();
    }

    // ── ৩ ও ৪ · সারির অঙ্ক ──────────────────────────────────────────────────

    public function test_a_direct_purchase_row_carries_every_figure(): void
    {
        $bill = $this->directBill('300', '200');
        $this->unloading($bill, 'cash', '100', '20');

        $row = $this->rowOf('bill', $bill->id);

        $this->assertSame(0, bccomp($row['cartons'], '20', 4));
        $this->assertSame(0, bccomp($row['value'], '5000', 4));
        $this->assertSame(0, bccomp($row['labour'], '100', 4));
        $this->assertSame(0, bccomp($row['fare'], '300', 4));
        $this->assertSame(0, bccomp($row['other'], '200', 4));
        $this->assertSame(0, bccomp($row['total'], '600', 4));
        $this->assertSame(0, bccomp($row['pct'], '12', 4), '⛔ খরচ % = মোট ÷ মূল্য।');
        $this->assertSame(0, bccomp($row['per_carton'], '30', 4), '⛔ প্রতি কার্টন = মোট ÷ কার্টন।');
        $this->assertTrue($row['over'], '⛔ ১২% সীমা ৩%-এর উপরে — দাগ খাওয়ার কথা।');
    }

    public function test_unloading_the_supplier_paid_for_costs_us_nothing(): void
    {
        $bill = $this->directBill('0', null);
        $this->unloading($bill, 'supplier', '400', '20');

        $this->assertSame(0, bccomp($this->rowOf('bill', $bill->id)['labour'], '0', 4),
            '⛔ সরবরাহকারী মাল নামালে লেবার আমাদের খরচ নয়।');
    }

    public function test_a_draft_bill_is_left_out(): void
    {
        $draft = app(PurchaseBillService::class)->create($this->header('300', '0'), $this->lines());

        $this->assertNull($this->rowOrNull('bill', $draft->id), '⛔ খসড়া বিল রিপোর্টে গোনা হলো।');
    }

    public function test_a_bill_made_from_a_receipt_is_counted_once_on_the_receipt_row(): void
    {
        $before = $this->report()['totals'];

        $service = app(PurchaseOrderService::class);
        $order = $service->confirm($service->create($this->header('0', null), [
            ['product_id' => $this->cheap->id, 'ordered_qty' => '10', 'rate' => '100'],
        ]))->load('lines');

        $receipt = app(PurchaseReceiptService::class)->create([
            'purchase_order_id' => $order->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->cheap->id, 'purchase_order_line_id' => $order->lines->first()->id,
            'received_qty' => '10', 'rate' => '100',
        ]]);

        $bill = app(PurchaseReceiptService::class)->confirmAndBill($receipt->fresh())['bill'];
        $this->assertNotNull($bill, 'setup: GRN থেকে বিল হওয়ার কথা');

        // ⓘ ভাড়া বিলে — রিপোর্ট সেটা GRN-এর সারিতে আনে
        DB::table('pur_bills')->where('id', $bill->id)->update(['transport_cost' => '90', 'other_cost' => '10']);

        $this->assertNull($this->rowOrNull('bill', $bill->id), '⛔ GRN থেকে আসা বিল আলাদা সারি হলো — একই ক্রয় দুইবার।');

        $row = $this->rowOf('grn', $receipt->id);
        $this->assertSame(0, bccomp($row['value'], '1000', 4));
        $this->assertSame(0, bccomp($row['fare'], '90', 4), '⛔ বিলের ভাড়া GRN-এর সারিতে আসেনি।');
        $this->assertSame(0, bccomp($row['other'], '10', 4));

        $after = $this->report()['totals'];
        $this->assertSame(0, bccomp(bcsub($after['value'], $before['value'], 4), '1000', 4),
            '⛔ মোট ক্রয় মূল্য ১০০০ বাড়ার কথা — বেশি বাড়লে বিলটা দুইবার গোনা হয়েছে।');
    }

    // ── ৫ · ভাগ, সীমা, ফাইল ─────────────────────────────────────────────────

    public function test_product_grouping_adds_up_to_the_same_totals(): void
    {
        $bill = $this->directBill('301', '7');
        $this->unloading($bill, 'cash', '101', '20');

        $result = $this->report('product');
        $sum = ['labour' => '0', 'fare' => '0', 'other' => '0', 'value' => '0', 'cartons' => '0'];

        foreach ($result['rows'] as $row) {
            foreach (array_keys($sum) as $k) {
                $sum[$k] = bcadd($sum[$k], (string) $row[$k], 4);
            }
        }

        foreach (array_keys($sum) as $k) {
            $this->assertSame(0, bccomp($sum[$k], (string) $result['totals'][$k], 4), "⛔ পণ্যের ভাগে {$k}-এর যোগ মোটের সমান নয়।");
        }
    }

    public function test_fare_splits_by_value_in_the_product_grouping(): void
    {
        $parts = PurchaseCostReport::spread('300', [1 => '1000', 2 => '4000']);

        $this->assertSame(['1' => '60.0000', '2' => '240.0000'], array_map('strval', $parts));
        $this->assertSame(0, bccomp(bcadd($parts[1], $parts[2], 4), '300', 4));
    }

    public function test_the_threshold_comes_from_the_setting_and_defaults_to_three(): void
    {
        $report = app(PurchaseCostReport::class);

        $this->assertSame(0, bccomp($report->defaultThreshold(), '3', 4), '⛔ সীমার ডিফল্ট ৩% হওয়ার কথা।');

        app(SettingsService::class)->set(PurchaseCostReport::SETTING, 5);
        $this->assertSame(0, bccomp($report->defaultThreshold(), '5', 4), '⛔ সীমা সেটিংস থেকে আসেনি।');
    }

    public function test_the_excel_file_carries_money_as_numbers(): void
    {
        $bill = $this->directBill('300', '200');
        $this->unloading($bill, 'cash', '100', '20');

        $response = $this->get(route('purchase.cost_report.index', [
            'from' => now()->toDateString(), 'to' => now()->toDateString(), 'supplier_id' => $this->supplier->id, 'export' => 'xlsx',
        ]));
        $response->assertOk();
        $bytes = $response->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse
            ? $response->streamedContent() : (string) $response->getContent();

        $path = tempnam(sys_get_temp_dir(), 'pc_xlsx_');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, '⛔ Excel ফাইল খোলে না।');
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        $this->assertStringContainsString('<v>5000</v>', $sheet, '⛔ ক্রয় মূল্য Excel-এ সংখ্যা নয়।');
        $this->assertStringContainsString('<v>600</v>', $sheet, '⛔ মোট খরচ Excel-এ সংখ্যা নয়।');
    }

    // ── যন্ত্রপাতি ──

    private function directBill(string $fare, ?string $other): PurchaseBill
    {
        $bill = app(PurchaseBillService::class)->create($this->header($fare, $other), $this->lines());

        return app(PurchaseBillService::class)->confirm($bill)->fresh();
    }

    /** @return array<string, mixed> */
    private function header(string $fare, ?string $other): array
    {
        return array_filter([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'transport_cost' => $fare,
            'other_cost' => $other,
        ], fn ($v) => $v !== null);
    }

    /** @return list<array<string, string|int>> */
    private function lines(): array
    {
        return [
            ['product_id' => $this->cheap->id, 'qty' => '10', 'rate' => '100', 'tax' => '0'],
            ['product_id' => $this->dear->id, 'qty' => '10', 'rate' => '400', 'tax' => '0'],
        ];
    }

    /** ⓘ লেবারের ঘর সরাসরি — রিপোর্ট কাগজের ঘরই পড়ে; মাল নামানোর নিজের পথ তার নিজের পরীক্ষায় */
    private function unloading(PurchaseBill $bill, string $mode, string $amount, string $cartons): void
    {
        DB::table('pur_bills')->where('id', $bill->id)
            ->update(['unload_mode' => $mode, 'unload_amount' => $amount, 'unload_cartons' => $cartons]);
    }

    /** @return array<string, mixed> */
    private function report(string $by = 'invoice'): array
    {
        return app(PurchaseCostReport::class)->run([
            'from' => now()->toDateString(), 'to' => now()->toDateString(),
            'threshold' => '3.0000', 'by' => $by,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function rowOrNull(string $doc, int $id): ?array
    {
        foreach ($this->report()['rows'] as $row) {
            if ($row['doc'] === $doc && $row['doc_id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function rowOf(string $doc, int $id): array
    {
        $row = $this->rowOrNull($doc, $id);
        $this->assertNotNull($row, "⛔ {$doc} #{$id}-এর সারি রিপোর্টে নেই।");

        return $row;
    }

    private function valueOnHand(Product $product): string
    {
        return app(CostLayerService::class)->valueOnHand($product->fresh());
    }
}
