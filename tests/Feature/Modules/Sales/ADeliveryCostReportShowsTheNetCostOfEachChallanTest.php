<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Sales\Models\ShipmentLine;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryCostReport;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ডেলিভারি খরচের রিপোর্ট — প্রতিটা চালানের নিট খরচ (মালিক, ২১ নভেম্বর ২০২৬; নমুনা v6 খ; [[DeliveryCostReport]])।
 *
 * দাবি:
 *  - পাতাটা নিজের চাবি চায় — চাবি ছাড়া ৪০৩, একই মানুষ চাবি পেলে ২০০।
 *  - ট্রিপের ভাড়া চালানগুলোয় মূল্যের অনুপাতে, দুই দশমিকে, আর ভাগের যোগ ঠিক ভাড়ার সমান।
 *  - ক্রেতা ভাড়া দিলে আমাদের ভাড়া ০; বিলে যোগ করা ভাড়া বাদ — নিট (০-এর নিচে নয়)।
 *  - খসড়া আর বাতিল চালান রিপোর্টে নেই; মোটের % মোট থেকে, গড় থেকে নয়।
 *  - গাড়ি, পয়েন্ট, মাস ধরে ভাগ; সীমা সেটিং থেকে (ডিফল্ট ৩), উপরে হলে চিহ্ন।
 *  - Excel-এ টাকা সংখ্যা হয়ে নামে।
 */
