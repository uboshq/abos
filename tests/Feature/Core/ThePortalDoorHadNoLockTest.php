<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Security\LoginLock;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LoginAttempt;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ডিলারের দরজায় তালা ছিল না — নিরীক্ষা, ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ভাঙা ছিল ────────────────────────────────────────────────────
 * পোর্টালের লগইনে পাহারা ছিল একটাই: `throttle:5,1`, অর্থাৎ **IP ধরে**
 * মিনিটে পাঁচবার। কর্মীর দরজায় এর উপরে আরও তিনটা স্তর আছে
 * ([[CredentialCheck]]) — পরিচয় ধরে তালা, ব্যর্থতার খাতা, আর অচেনা নামেও
 * একটা hash যাচাই — পোর্টালে একটাও ছিল না।
 *
 * ⚠️ ফল: বহু IP থেকে চালানো একটা পাসওয়ার্ড-তালিকা একটা ডিলারের কোডে
 * দিনভর চলতে পারত, আর কোথাও একটা দাগও পড়ত না। ⓘ আর অচেনা কোডে
 * উত্তরটা hash ছাড়াই আসত — **সময় মেপেই** বলা যেত কোন কোডগুলো আসল।
 *
 * ── ⭐ প্রতিটা দাবি একই গ্রাহক, একই পাসওয়ার্ড ─────────────────────────
 * "তালাবদ্ধ" আর "খোলা" দুইটা আলাদা মানুষ দিয়ে দেখালে তফাতটা মানুষেরও
 * হতে পারত। এখানে কেবল তালাটা বদলায়, আর কিছু নয়।
 */
final class ThePortalDoorHadNoLockTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'shop-pass-2026';

    private Company $company;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        $this->customer = $this->portalCustomer($this->company, 'LOCK-1', self::PASSWORD);

        /*
         * ⚠️ আসল ব্রাউজারে লগইনের মুহূর্তে কোনো কোম্পানি বসানো থাকে না —
         * আর পোর্টালের একটা পুরনো ভুল ঠিক এই তফাতেই লুকিয়ে ছিল।
         */
        CompanyContext::clear();
    }

    /* ── তালা ─────────────────────────────────────────────────────── */

    /**
     * আটটা ভুল, আটটা আলাদা ঠিকানা থেকে — তারপর সঠিক পাসওয়ার্ডও ফেরে,
     * আর তালার মেয়াদ ফুরোলে সেই একই পাসওয়ার্ডে দরজা খোলে।
     *
     * ⓘ প্রতিটা চেষ্টা আলাদা IP থেকে, কারণ ঠিক ঐ আক্রমণটাই IP-ধরা
     * throttle পেরিয়ে যায়।
     */
    public function test_eight_failures_lock_the_code_and_the_right_password_waits_for_the_lock(): void
    {
        $this->failTimes('LOCK-1', LoginLock::TRIES);

        $this->attempt('LOCK-1', self::PASSWORD, '10.9.9.1')
            ->assertSessionHasErrors(['code' => __('auth.locked', ['minutes' => LoginLock::MINUTES])]);

        $this->assertGuest('portal');

        $this->travel(LoginLock::MINUTES + 1)->minutes();

        $this->attempt('LOCK-1', self::PASSWORD, '10.9.9.2')
            ->assertRedirect(route('sales.portal.home'));

        $this->assertAuthenticatedAs($this->customer, 'portal');
    }

    /**
     * তালা পড়লে পাসওয়ার্ড আর যাচাই হয় না।
     *
     * ⓘ নাহলে তালাবদ্ধ অবস্থাতেও প্রতিটা চেষ্টায় একটা bcrypt চলত — ঢুকতে
     * না পারলেও সার্ভারের CPU খাওয়ানো যেত।
     */
    public function test_a_locked_code_does_not_even_check_the_password(): void
    {
        $this->failTimes('LOCK-1', LoginLock::TRIES);

        Hash::spy();

        $this->attempt('LOCK-1', self::PASSWORD, '10.9.9.3')->assertSessionHasErrors('code');

        Hash::shouldNotHaveReceived('check');
    }

    /**
     * ⭐ একটা সফল লগইন গোনাটা শূন্যে ফেরায়।
     *
     * ⛔ নাহলে সারা বছরের আটটা টাইপের ভুল জমে একজন ডিলার প্রতিটা নতুন
     * ভুলের পর পনেরো মিনিট বাইরে থাকতেন — তালাটা আক্রমণকারীকে নয়,
     * আসল মানুষটাকেই শাস্তি দিত।
     */
    public function test_a_success_between_failures_resets_the_count(): void
    {
        $this->failTimes('LOCK-1', LoginLock::TRIES - 1);

        $this->attempt('LOCK-1', self::PASSWORD, '10.8.0.1')->assertRedirect(route('sales.portal.home'));
        $this->post(route('sales.portal.logout'));

        $this->attempt('LOCK-1', 'one-more-typo-1', '10.8.0.2')
            ->assertSessionHasErrors(['code' => __('sales::portal.bad_login')]);

        $this->attempt('LOCK-1', self::PASSWORD, '10.8.0.3')->assertRedirect(route('sales.portal.home'));
    }

    /**
     * ডিলারের কোড আর কর্মীর নাম একই খাতায়, কিন্তু আলাদা গোনায়।
     *
     * ⚠️ কর্মীর দরজায় কেউ `LOCK-1` নামে আটবার ভুল করলে ডিলার `LOCK-1`
     * তালাবদ্ধ হওয়া উচিত নয় — আর উল্টোটাও। একই গোনা হলে একটা দরজা দিয়ে
     * আরেকটা দরজা বন্ধ করা যেত।
     */
    public function test_failures_at_the_staff_door_do_not_lock_the_dealer(): void
    {
        for ($i = 0; $i < LoginLock::TRIES; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.7.0.{$i}"])
                ->post(route('login.store'), ['identifier' => 'LOCK-1', 'password' => "wrong-{$i}"]);
        }

        $this->attempt('LOCK-1', self::PASSWORD, '10.7.1.1')->assertRedirect(route('sales.portal.home'));
    }

    /* ── অচেনা কোড ─────────────────────────────────────────────────── */

    /**
     * অচেনা কোডেও ঠিক একটা hash যাচাই — চেনা কোডে ভুল পাসওয়ার্ডের মতোই।
     *
     * ⛔ আগে অচেনা কোডে শূন্যটা যাচাই হত, তাই উত্তরটা কয়েক মিলিসেকেন্ডে
     * আসত; চেনা কোডে একটা bcrypt লাগত। ⓘ ঐ তফাতটুকুই কোডের তালিকা।
     */
    public function test_an_unknown_code_costs_the_same_one_hash_check_as_a_wrong_password(): void
    {
        Hash::spy();

        $unknown = $this->attempt('NOBODY-9', 'whatever-pass-1', '10.6.0.1');

        Hash::shouldHaveReceived('check')->once();

        $wrong = $this->attempt('LOCK-1', 'whatever-pass-1', '10.6.0.2');

        // ⓘ একই গুপ্তচর, তাই গোনা জমে: আগের একটা আর এইটার একটা
        Hash::shouldHaveReceived('check')->twice();

        /*
         * ⭐ আর উত্তর দুইটা হুবহু এক — অবস্থা, ঠিকানা, ঘর, বার্তা।
         */
        $this->assertSame($wrong->status(), $unknown->status());
        $this->assertSame($wrong->headers->get('Location'), $unknown->headers->get('Location'));
        $unknown->assertSessionHasErrors(['code' => __('sales::portal.bad_login')]);
        $wrong->assertSessionHasErrors(['code' => __('sales::portal.bad_login')]);
    }

    /* ── খাতা ─────────────────────────────────────────────────────── */

    /**
     * প্রতিটা চেষ্টা খাতায় — কারণসহ, আর পোর্টালের নিজের নামে।
     *
     * ⓘ পর্দা "অচেনা কোড" আর "ভুল পাসওয়ার্ড" আলাদা করে বলে না, খাতা বলে:
     * কেউ কোড আন্দাজ করছেন না পাসওয়ার্ড — দুইটা আলাদা ঘটনা।
     */
    public function test_every_attempt_is_written_down_with_its_reason(): void
    {
        $this->attempt('NOBODY-9', 'whatever-pass-1', '10.5.0.1');
        $this->attempt('LOCK-1', 'whatever-pass-1', '10.5.0.2');
        $this->attempt('LOCK-1', self::PASSWORD, '10.5.0.3');

        $rows = LoginAttempt::query()->orderBy('id')->get(['identifier', 'succeeded', 'reason', 'company_id', 'ip_address', 'user_id']);

        $this->assertSame([
            ['portal:NOBODY-9', false, LoginAttempt::UNKNOWN, null, '10.5.0.1', null],
            ['portal:LOCK-1', false, LoginAttempt::WRONG_PASSWORD, null, '10.5.0.2', null],
            ['portal:LOCK-1', true, null, $this->company->id, '10.5.0.3', null],
        ], $rows->map(fn (LoginAttempt $r) => [
            $r->identifier, $r->succeeded, $r->reason, $r->company_id, $r->ip_address, $r->user_id,
        ])->all());

        // ⚠️ পাসওয়ার্ড কোনো রূপেই খাতায় নয় — ভুলটাও না
        $this->assertSame(0, LoginAttempt::query()
            ->where('identifier', 'like', '%whatever-pass-1%')
            ->orWhere('identifier', 'like', '%'.self::PASSWORD.'%')
            ->count());
    }

    /** তালা পড়ার পরের চেষ্টাগুলোও খাতায় — `locked` নামে। */
    public function test_attempts_against_a_lock_are_written_down_as_locked(): void
    {
        $this->failTimes('LOCK-1', LoginLock::TRIES);

        $this->attempt('LOCK-1', self::PASSWORD, '10.4.0.1');

        $this->assertSame(LoginAttempt::LOCKED, LoginAttempt::query()->latest('id')->value('reason'));
    }

    /* ── অন্য কোম্পানি ────────────────────────────────────────────── */

    /**
     * দুই কোম্পানিতে একই কোড — প্রত্যেকের পাসওয়ার্ড কেবল নিজের দরজা খোলে।
     *
     * ⓘ কোডটা কোম্পানির **ভেতরে** অনন্য, সবার মধ্যে নয়। ⛔ অন্য কোম্পানির
     * একই-কোডের ডিলারের পাসওয়ার্ড দিয়ে এই কোম্পানির ডিলার হয়ে ঢোকা গেলে
     * একজন আরেকজনের খাতা দেখতেন।
     */
    public function test_a_twin_code_in_another_company_opens_only_its_own_door(): void
    {
        $other = Company::query()->where('code', 'OTHER')->first()
            ?? Company::query()->create([
                'code' => 'OTHER', 'name_en' => 'Other Traders', 'name_bn' => 'অন্য ট্রেডার্স', 'is_active' => true,
            ]);

        $mine = $this->portalCustomer($this->company, 'TWIN-1', 'mine-pass-2026');
        $theirs = $this->portalCustomer($other, 'TWIN-1', 'theirs-pass-2026');
        CompanyContext::clear();

        $this->attempt('TWIN-1', 'theirs-pass-2026', '10.3.0.1')->assertRedirect(route('sales.portal.home'));
        $this->assertSame($theirs->id, Auth::guard('portal')->id());
        $this->assertNotSame($mine->id, Auth::guard('portal')->id());

        $this->post(route('sales.portal.logout'));
        CompanyContext::clear();

        $this->attempt('TWIN-1', 'mine-pass-2026', '10.3.0.2')->assertRedirect(route('sales.portal.home'));
        $this->assertSame($mine->id, Auth::guard('portal')->id());

        $this->post(route('sales.portal.logout'));
        CompanyContext::clear();

        // ⛔ আর কোনোটার পাসওয়ার্ড নয় এমন কিছু দিয়ে — কেউ নয়
        $this->attempt('TWIN-1', 'neither-pass-2026', '10.3.0.3')->assertSessionHasErrors('code');
        $this->assertGuest('portal');
    }

    /**
     * ⛔ লগইন করা ডিলার নিজের পোর্টাল খোলেন — সুপার অ্যাডমিনের দুই-ধাপের তালা
     * তাঁকে ছোঁয় না (গভীর অডিট ২৯ সেপ্টেম্বর ২০২৬)।
     *
     * ⓘ ঐ তালাটা ([[SuperAdminMustHaveTwoSteps]]) গোটা web গ্রুপে বসানো, আর সে
     * `$request->user()`-কে সবসময় কর্মী ধরে নিত; পোর্টালে ওটা একজন গ্রাহক, তাই
     * লগইনের পরের প্রতিটা পাতা TypeError-এ ৫০০ দিত। ⚠️ সুইচ বন্ধ থাকলেও —
     * ভাঙনটা সুইচ দেখার আগেই। ⭐ একই ডিলার, সুইচ চালু আর বন্ধ দুই অবস্থায়।
     */
    public function test_a_signed_in_dealer_opens_the_portal_whether_the_super_admin_lock_is_on_or_off(): void
    {
        foreach ([true, false] as $on) {
            config(['abos.super_admin_two_step' => $on]);

            $this->attempt('LOCK-1', self::PASSWORD, $on ? '10.4.0.1' : '10.4.0.2')
                ->assertRedirect(route('sales.portal.home'));
            $this->assertAuthenticatedAs($this->customer, 'portal');

            $this->get(route('sales.portal.home'))->assertOk();

            $this->post(route('sales.portal.logout'));
            $this->app['auth']->forgetGuards();
        }
    }

    /* ── সহায়ক ────────────────────────────────────────────────────── */

    private function attempt(string $code, string $password, string $ip): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post(route('sales.portal.login.attempt'), ['code' => $code, 'password' => $password]);
    }

    private function failTimes(string $code, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->attempt($code, "guess-{$i}-x", "10.0.{$i}.1")->assertSessionHasErrors('code');
        }
    }

    private function portalCustomer(Company $company, string $code, string $password): Customer
    {
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'branch_id' => $company->defaultBranch()?->id,
            'code' => $code,
            'name_en' => "Portal {$code}",
            'status' => DocumentStatus::CONFIRMED,
            'is_active' => true,
        ]);

        $customer->forceFill(['portal_enabled' => true, 'portal_password' => $password])->save();

        return $customer->fresh();
    }
}
