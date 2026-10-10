<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Services\NotificationService;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notification;
use App\Models\NotificationAuditLog;
use App\Models\NotificationEvent;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ বিজ্ঞপ্তির পর্দাগুলো — ঘণ্টা, আমার বিজ্ঞপ্তি, বিজ্ঞপ্তি কেন্দ্র, নিরীক্ষা (মালিকের স্পেক §২, §৪, §৯, §১৩; ধাপ ১)।
 *
 * ⭐ দাবি:
 *   · ঘণ্টায় জরুরি খবরের আলাদা চিহ্ন, ছাঁকনির ট্যাব, "সব বিজ্ঞপ্তি"-র দরজা; polling ডিফল্টে বন্ধ, সুইচে চালু
 *   · আমার বিজ্ঞপ্তি: ট্যাব আর ছাঁকনি কাজ করে; বাছাগুলো একসাথে পড়া/আর্কাইভ, আর তা নিরীক্ষার খাতায়; সব পড়া খাতায়
 *   · দেখানো খবর "দেখা", খোলা নয়
 *   · বিজ্ঞপ্তি কেন্দ্র আর নিরীক্ষা কেবল নিজের চাবিতে; কেন্দ্রে কে পেলেন আর কে পড়লেন; আর্কাইভ আলাদা চাবিতে
 */
final class TheNotificationScreensAnswerOnlyToTheirKeysTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->actingAs($this->owner);
    }

    public function test_the_bell_marks_critical_news_offers_tabs_and_polls_only_when_switched_on(): void
    {
        app(NotificationService::class)->send($this->clerk, 'backup.failed', 'রাতের ব্যাকআপ ব্যর্থ');

        $page = $this->actingAs($this->clerk)->get(route('notifications.index'))->assertOk();
        $page->assertSee('data-critical', false)
            ->assertSee(__('core.notify.drawer.approval'))
            ->assertSee(route('notifications.index'), false)
            ->assertSee('data-poll-seconds="0"', false);

        $this->actingAs($this->owner);
        app(SettingsService::class)->set('notification.bell_polling', true);
        app(SettingsService::class)->flush();

        $this->actingAs($this->clerk)->get(route('notifications.index'))->assertOk()
            ->assertSee('data-poll-seconds="60"', false);
        $this->actingAs($this->clerk)->getJson(route('notifications.unread-count'))->assertOk()->assertJson(['unread' => 1]);
    }

    public function test_my_notifications_filters_and_acts_on_a_selection(): void
    {
        $notify = app(NotificationService::class);
        $a = $notify->send($this->clerk, 'approval.rejected', 'প্রথম খবর');
        $b = $notify->send($this->clerk, 'report_ready', 'দ্বিতীয় খবর');
        $c = $notify->send($this->clerk, 'backup.failed', 'তৃতীয় খবর');

        $this->actingAs($this->clerk);
        // ⓘ ঘণ্টাতেও খবরগুলো থাকে, তাই তালিকার সারি ধরে মেলানো
        $this->get(route('notifications.index', ['priority' => 'critical']))->assertOk()
            ->assertSee($this->row($c), false)->assertDontSee($this->row($b), false);
        $this->get(route('notifications.index', ['q' => 'দ্বিতীয়']))->assertOk()
            ->assertSee($this->row($b), false)->assertDontSee($this->row($a), false);

        // ⓘ পাতায় দেখানো মানে দেখা — পড়া নয়
        $this->assertNotNull($b->fresh()->seen_at, '⛔ তালিকায় দেখানো খবর "দেখা" হলো না');
        $this->assertNull($b->fresh()->read_at, '⛔ দেখানো মানেই পড়া ধরা হলো');
        $this->assertNull($a->fresh()->seen_at, '⛔ না-দেখানো খবরও "দেখা" হলো');

        $this->post(route('notifications.bulk'), ['ids' => [$a->id, $b->id], 'action' => 'archive'])->assertRedirect();
        $this->assertNotNull($a->fresh()->archived_at);
        $this->assertNull($c->fresh()->archived_at);
        $this->get(route('notifications.index'))->assertOk()->assertDontSee($this->row($a), false)->assertSee($this->row($c), false);
        $this->get(route('notifications.index', ['tab' => 'archived']))->assertOk()->assertSee($this->row($a), false);

        $this->post(route('notifications.read-all'))->assertRedirect();
        $this->assertNotNull($c->fresh()->read_at);

        $this->assertSame(1, NotificationAuditLog::query()->where('action', 'bulk_archive')->where('actor_id', $this->clerk->id)->count());
        $this->assertSame(1, NotificationAuditLog::query()->where('action', 'read_all')->where('actor_id', $this->clerk->id)->count());
    }

    public function test_the_center_and_the_audit_answer_only_to_their_keys(): void
    {
        $sent = app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'কেন্দ্রের খবর', key: 'approval:3:rejected');
        $event = NotificationEvent::query()->findOrFail($sent->event_id);

        $this->actingAs($this->clerk)->get(route('notification.center.index'))->assertForbidden();
        $this->actingAs($this->clerk)->get(route('notification.center.show', $event))->assertForbidden();
        $this->actingAs($this->clerk)->get(route('notification.audit.index'))->assertForbidden();

        $this->grant($this->clerk, 'notification.center');
        $this->actingAs($this->clerk->fresh())->get(route('notification.center.index'))->assertOk()->assertSee('কেন্দ্রের খবর');
        $this->actingAs($this->clerk->fresh())->get(route('notification.center.show', $event))->assertOk()
            ->assertSee('data-center-recipient="'.$this->clerk->id.'"', false);
        $this->actingAs($this->clerk->fresh())->post(route('notification.center.archive'), ['ids' => [$event->id], 'action' => 'archive'])
            ->assertForbidden();

        // ⓘ মালিক কেন্দ্র থেকে আর্কাইভ করেন — প্রাপকের ঘণ্টা বদলায় না
        $this->actingAs($this->owner)->post(route('notification.center.archive'), ['ids' => [$event->id], 'action' => 'archive'])->assertRedirect();
        $this->assertNotNull($event->fresh()->archived_at);
        $this->assertNull(Notification::query()->findOrFail($sent->id)->archived_at, '⛔ কেন্দ্রের আর্কাইভ প্রাপকের ঘণ্টা বদলাল');
        $this->actingAs($this->owner)->get(route('notification.audit.index'))->assertOk()
            ->assertSee(__('notification::audit.actions.center_archive'));
    }

    private function row(Notification $note): string
    {
        return 'data-notification="'.$note->id.'"';
    }

    private function grant(User $user, string $permission): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate($permission, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