final class ADeliveryCostReportShowsTheNetCostOfEachChallanTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private User $owner;

    private int $serial = 0;

    /** ⓘ পরীক্ষার সব চালান এই দিনে — ডেমোর চালান রিপোর্টে মেশে না */
    private const DAY = '2031-03-10';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app()->setLocale('bn');

        $this->warehouse = Warehouse::query()->where('is_default', true)->first() ?? Warehouse::query()->firstOrFail();
    }

    public function test_the_page_needs_its_own_key(): void
    {
        $clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);

        $this->actingAs($clerk);
        $this->get(route('sales.delivery_cost.index', $this->day()))->assertForbidden();

        CompanyContext::forCompany($this->company->id,
            fn () => $clerk->givePermissionTo(Permission::findOrCreate('sales.delivery_cost.report', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($clerk->fresh());
        $this->get(route('sales.delivery_cost.index', $this->day()))->assertOk();
    }

    public function test_a_trip_fare_is_split_by_value_and_the_parts_add_up_to_the_fare_exactly(): void
    {
        $shop = $this->shop('DC-1', null);
        $a = $this->challan($shop, '100000');
        $b = $this->challan($shop, '200000');
        $c = $this->challan($shop, '300000');
        $this->trip([$a, $b, $c], '1000');

        $rows = $this->rowsById();

        // 1000 × 1/6 = 166.666… → 166.67; × 2/6 = 333.33; শেষটা বাকি = 500.00
        $this->assertSame('166.67', $rows[$a->id]['fare']);
        $this->assertSame('333.33', $rows[$b->id]['fare']);
        $this->assertSame('500.00', $rows[$c->id]['fare']);
        $this->assertSame('1000.00', bcadd(bcadd($rows[$a->id]['fare'], $rows[$b->id]['fare'], 2), $rows[$c->id]['fare'], 2),
            '⛔ ট্রিপের ভাড়ার ভাগগুলোর যোগ ভাড়ার সমান নয় — পয়সা হারাল বা বাড়ল।');
    }

    public function test_the_split_gives_leftover_paisa_by_largest_remainder(): void
    {
        $parts = DeliveryCostReport::split('100', [1 => '1', 2 => '1', 3 => '1']);

        // ⓘ সমান ভগ্নাংশে বাকি পয়সাটা আগের ঘরে
        $this->assertSame([1 => '33.34', 2 => '33.33', 3 => '33.33'], $parts);
        $this->assertSame(['7' => '10.00'], array_map('strval', DeliveryCostReport::split('10', ['7' => '0'])), '⛔ শূন্য ওজনে সমান ভাগ হওয়ার কথা।');
        $this->assertSame([5 => '5.00', 6 => '5.00'], DeliveryCostReport::split('10', [5 => '0', 6 => '0']));
    }

    /** ⛔ স্বাধীন রিভিউ, ২১ নভেম্বর ২০২৬: আগে বাকিটা শেষ ঘরে যেত — শূন্য-মূল্যের শেষ চালান −০.০১ পেত */
    public function test_no_part_is_ever_negative_and_the_parts_sum_exactly(): void
    {
        $parts = DeliveryCostReport::split('1000.01', [1 => '5000', 2 => '5000', 3 => '0']);

        $this->assertSame([1 => '500.01', 2 => '500.00', 3 => '0.00'], $parts);

        foreach ($parts as $part) {
            $this->assertGreaterThanOrEqual(0, bccomp($part, '0', 2), '⛔ ভাগ ঋণাত্মক — '.$part);
        }

        $this->assertSame(0, bccomp(array_reduce($parts, fn (string $c, string $p) => bcadd($c, $p, 2), '0'), '1000.01', 2),
            '⛔ ভাগগুলোর যোগ অঙ্কের সমান নয়।');
    }

    public function test_a_fare_the_customer_pays_costs_us_nothing(): void
    {
        $shop = $this->shop('DC-2', null);
        $ours = $this->challan($shop, '50000', ['transport_cost' => '1500', 'fare_paid_by' => 'us']);
        $theirs = $this->challan($shop, '50000', ['transport_cost' => '1500', 'fare_paid_by' => 'customer']);
        $none = $this->challan($shop, '50000', ['transport_cost' => '1500', 'fare_paid_by' => 'none']);

        $rows = $this->rowsById();

        $this->assertSame('1500.00', $rows[$ours->id]['fare']);
        $this->assertSame('0.00', $rows[$theirs->id]['fare'], '⛔ ক্রেতা ভাড়া দিলেন, অথচ রিপোর্টে আমাদের খরচ।');
        $this->assertSame('0.00', $rows[$none->id]['fare']);
    }

    public function test_freight_billed_to_the_customer_is_netted_off_and_never_goes_below_zero(): void
    {
        $shop = $this->shop('DC-3', null);
        $part = $this->challan($shop, '40000', ['transport_cost' => '2000', 'fare_paid_by' => 'us_add_to_bill']);
        $whole = $this->challan($shop, '40000', ['transport_cost' => '1000', 'fare_paid_by' => 'us_add_to_bill']);
        $this->invoice($part, '800');
        $this->invoice($whole, '1500');
        // ⓘ খসড়া বিলের ভাড়া বাদ যায় না — কেবল পাকা বিল
        $draft = $this->challan($shop, '40000', ['transport_cost' => '700', 'fare_paid_by' => 'us_add_to_bill']);
        $this->invoice($draft, '700', DocumentStatus::DRAFT);

        $rows = $this->rowsById();

        $this->assertSame('1200.00', $rows[$part->id]['fare'], '⛔ বিলে যোগ করা ভাড়া বাদ যায়নি — নিট খরচ নয়।');
        $this->assertSame('0.00', $rows[$whole->id]['fare'], '⛔ বিলে বেশি আদায় হলে খরচ ঋণাত্মক হলো।');
        $this->assertSame('700.00', $rows[$draft->id]['fare']);
    }

    public function test_draft_and_cancelled_challans_are_left_out_and_the_total_percent_comes_from_the_totals(): void
    {
        $shop = $this->shop('DC-4', null);
        $a = $this->challan($shop, '100000', ['transport_cost' => '1000', 'fare_paid_by' => 'us']);
        $b = $this->challan($shop, '10000', ['transport_cost' => '1000', 'fare_paid_by' => 'us', 'expense_amount' => '100']);
        $draft = $this->challan($shop, '10000', ['transport_cost' => '900', 'fare_paid_by' => 'us'], DocumentStatus::DRAFT);
        $cancelled = $this->challan($shop, '10000', ['transport_cost' => '900', 'fare_paid_by' => 'us'], DocumentStatus::CANCELLED);

        $report = app(DeliveryCostReport::class);
        $rows = $report->rows($this->challans(), '3.00');
        $ids = array_column($rows, 'id');

        $this->assertNotContains($draft->id, $ids, '⛔ খসড়া চালান খরচের রিপোর্টে।');
        $this->assertNotContains($cancelled->id, $ids, '⛔ বাতিল চালান খরচের রিপোর্টে।');

        $totals = $report->totals($rows, '3.00');
        $this->assertSame('110000.00', $totals['value']);
        $this->assertSame('2100.00', $totals['total']);
        // মোট থেকে: 2100 ÷ 110000 = 1.91% — সারির গড় (1% আর 21%) হলে 11% হত
        $this->assertSame('1.91', $totals['percent'], '⛔ মোটের % সারির গড় থেকে।');

        $html = $this->get(route('sales.delivery_cost.index', $this->day()))->assertOk()->getContent();
        $this->assertStringContainsString($a->document_no, $html);
        $this->assertStringNotContainsString($draft->document_no, $html);
        $this->assertStringContainsString(e(__('sales::delivery_cost.total_row', ['n' => 2])), $html);
        $this->assertStringContainsString('1.91%', $html);
    }

    public function test_the_report_groups_by_vehicle_point_and_month(): void
    {
        $mirpur = $this->place('DP-MR', 'Mirpur', 'মিরপুর');
        $uttara = $this->place('DP-UT', 'Uttara', 'উত্তরা');
        $one = $this->shop('DC-5', $mirpur);
        $two = $this->shop('DC-6', $uttara);
        $this->challan($one, '100000', ['transport_cost' => '2000', 'fare_paid_by' => 'us', 'vehicle_no' => 'ট 11-2345']);
        $this->challan($two, '100000', ['transport_cost' => '4000', 'fare_paid_by' => 'us', 'vehicle_no' => 'ট 11-2345']);
        $this->challan($two, '50000', ['transport_cost' => '500', 'fare_paid_by' => 'us', 'vehicle_no' => 'ন 20-1122']);

        $report = app(DeliveryCostReport::class);
        $rows = $report->rows($this->challans(), '3.00');

        $byVehicle = collect($report->group($rows, 'vehicle', '3.00'))->keyBy('label');
        $this->assertSame(2, $byVehicle['ট 11-2345']['count']);
        $this->assertSame('6000.00', $byVehicle['ট 11-2345']['total']);
        $this->assertSame('3.00', $byVehicle['ট 11-2345']['percent']);
        $this->assertFalse($byVehicle['ট 11-2345']['over'], '⛔ ঠিক সীমায় থাকা "উপরে" ধরা হলো।');

        $byPoint = collect($report->group($rows, 'point', '3.00'))->keyBy('label');
        $this->assertTrue($byPoint->has('মিরপুর') && $byPoint->has('উত্তরা'), '⛔ পয়েন্ট ধরে ভাগে গ্রাহকের পয়েন্ট নেই।');
        $this->assertSame('4500.00', $byPoint['উত্তরা']['total']);
        $this->assertSame('3.00', $byPoint['উত্তরা']['percent']);
        $this->assertSame('2.00', $byPoint['মিরপুর']['percent']);

        $byMonth = $report->group($rows, 'month', '3.00');
        $this->assertCount(1, $byMonth);
        $this->assertSame(3, $byMonth[0]['count']);
        $this->assertSame('250000.00', $byMonth[0]['value']);

        foreach (['vehicle', 'point', 'month'] as $by) {
            $this->get(route('sales.delivery_cost.index', $this->day(['by' => $by])))->assertOk()
                ->assertSee(__('sales::delivery_cost.by_'.$by));
        }
    }

    public function test_the_threshold_comes_from_the_setting_and_rows_above_it_are_marked(): void
    {
        $shop = $this->shop('DC-7', null);
        $high = $this->challan($shop, '100000', ['transport_cost' => '3500', 'fare_paid_by' => 'us']);
        $low = $this->challan($shop, '100000', ['transport_cost' => '2000', 'fare_paid_by' => 'us']);

        $this->assertEquals(3, app(SettingsService::class)->get(DeliveryCostReport::THRESHOLD_KEY), '⛔ সীমার ডিফল্ট ৩ নয়।');

        $html = $this->get(route('sales.delivery_cost.index', $this->day()))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-cost-over'), '⛔ সীমার উপরের সারিতে চিহ্ন নেই, বা নিচেরটায়ও বসল।');
        $this->assertStringContainsString(e(__('sales::delivery_cost.chart_legend', ['n' => '3'])), $html);

        app(SettingsService::class)->set(DeliveryCostReport::THRESHOLD_KEY, 4);
        $rows = collect(app(DeliveryCostReport::class)->rows($this->challans(), '4.00'))->keyBy('id');
        $this->assertFalse($rows[$high->id]['over']);
        $html = $this->get(route('sales.delivery_cost.index', $this->day()))->assertOk()->getContent();
        $this->assertSame(0, substr_count($html, 'data-cost-over'), '⛔ সেটিং বদলালেও সীমা আগের মতো।');

        // ⓘ ছাঁকনির ঘরে দিলে সেটাই
        $html = $this->get(route('sales.delivery_cost.index', $this->day(['threshold' => '1'])))->assertOk()->getContent();
        // ⓘ দুই সারি আর মোটের সারি (2.75%) — তিনটাই ১%-এর উপরে
        $this->assertSame(3, substr_count($html, 'data-cost-over'));
        $this->assertNotNull($low);
    }

    public function test_the_excel_file_carries_money_as_numbers(): void
    {
        $shop = $this->shop('DC-8', null);
        $this->challan($shop, '385000', ['transport_cost' => '6500', 'fare_paid_by' => 'us', 'expense_amount' => '300']);

        $response = $this->get(route('sales.delivery_cost.index', $this->day(['export' => 'xlsx'])));
        $response->assertOk();
        $bytes = $response->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse
            ? $response->streamedContent() : (string) $response->getContent();

        $path = tempnam(sys_get_temp_dir(), 'dc_xlsx_');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, '⛔ Excel ফাইল খোলে না।');
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        $this->assertStringContainsString('<v>385000</v>', $sheet, '⛔ চালানের মূল্য Excel-এ সংখ্যা নয়।');
        $this->assertStringContainsString('<v>6500</v>', $sheet, '⛔ ভাড়া Excel-এ সংখ্যা নয়।');
        $this->assertStringContainsString('<v>6800</v>', $sheet, '⛔ মোট খরচ Excel-এ সংখ্যা নয়।');
        $this->assertStringContainsString('<v>1.77</v>', $sheet, '⛔ খরচ % Excel-এ সংখ্যা নয়।');
        $this->assertStringContainsString(e(__('sales::delivery_cost.total_row', ['n' => 1])), $sheet, '⛔ ফাইলের শেষে মোটের সারি নেই।');
    }

    // ── যন্ত্রপাতি ──

    /**
     * @param  array<string, string>  $more
     * @return array<string, string>
     */
    private function day(array $more = []): array
    {
        return ['from' => self::DAY, 'to' => self::DAY] + $more;
    }

    /** @return array<int, array<string, mixed>> */
    private function rowsById(): array
    {
        return collect(app(DeliveryCostReport::class)->rows($this->challans(), '3.00'))->keyBy('id')->all();
    }

    private function challans(): \Illuminate\Support\Collection
    {
        return DeliveryChallan::query()->with(['customer.location.parent', 'vehicle'])->whereDate('trx_date', self::DAY)->get();
    }

    private function place(string $code, string $en, string $bn): Location
    {
        return Location::query()->create([
            'company_id' => $this->company->id, 'code' => $code, 'level' => Location::POINT,
            'name_en' => $en, 'name_bn' => $bn, 'is_active' => true,
        ]);
    }

    private function shop(string $code, ?Location $place): Customer
    {
        return Customer::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'code' => $code, 'name_en' => 'Shop '.$code, 'name_bn' => 'দোকান '.$code, 'location_id' => $place?->id, 'is_active' => true,
        ]);
    }

    /**
     * ⓘ খসড়া হিসেবে সেবা দিয়ে বানানো, তারপর অবস্থা আর অঙ্ক বসানো — রিপোর্ট কেবল পড়ে, খাতা বা মজুদ লাগে না।
     *
     * @param  array<string, mixed>  $extra
     */
    private function challan(Customer $shop, string $total, array $extra = [], string $status = DocumentStatus::CONFIRMED): DeliveryChallan
    {
        $challan = app(DeliveryChallanService::class)->create(
            ['customer_id' => $shop->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => self::DAY],
            [['product_id' => Product::query()->firstOrFail()->id, 'delivered_qty' => '1', 'rate' => '50']],
        );
        $challan->forceFill(['status' => $status, 'total' => $total, 'vehicle_id' => null] + $extra)->save();

        return $challan->fresh(['lines']);
    }

    /** @param  list<DeliveryChallan>  $challans */
    private function trip(array $challans, string $fare): Shipment
    {
        $trip = Shipment::query()->forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'document_no' => 'TR-DC-'.(++$this->serial), 'trx_date' => self::DAY, 'warehouse_id' => $this->warehouse->id,
            'status' => DocumentStatus::CONFIRMED, 'dispatched_at' => now(),
        ]);
        $trip->forceFill(['transport_cost' => $fare])->save();

        foreach ($challans as $i => $challan) {
            ShipmentLine::query()->forceCreate([
                'company_id' => $this->company->id, 'shipment_id' => $trip->id, 'delivery_challan_id' => $challan->id, 'line_no' => $i + 1,
            ]);
        }

        return $trip->fresh();
    }

    private function invoice(DeliveryChallan $challan, string $freight, string $status = DocumentStatus::CONFIRMED): SalesInvoice
    {
        $invoice = SalesInvoice::query()->forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $challan->branch_id,
            'document_no' => 'INV-DC-'.Str::upper(Str::random(6)), 'customer_id' => $challan->customer_id,
            'trx_date' => self::DAY, 'total' => bcadd((string) $challan->total, $freight, 4),
            'freight_charge' => $freight, 'status' => $status,
        ]);

        $line = $challan->lines->first();
        SalesInvoiceLine::query()->forceCreate([
            'sales_invoice_id' => $invoice->id, 'product_id' => $line->product_id, 'delivery_challan_line_id' => $line->id,
            'qty' => '1', 'rate' => (string) $challan->total, 'amount' => (string) $challan->total, 'line_no' => 1,
        ]);

        return $invoice;
    }
}
