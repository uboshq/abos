<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\AuthController;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ /me বলে দেয় এই সার্ভারে নিজের ছবি আর "আমার ফোনগুলো"-র দরজা আছে কি না — অ্যাপের মেনু কাজ না করা সারি দেখায় না
 * (মালিকের নিয়ম; a4-এর সাথে ঠিক করা, a6, ২০ অক্টোবর ২০২৬; [[MeController]])।
 *
 * দাবি: দরজা আছে → দুটোই true; একটা দরজা না থাকলে সেই ঘর false, অন্যটা যেমন ছিল।
 */
final class TheMenuOfferedARowTheServerCouldNotAnswerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Sanctum::actingAs(User::query()->where('email', 'sales@abos.test')->firstOrFail(), [AuthController::APP]);
    }

    public function test_this_server_says_it_has_both_doors(): void
    {
        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('features.profile_photo', true)
            ->assertJsonPath('features.my_phones', true);
    }

    public function test_a_door_that_is_not_there_is_not_offered(): void
    {
        $this->without(['api.users.photo', 'api.me.devices.destroy']);

        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('features.profile_photo', false)
            ->assertJsonPath('features.my_phones', false);
    }

    public function test_one_missing_door_leaves_the_other_offered(): void
    {
        $this->without(['api.me.devices.index']);

        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('features.profile_photo', true)
            ->assertJsonPath('features.my_phones', false);
    }

    /** @param list<string> $names */
    private function without(array $names): void
    {
        $kept = new RouteCollection;
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array($route->getName(), $names, true)) {
                $kept->add($route);
            }
        }
        Route::setRoutes($kept);
    }
}
