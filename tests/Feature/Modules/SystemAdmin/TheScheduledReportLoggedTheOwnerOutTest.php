<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\SystemAdmin\Services\ScheduledReportRunner;
use App\Modules\SystemAdmin\Services\ScheduleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * প্রতিটা নির্ধারিত রিপোর্ট মালিককে সব যন্ত্র থেকে বের করে দিত — সিস্টেম-অ্যাডমিন লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ [[ScheduledReportRunner]] মালিকের পরিচয়ে চালাতে সত্যিকারের `Auth::login()`/`Auth::logout()` করত। Laravel-এর লগআউট "মনে রাখুন"
 * চিহ্ন বদলে দেয় — ফলে রোজ সকালের রিপোর্টের পর মালিকের ফোন আর ল্যাপটপ দুটোই আবার লগইন চাইত। ⭐ এখন কেবল পরিচয় বসানো।
 */
final class TheScheduledReportLoggedTheOwnerOutTest extends TestCase
{
    use RefreshDatabase;

    public function test_running_a_schedule_leaves_the_owners_remember_token_alone(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($owner);

        app(ReportEngine::class)->register(new ReportDefinition(
            key: 'a4_remember_probe',
            title: 'Remember probe',
            query: fn (array $filters) => DB::query()->selectRaw("'row' as name"),
            columns: [['key' => 'name', 'label' => 'Name', 'type' => ReportColumn::TEXT]],
            filters: [],
            permission: 'system_admin.reports.schedule',
        ));

        $schedule = app(ScheduleService::class)->create(['report_key' => 'a4_remember_probe', 'frequency' => 'daily', 'format' => 'csv']);
        $schedule->forceFill(['next_run_at' => now()->subMinute()])->save();

        $owner->forceFill(['remember_token' => 'remember-me-on-the-phone'])->save();
        CompanyContext::clear();
        Auth::forgetUser();

        app(ScheduledReportRunner::class)->runDue();

        $this->assertSame('remember-me-on-the-phone', $owner->fresh()->remember_token,
            'নির্ধারিত রিপোর্টের পরে মালিকের "মনে রাখুন" চিহ্ন বদলে গেল — সব যন্ত্র থেকে বের।');
    }
}
