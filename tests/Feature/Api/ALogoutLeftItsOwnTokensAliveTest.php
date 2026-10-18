<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ লগআউট শরীরের যন্ত্রের নাম মানত, ডাকা টোকেনের নয় (Api/Sync নিরীক্ষা, ১৪ অক্টোবর ২০২৬; [[AuthController::logout()]])।
 *
 * ⚠️ শরীরে অন্য নাম গেলে ডাকা জোড়াটাই বেঁচে থাকত — প্রবেশের টোকেন ৩০ মিনিট, নবায়নের ৩০ দিন। ⭐ এখন যন্ত্র টোকেনের নিজের নাম থেকে।
 */
final class ALogoutLeftItsOwnTokensAliveTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_ends_the_calling_devices_pair_whatever_the_body_says(): void
    {
        $this->seed(DemoSeeder::class);
        $this->app['auth']->forgetGuards();

        $login = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'sales@abos.test', 'password' => 'password', 'deviceId' => 'logout-device-01',
            'appVersion' => '0.1.0', 'platform' => 'android',
        ]);
        $token = (string) $login->json('accessToken');
        $this->assertNotSame('', $token, 'লগইনই হয়নি');
        $this->app['auth']->forgetGuards();

        $user = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->assertGreaterThan(0, $user->tokens()->where('name', 'like', '%logout-device-01')->count());

        $this->withToken($token)->postJson('/api/v1/auth/logout', ['deviceId' => 'somebody-elses-device'])->assertOk();

        $this->assertSame(0, $user->tokens()->where('name', 'like', '%logout-device-01')->count(),
            '⛔ লগআউটের পরেও ডাকা যন্ত্রের টোকেন বেঁচে রইল');
    }
}
