<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\ReportRun;
use App\Models\User;
use App\Modules\SystemAdmin\Services\ScheduledReportRunner;
use App\Modules\SystemAdmin\Services\ScheduleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\RealAccounts;
use Tests\TestCase;

/**
 * ছাদের (এক লাখ) বেশি সারি — ওয়েবের ফাইল প্রথম সারিগুলো বারবার দিত, আর নির্ধারিত ফাইল চুপচাপ কাটত (ec, রিপোর্ট লাইন-নিরীক্ষা ⚠️১৩,
 * ১৪ অক্টোবর ২০২৬)।
 *
 * ⓘ ছাদটা পরীক্ষায় ২-এ নামানো (`abos.report_whole_document_rows`), এক লাখ সারি না বানিয়ে; চলমান ব্যবস্থায় ছাদ বদলায়নি।
 *
 * দাবি: ওয়েবের CSV-তে প্রতিটা সারি একবার করে, কোনোটা বাদ নয়; নির্ধারিত রিপোর্টে ছাদ পেরোলে ফাইল নেই, অবস্থা "too_many_rows"।
 */
final class AFileOverTheCapRepeatedItsFirstRowsTest extends TestCase
{
    use RealAccounts;
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
    }

    public function test_the_web_file_carries_every_row_once(): void
    {
        foreach ([1, 2, 3] as $i) {
            app(PostingEngine::class)->post('sales_invoice', $i, '2026-08-0'.$i, [
                ['account_id' => $this->cashAccountId(), 'debit' => 1000 * $i],
                ['account_id' => $this->salesAccountId(), 'credit' => 1000 * $i],
            ], documentNo: 'CAP-'.$i);
        }

        config(['abos.report_whole_document_rows' => 2]);

        $csv = (string) $this->get(route('accounts.report.show', [
            'slug' => 'ledger', 'account_id' => $this->cashAccountId(), 'from' => '2026-08-01', 'to' => '2026-08-31', 'export' => 'csv',
        ]))->assertOk()->getContent();

        foreach (['CAP-1', 'CAP-2', 'CAP-3'] as $doc) {
            $this->assertSame(1, substr_count($csv, $doc), "⛔ {$doc} ফাইলে একবার নয় — ছাদের পরের পাতা প্রথম পাতার পুনরাবৃত্তি।");
        }
    }

    public function test_a_schedule_over_the_cap_sends_no_cut_file_and_says_so(): void
    {
        Storage::fake('local');
        app(ReportEngine::class)->register(new ReportDefinition(
            key: 'ec_cap_probe',
            title: 'Cap probe',
            query: fn (array $f) => DB::query()->fromRaw('(select 1 as n union all select 2 union all select 3) as t')->select('n'),
            columns: [['key' => 'n', 'label' => 'N', 'type' => ReportColumn::TEXT]],
            filters: [],
        ));

        $schedule = app(ScheduleService::class)->create(['report_key' => 'ec_cap_probe', 'frequency' => 'daily', 'format' => 'csv']);
        Auth::logout();
        CompanyContext::clear();

        config(['abos.report_whole_document_rows' => 2]);
        $run = app(ScheduledReportRunner::class)->runOne($schedule->fresh());

        $this->assertSame('too_many_rows', $run->status, '⛔ ছাদের বেশি সারি চুপচাপ কেটে পাঠানো হলো।');
        $this->assertNull($run->file_path, '⛔ কাটা ফাইল তবু বানানো হলো।');
        $this->assertSame('too_many_rows', $schedule->fresh()->last_status);
        $this->assertNotSame('system_admin::schedule.run_status.too_many_rows', __('system_admin::schedule.run_status.too_many_rows'),
            '⛔ অবস্থার কোনো লেখা নেই।');

        config(['abos.report_whole_document_rows' => 3]);
        $this->assertSame(1, ReportRun::query()->withoutGlobalScopes()->where('report_schedule_id', $schedule->id)->count());
        $ok = app(ScheduledReportRunner::class)->runOne($schedule->fresh());
        $this->assertSame('ok', $ok->status, 'ছাদের ভিতরে থাকলেও ফাইল হলো না — দাবি অন্ধ।');
    }
}
