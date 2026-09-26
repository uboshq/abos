<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notification;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Modules\SystemAdmin\Services\ScheduledReportRunner;
use App\Modules\SystemAdmin\Services\ScheduleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⛔ নির্ধারিত রিপোর্ট চলে সূচির মালিকের পরিচয়ে, আর আগে একই ফল **সব**
 * প্রাপককে যেত — ২৬ সেপ্টেম্বর ২০২৬।
 *
 * ── কী ফাঁস হচ্ছিল ────────────────────────────────────────────────────
 * প্রাপকের অনুমতি দেখা হত কেবল সূচি বানানোর দিনে। ⚠️ তারপর কারও চাবি
 * কেড়ে নেওয়া হলেও তিনি প্রতিদিন খবর পেতেন, ফাইল নামাতে পারতেন — আর
 * ফাইলটা মালিকের চোখের, গোটা কোম্পানির সংখ্যা। যে সারি সূচির পর্দা দিয়ে
 * বসে না (পুরনো সারি, সরাসরি লেখা), তাতে যাচাই কোনোদিনই হয়নি।
 *
 * ⭐ মালিকের নিয়ম: প্রত্যেকে কেবল নিজেরটা পাবেন। মালিকের অনুমতি প্রাপককে
 * ধার দেওয়া হয় না।
 *
 * ⓘ প্রতিটা দাবি **আসল দরজায়**: রানার চালিয়ে ফাইলের তালিকা আর খবরের
 * সারি গোনা, আর নামানো HTTP দিয়ে।
 */
