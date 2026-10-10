<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Engines\Report\ReportExport;
use App\Core\Services\ListExport;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\RealAccounts;
use Tests\TestCase;

/**
 * "মোট" সারি হার আর গড় যোগ করত, আর ফাইলে মোটের সারিই ছিল না — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ভুল ছিল ────────────────────────────────────────────────────
 * ① প্রতিটা টাকা/পরিমাণের ঘর ডিফল্টে যোগ হত — দশটা পণ্যের একক দাম, দশটা
 *    অনুমোদনের গড় দিন, পুনঃঅর্ডারের স্তর — সবই "মোট"-এ একটা মিথ্যা সংখ্যা।
 * ② শাখা-ভাগ ছাড়া রিপোর্টের CSV/Excel/PDF-এ পর্দার "মোট" সারিটা ছিল না।
 */
final class TheTotalsAddedUpRatesAndTheFileHadNoTotalTest extends TestCase
{
    use RealAccounts;
    use RefreshDatabase;

    public function test_rates_prices_days_and_levels_are_not_totalled_but_amounts_are(): void
    {
        $this->seed(DemoSeeder::class);
        $reports = app(ReportEngine::class);

        $totalled = fn (string $key): array => array_map(fn (ReportColumn $c) => $c->key, $reports->get($key)->totalledColumns());

        foreach ([
            'purchase.price_history' => ['rate', 'previous_rate', 'rate_change'],
            'inventory.stock_value' => ['purchase_price', 'sale_price'],
            'inventory.expiring' => ['mrp'],
            'inventory.replenishment' => ['reorder_level', 'max_level'],
            'approval.by_user' => ['avg_days'],
            'approval.bottleneck' => ['avg_days', 'worst_days', 'level'],
            'approval.pending' => ['waiting_days', 'current_level'],
        ] as $report => $notSums) {
            foreach ($notSums as $column) {
                $this->assertNotContains($column, $totalled($report), "⛔ {$report}: '{$column}' একটা হার/গড়/স্তর, অথচ মোটে যোগ হলো।");
            }
        }

        // ⓘ টাকার অঙ্ক আগের মতোই যোগ হয় — নিয়মটা সবকিছু বন্ধ করে দেয়নি
        $this->assertContains('debit', $totalled('accounts.ledger'));
        $this->assertContains('net', $totalled('accounts.profit_loss'));
    }

    public function test_an_unsplit_reports_file_ends_with_the_same_total_as_the_screen(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id);
        $this->actingAs($owner = User::query()->where('email', 'owner@abos.test')->firstOrFail());

        foreach ([1, 2, 3] as $i) {
            app(PostingEngine::class)->post('sales_invoice', $i, '2026-08-0'.$i, [
                ['account_id' => $this->cashAccountId(), 'debit' => 1000 * $i],
                ['account_id' => $this->salesAccountId(), 'credit' => 1000 * $i],
            ], documentNo: 'TOT-'.$i);
        }

        $result = app(ReportEngine::class)->run('accounts.ledger', ['from' => '2026-08-01', 'to' => '2026-08-31', 'account_id' => $this->cashAccountId()]);
        $this->assertFalse($result->isSplitByBranch(), 'রিপোর্টটা শাখায় ভাগ হলো — দাবিটা শাখা-ভাগহীন রিপোর্টের।');

        $export = new ListExport;
        ReportExport::into($export, $result, $result->columnsFor($owner));

        $footer = $export->footerRow();
        $this->assertNotNull($footer, '⛔ ফাইলে মোটের সারি নেই, অথচ পর্দায় আছে।');
        $this->assertSame(__('core.print.total'), $footer[0]);

        $columns = array_map(fn (ReportColumn $c) => $c->key, $result->columnsFor($owner));
        $this->assertSame('6,000.00', $footer[array_search('debit', $columns, true)], 'ফাইলের মোট পর্দার মোটের সমান নয়।');
        $this->assertSame('', $footer[array_search('balance', $columns, true)], '⛔ চলমান জেরের ঘর মোটে যোগ হলো।');

        $this->assertStringEndsWith(implode(',', array_map(fn ($v) => str_contains($v, ',') ? '"'.$v.'"' : $v, $footer))."\r\n",
            (string) $export->csv(), 'CSV-র শেষ লাইনটা মোটের সারি নয়।');
    }
}
