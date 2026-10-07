<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * নিয়ম মেনে চললেই সিঙ্ক ব্যর্থ হত, আর নীরবে।
 *
 * ── ⛔ কী ধরা পড়েছিল, ১৫ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * মাঠের অ্যাপ থেকে একটা অর্ডার লিখে দেখা গেল সেটা কিউতে **চিরকাল
 * অপেক্ষমাণ**। কোনো প্রত্যাখ্যাত সারি নেই, কোনো লাল বার্তা নেই। শুধু
 * `logcat`-এ:
 *
 *     ABOS sync: sales push failed (DioException … status code of 422)
 *
 * ⓘ সেলসম্যান দেখতেন "১ অপেক্ষমাণ" আর ভাবতেন নেট নেই।
 *
 * ── ⚠️ কারণ, আর কেন এটা এত নিষ্ঠুর ──────────────────────────────────
 * `SyncController::push()` লিখত `$request->all()`, আর সেটা **শরীর ও
 * query string একসাথে** দেয়। সিঙ্কের চুক্তি §২ বলে প্রতিটা দরজায়
 * `?deviceId=` থাকতেই হবে।
 *
 * ⛔ তাই শরীর `[0 => {…}]` আর query `['deviceId' => '…']` মিলে হত
 * `[0 => {…}, 'deviceId' => '…']` — যা `array_is_list()`-এ ব্যর্থ, আর
 * সার্ভার ৪২২ দিত *"এন্ট্রিটা খালি এসেছে"*।
 *
 * ⭐ অর্থাৎ **যত সঠিকভাবে ডাকা হত, তত নিশ্চিতভাবে ব্যর্থ** — আর
 * `deviceId` ছাড়া ডাকলে আরও আগে থেমে যেত (৪০০, "ফোন নিবন্ধিত নয়")।
 * দুই দিকেই বন্ধ দরজা।
 *
 * ── ⓘ কোনো পরীক্ষা এটা ধরেনি কেন ────────────────────────────────────
 * সার্ভার-সাইড পরীক্ষাগুলো `postJson('/api/v1/sync/sales/push', [...])`
 * ডাকত — **query string ছাড়া**। তখন `$request->all()` বিশুদ্ধ তালিকা,
 * আর সব সবুজ।
 *
 * ⚠️ **পরীক্ষাটা আসল পথে হাঁটেনি**, আর সেটাই একমাত্র কারণ। তাই নিচের
 * প্রতিটা দাবি ঠিকানায় `?deviceId=` রেখে ডাকে — যেভাবে ফোন ডাকে।
 */
