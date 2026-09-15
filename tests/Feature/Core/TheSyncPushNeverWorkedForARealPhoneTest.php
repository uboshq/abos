<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $user->switchCompany($company->id);

        Sanctum::actingAs($user);
    }

    /**
     * ⭐ ফোন যেভাবে ডাকে — ঠিকানায় `?deviceId=` সহ।
     *
     * ⛔ এই একটা প্যারামিটারই গোটা সিঙ্কটা বন্ধ করে রেখেছিল।
     */
    public function test_a_push_with_the_device_id_in_the_query_is_accepted(): void
    {
        $response = $this->postJson(
            '/api/v1/sync/sales/push?deviceId='.self::DEVICE,
            [$this->anOrder()],
        );

        $response->assertOk();

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
        $this->postJson('/api/v1/sync/sales/push', [$this->anOrder()])
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
        $this->postJson(
            '/api/v1/sync/sales/push?deviceId='.self::DEVICE,
            ['changes' => [$this->anOrder()]],
        )->assertStatus(422);
    }

    /**
     * @return array<string, mixed>
     */
    private function anOrder(): array
    {
        return [
            'clientId' => 'phone-order-0001',
            'kind' => 'order',
            'payload' => [
                'trxDate' => now()->toDateString(),
                'lines' => [],
            ],
        ];
    }
}
