<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\RefuseRetiredAppBuilds;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ বন্ধ করা বিল্ড সার্ভারেও ফেরে — পূর্ণ নিরীক্ষা §৯ ⚠️৯, ১৮ অক্টোবর ২০২৬ ([[RefuseRetiredAppBuilds]])।
 *
 * ⛔ আগে সর্বনিম্ন সংস্করণ কেবল ফোনের নিজের পরীক্ষা — সার্ভার `X-App-Version` কেবল খাতায় লিখত, বন্ধ বিল্ডও লিখে যেত।
 *
 * দাবি:
 *  - কোড সর্বনিম্নের কম → লেখায় ৪২৬ (`app_update_required`, `minimumCode`); সমান বা বেশি → যায়।
 *  - কোডের হেডার ছাড়া ফোন (আগের বিল্ড) = ০.৪.৩৫ — সর্বনিম্ন তার উপরে উঠলে ৪২৬, নইলে যায়।
 *  - ভুল লেখা কোড পুরনো ধরা হয়।
 *  - পড়া, বেরোনো আর ফোনের নয় এমন অনুরোধ খোলা।
 *  - রিলিজ অর্ধেক বসানো হলে (যেমন URL নেই) কিছুই আটকায় না — ফোনের দেয়াল যে মানে ঘুমায়, সার্ভারও।
 */
final class ARetiredAppBuildKeptWritingTest extends TestCase
{
    use RefreshDatabase;

    private const WRITE = '/api/v1/notifications/read-all';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Sanctum::actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail(), [AuthController::APP]);
    }

    public function test_a_build_below_the_minimum_is_refused_on_writes_and_the_minimum_itself_goes_through(): void
    {
        $this->release(minimum: 45);

        $this->postJson(self::WRITE, [], ['X-App-Code' => '44', 'X-App-Version' => '0.4.39'])
            ->assertStatus(426)
            ->assertJsonPath('code', 'app_update_required')
            ->assertJsonPath('minimumCode', 45);

        $this->assertNotSame(426, $this->postJson(self::WRITE, [], ['X-App-Code' => '45'])->status(), '⛔ সর্বনিম্ন বিল্ড নিজেই আটকাল');
        $this->assertNotSame(426, $this->postJson(self::WRITE, [], ['X-App-Code' => '52'])->status());

        $this->postJson(self::WRITE, [], ['X-App-Code' => 'abc'])->assertStatus(426);
    }

    public function test_a_build_from_before_the_code_header_counts_as_the_last_one_without_it(): void
    {
        $this->release(minimum: RefuseRetiredAppBuilds::LAST_CODE_WITHOUT_THE_HEADER + 1);
        $this->postJson(self::WRITE, [], ['X-App-Version' => '0.4.34', 'X-Platform' => 'android'])
            ->assertStatus(426);

        $this->release(minimum: RefuseRetiredAppBuilds::LAST_CODE_WITHOUT_THE_HEADER);
        $this->assertNotSame(426, $this->postJson(self::WRITE, [], ['X-App-Version' => '0.4.35'])->status(),
            '⛔ সার্ভার নামা মাত্র মাঠের চালু বিল্ড আটকাল');
    }

    public function test_reads_logout_and_non_phone_callers_stay_open(): void
    {
        $this->release(minimum: 45);
        $old = ['X-App-Code' => '30'];

        $this->assertNotSame(426, $this->getJson('/api/v1/notifications/unread-count', $old)->status(), 'পড়া খোলা থাকার কথা');
        $this->assertNotSame(426, $this->postJson('/api/v1/auth/logout', [], $old)->status(), 'পুরনো বিল্ডও বেরোতে পারে');
        $this->assertNotSame(426, $this->postJson(self::WRITE)->status(), 'ফোনের নয় — কিছু করার নেই');
    }

    public function test_a_half_set_release_walls_nobody(): void
    {
        $this->release(minimum: 45);
        config(['mobile.android.url' => '']);

        $this->assertNotSame(426, $this->postJson(self::WRITE, [], ['X-App-Code' => '30'])->status(),
            '⛔ অর্ধেক বসানো .env-এ সব ফোনের লেখা বন্ধ — অথচ ফোন জানে না নতুনটা কোথায়');
    }

    private function release(int $minimum): void
    {
        config(['mobile.android' => [
            'version_code' => '60',
            'version_name' => '0.4.60',
            'url' => 'https://erp.example.test/app.apk',
            'apk_sha256' => str_repeat('a', 64),
            'size_bytes' => '1000',
            'minimum_code' => (string) $minimum,
            'note' => ['bn' => null, 'en' => null],
        ]]);
    }
}
