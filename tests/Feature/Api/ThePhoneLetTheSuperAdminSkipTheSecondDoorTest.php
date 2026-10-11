<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Security\MfaService;
use App\Core\Security\Totp;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * ফোন দিয়ে সুপার অ্যাডমিন দুই ধাপ ছাড়াই ঢুকতে পারতেন — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ভুল ছিল ────────────────────────────────────────────────────
 * ওয়েবে [[SuperAdminMustHaveTwoSteps]] তাঁকে বসানোর পর্দায় আটকায়, কিন্তু
 * সেটা কেবল web গ্রুপে। ফোনের লগইন দেখত কেবল "দুই ধাপ চালু কি না" —
 * তাই যাঁর জন্য বাধ্যতামূলক অথচ বসানো নেই, তিনি কেবল পাসওয়ার্ডে পুরো
 * টোকেন পেতেন।
 *
 * ⓘ বিপজ্জনক মানুষটা এখানে: পাসওয়ার্ড জানা একজন, যাঁর দুই ধাপ বসানো নেই।
 */
final class ThePhoneLetTheSuperAdminSkipTheSecondDoorTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = 'handset-two-step';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        // ⚠️ লাইভের আকৃতি: চালুর-দিনের সুইচ খোলা (phpunit.xml এটা বন্ধ রাখে)
        config(['abos.super_admin_two_step' => true]);
    }

    public function test_a_super_admin_without_two_step_gets_no_token_on_the_phone(): void
    {
        $owner = $this->owner();
        app(MfaService::class)->turnOff($owner);

        $this->login('owner@abos.test')
            ->assertForbidden()
            ->assertJsonPath('message', __('auth.two_step_set_up_on_web_first'));

        $this->assertSame(0, PersonalAccessToken::query()->count(), 'বাধ্যতামূলক দুই ধাপ ছাড়াই টোকেন বেরিয়েছে।');
    }

    public function test_a_person_the_admin_required_two_step_for_gets_no_token_until_it_is_set_up(): void
    {
        User::query()->where('email', 'sales@abos.test')->firstOrFail()
            ->forceFill(['two_step_required' => true])->save();

        $this->login('sales@abos.test')->assertForbidden();

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_a_super_admin_with_two_step_set_up_must_give_the_code(): void
    {
        $this->login('owner@abos.test')->assertStatus(409)->assertJsonPath('needsCode', true);
        $this->assertSame(0, PersonalAccessToken::query()->count());

        $this->login('owner@abos.test', Totp::codeFor((string) $this->owner()->mfa_secret))->assertOk();
    }

    /**
     * ⚠️ নিয়মটা পড়ার আগে নেওয়া refresh টোকেন চিরকাল চলত।
     */
    public function test_a_refresh_token_from_before_the_rule_stops_working(): void
    {
        $tokens = $this->login('sales@abos.test')->assertOk()->json();

        User::query()->where('email', 'sales@abos.test')->firstOrFail()
            ->forceFill(['two_step_required' => true])->save();

        $this->app['auth']->forgetGuards();
        $this->withToken($tokens['refreshToken'])
            ->postJson('/api/v1/auth/refresh', ['deviceId' => self::DEVICE])
            ->assertForbidden();
    }

    /** ⓘ বাকিরা আগের মতোই ঢোকেন — তালাটা সবার উপর পড়েনি। */
    public function test_a_person_without_the_rule_still_signs_in(): void
    {
        $this->login('sales@abos.test')->assertOk();
    }

    /**
     * ⭐ লাইভের আজকের অবস্থা — সুইচ বন্ধ, মালিকের দুই ধাপ শুরু হয়েছিল কিন্তু নিশ্চিত হয়নি (চাবি আছে, `mfa_confirmed_at` খালি)।
     * ⓘ তখন ফোনে লগইন আর নবায়ন আজকের মতোই — কোড চায় না, ফেরায়ও না (cb-র রিভিউ, fe-র চাওয়া, ১১ অক্টোবর ২০২৬)।
     */
    public function test_with_the_switch_off_an_owner_whose_two_step_was_started_but_not_confirmed_signs_in_as_today(): void
    {
        config(['abos.super_admin_two_step' => false]);
        $this->owner()->forceFill(['mfa_secret' => Totp::newSecret(), 'mfa_confirmed_at' => null, 'two_step_required' => false])->save();

        $tokens = $this->login('owner@abos.test')->assertOk()->json();

        $this->app['auth']->forgetGuards();
        $this->withToken($tokens['refreshToken'])
            ->postJson('/api/v1/auth/refresh', ['deviceId' => self::DEVICE])
            ->assertOk();
    }

    /**
     * ⭐ সুইচ খুললে তালাবদ্ধ নয় — ফোন ফেরায় আর বলে "আগে ওয়েবে বসান"; ওয়েবে ঐ শুরু-হওয়া চাবিটাই দেখায়, প্রথম কোডে নিশ্চিত হয়,
     * তারপর ফোন কোড চেয়ে ঢোকায় (cb-র রিভিউ, ১১ অক্টোবর ২০২৬)।
     */
    public function test_with_the_switch_on_the_started_two_step_is_finished_on_the_web_and_then_the_phone_opens(): void
    {
        $secret = Totp::newSecret();
        $this->owner()->forceFill(['mfa_secret' => $secret, 'mfa_confirmed_at' => null, 'two_step_required' => false])->save();

        $this->login('owner@abos.test')->assertForbidden()->assertJsonPath('message', __('auth.two_step_set_up_on_web_first'));

        // ⓘ ওয়েব: বসানোর পর্দা খোলে, একই চাবি দেখায়, কোডে নিশ্চিত
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner())->get(route('mfa'))->assertOk()->assertSee($secret);
        $this->actingAs($this->owner())->post(route('mfa.confirm'), ['code' => Totp::codeFor($secret)])->assertSessionHasNoErrors();
        $this->assertTrue(app(MfaService::class)->isOn($this->owner()->fresh()), '⛔ ওয়েবে নিশ্চিত করা গেল না — মালিক কোনো পথেই ঢুকতে পারতেন না।');

        // ⓘ ফোন: এখন কোড চায়, আর কোডে ঢোকায়
        $this->login('owner@abos.test')->assertStatus(409)->assertJsonPath('needsCode', true);
        $this->login('owner@abos.test', Totp::codeFor($secret, time() + 30))->assertOk();
    }

    private function owner(): User
    {
        return User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    private function login(string $identifier, ?string $code = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/login', array_filter([
            'identifier' => $identifier,
            'password' => 'password',
            'code' => $code,
            'deviceId' => self::DEVICE,
        ]));
    }
}
