<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Notifications\RetentionService;
use App\Core\Services\DataScope;
use App\Core\Services\NotificationService;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Notification;
use App\Models\NotificationAuditLog;
use App\Models\NotificationDeliveryAttempt;
use App\Models\NotificationEvent;
use App\Models\NotificationJob;
use App\Models\User;
use App\Models\UserDataScope;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ রিপোর্ট, ড্যাশবোর্ড, ওপরে পাঠানো, আর্কাইভ আর রাখার মেয়াদ (মালিকের স্পেক §৩, §৪, §১৪, §১৭; ধাপ ৪)।
 *
 * ⭐ দাবি:
 *   · ১৭টা রিপোর্টই খোলে, চাবি ছাড়া ৪০৩; সংখ্যা ঘণ্টার সাথে মেলে; শাখায় আটকানো মানুষ অন্য শাখার খবর গোনেন না
 *   · রপ্তানি আলাদা চাবিতে — চাবি না থাকলে ঠিকানায় লিখলেও ফাইল নামে না
 *   · ড্যাশবোর্ড নিজের চাবিতে, সংখ্যা ঠিক
 *   · ওপরে পাঠানোর পাতা অনুমোদন ইঞ্জিনের অবস্থা দেখায়; শাখায় আটকানো মানুষের কাছে বন্ধ
 *   · আর্কাইভ: পুরনো পড়া খবর যায়, না-পড়া থাকে; মোছা কেবল মেয়াদ বসালে; নিরীক্ষার খাতা কখনো নয়; দুইবার চালালে একই ফল
 *   · আর্কাইভের পর্দা, আর তার রপ্তানি আলাদা চাবিতে
 */
final class TheReportsCountWhatTheBellRangTest extends TestCase
{
    use RefreshDatabase;

    private const SLUGS = [
        'summary', 'user-wise', 'module-wise', 'priority-wise', 'channel-delivery', 'delivery-status', 'read-unread',
        'attempt-history', 'provider-errors', 'retry-dead-letter', 'rule-execution', 'template-usage', 'escalation',
        'latency', 'channel-availability', 'suppression', 'audit',
    ];

    private Company $company;

