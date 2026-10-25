<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Notifications\Channels\MobilePushChannel;
use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notification;
use App\Models\SyncDevice;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\DeliveryChallanService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⭐ বিজ্ঞপ্তির মোবাইল পুশ পুরনো `data` রাখে আর কেবল যোগ করে — কোন খবর, কোন কাগজ, কোথায় খোলে (চুক্তি
 * `docs/Contract — ফোনের খবর কোথায় খোলে.md`; ২৪ অক্টোবর ২০২৬)।
 *
 * ⭐ দাবি:
 *   · `open` = notification আর `id` = খবরের public_id — যেমন ছিল (পুরনো অ্যাপ)
 *   · যোগ: `notification_id`, `type`, `paper_type`, `paper_id`, `destination` — ⛔ সব মান লেখা (FCM-এর নিয়ম), `null` নয়
 *   · কাগজহীন খবর: কাগজের ঘর ফাঁকা লেখা, গন্তব্য `list`
 * ⛔ আসল FCM-এ কোনো কল নয় — Http::fake।
 */
final class TheMobilePushKeepsItsOldWordsAndSaysWhereItLeadsTest extends TestCase
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
        $this->actingAs($this->owner);

        $this->clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);

        $this->fakeKey();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/p/messages/1']),
        ]);
    }

    public function test_a_paper_notice_keeps_open_and_id_and_adds_its_paper_and_destination_as_strings(): void
    {
        $challan = $this->challan();
        $bell = app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'চালান ফেরত', about: $challan);
        $this->assertInstanceOf(Notification::class, $bell, 'প্রস্তুতিটাই ভুল — কেরানি খবরটাই পাননি।');

        $data = $this->pushedData($bell);

        $this->assertSame('notification', $data['open'] ?? null, '⛔ পুশের open বদলাল — পুরনো অ্যাপ ভাঙে।');
        $this->assertSame((string) $bell->public_id, $data['id'] ?? null, '⛔ পুশের id আর খবরের public_id নয় — পুরনো অ্যাপ ভাঙে।');

        $this->assertSame([
            'notification_id' => (string) $bell->public_id,
            'type' => 'approval.rejected',
            'paper_type' => 'delivery_challan',
            'paper_id' => (string) $challan->public_id,
            // ⓘ সইয়ের ফল — যিনি চেয়েছিলেন তাঁর সই বাকি নেই, তাই কাগজটাই
            'destination' => 'paper',
        ], array_intersect_key($data, array_flip(['notification_id', 'type', 'paper_type', 'paper_id', 'destination'])),
            '⛔ পুশ বলে না কোন খবর, কোন কাগজ, কোথায় খোলে।');

        foreach ($data as $key => $value) {
            $this->assertIsString($value, "⛔ পুশের '{$key}' লেখা নয় — FCM বার্তাটাই ফেরত দিত।");
        }
    }

    public function test_a_notice_without_a_paper_says_list_and_leaves_the_paper_empty(): void
    {
        $bell = app(NotificationService::class)->send($this->clerk, 'report_ready', 'রিপোর্ট তৈরি');
        $this->assertInstanceOf(Notification::class, $bell, 'প্রস্তুতিটাই ভুল — কেরানি খবরটাই পাননি।');

        $data = $this->pushedData($bell);

        $this->assertSame('notification', $data['open'] ?? null, '⛔ পুশের open বদলাল — পুরনো অ্যাপ ভাঙে।');
        $this->assertSame('', $data['paper_type'] ?? null, '⛔ কাগজহীন খবরে কাগজের ধরন এল, নয়তো ঘরটাই নেই।');
        $this->assertSame('', $data['paper_id'] ?? null, '⛔ কাগজহীন খবরে কাগজের public_id এল, নয়তো ঘরটাই নেই।');
        $this->assertSame('list', $data['destination'] ?? null, '⛔ কাগজহীন খবর অ্যাপকে কোথাও নিয়ে যেতে বলল।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /**
     * ফোনটা খবরের পরে বসে — তাই আগে কোনো পুশ যায়নি; তারপর মাধ্যম একবার পাঠায়, আর সেই বার্তার `data`।
     *
     * @return array<string, mixed>
     */
    private function pushedData(Notification $bell): array
    {
        SyncDevice::query()->withoutGlobalScopes()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'company_id' => $this->company->id, 'user_id' => $this->clerk->id, 'device_id' => 'dev-clerk',
            'platform' => 'android', 'push_token' => 'tok-clerk', 'push_token_at' => now(),
        ]);

        $result = app(MobilePushChannel::class)->send($bell->fresh(), $this->clerk->fresh(), null);
        $this->assertTrue($result->ok(), 'প্রস্তুতিটাই ভুল — মাধ্যম পুশটাই পাঠাল না: '.(string) $result->error);

        $sent = Http::recorded()
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'fcm.googleapis.com')
                && (($pair[0]->data()['message']['token'] ?? null) === 'tok-clerk'))
            ->values();
        $this->assertCount(1, $sent, 'প্রস্তুতিটাই ভুল — এই ফোনে একটাই বার্তা যাওয়ার কথা।');

        return (array) ($sent[0][0]->data()['message']['data'] ?? []);
    }

    private function challan(): DeliveryChallan
    {
        $customer = Customer::query()->firstOrFail();
        $customer->forceFill(['credit_limit' => '100000'])->save();
        $service = app(DeliveryChallanService::class);

        return $service->confirm($service->create([
            'customer_id' => $customer->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->id, 'delivered_qty' => '5', 'rate' => '10']]));
    }

    /** পরীক্ষার "চাবি" — আসল নয়, কোথাও যায় না ([[AClosedPhoneNeverHeardItsSaleMoveTest]]-এর একই পথ) */
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
