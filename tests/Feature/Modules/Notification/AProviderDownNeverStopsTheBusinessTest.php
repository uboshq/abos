<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Notifications\Channels\EmailChannel;
use App\Core\Notifications\DeliveryResult;
use App\Core\Notifications\DeliveryService;
use App\Core\Services\NotificationService;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notification;
use App\Models\NotificationAuditLog;
use App\Models\NotificationDeliveryAttempt;
use App\Models\NotificationJob;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Modules\Notification\Support\ScriptedChannel;
use Tests\TestCase;

/**
 * ⭐ প্রোভাইডার বন্ধ থাকলেও ব্যবসা থামে না — আর আবার-চেষ্টা জানে কোথায় থামতে হয় (মালিকের স্পেক §১০, §১৪; ধাপ ২)।
 *
 * ⭐ দাবি:
 *   · প্রোভাইডার ভেঙে পড়লে (ব্যতিক্রম ছুড়লেও) ব্যবসার লেনদেন পাকা হয়, ঘণ্টায় খবর আসে, আর সারিটা আবার-চেষ্টার জন্য থাকে
 *   · সাময়িক ভুলে backoff মেনে আবার চেষ্টা, সর্বোচ্চ সংখ্যায় থেমে ব্যর্থ-তালিকা — তার পরে আর অন্ধ চেষ্টা নয়
 *   · স্থায়ী ভুলে সাথে সাথে ব্যর্থ-তালিকা
 *   · backoff দ্বিগুণ হয়, ছয় ঘণ্টায় থামে, আর Retry-After-এর আগে নয়
 *   · হাতে আবার চেষ্টা আর বাতিল নিজের চাবিতে, বাতিলে কারণ লাগে, আর দুইটাই নিরীক্ষায়
 *   · চেষ্টার ভুলের লেখায় ঠিকানা, টোকেন বা চাবি থাকে না
 */
