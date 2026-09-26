<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Modules\SystemAdmin\Services\ScheduledReportRunner;
use App\Modules\SystemAdmin\Services\ScheduleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * সূচির চাবিটাই প্রতিটা রিপোর্টের দরজা খুলে দিত — ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ⛔ কোনো রিপোর্ট নিজের চাবি ঘোষণা করত না, আর null মানে ধরা হত "সবাই":
 * সূচি বানানো নির্মাতাকে নিজের জন্য ছেড়ে দিত, রানার চালাত, নামানোর দরজা
 * খুলত। ফল — কেবল `system_admin.reports.schedule` হাতে যে কেউ লাভ-ক্ষতি
 * পেতেন, `accounts.report` বা `accounts.report.final` ছাড়াই।
 *
 * ⭐ প্রতিটা দরজার দাবি একই মানুষকে দুইবার ([[same-user-key-off-then-on]])।
 */
final class TheScheduleKeyOpenedEveryReportTest extends TestCase
{
    use RefreshDatabase;

    private const PROFIT_LOSS = 'accounts.profit_loss';

    private const UNDECLARED = 'ec_report_with_no_key';

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->inCompany();

        app(ReportEngine::class)->register(new ReportDefinition(
            key: self::UNDECLARED,
            title: 'No key declared',
            query: fn (array $filters) => DB::query()->selectRaw("'x' as name"),
            columns: [['key' => 'name', 'label' => 'Name', 'type' => ReportColumn::TEXT]],
            filters: [],
        ));
    }

    /** ① কেবল সূচির চাবি — লাভ-ক্ষতির সূচি ফেরত; দুই চাবি পেলে তবে যায়। */
    public function test_the_schedule_key_alone_does_not_buy_the_profit_and_loss(): void
    {
        $clerk = $this->member(['system_admin.reports.schedule']);

        $this->assertRefused($clerk, self::PROFIT_LOSS);

        $this->give($clerk, 'accounts.report');
        $this->give($clerk, 'accounts.report.final');

        $this->assertNotNull($this->scheduleAs($clerk, self::PROFIT_LOSS),
            '⛔ দুই চাবি হাতে থাকার পরেও সূচি হলো না — দরজা বন্ধই থেকে গেছে।');
    }

    /** ③ দুই-চাবির রিপোর্টে একটা চাবি যথেষ্ট নয় — যেকোনোটা একা। */
    public function test_one_of_the_two_keys_is_not_enough(): void
    {
        $clerk = $this->member(['system_admin.reports.schedule', 'accounts.report']);
        $this->assertRefused($clerk, self::PROFIT_LOSS);

        $this->take($clerk, 'accounts.report');
        $this->give($clerk, 'accounts.report.final');
        $this->assertRefused($clerk, self::PROFIT_LOSS);
    }

    /**
     * ② চাবি ঘোষণা নেই — সুপার অ্যাডমিন ছাড়া কেউ নয়: রানার, নামানো, ফোন।
     *
     * ⓘ সূচির পর্দা এড়িয়ে বসা পুরনো সারির মতো করে নির্মাতা বদলানো —
     * পর্দার পাহারা পেরোনো সারিও রানারে থামতে হবে।
     */
    public function test_a_report_with_no_key_reaches_nobody_but_a_super_admin(): void
    {
        $clerk = $this->member(['system_admin.reports.schedule', 'accounts.report', 'accounts.report.final']);

        // সূচি — নির্মাতা নিজের জন্যও নয়
        $this->assertRefused($clerk, self::UNDECLARED);

        // রানার — সুপার অ্যাডমিনের সূচি চলে
        $schedule = $this->scheduleAs($this->owner, self::UNDECLARED);
        $run = app(ScheduledReportRunner::class)->runOne($schedule->fresh());
        $this->inCompany();
        $this->assertNotNull($run, 'সুপার অ্যাডমিনের সূচিও চলল না — দাবিটা কিছু মাপছে না।');

        // রানার — একই সূচি, নির্মাতা সাধারণ কর্মী: থামে
        $schedule->forceFill(['created_by' => $clerk->id, 'next_run_at' => now()->subMinute()])->save();
        $this->assertNull(app(ScheduledReportRunner::class)->runOne($schedule->fresh()));
        $this->inCompany();
        $this->assertSame('owner_no_permission', $schedule->fresh()->last_status);

        // নামানো — ফাইলের তালিকায় নাম থাকলেও
        DB::table('report_runs')->where('id', $run->id)->update(['recipients' => json_encode([$this->owner->id, $clerk->id])]);
        $run = ReportRun::query()->withoutGlobalScopes()->findOrFail($run->id);
        $this->assertTrue($run->canBeDownloadedBy($clerk->id), 'তালিকায় নাম বসেনি — দাবিটা কিছু মাপছে না।');
        $this->assertFalse(Gate::forUser($clerk->fresh())->allows('download', $run), '⛔ চাবিহীন রিপোর্টের ফাইল সাধারণ কর্মী নামাতে পারলেন।');
        $this->assertTrue(Gate::forUser($this->owner->fresh())->allows('download', $run));

        // ফোন
        $this->phone($clerk)->getJson('/api/v1/reports/'.self::UNDECLARED)->assertForbidden();
        $this->phone($this->owner)->getJson('/api/v1/reports/'.self::UNDECLARED)->assertOk();
    }

    /** ④ পুরনো সূচি, প্রাপকের একটা চাবি কাড়া হলো — চালানোর সময় বাদ। */
    public function test_an_old_schedule_drops_a_recipient_left_with_one_key(): void
    {
        $recipient = $this->member(['accounts.report', 'accounts.report.final']);
        $schedule = $this->scheduleAs($this->owner, self::PROFIT_LOSS, [$recipient->id]);

        $this->take($recipient, 'accounts.report.final');

        $run = app(ScheduledReportRunner::class)->runOne($schedule->fresh());
        $this->inCompany();

        $this->assertNotNull($run, 'রানটা হয়ইনি — দাবিটা কিছু মাপছে না।');
        $this->assertTrue($run->canBeDownloadedBy($this->owner->id));
        $this->assertFalse($run->canBeDownloadedBy($recipient->id), '⛔ একটা চাবি হারানো প্রাপকও লাভ-ক্ষতির ফাইল পেলেন।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function inCompany(): void
    {
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    /** @param list<string> $keys */
    private function member(array $keys): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        foreach ($keys as $key) {
            $this->give($user, $key);
        }

        return $user->fresh();
    }

    private function give(User $user, string $key): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function take(User $user, string $key): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $user->revokePermissionTo($key));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param list<int> $recipients */
    private function scheduleAs(User $user, string $report, array $recipients = []): ReportSchedule
    {
        $this->inCompany();
        $this->actingAs($user->fresh());

        $schedule = app(ScheduleService::class)->create([
            'report_key' => $report,
            'frequency' => 'daily',
            'format' => 'csv',
            'recipients' => $recipients,
        ]);
        $schedule->forceFill(['next_run_at' => now()->subMinute()])->save();

        return $schedule->fresh();
    }

    private function assertRefused(User $user, string $report): void
    {
        try {
            $this->scheduleAs($user, $report);
        } catch (ValidationException) {
            $this->assertSame(0, ReportSchedule::query()->withoutGlobalScopes()->where('report_key', $report)->where('created_by', $user->id)->count());

            return;
        }

        $this->fail("⛔ {$report}-এর সূচি এমন একজন বানাতে পারলেন যাঁকে ওয়েবের দরজা ফেরায়।");
    }

    private function phone(User $user): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this;
    }
}
