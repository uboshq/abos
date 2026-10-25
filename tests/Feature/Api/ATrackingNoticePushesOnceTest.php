<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Notifications\Channels\MobilePushChannel;
use App\Core\Notifications\PhoneNoticeTarget;
use App\Core\Notifications\RuleEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notification;
use App\Models\NotificationChannel;
use App\Models\NotificationJob;
use App\Models\NotificationRule;
use App\Models\SyncDevice;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\TrackingNotices;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⛔ একটা ট্র্যাকিংয়ের ঘটনায় একজনের ফোনে একটাই পুশ (২৪ অক্টোবর ২০২৬)।
 *
 * ⓘ আগে: ধাপ বদলালে [[TrackingNotices]] নিজে `SendPushToUser` পাঠাত (`open: tracking`), আর বিজ্ঞপ্তির নিয়মে মোবাইল পুশ চালু থাকলে
 * [[MobilePushChannel]]-ও একই খবর পাঠাত (`open: notification`) — একই মানুষ দুইটা পুশ পেতেন।
 * ⭐ এখন: পুরনো অ্যাপ যেটা চেনে (`open: tracking` → ট্র্যাকিং খোলে) সেটাই থাকে; মাধ্যম এই ধরনগুলো আর পাঠায় না
 * ([[PhoneNoticeTarget::PUSHES_ITSELF]]), কিন্তু তার সারি "পৌঁছেছে" — ব্যর্থ-তালিকায় মিথ্যা ব্যর্থতা নয়।
 * ⛔ আসল FCM-এ কোনো কল নয় — Http::fake।
 */
final class ATrackingNoticePushesOnceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        // ⓘ দিনের বেলা — কোম্পানির নীরব সময় রাতে চালালে মাধ্যমের সারি পিছিয়ে যেত, আর "একটাই" দাবিটা ফাঁকা হত
        $this->travelTo(now()->setTime(12, 0));
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->customer = Customer::query()->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '100000'])->save();
    }

    public function test_one_stage_change_with_mobile_push_on_reaches_the_phone_once_with_what_the_app_reads(): void
    {
        $writer = $this->member();
        $this->device($writer, 'tok-writer');

        $this->actingAs($writer);
        $challan = $this->challan();
        Notification::query()->delete();

        // ⓘ নিয়মে মোবাইল পুশ চালু — ঠিক যে অবস্থায় দুইটা যেত
        NotificationRule::query()->create([
            'name' => 'ধাপ ফোনে', 'module' => 'sales', 'event' => TrackingNotices::TYPE, 'is_active' => true, 'version' => 1,
            'channels' => [NotificationChannel::MOBILE_PUSH],
        ]);
        app(RuleEngine::class)->forget();

        $this->fakeKey();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/p/messages/1']),
        ]);

        $this->actingAs($this->owner);
        app(DeliveryStageService::class)->move($challan->fresh(), DeliveryStage::DISPATCHED, ['note' => 'test']);

        $bell = Notification::query()->where('user_id', $writer->id)->where('type', TrackingNotices::TYPE)->first();
        $this->assertNotNull($bell, 'প্রস্তুতিটাই ভুল — চালানের লেখক রওনার খবর পাননি।');
        $job = NotificationJob::query()->withoutGlobalScopes()->where('notification_id', $bell->id)
            ->where('channel', NotificationChannel::MOBILE_PUSH)->first();
        $this->assertNotNull($job, 'প্রস্তুতিটাই ভুল — নিয়মের মোবাইল পুশ এই খবরে বসেইনি, তাই "একটাই" কিছু প্রমাণ করত না।');

        $pushes = $this->pushesTo('tok-writer');
        $this->assertCount(1, $pushes, '⛔ একই ধাপ-বদলে একই মানুষের ফোনে '.count($pushes).'টা পুশ গেল — একটাই যাওয়ার কথা।');

        // ⛔ পুরনো অ্যাপ যা পড়ে — যেমন ছিল
        $data = $pushes[0];
        $this->assertSame('tracking', $data['open'] ?? null, '⛔ পুশ আর ট্র্যাকিং খোলে না — পুরনো অ্যাপ কোথাও যায় না।');
        $this->assertSame('challan', $data['kind'] ?? null, '⛔ পুশের kind বদলাল — পুরনো অ্যাপ ভাঙে।');
        $this->assertSame((string) $challan->public_id, $data['id'] ?? null, '⛔ পুশের id চালানের public_id নয় — পুরনো অ্যাপ ভাঙে।');

        // ⭐ নতুন অ্যাপের জন্য যোগ
        $this->assertSame('tracking', $data['destination'] ?? null, '⛔ ট্র্যাকিংয়ের পুশ নতুন গন্তব্য বলে না।');
        $this->assertSame('delivery_challan', $data['paper_type'] ?? null, '⛔ ট্র্যাকিংয়ের পুশ কাগজের ধরন বলে না।');
        $this->assertSame((string) $challan->public_id, $data['paper_id'] ?? null, '⛔ ট্র্যাকিংয়ের পুশ কাগজের public_id বলে না।');
        $this->assertSame((string) $bell->public_id, $data['notification_id'] ?? null, '⛔ ট্র্যাকিংয়ের পুশ কোন খবর তা বলে না।');
        $this->assertSame(TrackingNotices::TYPE, $data['type'] ?? null, '⛔ ট্র্যাকিংয়ের পুশ খবরের ধরন বলে না।');

        // ⓘ মাধ্যমের সারি মিথ্যা ব্যর্থতা নয় — পুশটা গেছে, অন্য পথে
        $job->refresh();
        $this->assertSame(NotificationJob::SENT, $job->status, '⛔ মাধ্যমের সারি ব্যর্থ-তালিকায় গেল, অথচ পুশটা গেছে।');
        $this->assertSame(MobilePushChannel::OWN_PUSH, $job->provider_ref, '⛔ মাধ্যমের সারি বলে না যে পুশটা নিজের পথে গেছে।');
    }

    public function test_the_kinds_that_push_themselves_are_exactly_the_tracking_kinds(): void
    {
        $this->assertSame(
            [TrackingNotices::TYPE, TrackingNotices::ORDER_HELD, TrackingNotices::ORDER_AWAITS],
            PhoneNoticeTarget::PUSHES_ITSELF,
            '⛔ মাধ্যম যে ধরনগুলো বাদ দেয় আর TrackingNotices যেগুলো নিজে পাঠায় আলাদা — কোনোটা দুইবার, নয়তো একবারও যায় না।',
        );
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /**
     * এই টোকেনে যাওয়া প্রতিটা FCM বার্তার `data`।
     *
     * @return list<array<string, mixed>>
     */
    private function pushesTo(string $token): array
    {
        return Http::recorded()
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'fcm.googleapis.com')
                && (($pair[0]->data()['message']['token'] ?? null) === $token))
            ->map(fn (array $pair) => (array) ($pair[0]->data()['message']['data'] ?? []))
            ->values()->all();
    }

    private function member(): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user;
    }

    private function device(User $user, string $token): SyncDevice
    {
        return SyncDevice::query()->withoutGlobalScopes()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'company_id' => $this->company->id, 'user_id' => $user->id, 'device_id' => 'dev-'.$token,
            'platform' => 'android', 'push_token' => $token, 'push_token_at' => now(),
        ]);
    }

    private function challan(): DeliveryChallan
    {
        $service = app(DeliveryChallanService::class);

        return $service->confirm($service->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->id, 'delivered_qty' => '5', 'rate' => '10']]));
    }

    /**
     * পরীক্ষার "চাবি" — আসল নয়, কোথাও যায় না; Google-এর টোকেন আগেই ক্যাশে ([[AClosedPhoneNeverHeardItsSaleMoveTest]]-এর একই পথ)।
     */
    private function fakeKey(): void
    {
        $email = 'zq@test.iam.gserviceaccount.com';
        $path = sys_get_temp_dir().'/zq-fcm-'.uniqid().'.json';
        file_put_contents($path, json_encode([
            'client_email' => $email, 'private_key' => 'not-a-real-key',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));
        Cache::put('fcm.access_token.'.sha1($email), 'at', now()->addMinutes(5));
        config(['services.firebase.credentials' => $path, 'services.firebase.project_id' => 'p']);
    }
}