final class AProviderDownNeverStopsTheBusinessTest extends TestCase
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

        // ⚠️ পাঠাচ্ছেন মালিক — নিজের কাজের খবর নিজে পান না, তাই কেরানির কাছে
        $this->actingAs($this->owner);
    }

    public function test_a_provider_that_breaks_never_fails_the_business_transaction_and_the_bell_still_rings(): void
    {
        $mail = $this->channel([new RuntimeException('smtp went away')]);

        $bell = DB::transaction(function () {
            $this->company->forceFill(['legal_name' => 'ব্যবসা চলছে'])->save();

            return app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'অনুমোদন ফেরত এল');
        });

        $this->assertSame('ব্যবসা চলছে', $this->company->fresh()->legal_name, '⛔ প্রোভাইডারের ভুলে ব্যবসার লেনদেন উল্টে গেল');
        $this->assertNotNull($bell, '⛔ প্রোভাইডার বন্ধ থাকায় ঘণ্টার খবরও হারাল');
        $this->assertTrue(Notification::query()->whereKey($bell->id)->exists());
        $this->assertSame(1, $mail->calls, '⛔ পাকা হওয়ার পরে একবারও পাঠানোর চেষ্টা হলো না');

        $job = NotificationJob::query()->where('notification_id', $bell->id)->firstOrFail();
        $this->assertSame(NotificationJob::RETRYING, $job->status, '⛔ ভেঙে পড়া চেষ্টার সারি আবার-চেষ্টায় গেল না');
        $this->assertSame(DeliveryResult::TRANSIENT, NotificationDeliveryAttempt::query()->where('job_id', $job->id)->value('outcome'));

        // ⓘ মাধ্যম বেছে নেওয়াটাই ভাঙলেও (প্রোভাইডারের খোঁজ বন্ধ) — ব্যবসা আর ঘণ্টা চলে
        $mail->breaksOnReach = true;
        $second = DB::transaction(fn () => app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'আরেকটা ফেরত'));
        $this->assertNotNull($second);
        $this->assertTrue(Notification::query()->whereKey($second->id)->exists());
    }

    public function test_temporary_errors_back_off_and_stop_at_the_ceiling(): void
    {
        app(SettingsService::class)->set('notification.max_attempts', 3);
        app(SettingsService::class)->flush();
        $mail = $this->channel([DeliveryResult::transient('451 try again later')]);

        $bell = app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'ফেরত');
        $job = NotificationJob::query()->where('notification_id', $bell->id)->firstOrFail();
        $first = $job->next_attempt_at;
        $this->assertNotNull($first);
        $this->assertGreaterThanOrEqual(47, now()->diffInSeconds($first), '⛔ প্রথম আবার-চেষ্টা backoff ছাড়াই');

        // ⓘ সময় না হলে আবার চেষ্টা নয়
        app(DeliveryService::class)->retryDue();
        $this->assertSame(1, $mail->calls, '⛔ সময়ের আগেই আবার চেষ্টা');

        $this->travel(3)->minutes();
        app(DeliveryService::class)->retryDue();
        $this->assertSame(2, $mail->calls);
        $this->assertSame(NotificationJob::RETRYING, $job->fresh()->status);

        $this->travel(5)->minutes();
        app(DeliveryService::class)->retryDue();
        $this->assertSame(3, $mail->calls);
        $this->assertSame(NotificationJob::DEAD, $job->fresh()->status, '⛔ সর্বোচ্চ চেষ্টার পরেও ব্যর্থ-তালিকায় গেল না');
        $this->assertNotNull($job->fresh()->dead_at);

        $this->travel(1)->days();
        app(DeliveryService::class)->retryDue();
        $this->assertSame(3, $mail->calls, '⛔ ব্যর্থ-তালিকার সারিতেও অন্ধ চেষ্টা চলল');
        $this->assertSame(3, NotificationDeliveryAttempt::query()->where('job_id', $job->id)->count());
    }

    public function test_a_permanent_error_goes_straight_to_the_failed_list(): void
    {
        $mail = $this->channel([DeliveryResult::permanent('550 no such mailbox')]);

        $bell = app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'ফেরত');
        $job = NotificationJob::query()->where('notification_id', $bell->id)->firstOrFail();

        $this->assertSame(NotificationJob::DEAD, $job->status, '⛔ স্থায়ী ভুলেও আবার চেষ্টার জন্য রাখা হলো');
        $this->assertSame(DeliveryResult::PERMANENT, $job->error_kind);

        $this->travel(1)->days();
        app(DeliveryService::class)->retryDue();
        $this->assertSame(1, $mail->calls, '⛔ স্থায়ী ভুলের পরেও আবার চেষ্টা');
    }

    public function test_backoff_doubles_stops_at_six_hours_and_waits_for_retry_after(): void
    {
        $this->assertSame(60, DeliveryService::backoff(1, null, 1.0));
        $this->assertSame(120, DeliveryService::backoff(2, null, 1.0));
        $this->assertSame(480, DeliveryService::backoff(4, null, 1.0));
        $this->assertSame(21600, DeliveryService::backoff(20, null, 1.0), '⛔ backoff ছয় ঘণ্টায় থামল না');
        $this->assertSame(900, DeliveryService::backoff(1, 900, 1.0), '⛔ প্রোভাইডারের Retry-After-এর আগেই আবার চেষ্টা');
        $this->assertSame(48, DeliveryService::backoff(1, null, 0.8));

        foreach (range(1, 20) as $i) {
            $this->assertThat(DeliveryService::backoff(3), $this->logicalAnd($this->greaterThanOrEqual(192), $this->lessThanOrEqual(288)));
        }
    }

    public function test_retry_and_cancel_by_hand_need_their_key_and_are_audited(): void
    {
        $mail = $this->channel([DeliveryResult::permanent('550 no such mailbox')]);
        $bell = app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'ফেরত');
        $job = NotificationJob::query()->where('notification_id', $bell->id)->firstOrFail();
        $this->assertSame(NotificationJob::DEAD, $job->status);

        $this->actingAs($this->clerk)->get(route('notification.deliveries.failed'))->assertForbidden();
        $this->actingAs($this->clerk)->post(route('notification.deliveries.retry', $job))->assertForbidden();

        // ⓘ দেখার চাবি আছে, আবার-চেষ্টার নেই — তালিকা খোলে, বোতাম নেই, কাজ ৪০৩
        $this->grant($this->clerk, 'notification.deliveries');
        $this->actingAs($this->clerk->fresh())->get(route('notification.deliveries.failed'))->assertOk()
            ->assertSee('data-job="'.$job->id.'"', false)
            ->assertDontSee(route('notification.deliveries.retry', $job), false);
        $this->actingAs($this->clerk->fresh())->post(route('notification.deliveries.retry', $job))->assertForbidden();
        $this->actingAs($this->clerk->fresh())->post(route('notification.deliveries.cancel', $job), ['resolution' => 'x'])->assertForbidden();
        $this->assertSame(NotificationJob::DEAD, $job->fresh()->status);

        // ⭐ মালিক আবার চেষ্টা করেন — এবার প্রোভাইডার ঠিক আছে
        $mail->then([DeliveryResult::sent('msg-42')]);
        $this->actingAs($this->owner)->get(route('notification.deliveries.failed'))->assertOk()
            ->assertSee(route('notification.deliveries.retry', $job), false);
        $this->actingAs($this->owner)->post(route('notification.deliveries.retry', $job))->assertRedirect();
        $this->assertSame(NotificationJob::SENT, $job->fresh()->status, '⛔ হাতে আবার চেষ্টায় খবরটা গেল না');
        $this->assertSame('msg-42', $job->fresh()->provider_ref);
        $this->assertSame(1, NotificationAuditLog::query()->where('action', 'delivery_retry')->where('actor_id', $this->owner->id)->count());

        // ⓘ পৌঁছে যাওয়া সারি আবার চেষ্টা বা বাতিল হয় না
        $this->post(route('notification.deliveries.retry', $job))->assertSessionHas('failed');

        // ⭐ বাতিলে কারণ লাগে
        $mail->then([DeliveryResult::permanent('550 no such mailbox')]);
        $other = app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'আরেকটা');
        $dead = NotificationJob::query()->where('notification_id', $other->id)->firstOrFail();
        $this->post(route('notification.deliveries.cancel', $dead), ['resolution' => ''])->assertSessionHasErrors('resolution');
        $this->assertSame(NotificationJob::DEAD, $dead->fresh()->status);
        $this->post(route('notification.deliveries.cancel', $dead), ['resolution' => 'ঠিকানা ভুল, কর্মী জানেন'])->assertRedirect();
        $this->assertSame(NotificationJob::CANCELLED, $dead->fresh()->status);
        $this->assertSame($this->owner->id, $dead->fresh()->resolved_by);
        $this->assertSame(1, NotificationAuditLog::query()->where('action', 'delivery_cancel')->count());
    }

    public function test_errors_kept_from_attempts_carry_no_addresses_tokens_or_keys(): void
    {
        $this->channel([DeliveryResult::transient(
            '535 auth failed for boss@example.com token tok-fake-abcdefghijklmnopqrstuvwxyz0123 at https://api.example.com/send?api_key=hunter2'
        )]);

        $bell = app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'ফেরত');
        $job = NotificationJob::query()->where('notification_id', $bell->id)->firstOrFail();
        $error = (string) NotificationDeliveryAttempt::query()->where('job_id', $job->id)->value('error');

        foreach (['boss@example.com', 'tok-fake-abcdefghijklmnopqrstuvwxyz0123', 'hunter2'] as $secret) {
            $this->assertStringNotContainsString($secret, $error, '⛔ চেষ্টার লগে গোপন জিনিস: '.$secret);
            $this->assertStringNotContainsString($secret, (string) $job->last_error);
        }
        $this->assertStringContainsString('535 auth failed', $error, 'ভুলের কাজের অংশটা থাকে');

        $page = $this->get(route('notification.deliveries.logs'))->assertOk()->assertSee('535 auth failed');
        $page->assertDontSee('hunter2')->assertDontSee('boss@example.com');
    }

    private function channel(array $script): ScriptedChannel
    {
        config(['mail.default' => 'smtp']);
        $channel = new ScriptedChannel($script);
        $this->app->instance(EmailChannel::class, $channel);

        return $channel;
    }

    private function grant(User $user, string $permission): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate($permission, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
