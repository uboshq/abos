<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\FcmSender;
use App\Http\Controllers\Api\AuthController;
use App\Jobs\SendPushToUser;
use App\Models\Company;
use App\Models\SyncDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ অ্যাপ বন্ধ থাকলেও বার্তা — FCM (মালিকের আদেশ, ২ অক্টোবর ২০২৬; সমন্বয়কের চার শর্ত)।
 *
 *   ১. নিজের ফোনে নিজের টোকেন; একই টোকেন অন্যের সারিতে থাকলে সেখান থেকে মোছে; অন্যের ডিভাইসে ৪০৪।
 *   ২. (লক-স্ক্রিন) শরীর ফাঁকা — কেবল শিরোনাম যায়।
 *   ৩. FCM ব্যর্থ হলে কিউ-জব সীমিত চেষ্টা; "আর নেই" বললে টোকেন মোছে।
 *   ৪. চাবি বসানো না থাকলে বা ফাইল না পেলে চুপচাপ বন্ধ — কোনো কল নয়, কোনো ব্যতিক্রম নয়।
 * ⛔ আসল FCM-এ কোনো কল নয় — Http::fake।
 */
final class AClosedPhoneNeverHeardItsSaleMoveTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['code' => 'PU', 'name_en' => 'Push Co']);
    }

    /**
     * ⭐ ফোন নিজের টোকেন বসায়, আর নিজেরই পুরনো ইনস্টলের সারি থেকে সেটা সরে আসে। ⛔ আরেকজনের ফোনের টোকেন নয় — ৪০৯, কিছু
     * বদলায় না (পুরো ERP অডিট, ৯ অক্টোবর ২০২৬: আগে যেকোনো সারি থেকে কেড়ে নেওয়া যেত)। ⓘ ফোনপ্রতি সারি একটাই — একই ফোনে
     * লোক বদলালে সারিটাই নতুন জনের হয়।
     */
    public function test_a_phone_takes_its_token_from_its_own_old_install_but_never_from_another_persons_phone(): void
    {
        $someone = $this->member();
        $now = $this->member();
        $stale = $this->device($now, 'dev-old-install', 'tok-mine');
        $mine = $this->device($now, 'dev-new');

        Sanctum::actingAs($now, [AuthController::APP]);
        $this->postJson('/api/v1/devices/push-token', ['deviceId' => 'dev-new', 'token' => 'tok-mine'])->assertOk();
        $this->assertSame('tok-mine', $mine->fresh()->push_token);
        $this->assertNull($stale->fresh()->push_token, '⛔ পুরনো ইনস্টলে টোকেন থেকে গেল — একই বার্তা দুবার যেত।');

        $theirs = $this->device($someone, 'dev-theirs', 'tok-theirs');
        $this->postJson('/api/v1/devices/push-token', ['deviceId' => 'dev-new', 'token' => 'tok-theirs'])->assertStatus(409);
        $this->assertSame('tok-theirs', $theirs->fresh()->push_token, '⛔ অন্যের ফোনের টোকেন কেড়ে নেওয়া গেল।');
        $this->assertSame('tok-mine', $mine->fresh()->push_token);

        $this->postJson('/api/v1/devices/push-token', ['deviceId' => 'dev-theirs', 'token' => 'x'])->assertNotFound();
    }

    public function test_signing_out_stops_the_pushes_to_that_phone(): void
    {
        $user = $this->member();
        $device = $this->device($user, 'dev-1', 'tok-1');

        Sanctum::actingAs($user, [AuthController::APP]);
        $this->postJson('/api/v1/auth/logout', ['deviceId' => 'dev-1']);

        $this->assertNull($device->fresh()->push_token, '⛔ বের হওয়া ফোনেও বার্তা যেত।');
    }

    public function test_with_no_key_set_nothing_is_called_and_nothing_breaks(): void
    {
        Http::fake();
        config(['services.firebase.credentials' => '', 'services.firebase.project_id' => 'p']);

        $this->assertSame(FcmSender::OFF, app(FcmSender::class)->send('t', 'S-1 — রওনা', null));

        config(['services.firebase.credentials' => storage_path('nope/missing.json')]);
        $this->assertSame(FcmSender::OFF, app(FcmSender::class)->send('t', 'S-1 — রওনা', null));
        Http::assertNothingSent();
    }

    public function test_a_push_carries_only_the_title_and_a_gone_token_is_dropped(): void
    {
        $user = $this->member();
        $live = $this->device($user, 'dev-a', 'tok-live');
        $gone = $this->device($user, 'dev-b', 'tok-gone');
        $this->fakeKey();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => fn ($request) => str_contains($request->body(), 'tok-gone')
                ? Http::response(['error' => ['details' => [['errorCode' => 'UNREGISTERED']]]], 404)
                : Http::response(['name' => 'projects/p/messages/1']),
        ]);

        (new SendPushToUser($user->id, 'S-0007 — গেট পেরিয়েছে', ['open' => 'tracking']))->handle(app(FcmSender::class));

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'fcm.googleapis.com')) {
                return false;
            }
            $message = $request->data()['message'] ?? [];

            return ($message['notification']['title'] ?? null) === 'S-0007 — গেট পেরিয়েছে'
                && ! array_key_exists('body', $message['notification'] ?? []);
        });
        $this->assertSame('tok-live', $live->fresh()->push_token);
        $this->assertNull($gone->fresh()->push_token, '"আর নেই" বলা টোকেন রয়ে গেল।');
    }

    public function test_the_job_tries_a_limited_number_of_times(): void
    {
        $job = new SendPushToUser(1, 't');
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300], $job->backoff);
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function member(): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user->fresh();
    }

    private function device(User $user, string $id, ?string $token = null): SyncDevice
    {
        return SyncDevice::query()->withoutGlobalScopes()->forceCreate([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'company_id' => $this->company->id, 'user_id' => $user->id, 'device_id' => $id,
            'platform' => 'android', 'push_token' => $token, 'push_token_at' => $token ? now() : null,
        ]);
    }

    /**
     * পরীক্ষার "চাবি" — আসল নয়, কোথাও যায় না। ⓘ Google-এর টোকেন আগেই ক্যাশে বসানো, তাই সই করার দরকার পড়ে না
     * (পাশের পিসির Windows-এ openssl কনফিগ ছাড়া চাবি বানানো যায় না)।
     */
    private function fakeKey(): void
    {
        $email = 'zq@test.iam.gserviceaccount.com';
        $path = sys_get_temp_dir().'/zq-fcm-'.uniqid().'.json';
        file_put_contents($path, json_encode([
            'client_email' => $email, 'private_key' => 'not-a-real-key',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));
        \Illuminate\Support\Facades\Cache::put('fcm.access_token.'.sha1($email), 'at', now()->addMinutes(5));
        config(['services.firebase.credentials' => $path, 'services.firebase.project_id' => 'p']);
    }
}
