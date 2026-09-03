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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * নির্ধারিত রিপোর্ট — সবসময় একজন মানুষের অনুমতিতে, আর একজনেরটা অন্যজনের
 * হাতে যায় না।
 *
 * সবচেয়ে জরুরি দুইটা: (১) পরপর দুই কোম্পানির সূচি চললে দ্বিতীয়টা প্রথমটার
 * প্রসঙ্গ বয়ে নেয় না; (২) প্রাপক যে কলাম পর্দায় দেখতে পান না, সেটা ফাইলেও
 * বসে না।
 */
class ScheduledReportTest extends TestCase
{
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

        $this->registerTestReports();
    }

    /**
     * দুইটা টেস্ট-রিপোর্ট নিবন্ধন — একটায় চলতি কোম্পানি একটা কলাম হিসেবে
     * ধরা (bleed পরীক্ষা), আরেকটায় একটা অনুমতি-বাঁধা কলাম।
     */
    private function registerTestReports(): void
    {
        $engine = app(ReportEngine::class);

        $engine->register(new ReportDefinition(
            key: 'a4_context_probe',
            title: 'Context probe',
            // চলতি কোম্পানিটাই একটা কলাম হিসেবে — closure চলার মুহূর্তের প্রসঙ্গ ধরে
            query: fn (array $filters) => DB::query()->selectRaw((int) CompanyContext::id().' as company_seen'),
            columns: [['key' => 'company_seen', 'label' => 'Company seen', 'type' => ReportColumn::TEXT]],
            filters: [],
            permission: 'system_admin.reports.schedule',
        ));

        $engine->register(new ReportDefinition(
            key: 'a4_secret_column',
            title: 'Has a secret column',
            query: fn (array $filters) => DB::query()->selectRaw("'row' as name, 100 as cost"),
            columns: [
                ['key' => 'name', 'label' => 'Name', 'type' => ReportColumn::TEXT],
                ['key' => 'cost', 'label' => 'Cost', 'type' => ReportColumn::MONEY, 'permission' => 'inventory.report'],
            ],
            filters: [],
            permission: 'customer.report',
        ));

        $engine->register(new ReportDefinition(
            key: 'a4_no_permission',
            title: 'No declared audience',
            query: fn (array $filters) => DB::query()->selectRaw("'row' as name"),
            columns: [['key' => 'name', 'label' => 'Name', 'type' => ReportColumn::TEXT]],
            filters: [],
        ));
    }

    private function service(): ScheduleService
    {
        return app(ScheduleService::class);
    }

    private function runner(): ScheduledReportRunner
    {
        return app(ScheduledReportRunner::class);
    }

    /** একজন ব্যবহারকারী, এই কোম্পানির সদস্য — সদস্যপদ pivot-এ, users-এ নয়। */
    private function member(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user;
    }

    private function makeDue(ReportSchedule $schedule): void
    {
        $schedule->forceFill(['next_run_at' => now()->subMinute()])->save();
    }

    private function fileText(ReportRun $run): string
    {
        return (string) Storage::disk('local')->get((string) $run->file_path);
    }

    /** ★ পরপর দুই কোম্পানি — দ্বিতীয়টা প্রথমটার প্রসঙ্গ বয়ে নেয় না। */
    public function test_two_consecutive_companies_do_not_bleed(): void
    {
        $second = Company::query()->where('code', 'FMART')->firstOrFail();
        $ownerTwo = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $s1 = $this->service()->create(['report_key' => 'a4_context_probe', 'frequency' => 'daily', 'format' => 'csv']);

        CompanyContext::set($second->id, $second->defaultBranch()?->id);
        $s2 = $this->service()->create(['report_key' => 'a4_context_probe', 'frequency' => 'daily', 'format' => 'csv']);

        CompanyContext::clear();
        Auth::logout();
        $this->makeDue($s1);
        $this->makeDue($s2);

        $this->runner()->runDue();

        $run1 = ReportRun::withoutGlobalScope('company')->where('report_schedule_id', $s1->id)->firstOrFail();
        $run2 = ReportRun::withoutGlobalScope('company')->where('report_schedule_id', $s2->id)->firstOrFail();

        $this->assertStringContainsString((string) $this->company->id, $this->fileText($run1));
        // দ্বিতীয়টা দ্বিতীয় কোম্পানিই দেখেছে — প্রথমটা বয়ে আনেনি
        $this->assertStringContainsString((string) $second->id, $this->fileText($run2));
        $this->assertStringNotContainsString((string) $this->company->id, $this->fileText($run2));

        // চালানোর পর কোনো পরিচয়/প্রসঙ্গ আটকে নেই
        $this->assertNull(CompanyContext::id());
        $this->assertFalse(Auth::check());
    }

    /** ★ প্রাপক যে কলাম দেখতে পান না, তা ফাইলে নেই। */
    public function test_a_column_the_recipient_cannot_see_is_left_out(): void
    {
        $recipient = $this->member();
        // রিপোর্ট দেখতে পারেন (customer.report), কিন্তু cost কলাম নয় (inventory.report নেই)
        $recipient->givePermissionTo(Permission::findOrCreate('customer.report', 'web'));

        $schedule = $this->service()->create([
            'report_key' => 'a4_secret_column',
            'frequency' => 'daily',
            'format' => 'csv',
            'recipients' => [$recipient->id],
        ]);
        $this->makeDue($schedule);

        $run = $this->runner()->runOne($schedule->fresh());

        $text = $this->fileText($run);
        $this->assertStringContainsString('Name', $text);
        $this->assertStringNotContainsString('Cost', $text, 'প্রাপকের না-দেখা cost কলাম ফাইলে বেরিয়ে গেছে।');
    }

    /** মালিক নিষ্ক্রিয় হলে সূচি থামে, ফাইল হয় না। */
    public function test_an_inactive_owner_stops_the_schedule(): void
    {
        $schedule = $this->service()->create(['report_key' => 'a4_no_permission', 'frequency' => 'daily', 'format' => 'csv']);
        $this->owner->forceFill(['is_active' => false])->save();
        $this->makeDue($schedule);

        $result = $this->runner()->runOne($schedule->fresh());

        $this->assertNull($result);
        $this->assertFalse($schedule->fresh()->is_active);
        $this->assertSame(0, ReportRun::withoutGlobalScope('company')->where('report_schedule_id', $schedule->id)->count());
    }

    /** ★ পরে যোগ হওয়া প্রাপক পুরনো ফাইল নামাতে পারেন না। */
    public function test_a_later_added_recipient_cannot_download_an_old_file(): void
    {
        $schedule = $this->service()->create(['report_key' => 'a4_no_permission', 'frequency' => 'daily', 'format' => 'csv']);
        $this->makeDue($schedule);
        $run = $this->runner()->runOne($schedule->fresh());

        // এখন একজন নতুন ব্যবহারকারী — পুরনো রানের snapshot-এ নেই
        $newcomer = $this->member();

        $this->assertTrue($run->canBeDownloadedBy($this->owner->id));
        $this->assertFalse($run->canBeDownloadedBy($newcomer->id));

        $this->actingAs($newcomer)
            ->get(route('system_admin.reports.download', $run))
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->get(route('system_admin.reports.download', $run))
            ->assertOk();
    }

    /** ঘোষিত অনুমতি ছাড়া রিপোর্ট অন্য কাউকে পাঠানো যায় না। */
    public function test_a_report_with_no_permission_cannot_be_scheduled_for_others(): void
    {
        $other = $this->member();

        $this->expectException(ValidationException::class);

        $this->service()->create([
            'report_key' => 'a4_no_permission',
            'frequency' => 'daily',
            'format' => 'csv',
            'recipients' => [$other->id],
        ]);
    }
}
