<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * ⛔ ফোন ৩০ মিনিট পরে চুপ হয়ে যেত — মালিক, ৪ অক্টোবর ২০২৬: *"amar app e customer inventory pur sales sync kokhono na
 * dekhay"* ([[RefreshTokenMayComeInTheBody]])।
 *
 * অ্যাপ (০.৪.৮ পর্যন্ত) নবায়নে refresh টোকেন পাঠায় body-তে, হেডার ছাড়া, deviceId ছাড়া — আর দরজা কেবল হেডার
 * পড়ত, তাই প্রতিটা নবায়ন ৪০১; লাইভে মালিকের ফোনের সব অনুরোধ ৩০ মিনিট পরে ৪০১।
 * দাবি (পুরনো অ্যাপের হুবহু আকারে):
 *   body-তে refresh টোকেন, হেডার আর deviceId ছাড়া — নবায়ন হয়, নতুন টোকেন একই ডিভাইসের নামে, আর নতুনটা দিয়ে সিঙ্ক খোলে;
 *   ব্যবহার করা refresh টোকেন দ্বিতীয়বার চলে না;
 *   body-তে access টোকেন দিলে নবায়ন নয় — পাহারা অবিকল।
 */
final class ThePhoneFellSilentAfterHalfAnHourTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = 'handset-owner';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    public function test_the_old_app_refreshes_with_the_token_in_the_body_and_keeps_working(): void
    {
        $first = $this->login()->assertOk()->json();

        $second = $this->bodyRefresh($first['refreshToken'])->assertOk()
            ->assertJsonStructure(['accessToken', 'refreshToken'])->json();

        $this->assertTrue(PersonalAccessToken::query()->where('name', 'refresh:'.self::DEVICE)->exists(),
            '⛔ নতুন refresh টোকেন ডিভাইসের নাম হারাল।');

        $this->app['auth']->forgetGuards();
        $this->withToken($second['accessToken'])->getJson('/api/v1/me')->assertOk();

        // ⛔ ব্যবহার করা টোকেন আর চলে না
        $this->bodyRefresh($first['refreshToken'])->assertUnauthorized();
    }

    /**
     * ⛔ নতুন জোড়া টোকেনের নিজের ফোনের নামে — body-র deviceId-তে নয় (পুরো ERP অডিট, ৯ অক্টোবর ২০২৬)।
     */
    public function test_a_refresh_names_the_tokens_after_its_own_phone_whatever_the_body_says(): void
    {
        $first = $this->login()->assertOk()->json();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $first['refreshToken'], 'deviceId' => 'someone-elses-phone'])->assertOk();

        $this->assertFalse(PersonalAccessToken::query()->where('name', 'like', '%someone-elses-phone')->exists(), '⛔ অন্য ফোনের নামে টোকেন বানানো গেল।');
        $this->assertTrue(PersonalAccessToken::query()->where('name', 'refresh:'.self::DEVICE)->exists());
    }

    /**
     * ⛔ ব্যবহার হয়ে যাওয়া নবায়ন-টোকেন এক মিনিট পরে আবার এলে — চুরি: ঐ ফোনের সব টোকেন বাতিল (পুরো ERP অডিট,
     * ৯ অক্টোবর ২০২৬)। ⓘ এক মিনিটের মধ্যে আবার এলে কেবল ৪০১ — দুর্বল নেটে একই নবায়ন দুবার যেতে পারে, আসল ফোন থাকে।
     */
    public function test_a_used_refresh_token_brought_back_later_signs_that_phone_out_and_a_quick_repeat_does_not(): void
    {
        $first = $this->login()->assertOk()->json();
        $second = $this->bodyRefresh($first['refreshToken'])->assertOk()->json();

        // ⓘ সাথে সাথে আবার — দুর্বল নেট; আসল ফোনের নতুন জোড়া থাকে
        $this->bodyRefresh($first['refreshToken'])->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($second['accessToken'])->getJson('/api/v1/me')->assertOk();

        // ⛔ এক মিনিট পরে — চুরি; নতুন জোড়াও বাতিল
        $this->travel(2)->minutes();
        $this->bodyRefresh($first['refreshToken'])->assertUnauthorized();
        $this->assertFalse(PersonalAccessToken::query()->where('name', 'like', '%:'.self::DEVICE)->exists(), '⛔ চুরির পরেও ফোনের টোকেন থেকে গেল।');
        $this->bodyRefresh($second['refreshToken'])->assertUnauthorized();
    }

    public function test_an_access_token_in_the_body_does_not_refresh(): void
    {
        $tokens = $this->login()->assertOk()->json();

        $response = $this->bodyRefresh($tokens['accessToken']);

        $this->assertContains($response->status(), [401, 403], '⛔ access টোকেন দিয়েই নবায়ন হয়ে গেল।');
    }

    private function bodyRefresh(string $token): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        // ⓘ অ্যাপ ০.৪.৮-এর হুবহু অনুরোধ — হেডার নেই, deviceId নেই
        return $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $token]);
    }

    private function login(): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/login', [
            'identifier' => 'sales@abos.test',
            'password' => 'password',
            'deviceId' => self::DEVICE,
            'appVersion' => '0.4.8',
            'platform' => 'android',
        ]);
    }
}
