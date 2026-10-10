<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Notifications\ChannelRegistry;
use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\NotificationAuditLog;
use App\Models\NotificationChannel;
use App\Models\NotificationJob;
use App\Models\NotificationSubscription;
use App\Models\User;
use App\Notifications\NewsByMail;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as Notices;
use Tests\TestCase;

/**
 * ⭐ মাধ্যমের চাবি তালাবদ্ধ, আর অনুমোদিত প্রোভাইডার ছাড়া কিছু "সংযুক্ত" নয় (মালিকের স্পেক §৪, §৭, §১৩; ধাপ ২)।
 *
 * ⭐ দাবি:
 *   · SMS কখনো সংযুক্ত দেখায় না, আর কোনো খবর SMS-এ যায় না — চালু করে প্রোভাইডারের নাম লিখলেও
 *   · গোপন চাবি ডেটাবেসে এনক্রিপ্ট করা, পর্দায়-নিরীক্ষায়-মডেলের JSON-এ কখনো নয়; ফাঁকা পাঠালে আগেরটাই থাকে
 *   · মাধ্যম আর ডেলিভারির পর্দা কেবল নিজের চাবিতে
 *   · Web Push সাবস্ক্রিপশন কেবল সংযুক্ত মাধ্যমে, https ঠিকানায়, চাবি এনক্রিপ্ট করা; অন্য কেউ বন্ধ করতে পারেন না
 *   · সংযোগ পরীক্ষা পরীক্ষাকারীর নিজের কাছে যায়, ফল মাধ্যমের সারিতে আর নিরীক্ষায়
 */