    private User $owner;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->branch('MMS')->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->clerk = User::factory()->create(['name' => 'কেরানি', 'is_active' => true, 'current_company_id' => $this->company->id]);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->actingAs($this->owner);
    }

    public function test_every_report_opens_for_its_key_and_counts_what_the_bell_rang(): void
    {
        $notify = app(NotificationService::class);
        $notify->send($this->clerk, 'approval.rejected', 'এই শাখার খবর', about: $this->paper('MMS'));
        $notify->send($this->clerk, 'backup.failed', 'অন্য শাখার খবর', about: $this->paper('NTK'));
        $read = $notify->send($this->clerk, 'report_ready', 'পড়া খবর', about: $this->paper('MMS'));
        $notify->markRead($read, $this->clerk);

        foreach (self::SLUGS as $slug) {
            $this->get(route('notification.report.show', ['slug' => $slug]))->assertOk();
        }

        $this->get(route('notification.report.show', ['slug' => 'read-unread']))->assertOk()
            ->assertSee(__('core.notify.category.system'));

        $this->actingAs($this->clerk)->get(route('notification.report.show', ['slug' => 'summary']))->assertForbidden();

        // ⓘ শাখায় আটকানো মানুষ — কেবল নিজের শাখার খবর গোনেন
        $this->grant($this->clerk, 'notification.reports');
        $this->limit($this->clerk, 'MMS');
        $html = $this->actingAs($this->clerk->fresh())->get(route('notification.report.show', ['slug' => 'priority-wise', 'from' => 'all']))->assertOk()->getContent();
        $this->assertStringNotContainsString(__('core.notify.priority.critical'), $this->table($html), '⛔ শাখায় আটকানো মানুষ অন্য শাখার খবর গুনলেন');
        $this->assertStringContainsString(__('core.notify.priority.normal'), $this->table($html));

        // ⓘ গোটা কোম্পানির রিপোর্ট শাখায় আটকানো মানুষের কাছে বন্ধ
        $this->actingAs($this->clerk->fresh())->get(route('notification.report.show', ['slug' => 'audit']))->assertForbidden();
    }

    public function test_exporting_needs_its_own_key(): void
    {
        app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'রপ্তানির খবর');

        $file = $this->get(route('notification.report.show', ['slug' => 'summary', 'export' => 'csv']))->assertOk();
        $this->assertStringContainsString('attachment', (string) $file->headers->get('Content-Disposition'), 'চাবিওয়ালা মালিক ফাইল পান না');

        $this->grant($this->clerk, 'notification.reports');
        $page = $this->actingAs($this->clerk->fresh())->get(route('notification.report.show', ['slug' => 'summary', 'export' => 'csv']));
        $this->assertStringNotContainsString('attachment', (string) $page->headers->get('Content-Disposition'), '⛔ রপ্তানির চাবি ছাড়াই ফাইল নামল');
    }

    public function test_the_dashboard_answers_to_its_key_and_counts_right(): void
    {
        app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'ড্যাশবোর্ডের খবর');

        $this->get(route('module.dashboard', ['module' => 'notification']))->assertOk()
            ->assertSee(__('notification::dashboard.total'))->assertSee('ড্যাশবোর্ডের খবর');
        $this->actingAs($this->clerk)->get(route('module.dashboard', ['module' => 'notification']))->assertForbidden();
    }

    public function test_the_escalation_page_shows_the_approval_engines_clock_and_is_whole_company(): void
    {
        $late = $this->approval(now()->subHour());
        $waiting = $this->approval(now()->addDay());

        $this->get(route('notification.escalations.index'))->assertOk()
            ->assertSee('data-escalation="'.$late.'" data-state="late"', false)
            ->assertSee('data-escalation="'.$waiting.'"', false);
        $this->get(route('notification.escalations.index', ['state' => 'late']))->assertOk()
            ->assertDontSee('data-escalation="'.$waiting.'"', false);

        $this->actingAs($this->clerk)->get(route('notification.escalations.index'))->assertForbidden();
        $this->grant($this->clerk, 'notification.escalations');
        $this->limit($this->clerk, 'MMS');
        $this->actingAs($this->clerk->fresh())->get(route('notification.escalations.index'))->assertForbidden();
    }

    public function test_retention_archives_old_read_notices_purges_only_when_set_and_never_the_audit_log(): void
    {
        $notify = app(NotificationService::class);
        $old = $notify->send($this->clerk, 'approval.rejected', 'পুরনো পড়া খবর');
        $oldUnread = $notify->send($this->clerk, 'approval.rejected', 'পুরনো না-পড়া খবর');
        $fresh = $notify->send($this->clerk, 'approval.rejected', 'নতুন পড়া খবর');
        $notify->markRead($old, $this->clerk);
        $notify->markRead($fresh, $this->clerk);
        $this->age($old, 120);
        $this->age($oldUnread, 120);

        $job = NotificationJob::query()->create(['company_id' => $this->company->id, 'notification_id' => $old->id, 'event_id' => $old->event_id,
            'user_id' => $this->clerk->id, 'channel' => 'email', 'status' => NotificationJob::SENT, 'attempts' => 1, 'max_attempts' => 5]);
        NotificationDeliveryAttempt::query()->create(['company_id' => $this->company->id, 'job_id' => $job->id, 'attempt' => 1, 'channel' => 'email', 'outcome' => 'sent']);
        DB::table('notification_jobs')->where('id', $job->id)->update(['created_at' => now()->subDays(400), 'updated_at' => now()->subDays(400)]);
        DB::table('notification_delivery_attempts')->update(['created_at' => now()->subDays(400)]);
        DB::table('notification_audit_logs')->insert(['company_id' => $this->company->id, 'action' => 'read_all', 'outcome' => 'done',
            'created_at' => now()->subDays(900), 'public_id' => (string) Str::uuid()]);

        $first = app(RetentionService::class)->runFor($this->company->id);
        $this->assertNotNull($old->fresh()->archived_at, '⛔ পুরনো পড়া খবর আর্কাইভে গেল না');
        $this->assertNull($oldUnread->fresh()->archived_at, '⛔ না-পড়া খবর নিজে আর্কাইভে গেল');
        $this->assertNull($fresh->fresh()->archived_at, '⛔ নতুন খবর আর্কাইভে গেল');
        $this->assertNotNull(NotificationEvent::query()->find($old->event_id)->archived_at, 'সব প্রাপকের খবর আর্কাইভে, তাই ঘটনাও');
        $this->assertSame(0, $first['purged'], '⛔ মেয়াদ না বসিয়েই কিছু মোছা হলো');
        $this->assertSame(1, NotificationDeliveryAttempt::query()->count());

        // ⓘ দ্বিতীয়বার — একই অবস্থা
        $second = app(RetentionService::class)->runFor($this->company->id);
        $this->assertSame(0, $second['archived'] + $second['events'] + $second['purged'], '⛔ দ্বিতীয়বার চালালে আবার কিছু বদলাল');

        app(SettingsService::class)->set('notification.retention_days', 365);
        app(SettingsService::class)->flush();
        app(RetentionService::class)->runFor($this->company->id);

        $this->assertSame(0, NotificationDeliveryAttempt::query()->count(), '⛔ মেয়াদ পেরোনো চেষ্টার লগ মোছা হলো না');
        $this->assertNull(NotificationJob::query()->find($job->id));
        $this->assertNotNull(Notification::query()->find($fresh->id), '⛔ নতুন খবর মুছে গেল');
        $this->assertNotNull(Notification::query()->find($oldUnread->id), '⛔ না-পড়া খবর মুছে গেল');
        $this->assertSame(1, NotificationAuditLog::query()->where('action', 'read_all')->count(), '⛔ নিরীক্ষার খাতা মুছে গেল');

        // ⓘ ৩০ দিন বসালেও কমপক্ষে ৯০ দিন রাখা হয়
        app(SettingsService::class)->set('notification.retention_days', 30);
        app(SettingsService::class)->flush();
        $this->age($fresh, 60);
        Notification::query()->whereKey($fresh->id)->update(['archived_at' => now()]);
        app(RetentionService::class)->runFor($this->company->id);
        $this->assertNotNull(Notification::query()->find($fresh->id), '⛔ ৯০ দিনের কম পুরনো খবর মুছে গেল');
    }

    public function test_the_archive_screen_and_its_export_answer_to_their_keys(): void
    {
        $sent = app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'আর্কাইভের খবর');
        NotificationEvent::query()->whereKey($sent->event_id)->update(['archived_at' => now()]);

        $this->get(route('notification.archive.index'))->assertOk()->assertSee('আর্কাইভের খবর')->assertSee(route('notification.center.show', $sent->event_id), false);
        $this->actingAs($this->clerk)->get(route('notification.archive.index'))->assertForbidden();

        $this->grant($this->clerk, 'notification.archive');
        $page = $this->actingAs($this->clerk->fresh())->get(route('notification.archive.index', ['export' => 'csv']))->assertOk();
        $this->assertStringNotContainsString('attachment', (string) $page->headers->get('Content-Disposition'), '⛔ নামানোর চাবি ছাড়াই আর্কাইভ নামল');

        $this->grant($this->clerk, 'notification.archive.export');
        $file = $this->actingAs($this->clerk->fresh())->get(route('notification.archive.index', ['export' => 'csv']))->assertOk();
        $this->assertStringContainsString('attachment', (string) $file->headers->get('Content-Disposition'));
    }

    /** ⓘ রিপোর্টের টেবিল অংশ — মাথার মেনু আর ছাঁকনির নাম বাদ দিয়ে */
    private function table(string $html): string
    {
        $start = strpos($html, '<tbody');

        return $start === false ? '' : substr($html, $start, (int) strpos($html, '</tbody>', $start) - $start);
    }

    private function approval(\DateTimeInterface $due): int
    {
        return (int) DB::table('approvals')->insertGetId([
            'company_id' => $this->company->id, 'approvable_type' => 'test', 'approvable_id' => 1, 'module' => 'purchase',
            'action' => 'order', 'amount' => '125000.00', 'status' => 'pending', 'current_level' => 1, 'assigned_to' => $this->owner->id,
            'requested_by' => $this->clerk->id, 'requested_reason' => 'পরীক্ষার অনুমোদন', 'requested_at' => now()->subDay(), 'due_at' => $due,
            'created_at' => now(), 'updated_at' => now(), 'public_id' => (string) Str::uuid(),
        ]);
    }

    private function age(Notification $note, int $days): void
    {
        DB::table('notifications')->where('id', $note->id)->update(['created_at' => now()->subDays($days)]);
        DB::table('notification_events')->where('id', $note->event_id)->update(['created_at' => now()->subDays($days)]);
    }

    private function grant(User $user, string $permission): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate($permission, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function limit(User $user, string $code): void
    {
        UserDataScope::query()->create([
            'company_id' => $this->company->id, 'user_id' => $user->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $this->branch($code)->id,
        ]);
        app(DataScope::class)->forget();
    }

    private function paper(string $code): Model
    {
        $paper = new class extends Model
        {
            protected $guarded = [];
        };

        return $paper->forceFill(['id' => 7, 'branch_id' => $this->branch($code)->id]);
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
