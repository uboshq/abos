<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\AppCrashController;
use App\Http\Controllers\Api\AuthController;
use App\Models\ErrorEvent;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⛔ খোলা ক্র্যাশ-দরজায় ভুলের খাতা ভরানো যেত — ২০টার ঝাঁক, টোকেন ছাড়া, মিনিটে ১০ ডাক = এক আইপি থেকে মিনিটে ২০০ সারি।
 *
 * ⭐ fe, ২০ অক্টোবর ২০২৬: টোকেন ছাড়া এক ডাকে সর্বোচ্চ ৫টা; আর এক আইপির দিনে সর্বোচ্চ খবর ([[AppCrashController::DAILY_PER_IP]]) —
 * পেরোলে চুপচাপ বাদ, শুধু দিনের গোনা থাকে। OWASP ASVS 4.0 V11.1.4 (অপব্যবহারের সীমা)। ec, ২০ অক্টোবর ২০২৬।
 */
final class TheCrashDoorCouldBeFloodedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        RateLimiter::clear('app-crash');
        Cache::flush();
    }

    public function test_without_a_token_a_batch_holds_five_and_with_one_twenty(): void
    {
        $this->postJson('/api/v1/app/crash', ['reports' => $this->batch(6)])->assertStatus(422);
        $this->assertSame(0, ErrorEvent::query()->where('route', 'api.app.crash')->count(), '⛔ টোকেন ছাড়া ৬টার ঝাঁক লেখা হলো।');

        $this->postJson('/api/v1/app/crash', ['reports' => $this->batch(5)])->assertStatus(202);
        $this->assertSame(5, ErrorEvent::query()->where('route', 'api.app.crash')->count());

        Sanctum::actingAs(User::query()->where('email', 'sales@abos.test')->firstOrFail(), [AuthController::APP]);
        $this->postJson('/api/v1/app/crash', ['reports' => $this->batch(20, 'T')])->assertStatus(202);
        $this->assertSame(25, ErrorEvent::query()->where('route', 'api.app.crash')->count(), '⛔ টোকেনসহ ২০টার ঝাঁক ফিরল।');
    }

    public function test_past_the_daily_ceiling_reports_are_dropped_quietly_and_counted(): void
    {
        config(['abos.app_crash.daily_per_ip' => 3]);

        $this->postJson('/api/v1/app/crash', ['reports' => $this->batch(4)])->assertStatus(202);
        $this->assertSame(3, ErrorEvent::query()->where('route', 'api.app.crash')->count(), '⛔ আইপির দিনের ছাদ পেরিয়েও লেখা হলো।');
        $this->assertSame(1, (int) Cache::get(AppCrashController::droppedKey()), '⛔ বাদ পড়া খবরের গোনা নেই।');

        $this->postJson('/api/v1/app/crash', ['reports' => $this->batch(2, 'L')])->assertStatus(202);
        $this->assertSame(3, ErrorEvent::query()->where('route', 'api.app.crash')->count());
        $this->assertSame(3, (int) Cache::get(AppCrashController::droppedKey()));
    }

    /** @return list<array<string, string>> আলাদা আলাদা ভুল — একই ছাপে এক সারিতে জমে যেত */
    private function batch(int $n, string $tag = 'A'): array
    {
        return array_map(fn (int $i) => [
            'version' => '0.4.39', 'screen' => '/home', 'error' => "Flood{$tag}{$i}",
            'message' => 'x', 'stack' => "#0 f{$tag}{$i} (package:abos_mobile/main.dart:{$i})",
        ], range(1, $n));
    }
}
