<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\NotificationChannel;
use App\Models\NotificationJob;
use App\Models\NotificationProviderEvent;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as Notices;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ⭐ প্রোভাইডার জানায় খবরটা পৌঁছাল কি না — রসিদ, bounce; স্বাক্ষর ছাড়া কিছুই নয় (মালিকের স্পেক §৭, §১১, §১৩)।
 *
 * ⭐ দাবি:
 *   · ইমেইলের সারিতে আমাদের নিজের রেফারেন্স বসে — ফেরত-খবর এটা দিয়েই মেলে
 *   · ঠিক স্বাক্ষরে bounce → সারি ব্যর্থ-তালিকায়, স্থায়ী ভুল, কারণ ছাঁটা; রসিদ → পৌঁছানোর সময়
 *   · একই ফেরত-খবর দুইবার এলে একবারই
 *   · ভুল স্বাক্ষর, পুরনো সময়, চাবি না বসানো → ৪০১ আর কিছুই বদলায় না; অচেনা কোম্পানি → ৪০৪
 */
final class TheProviderSaysWhetherItArrivedTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec-test-1234567890';

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
        config(['mail.default' => 'smtp']);
    }

    public function test_a_signed_bounce_moves_the_delivery_to_the_failed_list_and_a_receipt_records_arrival(): void
    {
        Notices::fake();
        $this->secret();

        $bell = app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'চিঠির খবর');
        $job = NotificationJob::query()->where('notification_id', $bell->id)->where('channel', 'email')->firstOrFail();
        $this->assertSame('abos-'.$bell->public_id, $job->provider_ref, '⛔ ইমেইলের সারিতে নিজের রেফারেন্স বসল না');
        $this->assertSame(NotificationJob::SENT, $job->status);

        $bounce = ['events' => [['provider_ref' => $job->provider_ref, 'event' => 'bounced', 'reason' => '550 no such user boss@example.com', 'at' => now()->toIso8601String()]]];
        $this->provider($bounce)->assertOk()->assertJsonPath('data.accepted', 1);

        $job->refresh();
        $this->assertSame(NotificationJob::DEAD, $job->status, '⛔ bounce-এর পরেও সারি "পৌঁছেছে"');
        $this->assertSame('bounced', $job->receipt);
        $this->assertSame('permanent', $job->error_kind);
        $this->assertStringNotContainsString('boss@example.com', (string) $job->last_error, '⛔ ফেরত-খবরের ঠিকানা খাতায় উঠল');

        // ⓘ একই ফেরত-খবর আবার — একবারই
        $this->provider($bounce)->assertOk()->assertJsonPath('data.accepted', 0);
        $this->assertSame(1, NotificationProviderEvent::query()->count());

        // ⓘ আরেকটা খবরের রসিদ
        $other = app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'দ্বিতীয় চিঠি');
        $second = NotificationJob::query()->where('notification_id', $other->id)->firstOrFail();
        $this->provider(['events' => [['provider_ref' => $second->provider_ref, 'event' => 'delivered', 'at' => now()->toIso8601String()]]])->assertOk();
        $this->assertNotNull($second->fresh()->delivered_at);
        $this->assertSame(NotificationJob::SENT, $second->fresh()->status);
    }

    public function test_without_a_good_signature_nothing_changes(): void
    {
        // ⓘ নিজে থেকে চিঠির সারি না বসুক — সারিটা হাতে বসানো
        config(['mail.default' => 'log']);
        $job = NotificationJob::query()->create(['company_id' => $this->company->id, 'notification_id' => app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'x')->id,
            'user_id' => $this->clerk->id, 'channel' => 'email', 'status' => NotificationJob::SENT, 'attempts' => 1, 'max_attempts' => 5, 'provider_ref' => 'abos-fixed']);
        $body = ['events' => [['provider_ref' => 'abos-fixed', 'event' => 'bounced']]];

        // ⓘ গোপন চাবি বসানো নেই — কিছুই মানা হয় না
        $this->provider($body)->assertStatus(401);

        $this->secret();
        $this->provider($body, secret: 'wrong-secret')->assertStatus(401);
        $this->provider($body, timestamp: time() - 3600)->assertStatus(401);
        $this->postJson('/api/v1/notification-callbacks/'.$this->company->public_id.'/email', $body)->assertStatus(401);
        $this->postJson('/api/v1/notification-callbacks/not-a-company/email', $body)->assertNotFound();

        $this->assertSame(NotificationJob::SENT, $job->fresh()->status, '⛔ স্বাক্ষর ছাড়া ফেরত-খবর সারি বদলাল');
        $this->assertSame(0, NotificationProviderEvent::query()->withoutGlobalScopes()->count());
    }

    private function secret(): void
    {
        NotificationChannel::query()->updateOrCreate(
            ['company_id' => $this->company->id, 'channel' => 'email'],
            ['enabled' => true, 'credentials' => ['webhook_secret' => self::SECRET]],
        );
    }

    /** @param  array<string, mixed>  $payload */
    private function provider(array $payload, ?string $secret = null, ?int $timestamp = null): TestResponse
    {
        $body = json_encode($payload);
        $timestamp ??= time();
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? self::SECRET);

        return $this->call('POST', '/api/v1/notification-callbacks/'.$this->company->public_id.'/email', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ABOS_TIMESTAMP' => (string) $timestamp, 'HTTP_X_ABOS_SIGNATURE' => $signature,
        ], $body);
    }
}
