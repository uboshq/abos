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

        // ⓘ ফোন উত্তরটা পায়নি — নতুন জোড়া কখনো ব্যবহার হয়নি; দশ মিনিট পরে পুরনো টোকেনেই আবার (১৫ মিনিটের সীমার মধ্যে)
        $this->travel(10)->minutes();
        $again = $this->refresh($first['refreshToken'])->assertOk()->json();

        $this->assertNotSame($lost['refreshToken'], $again['refreshToken'], '⛔ হারানো জোড়াটাই আবার দেওয়া হল।');
        $this->assertNull(PersonalAccessToken::findToken($lost['refreshToken']), '⛔ অব্যবহৃত হারানো জোড়া বেঁচে রইল।');
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withToken($again['accessToken'])->getJson('/api/v1/me')->assertOk();
    }

    /**
     * ⛔ দ্বিতীয় নিরীক্ষা, ২১ নভেম্বর ২০২৬: আগে চুরি যাওয়া পুরনো টোকেন অনেক পরে এনে কেউ নতুন জোড়া পেতেন, আর আসল ফোনের অব্যবহৃত জোড়া
     * মুছে আসল মানুষকে বের করে দিতেন। এখন ১৫ মিনিটের পরে সেটা পুনঃব্যবহার — ঐ ফোনের সব টোকেন বাতিল, চোর কিছু পান না।
     */
    public function test_an_old_token_brought_back_after_the_lost_answer_window_gets_nothing(): void
    {
        $first = $this->login()->assertOk()->json();
        $this->refresh($first['refreshToken'])->assertOk();

        $this->travel(20)->minutes();

        $this->refresh($first['refreshToken'])->assertUnauthorized();
        $this->assertFalse(PersonalAccessToken::query()->where('name', 'like', '%:'.self::DEVICE)->exists(),
            '⛔ পুরনো টোকেন ফিরে এল, অথচ ফোনের টোকেন বাতিল হল না।');
    }

    public function test_the_lost_answer_path_needs_the_same_phone(): void
    {
        $first = $this->login()->assertOk()->json();
        $this->refresh($first['refreshToken'])->assertOk();

        $this->travel(5)->minutes();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withToken($first['refreshToken'])->postJson('/api/v1/auth/refresh', ['deviceId' => 'a-thiefs-phone'])
            ->assertUnauthorized();
    }

    /** ⛔ জোড়ার লেখা ক্যাশে কেবল টক্কর-জানালার জন্য — এক বছর নয় (দ্বিতীয় নিরীক্ষা, ২১ নভেম্বর ২০২৬) */
    public function test_the_pair_itself_leaves_the_cache_after_the_window(): void
    {
        $first = $this->login()->assertOk()->json();
        $second = $this->refresh($first['refreshToken'])->assertOk()->json();

        $this->assertStringNotContainsString($second['refreshToken'], json_encode(\Illuminate\Support\Facades\Cache::get(
            'refresh-used:'.hash('sha256', $first['refreshToken']))), '⛔ নতুন টোকেনের লেখা লম্বা মেয়াদের চিহ্নে।');

        $this->travel(4)->minutes();
        $this->assertNull(\Illuminate\Support\Facades\Cache::get('refresh-pair:'.hash('sha256', $first['refreshToken'])),
            '⛔ জোড়াটা জানালার পরেও ক্যাশে।');
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
