<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ⛔ একজনের নাম আরেকজনের ইমেইল হতে পারত, আর তাতে আসল মানুষটা আটকে যেতেন।
 *
 * ── কী পাওয়া গেল, ১৪ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * প্রোফাইল পাতায় নতুন ঘর বসাতে গিয়ে লগইনের কোডটা পড়া হলো, আর তাতে:
 *
 *   [[App\Core\Security\CredentialCheck]]::findUser()
 *       where('email', $identifier)->orWhere('name', $identifier)
 *
 * ⓘ অর্থাৎ **নামটাও একটা লগইন পরিচয়** ছিল। ⚠️ কিন্তু `users.email`-এ
 * unique সূচক আছে আর `users.name`-এ **নেই** (মেপে দেখা: আছে কেবল
 * `users_email_unique` ও `users_public_id_unique`)।
 *
 * ⛔ আর নিজের নাম যে কেউ নিজেই বদলাতে পারেন — প্রোফাইল পাতাটা তার জন্যই।
 * তাই একজন নিজের নাম আরেকজনের ইমেইল বসিয়ে দিলে ঐ ঠিকানায় দুইটা সারি
 * মিলত, আর `first()` কোনটা ফেরাবে তার কোনো নিয়ম নেই।
 *
 * ── ⭐ সমাধানটা নামকে আটকানো নয়, নামকে পরিচয় না রাখা ─────────────────
 * ⓘ প্রথম খসড়ায় ভেবেছিলাম নামের ঘরে ইমেইল লেখা **নিষিদ্ধ** করব।
 * ⚠️ কিন্তু সেটা লক্ষণ সারানো: নামটা তখনো একটা পরিচয় থাকত, আর কাল
 * অন্য কোনো সংঘর্ষ বেরোত। ⭐ এখন পরিচয় তিনটা — `login_id`, `email`,
 * `mobile` — আর নাম আবার শুধু নাম। তাই এই ফাইলের দাবিগুলোও বদলেছে:
 * "নাম বদলানো গেল কি না" নয়, **"আসল মানুষটা ঢুকতে পারলেন কি না"**।
 */
final class OneUserCouldTakeAnothersLoginNameTest extends TestCase
{
    use RefreshDatabase;

    private User $attacker;

