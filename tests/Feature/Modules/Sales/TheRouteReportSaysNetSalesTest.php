<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Services\LocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ রুট ধরে বিক্রি (`sales.by_route`) — নিট বিক্রি, ভ্যাট ও ফেরত বাদে, আর ক্রম সেটা ধরেই (fe, নিট বিক্রি ২য় ভাগ ১, ২১ অক্টোবর ২০২৬)।
 *
 * দাবি, আসল দরজায় (`GET /sales/reports/by-route`, হেডারে বিলের শাখা):
 *   · বিল ১,০০,০০০ + VAT, আংশিক ফেরত ১০,০০০ + VAT → রুটের `net_sales` ঠিক ৯০,০০০.০০০০;
 *   · খতিয়ানের ঘর ভ্যাটসহই থাকে (বিল ১,১০,০০০, ফেরত ১১,০০০) — পাওনার সমীকরণ ভাঙে না;
 *   · মোটের সারি = সারির যোগ; নাম "নিট বিক্রি (ভ্যাট ও ফেরত বাদে)";
 *   · বড় বিল আর বেশি ফেরতের রুট (নিট ৪০,০০০) কম বিলের রুটের (নিট ৫০,০০০) নিচে বসে।
 */
final class TheRouteReportSaysNetSalesTest extends TestCase
{
    use NetSalesReportFixture;
    use RefreshDatabase;

    private Location $routeA;

    private Location $routeB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNetSales();

        $point = Location::query()->where('code', 'PT-KDA')->firstOrFail();
        $this->routeA = $this->route($point, 'RT-NS2-A');
        $this->routeB = $this->route($point, 'RT-NS2-B');
        Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail()->forceFill(['location_id' => $this->routeA->id])->save();
        Customer::acrossDealers()->where('name_en', 'Niloy Store')->firstOrFail()->forceFill(['location_id' => $this->routeB->id])->save();
    }

    public function test_a_route_shows_bills_less_returns_without_vat_to_the_paisa(): void
    {
        $before = $this->routeRow('RT-NS2-A');

        $this->billAndPartReturn('Rahim Traders');

        $result = $this->page('sales.report.show', 'by-route');
        $after = $this->routeRow('RT-NS2-A', $result);
        $d = fn (string $k) => bcsub($after[$k], $before[$k], 4);

        $this->assertSame('90000.0000', $d('net_sales'), '⛔ রুটের নিট বিক্রি = বিল − ফেরত, ভ্যাট বাদে নয়।');
        // ⓘ খতিয়ানের ঘর পাওনার — ভ্যাটসহ, আর সমীকরণের অংশ
        $this->assertSame('110000.0000', $d('sales'), 'প্রস্তুতিটাই ভুল — খতিয়ানে বিলটা ভ্যাটসহ বসেনি।');
        $this->assertSame('11000.0000', $d('returns'), 'প্রস্তুতিটাই ভুল — খতিয়ানে ফেরতটা ভ্যাটসহ বসেনি।');

        $this->assertTotalRowAddsUp($result, 'net_sales');
        $this->assertSame('net_sales', $result->report->rankBy, '⛔ "সবচেয়ে বড় রুট" নিট বিক্রি ধরে নয়।');
    }

    public function test_the_route_with_heavy_returns_ranks_below_the_one_that_kept_its_sales(): void
    {
        $this->billAndPartReturn('Rahim Traders', '10', '6');   // নিট ৪০,০০০; খতিয়ানে বিল ১,১০,০০০
        $this->bill('5', '10000', 'Niloy Store');               // নিট ৫০,০০০; খতিয়ানে বিল ৫৫,০০০

        $result = $this->page('sales.report.show', 'by-route');
        $a = $this->routeRow('RT-NS2-A', $result);
        $b = $this->routeRow('RT-NS2-B', $result);

        $this->assertSame(1, bccomp($a['sales'], $b['sales'], 4), 'প্রস্তুতিটাই ভুল — খতিয়ানের বিলে A বড় নয়, তাই ক্রমের দাবি কিছু মাপে না।');
        $this->assertSame(1, bccomp($b['net_sales'], $a['net_sales'], 4), 'প্রস্তুতিটাই ভুল — নিটে B বড় নয়।');

        $codes = array_column($this->rowsOf($result), 'route_code');
        $this->assertLessThan(array_search('RT-NS2-A', $codes, true), array_search('RT-NS2-B', $codes, true),
            '⛔ রুটগুলো ফেরতের আগের বিল ধরে সাজানো — বেশি ফেরতের রুট উপরে।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** @return array{net_sales: string, sales: string, returns: string} */
    private function routeRow(string $code, ?\App\Core\Engines\Report\ReportResult $result = null): array
    {
        $result ??= $this->page('sales.report.show', 'by-route');
        $row = collect($this->rowsOf($result))->firstWhere('route_code', $code);
        $this->assertNotNull($row, "প্রস্তুতিটাই ভুল — রিপোর্টে {$code} নেই।");

        return ['net_sales' => $this->money($row['net_sales'] ?? null), 'sales' => $this->money($row['sales']), 'returns' => $this->money($row['returns'])];
    }

    private function route(Location $point, string $code): Location
    {
        return app(LocationService::class)->create([
            'code' => $code, 'name_en' => $code, 'name_bn' => $code,
            'level' => Location::ROUTE, 'parent_id' => $point->id,
        ]);
    }
}