class TheScheduleLentTheOwnersEyesToEveryRecipientTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'c1_guarded_report';

    private const PERMISSION = 'customer.report';

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

        app(ReportEngine::class)->register(new ReportDefinition(
            key: self::KEY,
            title: 'Guarded report',
            query: fn (array $filters) => DB::query()->selectRaw("'whole company' as name, 100 as cost"),
            columns: [
                ['key' => 'name', 'label' => 'Name', 'type' => ReportColumn::TEXT],
                ['key' => 'cost', 'label' => 'Cost', 'type' => ReportColumn::MONEY, 'permission' => 'inventory.report'],
            ],
            filters: [],
            permission: self::PERMISSION,
        ));
    }

    /** একজন সদস্য, রিপোর্টের চাবিসহ বা ছাড়া। */
    private function member(bool $withKey): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        if ($withKey) {
            $user->givePermissionTo(Permission::findOrCreate(self::PERMISSION, 'web'));
        }

        return $user;
    }

    /** @param  list<int>  $recipients */
    private function schedule(array $recipients): ReportSchedule
    {
        $schedule = app(ScheduleService::class)->create([
            'report_key' => self::KEY,
            'frequency' => 'daily',
            'format' => 'csv',
            'recipients' => $recipients,
        ]);

        $schedule->forceFill(['next_run_at' => now()->subMinute()])->save();

        return $schedule->fresh();
    }

    private function runNow(ReportSchedule $schedule): ReportRun
    {
        $run = app(ScheduledReportRunner::class)->runOne($schedule->fresh());
        $this->assertNotNull($run, 'রানটা হয়ইনি — দাবিটা কিছুই মাপছে না।');

        return $run;
    }

    private function toldAbout(User $user): int
    {
        return Notification::query()->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('type', 'report_ready')
            ->count();
    }

    private function takeKeyAway(User $user): void
    {
        /*
         * ⚠️ কোম্পানির ভেতরে কাড়া — teams চালু, তাই চাবি কোম্পানি ধরে বাঁধা।
         * ⛔ রানার শেষে `CompanyContext::clear()` করে; রানের পরে কাড়লে টিম
         * null থাকত, কিছুই কাড়া হত না, আর দরজা ২০০ দিয়ে কোডকে দোষী দেখাত।
         */
        CompanyContext::forCompany(
            $this->company->id,
            fn () => $user->revokePermissionTo(self::PERMISSION),
        );
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** ⛔ সূচি বানানোর পরে চাবি হারালেন — ফাইল, খবর, নামানো, কিছুই নয়। */
    public function test_a_recipient_who_lost_the_key_gets_nothing(): void
    {
        $recipient = $this->member(withKey: true);
        $schedule = $this->schedule([$recipient->id]);

        $this->takeKeyAway($recipient);

        $run = $this->runNow($schedule);

        $this->assertFalse($run->canBeDownloadedBy($recipient->id), 'চাবিহীন প্রাপক ফাইলের তালিকায় উঠেছেন।');
        $this->assertSame(0, $this->toldAbout($recipient), 'চাবিহীন প্রাপক খবর পেয়েছেন।');

        $this->actingAs($recipient)
            ->get(route('system_admin.reports.download', $run))
            ->assertForbidden();
    }

    /** ✅ উল্টো দাবি: চাবি আছে — পান, খবরসহ, নামাতেও পারেন। */
    public function test_a_recipient_with_the_key_still_gets_it(): void
    {
        $recipient = $this->member(withKey: true);
        $schedule = $this->schedule([$recipient->id]);

        $run = $this->runNow($schedule);

        $this->assertTrue($run->canBeDownloadedBy($recipient->id));
        $this->assertSame(1, $this->toldAbout($recipient));

        $this->actingAs($recipient)
            ->get(route('system_admin.reports.download', $run))
            ->assertOk();
    }

    /**
     * ⛔ মালিকের চাবি ধার দেওয়া হয় না — এমনকি সূচির পর্দা এড়িয়ে বসা
     * প্রাপকের বেলাতেও (পুরনো সারি, সরাসরি লেখা)।
     */
    public function test_the_owners_key_is_not_lent_to_a_recipient_who_never_had_one(): void
    {
        $this->assertTrue($this->owner->can(self::PERMISSION), 'মালিকের নিজেরই চাবি নেই — দাবিটা কিছু মাপছে না।');

        $stranger = $this->member(withKey: false);
        $schedule = $this->schedule([]);
        $schedule->forceFill(['recipients' => [$stranger->id]])->save();

        $run = $this->runNow($schedule);

        $this->assertFalse($run->canBeDownloadedBy($stranger->id));
        $this->assertSame(0, $this->toldAbout($stranger));
    }

    /** ⛔ সূচি বানানোর দরজা চাবিহীন প্রাপককে এখনো ফেরায়। */
    public function test_a_schedule_cannot_name_a_recipient_without_the_key(): void
    {
        $stranger = $this->member(withKey: false);

        $this->expectException(ValidationException::class);

        $this->schedule([$stranger->id]);
    }

    /**
     * ⛔ পুরনো ফাইল: তালিকায় ছিলেন, তারপর চাবি হারালেন — নামানোর দরজা
     * এখনকার অনুমতি দেখে, সেদিনের ছবি নয়।
     */
    public function test_an_old_file_closes_when_the_key_is_taken_away(): void
    {
        $recipient = $this->member(withKey: true);
        $run = $this->runNow($this->schedule([$recipient->id]));

        $this->assertTrue($run->canBeDownloadedBy($recipient->id), 'শুরুতেই তালিকায় নেই — দাবিটা কিছু মাপছে না।');

        $this->takeKeyAway($recipient);

        $this->assertFalse(
            CompanyContext::forCompany($this->company->id, fn () => $recipient->fresh()->can(self::PERMISSION)),
            'চাবিটা আসলে কাড়াই হয়নি — ৪০৩ না এলে দোষ দরজার নয়।',
        );

        /*
         * ⓘ `fresh()` — সত্যিকারের অনুরোধ প্রতিবার ব্যবহারকারীকে ডাটাবেজ
         * থেকে পড়ে। ⚠️ পুরনো অবজেক্ট দিলে তার মেমরিতে রয়ে যাওয়া চাবিটাই
         * মাপা হত, আর দাবিটা দরজা নয়, পরীক্ষার নিজের ভুল দেখাত।
         */
        $this->actingAs($recipient->fresh())
            ->get(route('system_admin.reports.download', $run))
            ->assertForbidden();
    }

    /** ⛔ নিষ্ক্রিয় প্রাপক পান না। */
    public function test_an_inactive_recipient_gets_nothing(): void
    {
        $recipient = $this->member(withKey: true);
        $schedule = $this->schedule([$recipient->id]);
        $recipient->forceFill(['is_active' => false])->save();

        $run = $this->runNow($schedule);

        $this->assertFalse($run->canBeDownloadedBy($recipient->id));
        $this->assertSame(0, $this->toldAbout($recipient));
    }

    /**
     * ⓘ বাদ পড়া প্রাপক আর কলাম ছাঁটেন না — ফাইলটা যাঁরা সত্যিই পাবেন
     * তাঁদের চোখে বানানো।
     */
    public function test_a_dropped_recipient_no_longer_narrows_the_columns(): void
    {
        $this->assertTrue($this->owner->can('inventory.report'), 'মালিক নিজেই cost দেখেন না — দাবিটা কিছু মাপছে না।');

        $recipient = $this->member(withKey: true);   // cost কলামের চাবি (inventory.report) নেই
        $schedule = $this->schedule([$recipient->id]);
        $this->takeKeyAway($recipient);

        $run = $this->runNow($schedule);

        $this->assertStringContainsString('Cost', (string) Storage::disk('local')->get((string) $run->file_path));
    }
}
