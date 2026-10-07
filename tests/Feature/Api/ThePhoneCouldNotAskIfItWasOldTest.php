<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ফোন জিজ্ঞেস করতে পারত না সে পুরনো কিনা — চুক্তি §৬।
 *
 * ⓘ সরাসরি APK-তে Play Store-এর মতো আপনাআপনি কিছু হয় না, তাই ফোনটা নিজেই
 * জিজ্ঞেস করে। ⛔ দরজাটা ভুল বললে দুই রকম ক্ষতি: মাঠের প্রতিটা ফোনে দেয়াল
 * ওঠে, নয়তো একটা ভুল ফাইলের লিংক যায়। দুইটাই নীরব — অ্যাপ চলে, কেউ লাল
 * দেখে না।
 *
 * ⚠️ এই দরজা ডাটাবেস ছোঁয় না, তাই RefreshDatabase নেই।
 */
final class ThePhoneCouldNotAskIfItWasOldTest extends TestCase
{
    private const CONFIGURED = [
        'version_code' => '3',
        'version_name' => '0.2.0',
        'url' => 'https://erp.example.test/app/abos.apk',
        'apk_sha256' => '3f5a00000000000000000000000000000000000000000000000000000000c09e',
        'size_bytes' => '74213888',
        'minimum_code' => '1',
        'note' => ['bn' => 'নতুন সংস্করণ', 'en' => 'New version'],
    ];

    /** ⭐ টোকেন ছাড়াই খোলে — পুরনো বিল্ড লগইন করতে না পারলেও জানবে। */
    public function test_the_door_opens_without_a_token(): void
    {
        config(['mobile.android' => self::CONFIGURED]);

        $this->getJson('/api/v1/app/version')
            ->assertOk()
            ->assertExactJson([
                'versionCode' => 3,
                'versionName' => '0.2.0',
                'url' => 'https://erp.example.test/app/abos.apk',
                'apkSha256' => '3f5a00000000000000000000000000000000000000000000000000000000c09e',
                'sizeBytes' => 74213888,
                'minimumCode' => 1,
                'note' => ['bn' => 'নতুন সংস্করণ', 'en' => 'New version'],
            ]);
    }

    /**
     * ⛔ versionCode আর minimumCode সংখ্যা, স্ট্রিং নয়।
     *
     * ⓘ .env সব মান স্ট্রিং হিসেবে দেয়। ⚠️ স্ট্রিং গেলে ফোনের তুলনা
     * অক্ষর ধরে হত, আর তখন "10" < "9" — দশম বিল্ড নিজেকে পুরনো ভাবত।
     */
    public function test_the_codes_travel_as_numbers(): void
    {
        config(['mobile.android' => ['version_code' => '10'] + self::CONFIGURED]);

        $raw = $this->getJson('/api/v1/app/version')->assertOk()->getContent();

        $this->assertStringContainsString('"versionCode":10', $raw);
        $this->assertStringContainsString('"minimumCode":1', $raw);
        $this->assertStringContainsString('"sizeBytes":74213888', $raw, '⛔ আকার সংখ্যা — স্ট্রিং গেলে ফোনের তুলনা অক্ষর ধরে হত।');
    }

    /**
     * ⛔ কিছু বসানো না থাকলে 503 — আর শরীরে কোনো versionCode নেই।
     *
     * ⚠️ `versionCode: 0` একটা আসল উত্তরের মতো দেখাত। ⓘ ব্যর্থ পরীক্ষায়
     * ফোন কিছুই করে না (চুক্তির নিয়ম ক), তাই 503-ই নিরাপদ দিক।
     */
    public function test_an_empty_env_is_not_an_answer(): void
    {
        config(['mobile.android' => [
            'version_code' => null, 'version_name' => null, 'url' => null,
            'apk_sha256' => null, 'size_bytes' => null, 'minimum_code' => null, 'note' => ['bn' => null, 'en' => null],
        ]]);

        $this->getJson('/api/v1/app/version')
            ->assertStatus(503)
            ->assertExactJson(['configured' => false])
            ->assertJsonMissingPath('versionCode');
    }

    /** ⛔ অর্ধেক বসানো মানে বসানো নেই — URL ছাড়া সংস্করণ ফোনকে কোথাও পাঠায় না। */
    public function test_a_half_configured_release_is_not_an_answer(): void
    {
        config(['mobile.android' => ['url' => ''] + self::CONFIGURED]);

        $this->getJson('/api/v1/app/version')->assertStatus(503)->assertJsonMissingPath('versionCode');
    }

