<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Http\Controllers\Api\AuthController;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ফোন ঢুকতে পারত, কিন্তু জিজ্ঞেস করতে পারত না "আমি কে"।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * মালিকের সিদ্ধান্ত (৪ সেপ্টেম্বর ২০২৬): **নয়টা রোল, একটাই অ্যাপ** —
 * অ্যাপটা রোল অনুযায়ী আচরণ করবে। কিন্তু ফোনের ১৩টা দরজার একটাও বলত
 * না ব্যবহারকারী কে বা কী দেখবেন, তাই অ্যাপের পর্দা কী দেখাবে সেটা
 * **অ্যাপের ভিতরে হাতে লেখা** ছাড়া উপায় ছিল না।
 *
 * ⚠️ হাতে লেখা তালিকার দাম নীরব: কোম্পানি একটা মডিউল বন্ধ করলে বা
 * নতুন রোল বানালে **ওয়েব বদলাত, অ্যাপ বদলাত না** — আর দুইটা আলাদা
 * সত্য তৈরি হত।
 *
 * ── এই ফাইলের সবচেয়ে জরুরি অংশ ──────────────────────────────────────
 * টোকেনের চাবি আর সীমার পরীক্ষাগুলো। বাকিগুলো আকারের।
 */
class ThePhoneCouldNotAskWhoItWasTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = 'handset-me';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    /**
     * ⚠️ প্রতিটা মানুষের নিজের `deviceId` — একটা ভাগ করা নয়।
     *
     * ── এতে দুই ঘণ্টা গেছে, তাই লিখে রাখা ─────────────────────────────
     * `AuthController::login()` **ওই ডিভাইসের পুরনো টোকেনগুলো মুছে
     * দেয়** (একটা ফোন = একটা সেশন)। দুইজনকে একই `deviceId` দিলে
     * দ্বিতীয় লগইন প্রথমজনের টোকেন কেড়ে নেয়, আর তুলনাটা অর্থহীন হয়ে
     * যায় — অথচ দুইটা অনুরোধই ২০০ ফেরত দেয়, তাই ভুলটা **নীরব**।
     *
     * ⓘ এই ভুলে একবার মনে হয়েছিল অনুমতির ছাঁকনিই কাজ করছে না। মেপে
     * দেখা গেল ছাঁকনি নিখুঁত: মালিক ১৭৯ অনুমতি, বিক্রয়কর্মী ৪০।
     */
    private function login(string $who = 'sales@abos.test'): TestResponse
    {
        // ডেমোর সবার পাসওয়ার্ড `password` — README দেখুন
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/login', [
            'identifier' => $who,
            'password' => 'password',
            'deviceId' => self::DEVICE.'-'.md5($who),
            'appVersion' => '0.1.0',
            'platform' => 'android',
        ]);
    }

    /**
     * ⚠️ টোকেন দেওয়ার আগে গার্ডটা ভুলিয়ে দিতে হয়।
     *
     * ── এই এক লাইনে দুই ঘণ্টা গেছে ───────────────────────────────────
     * `login()` সফল হলে Laravel ওই অনুরোধের গার্ডে ব্যবহারকারীকে
     * **মনে রেখে দেয়**। পরের অনুরোধে `withToken()` দিলেও গার্ড আগের
     * জনকেই ফেরত দেয়, তাই দ্বিতীয় লগইনের টোকেন কার্যত অগ্রাহ্য হয়।
     *
     * ফল: দুইজন আলাদা মানুষের জন্য **হুবহু একই উত্তর** — ২৭ সারি
     * বনাম ২৭ সারি — আর মনে হয় অনুমতির ছাঁকনিই কাজ করছে না।
     *
     * ⚠️ ভুলটা নীরব: দুইটা অনুরোধই ২০০ ফেরত দেয়, JSON-ও ঠিক আসে —
     * কেবল **ভুল মানুষের**। মেপে দেখা গেছে ছাঁকনি নিখুঁত: বিক্রয়কর্মী
     * ২ মডিউল/২৭ সারি, মালিক ১২ মডিউল/১৬৬ সারি।
     */
    private function me(string $token): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson('/api/v1/me');
    }

    public function test_the_phone_can_finally_ask_who_it_is(): void
    {
        $token = $this->login()->json('accessToken');

        $this->me($token)
            ->assertOk()
            ->assertJsonStructure([
                'user' => ['public_id', 'name', 'email', 'locale', 'roles'],
                'company' => ['public_id', 'code', 'name'],
                'permissions',
                'menu',
            ]);
    }

    /**
     * ⚠️ ভেতরের ক্রমিক আইডি কোনোদিন যায় না।
     *
     * সিঙ্কের কোডে কারণটা লেখা: ক্রমিক আইডি হাতে পেলে কেউ **গুনে
     * ফেলতে পারেন "আমার আগে কতজন ছিল"** — কর্মী কতজন, গ্রাহক কতজন।
     * `public_id` কিছুই বলে না।
     */
    public function test_no_counting_id_ever_leaves_the_server(): void
    {
        $token = $this->login()->json('accessToken');

        $body = $this->me($token)->json();

        $this->assertArrayNotHasKey('id', $body['user']);
        $this->assertArrayNotHasKey('id', $body['company']);
    }

    /**
     * ⭐ চুরি যাওয়া refresh টোকেনে এই দরজা খোলে না।
     *
     * ⚠️ এটাই এই ফাইলের সবচেয়ে জরুরি পরীক্ষা। access টোকেনের মেয়াদ
     * ছোট (৩০ মিনিট) আর refresh টোকেনের লম্বা (৩০ দিন) — refresh
     * দিয়ে সাধারণ দরজা খোলা গেলে **ছোট মেয়াদটার পুরো মানেই থাকত না**।
     */
    public function test_a_refresh_token_does_not_open_this_door(): void
    {
        $refresh = $this->login()->json('refreshToken');

        $this->me($refresh)->assertForbidden();
    }

    /** লগইন ছাড়া কিছুই নয়। */
    public function test_without_a_token_there_is_no_answer(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    /**
     * ⭐ সিঙ্কের চাবি আর অ্যাপের চাবি আলাদা — দুইটাই টোকেনে থাকে।
     *
     * ⓘ নামটা আলাদা রাখা হয়েছে যাতে `sync` নামটার মানে থাকে: `/me`
     * সিঙ্ক নয়। একই টোকেনে দুইটা চাবি বসে, তাই ফোনকে দুইটা টোকেন
     * রাখতে হয় না।
     */
    public function test_the_access_token_carries_both_keys(): void
    {
        $token = $this->login()->json('accessToken');

        // সিঙ্কের দরজাও একই টোকেনে খোলে
        $this->withToken($token)->getJson('/api/v1/sync/capabilities')->assertOk();
        $this->me($token)->assertOk();

        $this->assertNotSame(AuthController::ACCESS, AuthController::APP, 'দুইটা চাবির নাম এক হয়ে গেছে।');
    }

    /**
     * ⭐ মেনু ব্যবহারকারী ধরে বদলায় — এটাই "রোল অনুযায়ী আচরণ"।
     *
     * ⚠️ শুধু "মেনু আছে" দেখলে যথেষ্ট নয়: একটা স্থির তালিকা ফেরত
     * দিলেও ওটা সবুজ থাকত। তাই দুইজন আলাদা রোলের উত্তর **মিলিয়ে**
     * দেখা হয়।
     */
    public function test_two_people_do_not_get_the_same_menu(): void
    {
        $salesman = $this->me($this->login('sales@abos.test')->json('accessToken'))->json();

        $this->app['auth']->forgetGuards();
        $owner = $this->me($this->login('owner@abos.test')->json('accessToken'))->json();

        // ⚠️ শূন্যটা আগে দেখে নেওয়া — খালি দুইটা তালিকা সবসময় "আলাদা নয়"
        $this->assertNotEmpty($owner['menu'], 'মালিকের মেনুই খালি — তুলনাটা তখন অর্থহীন।');

        /*
         * ⚠️ মডিউলের সংখ্যা নয়, **সারির** সংখ্যা।
         *
         * প্রথমে মডিউল গুনেছিলাম, আর দুইজনেই ২ পাচ্ছিলেন — মনে হচ্ছিল
         * অনুমতি দেখাই হচ্ছে না। **আসলে হচ্ছিল**: এই ফিকশ্চারে হাতে
         * গোনা কয়টা মডিউল, আর দুইজনেরই ওই দুইটাতে কিছু না কিছু
         * অনুমতি আছে। পার্থক্যটা মডিউলে নয়, **ভিতরের সারিগুলোয়**।
         *
         * ⓘ ওয়েবে সংখ্যাটা ১২ বনাম ১ — কারণ ওখানে বহু মডিউল বসানো।
         * অর্থাৎ মোটা তুলনা এক জায়গায় সবুজ, অন্য জায়গায় লাল হত, আর
         * দুইটার কোনোটাই সত্যি বলত না।
         */
        $rows = function (array $menu): int {
            $n = 0;

            foreach ($menu as $module) {
                foreach ($module['groups'] as $group) {
                    $n += count($group);
                }
            }

            return $n;
        };

        $this->assertGreaterThan(
            $rows($salesman['menu']),
            $rows($owner['menu']),
            'মালিক ও বিক্রয়কর্মী একই সংখ্যক সারি পাচ্ছেন — অনুমতি দেখা হচ্ছে না।',
        );

        // আর সবচেয়ে সরাসরি প্রমাণ: দুইজন সত্যিই দুইজন
        $this->assertNotSame($salesman['user']['email'], $owner['user']['email']);

        $this->assertGreaterThan(
            count($salesman['permissions']),
            count($owner['permissions']),
            'মালিকের অনুমতি বিক্রয়কর্মীর চেয়ে বেশি হওয়ার কথা।',
        );
    }

    /**
     * ⭐ অনুমতির তালিকা সত্যিই ব্যবহারকারীর, স্থির কিছু নয়।
     *
     * ⓘ অ্যাপ এই তালিকা দেখে বোতাম দেখাবে কি না ঠিক করে, তাই ভুল হলে
     * ব্যবহারকারী এমন বোতাম দেখতেন যা চাপলে ৪০৩।
     */
    public function test_the_permissions_belong_to_the_person_not_to_the_server(): void
    {
        $token = $this->login('sales@abos.test')->json('accessToken');

        $sent = $this->me($token)->json('permissions');
        $real = User::query()->where('email', 'sales@abos.test')->firstOrFail()
            ->getAllPermissions()->pluck('name')->sort()->values()->all();

        $this->assertNotEmpty($real, 'বিক্রয়কর্মীর একটাও অনুমতি নেই — তুলনাটা তখন অর্থহীন।');
        $this->assertSame($real, $sent);
    }

    /**
     * ⚠️ মেনুতে ওয়েবের ঠিকানা যায় না — কেবল রুটের নাম।
     *
     * অ্যাপের নিজের পর্দা আছে; সে নামটা দেখে নিজের পর্দায় যায়। URL
     * পাঠালে অ্যাপ ওয়েবের ঠিকানার সাথে বাঁধা পড়ত, আর ওয়েবে একটা রুট
     * সরালে অ্যাপ **নীরবে ভুল জায়গায়** পাঠাত।
     */
    public function test_the_menu_carries_names_not_web_addresses(): void
    {
        $menu = $this->me($this->login('owner@abos.test')->json('accessToken'))->json('menu');

        $this->assertNotEmpty($menu);

        foreach ($menu as $module) {
            foreach ($module['groups'] as $rows) {
                foreach ($rows as $row) {
                    $this->assertArrayNotHasKey('url', $row, 'মেনুতে ওয়েবের ঠিকানা চলে গেছে।');
                    $this->assertArrayHasKey('route', $row);
                }
            }
        }
    }
}
