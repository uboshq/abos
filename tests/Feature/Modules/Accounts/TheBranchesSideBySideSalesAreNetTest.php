<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Report\ReportResult;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Sales\Metrics\SalesMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Modules\Sales\NetSalesReportFixture;
use Tests\TestCase;

/**
 * ⭐ শাখা পাশাপাশি (`accounts.branches_side_by_side`) — "বিক্রি" নিট: ৪১০০ − ৪১১০ বিক্রয় ফেরত (fe, নিট বিক্রি ২য় ভাগ ৪,
 * ২১ অক্টোবর ২০২৬; IFRS 15.51)।
 *
 * দাবি, আসল দরজায় (`GET /accounts/reports/branches`, আজ, হেডারে বিলের শাখা):
 *   · বিল ১,০০,০০০ + VAT, আংশিক ফেরত ১০,০০০ + VAT → শাখার `sales` +৯০,০০০.০০০০ (খাতায় ভ্যাট ২১২০-এ, তাই আগেই ভ্যাট বাদে);
 *   · প্রস্তুতি: ৪১১০-এ সেদিন ১০,০০০ খরচ (ডেবিট) বসেছে — ফেরত সত্যিই খাতায়;
 *   · শাখার সারির বিক্রি = হোমের নিট বিক্রি (SalesMetrics, একই শাখা, একই দিন) — একই অঙ্ক দুই উৎসে;
 *   · মোটের সারি = সারির যোগ; নাম "নিট বিক্রি (ভ্যাট ও ফেরত বাদে)"।
 * ⓘ ছাড়: চার্টে আয়ের-বিপরীত বিক্রয়-ছাড়ের খাত নেই (৫৩০০ খরচের ধরনের), তাই বিক্রি থেকে আর কিছু বাদ নয়।
 */
final class TheBranchesSideBySideSalesAreNetTest extends TestCase
{
    use NetSalesReportFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNetSales();
    }

    public function test_a_branchs_sales_are_4100_less_4110_to_the_paisa(): void
    {
        $branch = (int) $this->warehouse->branch_id;
        $today = Carbon::today()->toDateString();
        $before = $this->branchSales($this->page('accounts.report.show', 'branches'), $branch);
        $returnsBefore = $this->moved(StandardChart::SALES_RETURN, $branch, $today);

        $this->billAndPartReturn();

        // প্রস্তুতি: ফেরত খাতায় — ৪১১০-এ ১০,০০০ ডেবিট (ভ্যাট বাদে)
        $this->assertSame('10000.0000', bcsub($this->moved(StandardChart::SALES_RETURN, $branch, $today), $returnsBefore, 4),
            'প্রস্তুতিটাই ভুল — বিক্রয় ফেরত খাতে (৪১১০) ফেরত বসেনি।');

        $result = $this->page('accounts.report.show', 'branches');
        $after = $this->branchSales($result, $branch);

        $this->assertSame('90000.0000', bcsub($after, $before, 4), '⛔ শাখার বিক্রিতে ফেরত (৪১১০) বাদ যায়নি, বা ভ্যাট ঢুকেছে।');

        // ⓘ একই শাখা, একই দিন — খাতার নিট বিক্রি = হোমের নিট বিক্রি
        $this->web();
        $this->assertSame(SalesMetrics::net($today, $today), $after, '⛔ শাখার নিট বিক্রি আর হোমের নিট বিক্রি দুই অঙ্ক।');

        $this->assertTotalRowAddsUp($result, 'sales');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function branchSales(ReportResult $result, int $branch): string
    {
        $row = collect($this->rowsOf($result))->first(fn (array $r) => (int) ($r['branch_id'] ?? 0) === $branch);

        return $this->money($row['sales'] ?? null);
    }

    /** খাতের ডেবিট − ক্রেডিট, এক শাখা এক দিন — খাত আর তার নিচের সবাই */
    private function moved(string $code, int $branch, string $day): string
    {
        $ids = StandardChart::find($code)?->selfAndDescendants()->pluck('id')->all() ?? [];
        $this->assertNotEmpty($ids, "প্রস্তুতিটাই ভুল — {$code} খাতটা নেই।");
        $row = DB::table('ledger_entries')->where('company_id', $this->company->id)->where('branch_id', $branch)
            ->whereIn('account_id', $ids)->whereDate('trx_date', $day)
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')->first();

        return bcsub((string) $row->d, (string) $row->c, 4);
    }
}