final class TheSyncPushNeverWorkedForARealPhoneTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = 'abos-test-phone-01';

    private string $token;

    /**
     * ⛔ এই পরীক্ষাটা নিজেই কোনোদিন চলেনি — ১৫ সেপ্টেম্বর ২০২৬।
     *
     * ── কী ছিল ───────────────────────────────────────────────────────
     * এখানে লেখা ছিল `Sanctum::actingAs($user)`, আর তিনটা দাবিই
     * প্রতিবার **৪০৩** পেত — ২০০/৪০০/৪২২ কোনোটাই নয়।
     *
     * ⓘ কারণ: Sanctum-এর `actingAs($user, $abilities = [])` — ডিফল্ট
     * **খালি তালিকা**, `['*']` নয়। খালি ability-র টোকেন বানানো হত, আর
     * `/api/v1/*`-এর `abilities:sync` মিডলওয়্যার সেটাকে ফিরিয়ে দিত।
     *
     * ⚠️ অর্থাৎ অনুরোধটা `SyncController`-এ **পৌঁছাতই না**। যে বাগটা
     * ধরার জন্য পরীক্ষাটা লেখা, সেই কোডের একটা লাইনও চলেনি — অথচ
     * পরীক্ষাটা আত্মবিশ্বাসের সাথে লাল হয়ে বসে ছিল, আর সারাইটা
     * (`$request->json()->all()`) **অপ্রমাণিত অবস্থায় লাইভে গেছে**।
     *
     * ── ⭐ কেন এখন আসল লগইন, আর শর্টকাট নয় ──────────────────────────
     * শর্টকাটটাই ভুলটা তৈরি করেছিল। আর এই পরীক্ষার গোটা যুক্তিই হলো
     * **"ফোন যে পথে হাঁটে সেই পথে হাঁটা"** — উপরের ব্যাখ্যায় লেখা আছে
     * পুরনো পরীক্ষাগুলো `?deviceId=` ছাড়া ডাকত বলেই বাগটা ধরা পড়েনি।
     *
     * ⓘ একই ভুল দুইবার: প্রথমে query প্যারামিটার বাদ, তারপর গোটা
     * টোকেন-পথটাই বাদ। তাই এখন `/api/v1/auth/login` দিয়েই টোকেন নেওয়া
     * হয় — ঠিক যেভাবে [[ThePhoneCouldNotAskWhoItWasTest]] করে।
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        /*
         * ⚠️ টোকেন নেওয়ার আগে গার্ডটা ভুলিয়ে দিতে হয় — নাহলে পরের
         * অনুরোধে Laravel আগের অনুরোধে মনে রাখা ব্যবহারকারীকেই ফেরত
         * দেয়, আর `withToken()` কার্যত অগ্রাহ্য হয়।
         */
        $this->app['auth']->forgetGuards();

        $login = $this->postJson('/api/v1/auth/login', [
            // ডেমোর সবার পাসওয়ার্ড `password` — README দেখুন
            'identifier' => 'sales@abos.test',
            'password' => 'password',
            'deviceId' => self::DEVICE,
            'appVersion' => '0.1.0',
            'platform' => 'android',
        ]);

        $this->token = (string) $login->json('accessToken');

        $this->assertNotSame('', $this->token, implode("\n", [
            'লগইনই হয়নি — নিচের কোনো দাবিই তাহলে সিঙ্কের কথা বলছে না।',
            '',
            'সার্ভার দিয়েছে '.$login->status().': '.$login->getContent(),
        ]));

        $this->app['auth']->forgetGuards();
    }

    /**
     * ⭐ ফোন যেভাবে ডাকে — ঠিকানায় `?deviceId=` সহ।
     *
     * ⛔ এই একটা প্যারামিটারই গোটা সিঙ্কটা বন্ধ করে রেখেছিল।
     */
    public function test_a_push_with_the_device_id_in_the_query_is_accepted(): void
    {
        $response = $this->withToken($this->token)->postJson(
            '/api/v1/sync/sales/push?deviceId='.self::DEVICE,
            [$this->anOrder()],
        );

        /*
         * ⓘ `assertOk()` নয় — সে কেবল সংখ্যাটা বলে, শরীরটা নয়।
         *
         * ⚠️ ৪২২-এর কারণ দুইটা হতে পারে: তালিকার আকৃতি ভাঙা, নাকি
         * সারিটার নিজের যাচাই ব্যর্থ। সংখ্যাটা দুইটার তফাত করে না,
         * আর তফাতটা না জানলে সারাইটা অনুমানে হয়।
         */
        $this->assertSame(200, $response->status(), implode("\n", [
            'সার্ভার সারিটা নেয়নি — ফেরত দিয়েছে '.$response->status().'।',
            '',
            'শরীর: '.$response->getContent(),
        ]));

        $this->assertArrayHasKey('outcomes', $response->json(), implode("\n", [
            'সার্ভার সারিটা নেয়নি।',
            '',
            '⛔ ৪২২ পেলে কারণটা প্রায় নিশ্চিতভাবে `$request->all()` —',
            'সে শরীরের সাথে query string মিলিয়ে দেয়, আর তালিকাটা',
            'আর তালিকা থাকে না।',
        ]));
    }

    /**
     * ⛔ `deviceId` ছাড়া দরজাটা আগেই বন্ধ — আর ওটাই বাগটাকে অনিবার্য
     * করে তুলেছিল।
     *
     * ── ⚠️ দুই দিকেই বন্ধ ────────────────────────────────────────────
     * `deviceId()` পড়ে **কেবল query থেকে** (`$request->query()`), আর
     * খালি হলে ৪০০। ⓘ অর্থাৎ একটা বৈধ push-এ ঐ প্যারামিটারটা **থাকতেই
     * হবে** — আর থাকলেই পুরনো `$request->all()` তালিকাটা ভেঙে দিত।
     *
     * ⭐ তাই বাগটা "কখনো কখনো" ছিল না, ছিল **প্রতিবার, প্রতিটা আসল
     * ফোনে**। ⓘ আর সেজন্যই একটাও সফল push কোনোদিন হয়নি।
     *
     * ⚠️ দাবিটা এখানে রাখা হলো যাতে কেউ ভবিষ্যতে `deviceId`-কে
     * ঐচ্ছিক বানালে সেটা চোখে পড়ে — নীরবে নয়।
     */
    public function test_a_push_without_a_device_id_is_refused_before_the_body_is_read(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/sync/sales/push', [$this->anOrder()])
            ->assertStatus(400);
    }

    /**
     * ⛔ সত্যিকারের খালি শরীর এখনো ৪২২ পায়।
     *
     * ⓘ পাহারাটা তুলে দেওয়া হয়নি, কেবল সে এখন **ঠিক জিনিসটা** দেখে।
     * ⚠️ এই দাবিটা না থাকলে "সারাই" মানে হত পাহারাটা বন্ধ করে দেওয়া,
     * আর তখন একটা ভুল আকৃতির অনুরোধ নীরবে গ্রহণ হত।
     */
    public function test_a_body_that_is_not_a_list_is_still_refused(): void
    {
        $this->withToken($this->token)->postJson(
            '/api/v1/sync/sales/push?deviceId='.self::DEVICE,
            ['changes' => [$this->anOrder()]],
        )->assertStatus(422);
    }

    /**
     * ⭐ তারে ঠিক যা যায় — `sync_engine.dart`-এর `flush()` থেকে নেওয়া।
     *
     * ── ⛔ আগে এখানে যা ছিল, আর কেন সেটা সবকিছু নষ্ট করেছিল ──────────
     * লেখা ছিল `clientId` · `kind` · `payload` — তিনটা ঘর, **তিনটাই
     * বানানো**। আসল চুক্তি ছয় ঘরের, আর নামগুলোও আলাদা।
     *
     * ⚠️ ফল: [[PushedChange::fromArray]] প্রথম যাচাইতেই সারিটা ফিরিয়ে
     * দিত, তাই পরীক্ষাটা যে বাগটা ধরার জন্য লেখা সেটা **স্পর্শই করত না**।
     *
     * ⓘ সবচেয়ে সূক্ষ্ম ফাঁদটা `payloadJson`: তারে ওটা একটা **JSON
     * স্ট্রিং**, নেস্টেড অবজেক্ট নয়। ফোন ওটা কিউতে বসানোর সময়ই
     * `jsonEncode` করে রাখে, যাতে সারিটা কিউতে বসে থাকা অবস্থায়
     * অ্যাপের মডেল বদলে গেলেও সারিটা অটুট থাকে।
     *
     * ⭐ তাই ফিক্সচারটা এখন অনুমান নয় — `sync_engine.dart:420-427`
     * থেকে ঘরে ঘরে মেলানো।
     *
     * @return array<string, mixed>
     */
    private function anOrder(): array
    {
        return [
            'changeId' => 'local-'.self::DEVICE.'-0001',
            'entityType' => 'SalesOrder',
            'entityId' => null,     // CREATE-এ আইডি থাকে না, সার্ভার বানায়
            'operation' => 'CREATE',
            'payloadJson' => json_encode([
                'trxDate' => now()->toDateString(),
                'lines' => [],
            ], JSON_THROW_ON_ERROR),
            'clientVersion' => 1,
        ];
    }
}
