<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Http\Controllers\Api\SyncController;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ একবারের পুশে সারির কোনো সীমা ছিল না (Api/Sync নিরীক্ষা, ১৪ অক্টোবর ২০২৬; [[SyncController::push()]])।
 *
 * ⚠️ প্রতিটা সারি একটা খোঁজা আর একটা লেনদেন — থ্রটল অনুরোধ গোনে, সারি নয়, তাই একটা টোকেন একবারে হাজারো সারি পাঠিয়ে PHP আর
 * ডাটাবেজ আটকে রাখতে পারত। ⭐ এখন ২০০-র বেশি হলে কারণসহ ৪২২, কিছুই বসে না; আজকের ফোন ৫০টা করে পাঠায়।
 */
final class OnePushCarriedTenThousandChangesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_push_beyond_the_cap_is_refused_with_a_reason(): void
    {
        $this->seed(DemoSeeder::class);
        $this->app['auth']->forgetGuards();
        $token = (string) $this->postJson('/api/v1/auth/login', [
            'identifier' => 'sales@abos.test', 'password' => 'password', 'deviceId' => 'cap-device-0001',
            'appVersion' => '0.1.0', 'platform' => 'android',
        ])->json('accessToken');
        $this->assertNotSame('', $token, 'লগইনই হয়নি');
        $this->app['auth']->forgetGuards();

        $rows = array_fill(0, SyncController::MAX_CHANGES_PER_PUSH + 1, ['changeId' => 'x']);

        $this->withToken($token)->postJson('/api/v1/sync/sales/push', $rows)
            ->assertStatus(422)
            ->assertJsonPath('message', __('sync.too_many_changes', ['max' => SyncController::MAX_CHANGES_PER_PUSH]));
    }
}
