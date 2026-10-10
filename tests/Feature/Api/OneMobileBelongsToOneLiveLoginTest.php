<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Support\LoginMobile;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * ⓘ লাইভের হুবহু জোড়া — একই মানুষের পুরনো নিষ্ক্রিয় অ্যাকাউন্ট আর নতুন চালুটায় একই মোবাইল (cb-র রিভিউ, cloud/security-fixes
 * মেশানোর আগে; fe লাইভে গুনেছে, ১১ অক্টোবর ২০২৬)।
 *
 * ⛔ নতুন "একটা নম্বর একটা লগইন" নিয়ম নিষ্ক্রিয়টাকেও গুনলে চালুজন মোবাইলে ঢুকতে পারতেন না (দুজন মিললে কাউকেই নয়), আর তাঁর
 * প্রোফাইল বা রোল রাখাও আটকাত। ⭐ এখন মোবাইলের খোঁজা আর "একজনেরই" যাচাই দুটোই কেবল চালু, না-মোছা লগইন দেখে;
 * নিষ্ক্রিয়জন মোবাইলে কখনো মেলেন না। ডেটা ছোঁয়া হয়নি।
 */
final class OneMobileBelongsToOneLiveLoginTest extends TestCase
{
    use RefreshDatabase;

    private const MOBILE = '01711000111';

    public function test_the_live_pair_the_active_login_signs_in_by_mobile_and_saves_and_the_inactive_one_never_matches(): void
    {
        $this->seed(DemoSeeder::class);

        $active = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $active->forceFill(['mobile' => self::MOBILE, 'login_id' => 'abukawser'])->save();

        $inactive = User::factory()->create(['name' => 'Abu Kawser', 'login_id' => 'kawser', 'mobile' => self::MOBILE,
            'is_active' => false, 'current_company_id' => $active->current_company_id]);
        $inactive->companies()->attach($active->current_company_id, ['is_active' => true]);

        // ⭐ চালুজন মোবাইলে ঢোকেন — লেখা যেমন খুশি (ড্যাশ সহ)
        $tokens = $this->login('01711-000111')->assertOk()->json();
        $this->assertSame((int) $active->id, (int) PersonalAccessToken::findToken($tokens['accessToken'])?->tokenable_id,
            '⛔ মোবাইলের লগইন চালুজনের নয়।');

        // ⭐ চালুজনের সংরক্ষণ চলে (প্রোফাইল আর ব্যবহারকারী-পাতা দুটোই এই নিয়ম পড়ে); নিষ্ক্রিয়জনের নম্বর তাঁকে আটকায় না
        $this->assertTrue(Validator::make(['mobile' => self::MOBILE], ['mobile' => LoginMobile::rules($active->id)])->passes(),
            '⛔ পুরনো নিষ্ক্রিয় অ্যাকাউন্টের নম্বর চালুজনের সংরক্ষণ আটকাল।');

        // ⛔ নিষ্ক্রিয়জন আবার চালু হয়ে একই নম্বর নিতে পারেন না — তখন দুই চালু লগইনে এক নম্বর হত
        $this->assertFalse(Validator::make(['mobile' => self::MOBILE], ['mobile' => LoginMobile::rules($inactive->id)])->passes(),
            '⛔ চালুজনের নম্বর দ্বিতীয় লগইনেও বসানো গেল।');

        // ⛔ মালিক পুরনো অ্যাকাউন্টটা আবার চালু করতে চাইলে — একমাত্র চালু-করার পথ ব্যবহারকারীর পাতা; নামসহ থামে, অ্যাকাউন্ট নিষ্ক্রিয়ই থাকে
        \App\Core\Support\CompanyContext::set((int) $active->current_company_id, null);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->app['auth']->forgetGuards();
        $this->actingAs($owner)->put(route('system_admin.user.update', $inactive), [
            'name' => $inactive->name, 'email' => $inactive->email, 'locale' => 'bn', 'is_active' => '1',
            'mobile' => self::MOBILE, 'roles' => ['salesman'], 'companies' => [(int) $active->current_company_id],
        ])->assertSessionHasErrors(['mobile' => __('validation.login_mobile_taken_by', ['name' => $active->name])]);
        $this->assertFalse((bool) $inactive->fresh()->is_active, '⛔ পুরনো অ্যাকাউন্ট একই নম্বর নিয়ে আবার চালু হলো।');

        // ⛔ নিষ্ক্রিয়জন মোবাইলে কখনো মেলেন না — নম্বরটা কেবল তাঁর থাকলেও
        $active->forceFill(['mobile' => null])->save();
        $before = PersonalAccessToken::query()->count();
        $this->assertNotSame(200, $this->login(self::MOBILE)->status(), '⛔ নিষ্ক্রিয় অ্যাকাউন্ট মোবাইলে মিলে টোকেন পেল।');
        $this->assertSame($before, PersonalAccessToken::query()->count());
    }

    private function login(string $identifier): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/login', [
            'identifier' => $identifier,
            'password' => 'password',
            'deviceId' => 'handset-mobile-pair',
        ]);
    }
}