    /**
     * ⛔ minimumCode > versionCode হলে 503, দেয়াল নয়।
     *
     * ⚠️ এটা মানলে সবচেয়ে নতুন বিল্ডও "আর চলবে না" পেত — মাঠের প্রতিটা
     * ফোন তালাবন্ধ, নেট থাকা অবস্থাতেই। ওটা ভুল বসানো, সিদ্ধান্ত নয়।
     */
    public function test_a_minimum_above_the_latest_locks_nobody_out(): void
    {
        config(['mobile.android' => ['minimum_code' => '4'] + self::CONFIGURED]);

        $this->getJson('/api/v1/app/version')->assertStatus(503)->assertJsonMissingPath('minimumCode');
    }

    /** ⛔ সংখ্যা নয় এমন কোড ("3a") বসানো নেই ধরা হয় — (int) "3a" চুপচাপ 3 হয়ে যেত। */
    public function test_a_code_that_is_not_a_number_is_not_an_answer(): void
    {
        config(['mobile.android' => ['version_code' => '3a'] + self::CONFIGURED]);

        $this->getJson('/api/v1/app/version')->assertStatus(503);
    }

    /**
     * ⛔ নোট দুই ভাষায়, নাহলে নেই (চুক্তির নিয়ম ঘ)।
     *
     * ⓘ এক ভাষার নোট অন্য ভাষার ফোনে অপঠিত লেখা হয়ে বসত।
     */
    public function test_a_note_in_one_language_is_not_sent(): void
    {
        config(['mobile.android' => ['note' => ['bn' => 'শুধু বাংলা', 'en' => '']] + self::CONFIGURED]);

        $this->getJson('/api/v1/app/version')
            ->assertOk()
            ->assertJsonPath('versionCode', 3)
            ->assertJsonMissingPath('note');
    }

    /**
     * ⛔ অ্যাপ নিজে APK বসায় — তাই যে ফাইল ফোন মিলিয়ে দেখতে পারবে না,
     * তার পথ সার্ভার খোলে না (মালিকের সিদ্ধান্ত, ২৭ সেপ্টেম্বর ২০২৬)।
     *
     * ⓘ প্রতিটা ভুল একটা একটা করে, বাকি সব ঠিক রেখে — যাতে 503-টা ঠিক
     * ওই ভুলেরই, অন্য কোনো ঘরের নয়।
     *
     * @return array<string, array{0: array<string, string>}>
     */
    public static function unverifiableFiles(): array
    {
        return [
            'sha ফাঁকা' => [['apk_sha256' => '']],
            'sha বড় হাতের' => [['apk_sha256' => strtoupper(str_repeat('ab', 32))]],
            'sha ৬৩ অক্ষর' => [['apk_sha256' => str_repeat('a', 63)]],
            'sha hex নয়' => [['apk_sha256' => str_repeat('g', 64)]],
            'আকার ফাঁকা' => [['size_bytes' => '']],
            'আকার শূন্য' => [['size_bytes' => '0']],
            'আকার সংখ্যা নয়' => [['size_bytes' => '74mb']],
            'লিংক http' => [['url' => 'http://erp.example.test/app/abos.apk']],
        ];
    }

    /** @param array<string, string> $broken */
    #[\PHPUnit\Framework\Attributes\DataProvider('unverifiableFiles')]
    public function test_a_file_the_phone_cannot_verify_is_not_offered(array $broken): void
    {
        config(['mobile.android' => $broken + self::CONFIGURED]);

        $this->getJson('/api/v1/app/version')
            ->assertStatus(503)
            ->assertExactJson(['configured' => false]);
    }

    /** ⚠️ খোলা দরজা, তাই throttle — চাবির বদলে এটাই পাহারা। */
    public function test_the_open_door_is_throttled(): void
    {
        $middleware = Route::getRoutes()->getByName('api.app.version')?->gatherMiddleware() ?? [];

        /*
         * ⓘ নিজের থলে (`app-version`) — ২৯ সেপ্টেম্বর ২০২৬ থেকে; আগে এই ডাক
         * লগইনের থলেতেই গোনা হত, আর দোকানের ফোনগুলো অ্যাপ খুললেই সবার লগইন
         * আটকাত ([[OneShopSharesOneAddressTest]])। সীমা একই, মিনিটে ৬০।
         */
        $this->assertContains('throttle:60,1,app-version', $middleware);
        $this->assertNotContains('auth:sanctum', $middleware);
    }
}
