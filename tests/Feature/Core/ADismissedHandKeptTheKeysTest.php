<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Http\Middleware\RefuseInactiveAccounts;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * ⛔ বিদায় নেওয়া হাতে চাবি থেকে যেত — নিরীক্ষা §১.৫, ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * `is_active` দেখা হত **কেবল লগইনের মুহূর্তে** ([[CredentialCheck]])।
 * তারপর:
 *
 *     ওয়েবের খোলা সেশন         চলতেই থাকত
 *     "মনে রাখুন" কুকি           পাসওয়ার্ড বদলের পরেও খুলত
 *     ফোনের refresh টোকেন       প্রতিবার নতুন ৩০ দিনের জোড়া দিত
 *
 * ⚠️ অর্থাৎ বরখাস্ত একজন বিক্রয়কর্মী ফোনটা চালু রাখলে **চিরকাল** ভেতরে
 * থাকতেন — SOC2-র সরাসরি ব্যর্থতা।
 *
 * ⓘ প্রতিটা দাবি **একই মানুষ, একই সেশন বা একই টোকেন** দিয়ে — কেবল
 * `is_active` বা পাসওয়ার্ডটা বদলায়। দুইজন আলাদা মানুষ দিয়ে ৪০১
 * দেখালে সেটা অন্য কোনো দেয়ালও হতে পারত।
 */
class ADismissedHandKeptTheKeysTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = 'handset-dismissed';

    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();
    }

    /* ── ওয়েব ─────────────────────────────────────────────────────── */

    public function test_the_next_web_request_of_a_dismissed_hand_is_logged_out(): void
    {
        $this->actingAs($this->sales)->get(route('profile'))->assertOk();

        $this->dismiss();

        $this->get(route('profile'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('identifier');

        $this->assertGuest('web');
    }

    public function test_a_dismissed_hand_asking_for_json_on_the_web_gets_401(): void
    {
        $this->actingAs($this->sales)->get(route('profile'))->assertOk();

        $this->dismiss();

        $this->getJson(route('profile'))->assertUnauthorized();
        $this->assertGuest('web');
    }

    /* ── ফোন ──────────────────────────────────────────────────────── */

    public function test_the_next_api_request_of_a_dismissed_hand_is_401_and_the_tokens_die(): void
    {
        $tokens = $this->phoneLogin()->assertOk()->json();

        $this->asToken($tokens['accessToken'])
            ->getJson('/api/v1/sync/capabilities')
            ->assertOk();

        $this->dismiss();

        $this->asToken($tokens['accessToken'])
            ->getJson('/api/v1/sync/capabilities')
            ->assertUnauthorized();

        $this->assertSame(0, $this->tokensOf($this->sales), 'বরখাস্তের পরেও টোকেন বেঁচে আছে।');
    }

    public function test_a_dismissed_phone_cannot_refresh(): void
    {
        $tokens = $this->phoneLogin()->assertOk()->json();

        $this->dismiss();

        $this->asToken($tokens['refreshToken'])
            ->postJson('/api/v1/auth/refresh', ['deviceId' => self::DEVICE])
            ->assertUnauthorized();

        $this->assertSame(0, $this->tokensOf($this->sales));
    }

    /**
     * ⓘ নবায়নের দরজা নিজেও মানুষটাকে চেনে — প্রতি-অনুরোধের পাহারাটা
     * সরিয়ে দিলেও। ⚠️ দুই স্তর, কারণ একটা মিডলওয়্যার একদিন কোনো
     * গ্রুপ থেকে বাদ পড়তে পারে, আর তখন ঠিক এই দরজাটাই চিরকালের চাবি।
     */
    public function test_the_refresh_door_itself_refuses_a_dismissed_hand(): void
    {
        $tokens = $this->phoneLogin()->assertOk()->json();

        $this->dismiss();

        $this->withoutMiddleware(RefuseInactiveAccounts::class);

        $this->asToken($tokens['refreshToken'])
            ->postJson('/api/v1/auth/refresh', ['deviceId' => self::DEVICE])
            ->assertUnauthorized();

        $this->assertSame(0, $this->tokensOf($this->sales));
    }

    /* ── প্রশাসকের পর্দা ────────────────────────────────────────────── */

    public function test_switching_somebody_off_on_the_screen_kills_their_tokens_and_cookie(): void
    {
        $this->phoneLogin()->assertOk();
        $this->sales->forceFill(['remember_token' => 'old-remember-token'])->save();

        $this->actingAs($this->owner())
            ->put(route('system_admin.user.update', $this->sales), $this->editForm(['is_active' => '0']))
            ->assertRedirect(route('system_admin.user.index'));

        $fresh = $this->sales->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertNotSame('old-remember-token', $fresh->remember_token);
        $this->assertSame(0, $this->tokensOf($this->sales));
    }

    public function test_a_password_reset_on_the_screen_kills_the_old_cookie_and_tokens(): void
    {
        $this->phoneLogin()->assertOk();
        $cookie = $this->rememberCookie();

        // ⓘ প্রমাণ যে কুকিটা আসলেই খোলে — নাহলে নিচের "বন্ধ" কিছুই বলে না
        $this->freshDevice();
        $this->assertOpens($this->withCookie(...$cookie)->get(route('profile')), 'cookie before reset');
        $this->freshDevice();

        $this->actingAs($this->owner())
            ->put(route('system_admin.user.update', $this->sales), $this->editForm(['password' => 'Kd8-dismissal-rotor-51']))
            ->assertRedirect(route('system_admin.user.index'));

        $this->assertSame(0, $this->tokensOf($this->sales));

        $this->freshDevice();
        $this->withCookie(...$cookie)->get(route('profile'))->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    /* ── নিজের পাসওয়ার্ড বদল ───────────────────────────────────────── */

    public function test_after_my_own_password_change_the_old_remember_cookie_no_longer_opens(): void
    {
        $this->phoneLogin()->assertOk();
        $cookie = $this->rememberCookie();

        // ⓘ প্রমাণ যে কুকিটা আসলেই খোলে — নাহলে নিচের "বন্ধ" কিছুই বলে না
        $this->freshDevice();
        $this->withCookie(...$cookie)->get(route('profile'))->assertOk();
        $this->assertAuthenticatedAs($this->sales, 'web');

        $this->freshDevice();
        $this->actingAs($this->sales)->get(route('profile'))->assertOk();

        $this->put(route('profile.password'), [
            'current_password' => 'password',
            'password' => 'Kd8-dismissal-rotor-51',
            'password_confirmation' => 'Kd8-dismissal-rotor-51',
        ])->assertSessionHasNoErrors();

        // ⭐ যে সেশন থেকে বদলানো হলো, সেটা থাকে
        $this->get(route('profile'))->assertOk();

        $this->assertSame(0, $this->tokensOf($this->sales), 'পাসওয়ার্ড বদলের পরেও ফোনের টোকেন বেঁচে আছে।');

        $this->freshDevice();
        $this->withCookie(...$cookie)->get(route('profile'))->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    /**
     * অন্য কোথাও পাসওয়ার্ড বদলালে এই খোলা সেশনটা শেষ — ড্রাইভার `file`,
     * তাই সেশনগুলো গোনা যায় না; পাহারাটা প্রতি অনুরোধে।
     */
    public function test_a_password_changed_elsewhere_ends_this_open_session(): void
    {
        $this->actingAs($this->sales)->get(route('profile'))->assertOk();

        User::query()->whereKey($this->sales->id)->update(['password' => Hash::make('Qv3-changed-elsewhere-77')]);

        $this->get(route('profile'))->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    /* ── ⚖️ পাল্টা দাবি: সক্রিয় মানুষের কিছুই বদলায় না ──────────────── */

    public function test_an_active_hand_is_not_touched(): void
    {
        $this->assertOpens($this->actingAs($this->sales)->get(route('profile')), 'step 1');
        $this->assertOpens($this->get(route('profile')), 'step 2');
        $this->assertAuthenticatedAs($this->sales, 'web');

        $tokens = $this->phoneLogin()->assertOk()->json();

        $this->asToken($tokens['accessToken'])
            ->getJson('/api/v1/sync/capabilities')
            ->assertOk();

        $this->asToken($tokens['refreshToken'])
            ->postJson('/api/v1/auth/refresh', ['deviceId' => self::DEVICE])
            ->assertOk()
            ->assertJsonStructure(['accessToken', 'refreshToken']);

        $cookie = $this->rememberCookie();
        $this->freshDevice();
        $this->assertOpens($this->withCookie(...$cookie)->get(route('profile')), 'step 3');
        $this->assertAuthenticatedAs($this->sales, 'web');
    }

    /* ── সহায়ক ────────────────────────────────────────────────────── */

    /**
     * প্রশাসক অন্য কোথাও থেকে নিষ্ক্রিয় করলেন — ⚠️ কোয়েরি দিয়ে, এই
     * টেস্টের হাতের `$this->sales` বস্তুটা দিয়ে নয়। নাহলে গার্ডের মনে থাকা
     * বস্তুটাও বদলে যেত, আর বাস্তবে সেটা কখনো ঘটে না।
     */
    private function dismiss(): void
    {
        User::query()->whereKey($this->sales->id)->update(['is_active' => false]);
    }

    private function owner(): User
    {
        return User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function editForm(array $overrides = []): array
    {
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        return array_merge([
            'name' => $this->sales->name,
            'email' => $this->sales->email,
            'locale' => 'bn',
            'is_active' => '1',
            'roles' => ['salesman'],
            'companies' => [$company->id],
        ], $overrides);
    }

    /**
     * একটা "মনে রাখুন" কুকি — ঠিক যা [[SessionGuard]] নিজে বানায়।
     *
     * @return array{0: string, 1: string}
     */
    private function rememberCookie(): array
    {
        $this->sales->forceFill(['remember_token' => 'old-remember-token'])->save();
        $user = $this->sales->fresh();

        $guard = Auth::guard('web');

        return [
            $guard->getRecallerName(),
            $user->id.'|old-remember-token|'.$guard->hashPasswordForCookie($user->password),
        ];
    }

    /** আরেকটা যন্ত্র: গার্ডের স্মৃতি নেই, সেশনও নেই। */
    private function freshDevice(): void
    {
        /*
         * ⚠️ `auth:sanctum` চলার পর Laravel ডিফল্ট গার্ডটাকে `sanctum` করে
         * দেয় (`shouldUse`), আর টেস্টে একই অ্যাপ সব অনুরোধ চালায় — তাই
         * পরের ওয়েব অনুরোধ ভুল গার্ডে দেখত আর কুকিটা "খুলত না"। ⓘ বাস্তবে
         * প্রতিটা অনুরোধ নতুন, তাই এটা কেবল টেস্টের সমস্যা।
         */
        $this->app['auth']->shouldUse('web');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->defaultHeaders = [];
    }

    /** ২০০ — আর না হলে কোথায় পাঠাল সেটাই বার্তায়। */
    private function assertOpens(TestResponse $response, string $step = ''): void
    {
        $this->assertSame(200, $response->getStatusCode(), $step.' খোলেনি; পাঠাল: '.(string) $response->headers->get('Location'));
    }

    private function tokensOf(User $user): int
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)
            ->count();
    }

    private function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function phoneLogin(): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/login', [
            'identifier' => 'sales@abos.test',
            'password' => 'password',
            'deviceId' => self::DEVICE,
            'appVersion' => '0.1.0',
            'platform' => 'android',
        ]);
    }
}
