<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ মাসওয়ারি বিক্রয় (`sales.monthly`) — নিট বিক্রি ভ্যাট ও ফেরত বাদে, বাকি-যোগ ভ্যাটসহ (fe, নিট বিক্রি ২য় ভাগ ৩, ২১ অক্টোবর ২০২৬)।
 *
 * দাবি, আসল দরজায় (`GET /sales/reports/monthly`, এই মাস, হেডারে বিলের শাখা):
 *   · বিল ১,০০,০০০ + VAT, আংশিক ফেরত ১০,০০০ + VAT → এই মাসের `net_sales` +৯০,০০০, `returned` +১০,০০০ (ভ্যাট বাদে), পয়সায়;
 *   · `due_added` +৯৯,০০০ — গ্রাহকের পাওনা ভ্যাটসহ (১,১০,০০০ − ১১,০০০), আদায় নেই;
 *   · পণ্য বাছলেও (সারি ধরে, [[LineNetSale]]) একই +৯০,০০০ আর +১০,০০০;
 *   · মোটের সারি = সারির যোগ; নাম "নিট বিক্রি (ভ্যাট ও ফেরত বাদে)"।
 */
final class TheMonthlySalesAreNetOfVatTest extends TestCase
{
    use NetSalesReportFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNetSales();
    }

    public function test_this_months_net_sales_are_bills_less_returns_without_vat_and_the_due_keeps_it(): void
    {
        $before = $this->thisMonth();

        $this->billAndPartReturn();

        $result = $this->monthly();
        $after = $this->thisMonth($result);
        $d = fn (string $k) => bcsub($after[$k], $before[$k], 4);

        $this->assertSame('90000.0000', $d('net_sales'), '⛔ মাসের নিট বিক্রি ভ্যাট বা ফেরত বাদ দেয় না।');
        $this->assertSame('10000.0000', $d('returned'), '⛔ মাসের ফেরত ভ্যাট বাদে নয়।');
        $this->assertSame('99000.0000', $d('due_added'), '⛔ বাকি-যোগ ভ্যাটসহ পাওনা নয় — গ্রাহকের খাতার সাথে মিলবে না।');

        $this->assertTotalRowAddsUp($result, 'net_sales');
        $this->assertTotalRowAddsUp($result, 'returned');
    }

    public function test_by_product_the_month_is_net_of_vat_too(): void
    {
        $before = $this->thisMonth(null, ['product_id' => $this->rice->id]);

        $this->billAndPartReturn();

        $after = $this->thisMonth(null, ['product_id' => $this->rice->id]);

        $this->assertSame('90000.0000', bcsub($after['net_sales'], $before['net_sales'], 4), '⛔ পণ্য ধরলে মাসের নিট বিক্রি ভ্যাটসহ।');
        $this->assertSame('10000.0000', bcsub($after['returned'], $before['returned'], 4), '⛔ পণ্য ধরলে ফেরত ভ্যাটসহ।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $query */
    private function monthly(array $query = []): ReportResult
    {
        return $this->page('sales.report.show', 'monthly', ['from' => Carbon::today()->startOfMonth()->toDateString(), ...$query]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{net_sales: string, returned: string, due_added: string}
     */
    private function thisMonth(?ReportResult $result = null, array $query = []): array
    {
        $result ??= $this->monthly($query);
        $row = collect($this->rowsOf($result))->firstWhere('month', Carbon::today()->format('Y-m')) ?? [];

        return [
            'net_sales' => $this->money($row['net_sales'] ?? null),
            'returned' => $this->money($row['returned'] ?? null),
            'due_added' => $this->money($row['due_added'] ?? null),
        ];
    }
}
