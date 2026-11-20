<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * ⛔ অ্যাপ মাঝে মাঝে নিজে থেকে লগআউট — মালিক, ২০ নভেম্বর ২০২৬: "লগইন থাকবে যতক্ষণ না নিজে বের হন; ৩০ দিন না খুললেও লগআউট নয়"।
 *
 * লাইভের খাতা (নভেম্বর): `/auth/refresh` ৯৫৯ বার ২০০, ৫২৯ বার ৪০১ — একই ফোনের একই সেকেন্ডে দুই-তিনটা নবায়ন (সামনের অ্যাপ, পেছনের
 * সিঙ্ক, উইজেট), একটা জেতে, বাকিরা ৪০১, আর অ্যাপ ৪০১ পেয়ে মানুষকে বের করে দিত।
 * দাবি:
 *   টক্করের হারা নবায়ন জেতাটার একই জোড়া পায়;
 *   উত্তর পথে হারালে (নতুন জোড়া কখনো ব্যবহার হয়নি) অনেক পরেও পুরনো টোকেনে নতুন জোড়া, আর অব্যবহৃত জোড়াটা মরে;
 *   বের হওয়ার পরে জানালার মধ্যেও পুরনো টোকেন কিছু পায় না;
 *   নবায়ন-টোকেনের মেয়াদ এক বছর — ৩০ দিন পরেও নবায়ন হয়।
 */
final class ThePhoneSignedItsOwnerOutTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = 'handset-race';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    public function test_a_refresh_lost_on_the_way_is_answered_again_much_later(): void
    {
        $first = $this->login()->assertOk()->json();
        $lost = $this->refresh($first['refreshToken'])->assertOk()->json();

        // ⓘ ফোন উত্তরটা পায়নি — নতুন জোড়া কখনো ব্যবহার হয়নি; এক ঘণ্টা পরে পুরনো টোকেনেই আবার
        $this->travel(1)->hours();
        $again = $this->refresh($first['refreshToken'])->assertOk()->json();

        $this->assertNotSame($lost['refreshToken'], $again['refreshToken'], '⛔ হারানো জোড়াটাই আবার দেওয়া হল।');
        $this->assertNull(PersonalAccessToken::findToken($lost['refreshToken']), '⛔ অব্যবহৃত হারানো জোড়া বেঁচে রইল।');
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withToken($again['accessToken'])->getJson('/api/v1/me')->assertOk();
    }

    public function test_after_signing_out_the_old_token_gets_nothing_even_inside_the_window(): void
    {
        $first = $this->login()->assertOk()->json();
        $second = $this->refresh($first['refreshToken'])->assertOk()->json();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withToken($second['accessToken'])->postJson('/api/v1/auth/logout')->assertOk();

        $this->refresh($first['refreshToken'])->assertUnauthorized();
    }

    public function test_a_dismissed_hand_gets_nothing_from_the_window(): void
    {
        $first = $this->login()->assertOk()->json();
        $this->refresh($first['refreshToken'])->assertOk();

        \App\Models\User::query()->where('email', 'sales@abos.test')->update(['is_active' => false]);

        $this->refresh($first['refreshToken'])->assertUnauthorized();
    }

    public function test_a_phone_left_shut_for_two_months_still_refreshes(): void
    {
        $first = $this->login()->assertOk()->json();

        $this->travel(60)->days();

        $this->refresh($first['refreshToken'])->assertOk()->assertJsonStructure(['accessToken', 'refreshToken']);
    }

    private function refresh(string $token): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withToken($token)->postJson('/api/v1/auth/refresh', ['deviceId' => self::DEVICE]);
    }

    private function login(): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/login', [
            'identifier' => 'sales@abos.test',
            'password' => 'password',
            'deviceId' => self::DEVICE,
            'appVersion' => '0.4.62',
            'platform' => 'android',
        ]);
    }
}
