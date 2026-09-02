<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * দুইটা দরজা, আর ব্রাউজারই মনে রাখে কোনটা কার।
 *
 * ── কী চাওয়া হয়েছিল ──────────────────────────────────────────────────
 * মালিকের কথা: *"প্রথম দরজা প্রথমবার লগিনে দেখা যাবে, রেগুলার যারা
 * ইউজ করবে তারা সবসময় দ্বিতীয় দরজা পাবে।"*
 *
 * `/login` পূর্ণ ব্র্যান্ড পাতা — আটটা বৈশিষ্ট্য, লকআপ, আলপনা। যিনি
 * ABOS প্রথমবার দেখছেন তাঁর জন্য। `/signin` তার উল্টো: দুইটা ঘর, একটা
 * বোতাম। **পঞ্চাশতম দিনে বিক্রির পাতা আর কোনো খবর নয়, কেবল একটা দেয়াল।**
 *
 * ── কেন এই পাহারাটা লাগে ─────────────────────────────────────────────
 * পুরো আচরণটা **একটা কুকির উপর দাঁড়ানো**, আর কুকি এমন জিনিস যা
 * নীরবে হারায়: কেউ `Cookie::queue` লাইনটা তুলে দিলে লগইন আগের মতোই
 * কাজ করত, টেস্ট সবুজ থাকত, আর কেবল **প্রতিদিনের ব্যবহারকারী চিরকাল
 * বিক্রির পাতা দেখতেন** — যা কেউ বাগ বলে রিপোর্টও করেন না।
 *
 * ── আর `?full` কেন আলাদা করে পরীক্ষা করা হয় ──────────────────────────
 * চিহ্ন বসে যাওয়ার পর `/login` শান্ত দরজায় ফেরত পাঠায়। ওই এড়ানোর
 * পথটা না থাকলে পূর্ণ পাতাটায় **আর কোনোদিন পৌঁছানোই যেত না** — আর যে
 * পাতা কেউ দেখে না, সেটা একদিন নীরবে ভেঙে পড়ে থাকে।
 */
class TwoDoorsAndTheBrowserRemembersTest extends TestCase
{
    use RefreshDatabase;

    private const RETURNING = 'abos_returning';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    /** নতুন ব্রাউজার পূর্ণ পাতাটাই দেখে। */
    public function test_a_browser_that_has_never_been_here_sees_the_full_door(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(__('core.brand.full_name'), false);
    }

    /**
     * সফলভাবে ঢোকার পর ব্রাউজারে চিহ্ন বসে।
     *
     * চিহ্নটা কেবল `1` — কে ঢুকেছিলেন, কোন কোম্পানি, কিছুই নয়। এটা
     * একটা পছন্দ, পরিচয় নয়।
     */
    public function test_a_successful_login_marks_the_browser(): void
    {
        $this->post(route('login.store'), [
            'identifier' => $this->user->email,
            'password' => 'password',
        ])->assertPlainCookie(self::RETURNING, '1');
    }

    /** চিহ্ন থাকলে `/login` শান্ত দরজায় পাঠিয়ে দেয়। */
    public function test_a_returning_browser_is_sent_to_the_quiet_door(): void
    {
        $this->withUnencryptedCookie(self::RETURNING, '1')
            ->get(route('login'))
            ->assertRedirect(route('login.calm'));
    }

    /**
     * তবু `?full` দিলে পূর্ণ পাতাটা পাওয়া যায়।
     *
     * শান্ত পাতার পাদটীকার লিংকটা ঠিক এটাই ব্যবহার করে।
     */
    public function test_the_full_door_stays_reachable_on_purpose(): void
    {
        $this->withUnencryptedCookie(self::RETURNING, '1')
            ->get(route('login', ['full' => 1]))
            ->assertOk()
            ->assertSee(__('core.brand.full_name'), false);
    }

    /** শান্ত দরজাটা নিজে কাউকে কোথাও পাঠায় না — নাহলে চক্র হত। */
    public function test_the_quiet_door_never_redirects(): void
    {
        $this->withUnencryptedCookie(self::RETURNING, '1')
            ->get(route('login.calm'))
            ->assertOk();

        $this->get(route('login.calm'))->assertOk();
    }

    /**
     * পূর্ণ দরজায় ফর্ম নেই — একটাই বোতাম, আর সেটা শান্ত দরজায় নিয়ে যায়।
     *
     * ── এই দাবিটা আগে উল্টো ছিল ─────────────────────────────────────
     * প্রথম খসড়ায় লেখা হয়েছিল "দুইটা দরজার ফর্ম হুবহু এক", আর দুইটা
     * পাতাতেই ঘরগুলো খোঁজা হত। তারপর মালিক বললেন প্রথম দরজায়
     * **শুধু লগইন বোতাম** থাকবে — অর্থাৎ পাতাটার কাজ পরিচয় করানো,
     * কাজ করানো নয়।
     *
     * দাবিটা তাই বদলাল। **পরীক্ষাটা মুছে দেওয়া হয়নি**, কারণ আসল
     * ঝুঁকিটা রয়ে গেছে: কেউ একদিন ফর্মটা ফিরিয়ে এনে দুই জায়গায়
     * বসিয়ে দিতে পারেন, আর তখন দুইটা আলাদা হয়ে যেত।
     */
    public function test_the_full_door_only_points_at_the_quiet_one(): void
    {
        $html = (string) $this->get(route('login', ['full' => 1]))->getContent();

        $this->assertStringContainsString(route('login.calm'), $html,
            'পূর্ণ দরজা থেকে শান্ত দরজায় যাওয়ার পথ নেই।');

        $this->assertStringNotContainsString('name="identifier"', $html,
            'পূর্ণ দরজায় ফর্ম ফিরে এসেছে — তাহলে দুইটা ফর্ম আলাদা হয়ে যাওয়ার ঝুঁকি ফিরল।');
    }

    /**
     * আর ফর্মটা শান্ত দরজায়, একটাই ফাইল থেকে।
     *
     * ঘরগুলোর নাম ও `autocomplete` এখানে মিলিয়ে দেখা হয় — কেউ ফর্মটা
     * কপি করে বসালে ওগুলোর একটা না একটা বাদ পড়ে, আর পাসওয়ার্ড
     * ম্যানেজার তখন চুপচাপ কাজ করা বন্ধ করে দেয়।
     */
    public function test_the_quiet_door_carries_the_whole_form(): void
    {
        $html = (string) $this->get(route('login.calm'))->getContent();

        foreach ([route('login.store'), 'name="identifier"', 'name="password"',
            'autocomplete="username"', 'autocomplete="current-password"'] as $needle) {
            $this->assertStringContainsString($needle, $html, "শান্ত দরজায় নেই: {$needle}");
        }
    }

    /**
     * শান্ত দরজায় কোম্পানির ঘর নেই।
     *
     * NEXUS-এর ওই পাতায় একটা "কোম্পানি আইডি" ঘর আছে, আর নকল করতে
     * গিয়ে সেটা এখানে চলে আসা সহজ। কিন্তু ABOS-এ ওটা **ইচ্ছে করেই
     * নেই**: কোম্পানির নাম চাওয়া মানে সার্ভারে কোন কোন প্রতিষ্ঠান আছে
     * তা বাইরে বলে দেওয়া ([[LoginController]]-এর ব্যাখ্যা)।
     */
    public function test_the_quiet_door_does_not_ask_which_company(): void
    {
        $html = (string) $this->get(route('login.calm'))->getContent();

        foreach (['name="company"', 'name="company_id"', 'name="tenant"'] as $field) {
            $this->assertStringNotContainsString($field, $html);
        }
    }
}
