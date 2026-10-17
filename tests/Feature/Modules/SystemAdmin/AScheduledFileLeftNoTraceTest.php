<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\ExportLog;
use App\Models\User;
use App\Modules\SystemAdmin\Services\ScheduledReportRunner;
use App\Modules\SystemAdmin\Services\ScheduleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * নির্ধারিত ফাইল নামানো রপ্তানির খাতায় ওঠে (ec, রিপোর্ট লাইন-নিরীক্ষা ⓘ১৪, ১৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে ওয়েব আর ফোনের প্রতিটা রপ্তানি খাতায় উঠত, অথচ নির্ধারিত রিপোর্টের ফাইল নামালে কোনো চিহ্ন নয় — কে কোন দিনের ক্রয়মূল্যের
 * ফাইল নিয়ে গেলেন, জানার উপায় ছিল না।
 */
final class AScheduledFileLeftNoTraceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_download_is_written_in_the_export_journal(): void
    {
        Storage::fake('local');
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($owner);

        app(ReportEngine::class)->register(new ReportDefinition(
            key: 'ec_trace_probe',
            title: 'Trace probe',
            query: fn (array $f) => DB::query()->selectRaw("'row' as name"),
            columns: [['key' => 'name', 'label' => 'Name', 'type' => ReportColumn::TEXT]],
            filters: [],
        ));

        $schedule = app(ScheduleService::class)->create(['report_key' => 'ec_trace_probe', 'frequency' => 'daily', 'format' => 'csv']);
        $run = app(ScheduledReportRunner::class)->runOne($schedule->fresh());

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $before = ExportLog::query()->withoutGlobalScopes()->where('user_id', $owner->id)->count();

        $this->actingAs($owner->fresh())->get(route('system_admin.reports.download', $run))->assertOk();

        $logs = ExportLog::query()->withoutGlobalScopes()->where('user_id', $owner->id)->get();
        $this->assertSame($before + 1, $logs->count(), '⛔ নির্ধারিত ফাইল নামানো রপ্তানির খাতায় ওঠেনি।');
        $this->assertSame(1, (int) $logs->last()->row_count);
    }
}