final class TheChannelKeysStayLockedTest extends TestCase
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
        $this->clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id, 'mobile' => '01700000000']);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->actingAs($this->owner);
    }

    public function test_sms_never_shows_connected_and_never_carries_a_notification(): void
    {
        $this->put(route('notification.channels.update', 'sms'), [
            'enabled' => '1', 'provider' => 'sslwireless', 'sender_id' => 'ABOS',
            'secrets' => ['api_key' => 'k-1', 'api_secret' => 's-1'],
        ])->assertRedirect();

        $this->assertFalse(app(ChannelRegistry::class)->connected($this->company->id, NotificationChannel::SMS), '⛔ অনুমোদিত প্রোভাইডার ছাড়া SMS সংযুক্ত');
        $this->get(route('notification.channels.index'))->assertOk()
            ->assertSee('data-channel="sms" data-connected="0"', false);
        $this->get(route('notification.channels.edit', 'sms'))->assertOk()->assertSee('data-connected="0"', false)
            ->assertSee(__('notification::channel.sms_note'));

        $bell = app(NotificationService::class)->send($this->clerk, 'backup.failed', 'রাতের ব্যাকআপ ব্যর্থ');
        $this->assertNotNull($bell);
        $this->assertSame(0, NotificationJob::query()->where('channel', NotificationChannel::SMS)->count(), '⛔ খবর SMS-এর পথে গেল');

        $this->post(route('notification.channels.test', 'sms'))->assertSessionHas('failed');
        $row = NotificationChannel::query()->where('channel', 'sms')->firstOrFail();
        $this->assertFalse($row->last_check_ok);
        $this->assertSame(1, NotificationAuditLog::query()->where('action', 'channel_test')->where('outcome', 'failed')->count());
    }

    public function test_channel_secrets_are_encrypted_and_never_shown_back(): void
    {
        $secret = 'TOPSECRET-API-KEY-12345';

        $this->put(route('notification.channels.update', 'sms'), [
            'enabled' => '0', 'sender_id' => 'ABOS', 'secrets' => ['api_key' => $secret],
        ])->assertRedirect();

        $raw = (string) DB::table('notification_channels')->where('channel', 'sms')->value('credentials');
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString($secret, $raw, '⛔ গোপন চাবি ডেটাবেসে খোলা লেখায়');

        $row = NotificationChannel::query()->where('channel', 'sms')->firstOrFail();
        $this->assertSame($secret, $row->secret('api_key'));
        $this->assertStringNotContainsString($secret, json_encode($row->toArray()), '⛔ মডেলের JSON-এ গোপন চাবি');

        $this->get(route('notification.channels.edit', 'sms'))->assertOk()
            ->assertDontSee($secret)->assertSee(__('notification::channel.secret_saved'));

        // ⓘ ফাঁকা পাঠালে আগেরটাই থাকে
        $this->put(route('notification.channels.update', 'sms'), ['sender_id' => 'ABOS2', 'secrets' => ['api_key' => '']])->assertRedirect();
        $this->assertSame($secret, $row->fresh()->secret('api_key'), '⛔ ফাঁকা ঘর আগের চাবি মুছে দিল');

        // ⓘ ইমেইলে গোপন ঘর নেই — পাঠালেও বসে না
        $this->put(route('notification.channels.update', 'email'), ['enabled' => '1', 'secrets' => ['api_key' => 'sneaky']])->assertRedirect();
        $this->assertNull(NotificationChannel::query()->where('channel', 'email')->firstOrFail()->credentials);

        $this->assertStringNotContainsString($secret, (string) DB::table('audit_trails')->get()->toJson(), '⛔ নিরীক্ষায় গোপন চাবি');
        $this->assertStringNotContainsString($secret, (string) DB::table('notification_audit_logs')->get()->toJson());
    }

    public function test_channel_and_delivery_screens_answer_only_to_their_keys(): void
    {
        $this->actingAs($this->clerk);
        foreach ([
            route('notification.channels.index'), route('notification.channels.edit', 'email'),
            route('notification.deliveries.queue'), route('notification.deliveries.failed'),
            route('notification.deliveries.logs'), route('notification.deliveries.health'),
        ] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->put(route('notification.channels.update', 'email'), ['enabled' => '1'])->assertForbidden();
        $this->post(route('notification.channels.vapid'))->assertForbidden();
        $this->post(route('notification.channels.test', 'email'))->assertForbidden();
        $this->assertSame(0, NotificationChannel::query()->count());

        $this->actingAs($this->owner);
        $this->get(route('notification.channels.index'))->assertOk();
        $this->get(route('notification.deliveries.queue'))->assertOk();
        $this->get(route('notification.deliveries.logs'))->assertOk();
        $this->get(route('notification.deliveries.health'))->assertOk()
            ->assertSee('data-health="email"', false)->assertSee('data-health="sms"', false);
    }

    public function test_web_push_subscriptions_need_a_connected_channel_and_keep_their_keys_encrypted(): void
    {
        $sub = ['endpoint' => 'https://push.example.com/send/abc123', 'keys' => ['p256dh' => 'BPublicKeyOfTheBrowser0123456789', 'auth' => 'authsecret-xyz']];

        $this->actingAs($this->clerk)->postJson(route('notifications.push.subscribe'), $sub)->assertStatus(409);

        $this->actingAs($this->owner)->post(route('notification.channels.vapid'))->assertRedirect();
        $this->put(route('notification.channels.update', 'web_push'), ['enabled' => '1', 'sender_id' => 'mailto:owner@abos.test'])->assertRedirect();
        $config = NotificationChannel::query()->where('channel', 'web_push')->firstOrFail();
        $this->assertNotNull($config->secret('vapid_private'));
        $this->assertStringNotContainsString((string) $config->secret('vapid_private'), (string) DB::table('notification_channels')->where('channel', 'web_push')->value('credentials'));
        $this->get(route('notification.channels.edit', 'web_push'))->assertOk()->assertDontSee((string) $config->secret('vapid_private'));

        // ⓘ প্রেরক mailto: বা https:// ছাড়া চলে না
        $this->put(route('notification.channels.update', 'web_push'), ['enabled' => '1', 'sender_id' => 'just text'])->assertSessionHasErrors('sender_id');

        $this->actingAs($this->clerk)->postJson(route('notifications.push.subscribe'), array_merge($sub, ['endpoint' => 'http://push.example.com/x']))
            ->assertStatus(422)->assertJsonValidationErrors('endpoint');
        $this->actingAs($this->clerk)->postJson(route('notifications.push.subscribe'), $sub)->assertOk();

        $row = NotificationSubscription::query()->where('user_id', $this->clerk->id)->firstOrFail();
        $this->assertStringNotContainsString('authsecret-xyz', (string) DB::table('notification_subscriptions')->value('keys'), '⛔ ব্রাউজারের চাবি খোলা লেখায়');
        $this->assertSame('authsecret-xyz', $row->keys['auth']);
        $this->assertSame(1, NotificationAuditLog::query()->where('action', 'push_subscribe')->count());

        // ⓘ অন্য কেউ এই ব্রাউজারের সাবস্ক্রিপশন বন্ধ করতে পারেন না
        $this->actingAs($this->owner)->postJson(route('notifications.push.unsubscribe'), ['endpoint' => $sub['endpoint']])->assertOk();
        $this->assertNull($row->fresh()->revoked_at, '⛔ অন্যের ব্রাউজারের পুশ বন্ধ হয়ে গেল');

        $this->actingAs($this->clerk)->postJson(route('notifications.push.unsubscribe'), ['endpoint' => $sub['endpoint']])->assertOk();
        $this->assertNotNull($row->fresh()->revoked_at);
    }

    public function test_the_email_connection_test_goes_to_the_tester_and_is_recorded(): void
    {
        config(['mail.default' => 'smtp']);
        Notices::fake();

        $this->post(route('notification.channels.test', 'email'))->assertSessionHas('saved');

        Notices::assertSentTo($this->owner, NewsByMail::class);
        Notices::assertNotSentTo($this->clerk, NewsByMail::class);
        $this->assertSame(0, NotificationChannel::query()->count(), 'সারি না থাকলে পরীক্ষা সারি বানায় না');
        $this->assertSame(1, NotificationAuditLog::query()->where('action', 'channel_test')->where('outcome', 'done')->count());

        $this->put(route('notification.channels.update', 'email'), ['enabled' => '1'])->assertRedirect();
        $this->post(route('notification.channels.test', 'email'))->assertSessionHas('saved');
        $this->assertTrue(NotificationChannel::query()->where('channel', 'email')->firstOrFail()->last_check_ok);

        // ⓘ চিঠি বন্ধ (log) সার্ভারে ইমেইল সংযুক্ত নয়
        config(['mail.default' => 'log']);
        $this->get(route('notification.channels.edit', 'email'))->assertOk()
            ->assertSee('data-connected="0"', false)->assertSee(__('notification::channel.email_silent'));
    }
}
