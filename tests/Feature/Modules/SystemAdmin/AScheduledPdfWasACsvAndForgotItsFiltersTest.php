<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Modules\SystemAdmin\Services\ScheduledReportRunner;
use App\Modules\SystemAdmin\Services\ScheduleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * নির্ধারিত "PDF" আসলে CSV ছিল, আর সূচি ছাঁকনি মনে রাখত না — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ভুল ছিল ────────────────────────────────────────────────────
 * ① [[ScheduledReportRunner]]-এ PDF-এর শাখাই ছিল না: `default => csv()`, আর
 *    ফাইলটা `.pdf` নামে জমা হত — খুললে নষ্ট ফাইল।
 * ② `ReportScheduleController::validated()`-এ `filters` ছিল না, তাই পাঠানো
 *    শাখা/তারিখ ঝরে যেত; আর প্রতিটা সম্পাদনা আগের ছাঁকনি মুছে দিত।
 */
final class AScheduledPdfWasACsvAndForgotItsFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_a_scheduled_pdf_is_a_pdf(): void
    {
        app(ReportEngine::class)->register(new ReportDefinition(
            key: 'sec_pdf_probe',
            title: 'PDF probe',
            query: fn (array $filters) => DB::query()->selectRaw("'row' as name, 100 as amount"),
            columns: [
                ['key' => 'name', 'label' => 'Name', 'type' => ReportColumn::TEXT],
                ['key' => 'amount', 'label' => 'Amount', 'type' => ReportColumn::MONEY],
            ],
            filters: [],
            permission: 'system_admin.reports.schedule',
        ));

        $schedule = app(ScheduleService::class)->create(['report_key' => 'sec_pdf_probe', 'frequency' => 'daily', 'format' => 'pdf']);
        $schedule->forceFill(['next_run_at' => now()->subMinute()])->save();

        $run = app(ScheduledReportRunner::class)->runOne($schedule->fresh());

        $this->assertInstanceOf(ReportRun::class, $run);
        $bytes = (string) Storage::disk('local')->get((string) $run->file_path);

        $this->assertStringStartsWith('%PDF', $bytes, '⛔ "PDF" সূচির ফাইলটা PDF নয় — খুললে নষ্ট।');
        $this->assertStringEndsWith('.pdf', (string) $run->file_path);
    }

    public function test_the_filters_sent_with_a_schedule_are_kept_and_survive_an_edit(): void
    {
        $branch = $this->company->defaultBranch();

        $this->post(route('system_admin.reports.schedule.store'), [
            'report_key' => 'accounts.day_book',
            'format' => 'csv',
            'frequency' => 'daily',
            'at_time' => '08:00',
            'filters' => ['from' => '2026-08-01', 'to' => '2026-08-31', 'branch_id' => (string) $branch->id, 'sneaky' => 'x'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $schedule = ReportSchedule::query()->where('report_key', 'accounts.day_book')->latest('id')->firstOrFail();

        $this->assertEquals(['from' => '2026-08-01', 'to' => '2026-08-31', 'branch_id' => (string) $branch->id], $schedule->filters,
            '⛔ পাঠানো ছাঁকনি সূচিতে বসেনি — নয়তো অচেনা চাবিও বসে গেছে।');

        // ── সম্পাদনার ফর্মে ছাঁকনির ঘর নেই — তাই আগেরটা থাকতে হবে ──
        $this->put(route('system_admin.reports.schedule.update', $schedule), [
            'report_key' => 'accounts.day_book',
            'format' => 'xlsx',
            'frequency' => 'daily',
            'at_time' => '09:00',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('xlsx', $schedule->fresh()->format);
        $this->assertEquals(['from' => '2026-08-01', 'to' => '2026-08-31', 'branch_id' => (string) $branch->id], $schedule->fresh()->filters,
            '⛔ সম্পাদনায় সূচির ছাঁকনি মুছে গেল।');
    }
}