    private User $victim;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['code' => 'LG', 'name_en' => 'Login Co']);

        /*
         * ⓘ আক্রমণকারীকে আগে বানানো হচ্ছে, অর্থাৎ তার `id` ছোট — ⚠️ আর
         * ক্রম ছাড়া `first()` ছোট id-টাই ফেরায়। এটা সাজানো কাকতাল নয়:
         * পুরনো অ্যাকাউন্টগুলোরই id ছোট, তাই বাস্তবে যিনি আগে থেকে আছেন
         * তিনিই এই সুবিধাটা পেতেন।
         */
        /*
         * ⚠️ নামটা আইডির সাথে **হুবহু এক নয়**, আর সেটা ইচ্ছাকৃত।
         *
         * ⛔ প্রথম খসড়ায় নাম ছিল `Karim` আর আইডিও `karim`, আর তাতে
         * "নাম দিয়ে আর ঢোকা যায় না" দাবিটা মিথ্যা লাল হয়েছিল: MySQL-এর
         * `utf8mb4_unicode_ci` কোলেশনে তুলনা **বড়-ছোট হরফ মানে না**,
         * তাই `Karim` আসলে আইডিটার সাথেই মিলছিল। ⓘ যন্ত্রটা সত্যি
         * বলছিল, প্রশ্নটাই ভুল ছিল।
         */
        $this->attacker = User::factory()->create([
            'name' => 'Karim Uddin',
            'email' => 'karim@abos.test',
            'login_id' => 'karim',
            'password' => Hash::make('karim-chabi-1'),
        ]);

        $this->victim = User::factory()->create([
            'name' => 'Boss',
            'email' => 'boss@abos.test',
            'login_id' => 'boss',
            'password' => Hash::make('boss-chabi-1'),
        ]);

        foreach ([$this->attacker, $this->victim] as $user) {
            $user->companies()->attach($company, ['is_active' => true]);
            $user->forceFill(['current_company_id' => $company->id])->save();
        }
    }

    /**
     * ⛔ নিজের নামে আরেকজনের ইমেইল বসালেও তিনি ঠিকই ঢুকতে পারেন।
     *
     * ⚠️ এটাই আসল দাবি। ⓘ ব্যবহারকারীর কাছে ক্ষতিটা "নাম বদলে গেল" নয়,
     * **"আমি আর ঢুকতে পারছি না"** — আর সেটাই একমাত্র দৃশ্যমান জিনিস।
     */
    public function test_the_real_owner_of_the_email_can_still_log_in(): void
    {
        $this->actingAs($this->attacker)->put(route('profile.update'), [
            'name' => 'boss@abos.test',
            'login_id' => 'karim',
        ]);

        $this->assertSame('boss@abos.test', $this->attacker->refresh()->name,
            'নামটা বদলায়নি — তাহলে এই পরীক্ষাটা আর আক্রমণটাই চালাচ্ছে না।');

        /*
         * ⛔ `flushSession()` একাই যথেষ্ট নয়।
         *
         * ⚠️ `actingAs()` গার্ডে ব্যবহারকারীটা **বসিয়ে রাখে**, আর সেটা
         * সেশন মুছলেও থেকে যায় — তাই নিচের `auth()->check()` সত্যি
         * বলত আক্রমণকারীর কথা, লগইন সফল হোক বা না হোক। ⓘ প্রথম খসড়ায়
         * দাবিটা তাই ভুল মানুষ দেখাচ্ছিল (৭, অথচ ৮ হওয়ার কথা)।
         *
         * ⭐ `forgetGuards()` সমাধান করা গার্ডগুলো ফেলে দেয়, তাই পরের
         * অনুরোধটা সেশন থেকেই ঠিক করে কে ঢুকেছেন।
         */
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->post(route('login'), [
            'identifier' => 'boss@abos.test',
            'password' => 'boss-chabi-1',
        ]);

        $this->assertTrue(auth()->check(),
            'যাঁর ইমেইল তিনি ঢুকতে পারলেন না — আরেকজনের নাম তাঁর ঠিকানা দখল করে নিয়েছে।');

        $this->assertSame($this->victim->id, auth()->id(), 'ঢোকা গেছে, কিন্তু ভুল মানুষ হিসেবে।');
    }

    /**
     * ⛔ নাম দিয়ে আর লগইন হয় না।
     *
     * ⓘ এটা একটা **আচরণ বদল**, আর সেটা লিখে রাখা দরকার: আগে কেউ নিজের
     * নাম লিখে ঢুকতে পারতেন। ⭐ তাঁদের জন্যই মাইগ্রেশনটা সবাইকে একটা
     * `login_id` বসিয়ে দিয়েছে।
     */
    public function test_a_name_is_no_longer_a_way_in(): void
    {
        $this->post(route('login'), [
            'identifier' => 'Karim Uddin',
            'password' => 'karim-chabi-1',
        ]);

        $this->assertFalse(auth()->check(), 'নাম দিয়ে এখনো ঢোকা যাচ্ছে — পরিচয়টা সরানো হয়নি।');
    }

    /**
     * ⭐ আর যে তিনটা পরিচয় রাখা হয়েছে, তিনটাই কাজ করে।
     *
     * ⚠️ এই দাবিটা না থাকলে উপরেরটা "সব লগইন বন্ধ করে দিলে" সবুজ থাকত
     * — ⓘ আজকের বারবার ফেরা শিক্ষা: যে পাহারা কেবল "না" প্রমাণ করে,
     * সে "হ্যাঁ"-টা ভেঙে ফেলেও চুপ থাকে।
     */
    public function test_login_id_email_and_mobile_all_work(): void
    {
        $this->victim->forceFill(['mobile' => '01712-345678'])->save();

        foreach (['boss', 'boss@abos.test', '01712-345678'] as $identifier) {
            $this->flushSession();

            $this->post(route('login'), [
                'identifier' => $identifier,
                'password' => 'boss-chabi-1',
            ]);

            $this->assertTrue(auth()->check(), $identifier.' দিয়ে ঢোকা গেল না।');
            $this->assertSame($this->victim->id, auth()->id(), $identifier.' ভুল মানুষকে ঢোকাল।');

            auth()->logout();
        }
    }

    /**
     * ⛔ একই নম্বর দুইজনের হলে নম্বর দিয়ে কাউকেই ঢোকানো হয় না।
     *
     * ⓘ মোবাইল unique নয়, আর হতেও পারে না: স্বামী-স্ত্রী বা বাবা-ছেলে
     * একই দোকানে একই নম্বর দেন। ⚠️ তখন চুপ করে প্রথমজনকে ফেরানো মানে
     * আজকের ভুলটাই নতুন ঘরে ফিরিয়ে আনা — তাই অস্পষ্টতায় উত্তর "না"।
     */
    public function test_a_shared_mobile_lets_nobody_in(): void
    {
        $this->attacker->forceFill(['mobile' => '01999-000000'])->save();
        $this->victim->forceFill(['mobile' => '01999-000000'])->save();

        $this->flushSession();

        $this->post(route('login'), [
            'identifier' => '01999-000000',
            'password' => 'boss-chabi-1',
        ]);

        $this->assertFalse(auth()->check(),
            'দুইজনের এক নম্বর, তবু কাউকে ঢোকানো হলো — কোনটা ফিরল তার কোনো নিয়ম নেই।');
    }
}
