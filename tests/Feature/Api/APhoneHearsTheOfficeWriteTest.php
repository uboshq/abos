<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\SyncNudge;
use App\Models\Company;
use App\Models\SyncDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⭐ রিয়েল-টাইম সিঙ্ক, ফোনের দিক — মালিক, ১০ অক্টোবর ২০২৬: "Real Time sync app r web dutotei koro", পথ (ক)।
 *
 * অফিসে কিছু লেখা হলে ঐ কোম্পানির প্রতিটা ফোনে একটা নীরব ডাক যায়, আর খোলা অ্যাপ তখনই সিঙ্ক করে
 * ([[SyncNudge]], [[NudgePhonesAfterWrite]])। ⛔ ডাকে কোনো লেখা বা ব্যবসার তথ্য নেই; অন্য কোম্পানির
 * ফোন জাগে না; পড়া, ব্যর্থ লেখা আর এক জানালায় দ্বিতীয় লেখা ডাকে না। ⛔ আসল FCM-এ কোনো কল নয়।
 */
final class APhoneHearsTheOfficeWriteTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['code' => 'RT', 'name_en' => 'Real Time Co']);
        $this->other = Company::create(['code' => 'OT', 'name_en' => 'Other Co']);
    }

    public function test_a_write_in_the_office_wakes_every_phone_of_that_company_silently(): void
    {
        $clerk = $this->member($this->company);
        $this->device($this->member($this->company), $this->company, 'dev-rep', 'tok-rep');
        $this->device($this->member($this->other), $this->other, 'dev-elsewhere', 'tok-elsewhere');
        $this->fakeFcm();

        $this->actingAs($clerk)->post(route('notifications.read-all'))->assertRedirect();

        Http::assertSent(function ($request) {
            $message = $request->data()['message'] ?? [];

            return str_contains($request->url(), 'fcm.googleapis.com')
                && ($message['token'] ?? null) === 'tok-rep'
                && ($message['data'] ?? null) === SyncNudge::DATA
                && ! array_key_exists('notification', $message);
        });
        Http::assertNotSent(fn ($request) => str_contains($request->body(), 'tok-elsewhere'));
    }

    public function test_a_read_never_wakes_a_phone_and_a_burst_wakes_it_once(): void
    {
        $clerk = $this->member($this->company);
        $this->device($this->member($this->company), $this->company, 'dev-rep', 'tok-rep');
        $this->fakeFcm();

        $this->actingAs($clerk)->get(route('notifications.settings'));
        Http::assertNothingSent();

        /*
         * ⓘ সরাসরি দুই ডাক, তারপর একবার শেষ — পরীক্ষায় অ্যাপটা অনুরোধের মাঝে বাঁচে, আর আগের অনুরোধের
         * "উত্তরের পরে" কাজ পরের অনুরোধের শেষেও আবার চলে; আসল সার্ভারে প্রতিটা অনুরোধ নতুন অ্যাপ।
         */
        app(SyncNudge::class)->touch($this->company->id);
        app(SyncNudge::class)->touch($this->company->id);
        $this->app->terminate();

        $this->assertSame(1, collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'fcm.googleapis.com'))->count(),
            'একই জানালায় দুই লেখা — ফোন দুবার জাগল।');
    }

    public function test_with_no_push_key_nothing_is_called(): void
    {
        $clerk = $this->member($this->company);
        $this->device($this->member($this->company), $this->company, 'dev-rep', 'tok-rep');
        Http::fake();
        config(['services.firebase.credentials' => '']);

        $this->actingAs($clerk)->post(route('notifications.read-all'));

        Http::assertNothingSent();
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function member(Company $company): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $company->id]);
        $user->companies()->attach($company->id, ['is_active' => true]);

        return $user->fresh();
    }

    private function device(User $user, Company $company, string $id, string $token): SyncDevice
    {
        return SyncDevice::query()->withoutGlobalScopes()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'company_id' => $company->id, 'user_id' => $user->id, 'device_id' => $id,
            'platform' => 'android', 'push_token' => $token, 'push_token_at' => now(),
        ]);
    }

    /** আসল নয় — Google-এর টোকেন আগেই ক্যাশে, তাই সই লাগে না ([[AClosedPhoneNeverHeardItsSaleMoveTest]]-এর একই কৌশল)। */
    private function fakeFcm(): void
    {
        $email = 'rt@test.iam.gserviceaccount.com';
        $path = sys_get_temp_dir().'/rt-fcm-'.uniqid().'.json';
        file_put_contents($path, json_encode([
            'client_email' => $email, 'private_key' => 'not-a-real-key',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));
        Cache::put('fcm.access_token.'.sha1($email), 'at', now()->addMinutes(5));
        config(['services.firebase.credentials' => $path, 'services.firebase.project_id' => 'p']);

        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/p/messages/1'])]);
    }
}
