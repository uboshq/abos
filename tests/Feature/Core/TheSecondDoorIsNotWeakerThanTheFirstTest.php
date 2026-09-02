<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\LoginAttempt;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * ফোনের দরজা ওয়েবের দরজার চেয়ে দুর্বল নয়।
 *
 * ── কেন এই পরীক্ষাটা আলাদা করে দরকার ────────────────────────────────
 * তালা, throttle আর `login_history` — তিনটাই বসানো ছিল ওয়েবের দরজায়।
 * দ্বিতীয় দরজাটা ওগুলো ছাড়া বানালে **আক্রমণকারী কেবল প্রথম দরজাটা
 * ব্যবহার করা বন্ধ করে দিতেন**, আর নিরাপত্তার কোনো মাপ সেটা দেখাত না:
 * ওয়েবের টেস্টগুলো সবুজই থাকত।
 *
 * তাই যাচাইটা এখন এক জায়গায় ([[CredentialCheck]]), আর এই পরীক্ষাটা
 * ধরে রাখে যে **দুইটা দরজা একই তালা ব্যবহার করছে**।
 */
class TheSecondDoorIsNotWeakerThanTheFirstTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = 'handset-alpha';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    public function test_a_phone_can_sign_in_and_gets_two_tokens(): void
    {
        $response = $this->login();

        $response->assertOk()
            ->assertJsonStructure(['accessToken', 'refreshToken', 'expiresInSeconds', 'user' => ['id', 'name', 'roles', 'permissions']]);

        $this->assertNotSame(
            $response->json('accessToken'),
            $response->json('refreshToken'),
            'একই টোকেন দুইবার দিলে ছোট মেয়াদের পুরো ব্যবস্থাটাই অর্থহীন।',
        );
    }

    /**
     * ⚠️ এটাই দুই-টোকেন ব্যবস্থার আসল পরীক্ষা।
     *
     * `auth:sanctum` **যেকোনো** বৈধ টোকেন মেনে নেয়। ability না দেখলে
     * চুরি যাওয়া একটা refresh টোকেন দিয়েই সরাসরি সিঙ্কের সব দরজা খোলা
     * যেত — আর তখন access টোকেনের ৩০ মিনিটের ছোট মেয়াদটা কেবল একটা
     * অসুবিধা হত, কোনো সুরক্ষা নয়।
     */
    public function test_a_refresh_token_cannot_open_the_sync_doors(): void
    {
        $tokens = $this->login()->json();

        $this->asToken($tokens['accessToken'])
            ->getJson('/api/v1/sync/capabilities')
            ->assertOk();

        $this->asToken($tokens['refreshToken'])
            ->getJson('/api/v1/sync/capabilities')
            ->assertForbidden();
    }

    /**
     * নবায়নের পর পুরনো refresh টোকেনটা মরে যায়।
     *
     * না মরলে চুরি যাওয়া একটা টোকেন **চিরকাল** নতুন access টোকেন
     * বানাতে পারত — ৩০ দিনের মেয়াদটা তখন কার্যত অসীম।
     */
    public function test_refreshing_kills_the_token_that_was_used(): void
    {
        $first = $this->login()->json();

        $second = $this->asToken($first['refreshToken'])
            ->postJson('/api/v1/auth/refresh', ['deviceId' => self::DEVICE])
            ->assertOk()
            ->json();

        $this->asToken($second['accessToken'])
            ->getJson('/api/v1/sync/capabilities')
            ->assertOk();

        $this->asToken($first['refreshToken'])
            ->postJson('/api/v1/auth/refresh', ['deviceId' => self::DEVICE])
            ->assertUnauthorized();
    }

    /**
     * ভুল পাসওয়ার্ড খাতায় বসে — ওয়েবের মতোই, আর একই কারণে।
     *
     * `UNKNOWN` আর `WRONG_PASSWORD` আলাদা করে লেখার পুরো উদ্দেশ্যই হলো
     * "কেউ নাম আন্দাজ করছেন" আর "কেউ পাসওয়ার্ড আন্দাজ করছেন" আলাদা করে
     * চেনা। API কিছু না লিখলে ফোনের দিকটা ওই তফাতে **অন্ধ** থাকত, আর
     * আক্রমণটা কেবল এই দরজা দিয়ে হলে খাতায় তার কোনো চিহ্নই থাকত না।
     */
    public function test_a_wrong_password_is_written_down_and_gives_no_token(): void
    {
        $before = LoginAttempt::query()->count();

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'sales@abos.test',
            'password' => 'not-the-password',
            'deviceId' => self::DEVICE,
        ])->assertStatus(422);

        $this->assertSame($before + 1, LoginAttempt::query()->count());

        $this->assertSame(
            LoginAttempt::WRONG_PASSWORD,
            LoginAttempt::query()->latest('id')->firstOrFail()->reason,
            'কারণটা ভুল লেখা হলে "কে কী আন্দাজ করছে" প্রশ্নের উত্তর ভুল হত।',
        );

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    /**
     * ⚠️ নরম-মোছা ব্যবহারকারী নাম দিয়েও ঢুকতে পারবেন না।
     *
     * ── যে বাগটা এটা ধরে ────────────────────────────────────────────
     * সরানোর আগে খোঁজাটা ছিল গোষ্ঠী ছাড়া:
     * `->where('email', $id)->orWhere('name', $id)`। `SoftDeletes`
     * নিজে থেকে `deleted_at is null` জোড়ে, তাই SQL দাঁড়াত
     * `deleted_at IS NULL AND email = X OR name = X` — আর `AND`-এর
     * অগ্রাধিকার বেশি হওয়ায় **নাম দিয়ে খুঁজলে মোছা অ্যাকাউন্টও ফিরে
     * আসত**।
     *
     * বিদায় নেওয়া কর্মীর অ্যাকাউন্ট মুছে দেওয়ার পরেও কাজ করত, আর
     * ইমেইল দিয়ে ঢুকলে ধরা পড়ত না — তাই কোনো টেস্ট এটা দেখেনি।
     */
    public function test_a_removed_person_cannot_sign_in_by_name(): void
    {
        $user = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $name = $user->name;
        $user->delete();

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $name,
            'password' => 'password',
            'deviceId' => self::DEVICE,
        ])->assertStatus(422);

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    /**
     * ⚠️ লগআউট কেবল **এই** ফোনের টোকেন মোছে।
     *
     * ── যে বাগটা এটা ধরে ────────────────────────────────────────────
     * প্রথমে লেখা ছিল `->where(name=A)->orWhere(name=B)`, আর সেটা
     * `$user->tokens()`-এর (`tokenable_id = X`) উপরে বসত। ফল:
     * `(tokenable_id = X AND name = A) OR (name = B)` — অর্থাৎ
     * **`orWhere`-টা ব্যবহারকারীর সীমানা ছাড়িয়ে যেত**, আর একজনের
     * লগআউটে ওই একই deviceId-র নামে অন্য যে কারো refresh টোকেনও মুছে
     * যেত। একটা ফোন হাতবদল হলে নামটা ঠিক ওই একই থাকে।
     */
    public function test_logging_out_one_person_leaves_another_signed_in(): void
    {
        $salesman = $this->login()->json();

        $this->app['auth']->forgetGuards();
        $accountant = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'accounts@abos.test',
            'password' => 'password',
            'deviceId' => self::DEVICE,
        ])->assertOk()->json();

        $this->asToken($salesman['accessToken'])
            ->postJson('/api/v1/auth/logout', ['deviceId' => self::DEVICE])
            ->assertOk();

        $this->asToken($accountant['accessToken'])
            ->getJson('/api/v1/sync/capabilities')
            ->assertOk();

        $this->asToken($accountant['refreshToken'])
            ->postJson('/api/v1/auth/refresh', ['deviceId' => self::DEVICE])
            ->assertOk();
    }

    /**
     * ঢোকার সাথে সাথেই হ্যান্ডসেটটা চেনা হয়ে যায়।
     *
     * নাহলে প্রথম সিঙ্ক কলটাই ডিভাইস নিবন্ধনের কাজটা করত, আর
     * "ফোনটা কি আদৌ সার্ভারে পৌঁছেছে" প্রশ্নের উত্তর পাওয়া যেত না
     * যতক্ষণ না কেউ সিঙ্ক করছেন।
     */
    public function test_signing_in_registers_the_handset(): void
    {
        $this->login()->assertOk();

        $this->assertDatabaseHas('sync_devices', [
            'device_id' => self::DEVICE,
            'app_version' => '0.1.0',
        ]);
    }

    /**
     * টোকেন ছাড়া সিঙ্কের কোনো দরজা খোলে না।
     */
    public function test_the_sync_doors_are_shut_without_a_token(): void
    {
        $this->getJson('/api/v1/sync/capabilities')->assertUnauthorized();
        $this->getJson('/api/v1/sync/customer/pull?deviceId='.self::DEVICE)->assertUnauthorized();
        $this->postJson('/api/v1/sync/sales/push?deviceId='.self::DEVICE, [])->assertUnauthorized();
    }

    /**
     * ⚠️ টোকেন বদলানোর আগে গার্ডকে ভুলিয়ে দেওয়া — নাহলে পরীক্ষাটা
     * নিজেই মিথ্যা বলে।
     *
     * ── কী ঘটে, আর কেন এটা কোডের বাগ নয় ─────────────────────────────
     * এক টেস্ট-মেথডের ভেতরে অ্যাপটা একবারই বুট হয়, তাই `AuthManager`
     * আর তার গার্ডগুলো সব অনুরোধের মধ্যে টিকে থাকে। Sanctum-এর গার্ড
     * একটা `RequestGuard`, আর সে **প্রথমবার সমাধান করা ব্যবহারকারী ও
     * টোকেনটা ধরে রাখে**। দ্বিতীয় অনুরোধে নতুন হেডার পাঠালেও সে
     * পুরনোটাই ফেরত দেয়।
     *
     * ফল ছিল তিনটা লাল, আর দুইটা পরস্পরবিরোধী — একই টোকেন একবার বেশি
     * ক্ষমতা দেখাত, একবার কম:
     *
     *   access ক্যাশড → পরে refresh পাঠালেও `sync` ক্ষমতা → ২০০
     *   refresh ক্যাশড → পরে access পাঠালেও `sync` নেই → ৪০৩
     *
     * **আসল সার্ভারে এটা ঘটে না** — প্রতিটা HTTP অনুরোধ নিজের প্রক্রিয়ায়
     * নতুন করে বুট হয়। এটা পুরোপুরি পরীক্ষার পরিবেশের কৃত্রিমতা, আর
     * ঠিক সেই কারণেই বিপজ্জনক: **পরীক্ষাটা এমন কিছু "প্রমাণ" করত যা
     * বাস্তবে সত্যি নয়**, দুই দিকেই।
     *
     * `forgetGuards()` প্রতিটা গার্ডের ক্যাশ ফেলে দেয়, তাই পরের
     * অনুরোধটা সত্যিই নতুন করে টোকেন পড়ে — যেমনটা লাইভে হয়।
     */
    private function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function login(): TestResponse
    {
        // ডেমোর সবার পাসওয়ার্ড `password` — README দেখুন
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
