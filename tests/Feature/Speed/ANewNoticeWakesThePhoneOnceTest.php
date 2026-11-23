<?php

declare(strict_types=1);

namespace Tests\Feature\Speed;

use App\Core\Services\NoticeNudge;
use App\Models\Company;
use App\Models\Notification;
use App\Models\SyncDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⭐ A bell item made for a user sends a silent `{"abos":"notice"}` push to that user's phones — at most one per user
 * per ten seconds (speed work, 23 Nov 2026; [[NoticeNudge]]).
 *
 * Claims: data only (no `notification` block, nothing private); only the user's own phones; a burst inside the window
 * is one push, and the next window pushes again; no push keys → nothing called.
 */
final class ANewNoticeWakesThePhoneOnceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['code' => 'NN', 'name_en' => 'Nudge Co']);
    }

    public function test_a_notice_wakes_only_its_users_phones_silently(): void
    {
        $me = $this->member();
        $other = $this->member();
        $this->device($me, 'dev-me', 'tok-me');
        $this->device($other, 'dev-other', 'tok-other');
        $this->fakeFcm();

        $this->bell($me);

        $pushes = $this->pushes();
        $this->assertCount(1, $pushes, 'one notice → one push');
        $this->assertSame('tok-me', $pushes[0]['token'] ?? null, '⛔ the push went to another person\'s phone.');
        $this->assertSame(NoticeNudge::DATA, $pushes[0]['data'] ?? null);
        $this->assertArrayNotHasKey('notification', $pushes[0], '⛔ the nudge showed something on the phone — it must be silent.');
    }

    public function test_a_burst_is_one_push_and_the_next_window_pushes_again(): void
    {
        $me = $this->member();
        $this->device($me, 'dev-me', 'tok-me');
        $this->fakeFcm();

        $this->bell($me);
        $this->bell($me);
        $this->bell($me);
        $this->assertCount(1, $this->pushes(), '⛔ three notices inside ten seconds woke the phone more than once.');

        $this->travel(NoticeNudge::WINDOW + 1)->seconds();
        $this->bell($me);
        $this->assertCount(2, $this->pushes(), '⛔ after the window a new notice did not wake the phone.');
    }

    public function test_with_no_push_key_nothing_is_called(): void
    {
        $me = $this->member();
        $this->device($me, 'dev-me', 'tok-me');
        Http::fake();
        config(['services.firebase.credentials' => '']);

        $this->bell($me);

        Http::assertNothingSent();
    }

    private function bell(User $user): Notification
    {
        return Notification::query()->withoutGlobalScopes()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'company_id' => $this->company->id, 'user_id' => $user->id,
            'type' => 'report_ready', 'title' => 'রিপোর্ট তৈরি',
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function pushes(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'fcm.googleapis.com'))
            ->map(fn ($pair) => (array) ($pair[0]->data()['message'] ?? []))
            ->values()->all();
    }

    private function member(): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user->fresh();
    }

    private function device(User $user, string $id, string $token): SyncDevice
    {
        return SyncDevice::query()->withoutGlobalScopes()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'company_id' => $this->company->id, 'user_id' => $user->id, 'device_id' => $id,
            'platform' => 'android', 'push_token' => $token, 'push_token_at' => now(),
        ]);
    }

    private function fakeFcm(): void
    {
        $email = 'nn@test.iam.gserviceaccount.com';
        $path = sys_get_temp_dir().'/nn-fcm-'.uniqid().'.json';
        file_put_contents($path, json_encode([
            'client_email' => $email, 'private_key' => 'not-a-real-key',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));
        Cache::put('fcm.access_token.'.sha1($email), 'at', now()->addMinutes(50));
        config(['services.firebase.credentials' => $path, 'services.firebase.project_id' => 'p']);

        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/p/messages/1'])]);
    }
}
