<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Notifications\PreferenceBook;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\NotificationChannel;
use App\Models\NotificationPreference;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⛔ ফোন থেকে পছন্দের একটা ঘর বদলালে বাকি সব বন্ধ হয়ে যেত (cb-র রিভিউ, cloud/notification-management মেশানোর আগে, ১১ অক্টোবর ২০২৬)।
 *
 * ⓘ `{"frequency":"daily"}` পাঠালে ইমেইল আর দুই পুশ বন্ধ, মালিকের নীরব সময় (২২–০৮) মুছে যেত, ছুটির বদলিও। এখন যা পাঠানো হয়নি
 * তা যেমন ছিল — সারি না থাকলেও (তখন সব চালু, আর কোম্পানির নীরব সময়), সারি থাকলেও (নিজের বাছাই)।
 */
final class APartialPreferenceChangeKeepsTheRestTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_partial_change_from_the_phone_keeps_every_field_it_did_not_send(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $me = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $colleague = User::query()->where('email', 'accounts@abos.test')->firstOrFail();

        // ① সারি নেই — সব চালু আর কোম্পানির নীরব সময়
        $this->sendPrefs($me, ['frequency' => 'daily']);
        $saved = $this->saved($me);
        $this->assertSame('daily', $saved->frequency);
        foreach (NotificationChannel::ALL as $channel) {
            $this->assertTrue($saved->allowsChannel($channel), "⛔ কেবল ঘনত্ব বদলাতে গিয়ে {$channel} বন্ধ হয়ে গেল।");
        }
        $this->assertSame(['22:00', '08:00', true], [substr((string) $saved->quiet_start, 0, 5), substr((string) $saved->quiet_end, 0, 5), (bool) $saved->quiet_enabled],
            '⛔ মালিকের নীরব সময় মুছে গেল।');

        // ② নিজের বাছাই আছে — ইমেইল বন্ধ, ছুটির বদলি; আরেকটা ঘর বদলালে সব থাকে
        $this->sendPrefs($me, ['channels' => [NotificationChannel::WEB_PUSH, NotificationChannel::MOBILE_PUSH], 'delegate_user_id' => $colleague->id,
            'delegate_from' => now()->toDateString(), 'delegate_until' => now()->addDays(3)->toDateString()]);
        $this->sendPrefs($me, ['digest_hour' => 7]);
        $saved = $this->saved($me);
        $this->assertSame(7, (int) $saved->digest_hour);
        $this->assertFalse($saved->allowsChannel(NotificationChannel::EMAIL), '⛔ নিজের বন্ধ করা ইমেইল আবার চালু হলো।');
        $this->assertTrue($saved->allowsChannel(NotificationChannel::MOBILE_PUSH));
        $this->assertSame((int) $colleague->id, (int) $saved->delegate_user_id, '⛔ ছুটির বদলি মুছে গেল।');
    }

    /** @param  array<string, mixed>  $body */
    private function sendPrefs(User $user, array $body): void
    {
        $this->app['auth']->forgetGuards();
        app(PreferenceBook::class)->forget();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);
        $this->putJson('/api/v1/notification-preferences', $body)->assertOk();
    }

    private function saved(User $user): NotificationPreference
    {
        return NotificationPreference::query()->withoutGlobalScopes()->where('user_id', $user->id)->firstOrFail();
    }
}
