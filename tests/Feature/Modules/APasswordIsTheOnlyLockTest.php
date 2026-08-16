<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Security\MfaService;
use App\Core\Security\Totp;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LoginAttempt;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * খাতার একমাত্র তালা একটা পাসওয়ার্ড।
 *
 * ── কেন এটা লাগে ────────────────────────────────────────────────────
 * মালিকের পাসওয়ার্ড জানলেই গোটা ব্যবসার খাতা খোলা — প্রতিটা বিল,
 * প্রতিটা ক্রয়মূল্য, প্রতিটা ব্যাংক হিসাব। ফাঁস হওয়ার পথ অনেক: কর্মীর
 * দেখে ফেলা, কাগজে লেখা, একই পাসওয়ার্ড অন্য কোথাও।
 *
 * ── কেন TOTP, SMS নয় ────────────────────────────────────────────────
 * SMS-এ টাকা লাগে, আর বাংলাদেশে দেরিতে আসে বা আসেই না — তখন কেউ নিজের
 * ব্যবস্থায় ঢুকতে পারেন না। অথেনটিকেটর অ্যাপ ইন্টারনেট ছাড়াই কোড
 * বানায়, তাই ডিপোতে নেট গেলেও লগইন চলে।
 */
class APasswordIsTheOnlyLockTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    private function mfa(): MfaService
    {
        return app(MfaService::class);
    }

    /** MFA চালু করা — চাবি বসিয়ে প্রথম কোড মিলিয়ে। */
    private function turnOn(): string
    {
        $secret = $this->mfa()->begin($this->user);

        $this->mfa()->confirm($this->user->fresh(), Totp::codeFor($secret));

        $this->user = $this->user->fresh();

        return $secret;
    }

    private function tryLogin(array $extra = []): TestResponse
    {
        return $this->post(route('login.store'), [
            'identifier' => $this->user->email,
            'password' => 'password',
            ...$extra,
        ]);
    }

    // ── কোডটা সত্যিই কাজ করে ────────────────────────────────────────

    /**
     * একই চাবি থেকে একই সময়ে একই কোড।
     *
     * এটাই RFC 6238-এর পুরো চুক্তি: ফোনের অ্যাপ আর আমাদের সার্ভার
     * আলাদাভাবে গুনে একই ছয় অঙ্কে পৌঁছায়।
     */
    public function test_the_same_key_gives_the_same_code(): void
    {
        $secret = Totp::newSecret();
        $at = 1_760_000_000;

        $this->assertSame(Totp::codeFor($secret, $at), Totp::codeFor($secret, $at));
        $this->assertMatchesRegularExpression('/^\d{6}$/', Totp::codeFor($secret, $at));
    }

    /** আলাদা চাবিতে আলাদা কোড — নাহলে যেকোনো চাবিই চলত। */
    public function test_a_different_key_gives_a_different_code(): void
    {
        $at = 1_760_000_000;

        $this->assertNotSame(
            Totp::codeFor(Totp::newSecret(), $at),
            Totp::codeFor(Totp::newSecret(), $at),
        );
    }

    /**
     * ঘড়ির সামান্য পার্থক্য মেনে নেওয়া হয়।
     *
     * ফোনের ঘড়ি আর সার্ভারের ঘড়ি কখনো হুবহু মেলে না। না মানলে প্রতি
     * মিনিটে কয়েকজনের কোড "ভুল" দেখাত আর তাঁরা MFA বন্ধ করে দিতেন।
     */
    public function test_a_small_clock_difference_is_forgiven(): void
    {
        $secret = Totp::newSecret();
        $at = 1_760_000_000;

        $this->assertTrue(Totp::verify($secret, Totp::codeFor($secret, $at - 30), $at));
        $this->assertTrue(Totp::verify($secret, Totp::codeFor($secret, $at + 30), $at));
    }

    /**
     * বেশি পুরনো কোড চলে না।
     *
     * প্রতিটা বাড়তি ধাপ চুরি করা একটা কোডের আয়ু বাড়ায়। দুই মিনিট
     * আগের কোড মেনে নিলে কাঁধের উপর দিয়ে দেখা কোডটাও কাজ করত।
     */
    public function test_an_old_code_is_refused(): void
    {
        $secret = Totp::newSecret();
        $at = 1_760_000_000;

        $this->assertFalse(Totp::verify($secret, Totp::codeFor($secret, $at - 120), $at));
    }

    /** ছয় অঙ্ক ছাড়া কিছুই নয় — খালি, ছোট, বা অক্ষর। */
    public function test_anything_that_is_not_six_digits_is_refused(): void
    {
        $secret = Totp::newSecret();

        foreach (['', '123', 'abcdef', '12345678'] as $rubbish) {
            $this->assertFalse(Totp::verify($secret, $rubbish));
        }
    }

    // ── লগইনে দ্বিতীয় ধাপ ───────────────────────────────────────────

    /** MFA চালু না থাকলে আগের মতোই — কিছুই বদলায়নি। */
    public function test_without_mfa_nothing_changes(): void
    {
        $this->tryLogin()->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->user);
    }

    /**
     * চালু থাকলে পাসওয়ার্ড একা যথেষ্ট নয়।
     *
     * এটাই পুরো কাজটা।
     */
    public function test_with_mfa_the_password_alone_is_not_enough(): void
    {
        $this->turnOn();

        $this->tryLogin()->assertSessionHasErrors('code');

        // `assertGuest()`-এর আর্গুমেন্টটা গার্ডের নাম, বার্তার নয় —
        // তাই দাবিটা আলাদা করে লেখা
        $this->assertFalse(auth()->check(),
            'ঠিক পাসওয়ার্ডেই ঢুকে পড়েছেন — দ্বিতীয় ধাপটা কিছুই আটকায়নি।');
    }

    /** ঠিক কোড দিলে ঢোকা যায়। */
    public function test_the_right_code_gets_in(): void
    {
        $secret = $this->turnOn();

        $this->tryLogin(['code' => Totp::codeFor($secret)])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->user);
    }

    /** ভুল কোডে নয়। */
    public function test_a_wrong_code_does_not(): void
    {
        $this->turnOn();

        $this->tryLogin(['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    /**
     * ভুল পাসওয়ার্ডে ঠিক কোড দিলেও নয়।
     *
     * দ্বিতীয় ধাপ প্রথমটার **বদলে** নয়, **উপরে**। উল্টো হলে কোড
     * জানা যে কেউ পাসওয়ার্ড ছাড়াই ঢুকতে পারতেন।
     */
    public function test_a_right_code_with_a_wrong_password_does_not(): void
    {
        $secret = $this->turnOn();

        $this->tryLogin(['password' => 'nope', 'code' => Totp::codeFor($secret)])
            ->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }

    /**
     * কোডে আটকে যাওয়া চেষ্টাও ঢোকার খাতায় ওঠে।
     *
     * একই নামে বারবার এটা মানে কেউ পাসওয়ার্ডটা পেয়ে গেছেন আর কোডে
     * আটকে আছেন — আর সেটাই সবচেয়ে জরুরি সতর্কবার্তা।
     */
    public function test_being_stopped_at_the_code_is_written_down(): void
    {
        $this->turnOn();

        $this->tryLogin();
        $this->tryLogin(['code' => '000000']);

        $reasons = LoginAttempt::query()->pluck('reason')->all();

        $this->assertContains(LoginAttempt::NEEDS_CODE, $reasons);
        $this->assertContains(LoginAttempt::WRONG_CODE, $reasons);
    }

    // ── পুনরুদ্ধার কোড ──────────────────────────────────────────────

    /**
     * ফোন হারালে পুনরুদ্ধার কোড দিয়েই ঢোকা যায়।
     *
     * পুনরুদ্ধারের পথ ছাড়া MFA চালু করা মানে একদিন মালিক নিজেই নিজের
     * খাতা থেকে চিরতরে বাইরে — আর সেই দিন MFA-টাই সবচেয়ে বড় ক্ষতি।
     */
    public function test_a_recovery_code_gets_in_when_the_phone_is_gone(): void
    {
        $secret = $this->mfa()->begin($this->user);
        $codes = $this->mfa()->confirm($this->user->fresh(), Totp::codeFor($secret));

        $this->assertNotNull($codes);
        $this->assertCount(8, $codes);

        $this->tryLogin(['code' => $codes[0]])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->user->fresh());
    }

    /**
     * একটা পুনরুদ্ধার কোড একবারই চলে।
     *
     * বারবার চললে সেটা আর দ্বিতীয় ধাপ নয়, দ্বিতীয় একটা পাসওয়ার্ড —
     * আর ওটা কাগজে লেখা থাকে।
     */
    public function test_a_recovery_code_only_works_once(): void
    {
        $secret = $this->mfa()->begin($this->user);
        $codes = $this->mfa()->confirm($this->user->fresh(), Totp::codeFor($secret));

        $this->tryLogin(['code' => $codes[0]])->assertRedirect(route('dashboard'));
        $this->post(route('logout'));

        $this->tryLogin(['code' => $codes[0]])->assertSessionHasErrors('code');

        $this->assertFalse(auth()->check(), 'একই পুনরুদ্ধার কোড দ্বিতীয়বার চলেছে।');
    }

    /** খরচ হওয়া কোডটা তালিকা থেকে সরে যায়, আর গোনায় দেখা যায়। */
    public function test_a_used_code_leaves_the_list(): void
    {
        $secret = $this->mfa()->begin($this->user);
        $codes = $this->mfa()->confirm($this->user->fresh(), Totp::codeFor($secret));

        $this->assertSame(8, $this->mfa()->recoveryCodesLeft($this->user->fresh()));

        $this->tryLogin(['code' => $codes[0]]);

        $this->assertSame(7, $this->mfa()->recoveryCodesLeft($this->user->fresh()));
    }

    // ── চালু করার ধাপগুলো ───────────────────────────────────────────

    /**
     * চাবি বসানোই চালু করা নয় — প্রথম কোড মিলতে হয়।
     *
     * সাথে সাথে চালু করলে যে অ্যাপে ভুল করে চাবিটা বসেনি, তিনি পরের
     * লগইনেই বাইরে — আর ঢুকে সেটা ঠিক করার কোনো পথ নেই।
     */
    public function test_putting_the_key_in_does_not_turn_it_on(): void
    {
        $this->mfa()->begin($this->user);

        $this->assertFalse($this->mfa()->isOn($this->user->fresh()));

        // চালু নয় মানে লগইনে কোড চাওয়াও হয় না
        $this->tryLogin()->assertRedirect(route('dashboard'));
    }

    /** ভুল কোডে চালু হয় না, আর পুনরুদ্ধার কোডও তৈরি হয় না। */
    public function test_a_wrong_first_code_turns_nothing_on(): void
    {
        $this->mfa()->begin($this->user);

        $this->assertNull($this->mfa()->confirm($this->user->fresh(), '000000'));
        $this->assertFalse($this->mfa()->isOn($this->user->fresh()));
        $this->assertSame(0, $this->mfa()->recoveryCodesLeft($this->user->fresh()));
    }

    // ── গোপনীয়তা ────────────────────────────────────────────────────

    /**
     * চাবিটা কোনো JSON বা লগে যায় না।
     *
     * একটা `dd($user)` বা একটা API রেসপন্সেই চাবিটা বেরিয়ে গেলে MFA
     * শেষ — আর ভুলটা ধরা পড়ত না, কারণ সবকিছু কাজ করতেই থাকত।
     */
    public function test_the_key_never_leaks_through_json(): void
    {
        $secret = $this->turnOn();

        $json = $this->user->fresh()->toJson();

        $this->assertStringNotContainsString($secret, $json, 'গোপন চাবিটা JSON-এ বেরিয়ে গেছে।');
        $this->assertStringNotContainsString('mfa_secret', $json);
        $this->assertStringNotContainsString('mfa_recovery_codes', $json);
    }

    /**
     * পুনরুদ্ধার কোডগুলো ডাটাবেজে সাদা অবস্থায় থাকে না।
     *
     * ওগুলো পাসওয়ার্ডের সমান ক্ষমতা রাখে — একটা দিয়েই MFA পেরোনো যায়।
     */
    public function test_recovery_codes_are_not_stored_in_the_clear(): void
    {
        $secret = $this->mfa()->begin($this->user);
        $codes = $this->mfa()->confirm($this->user->fresh(), Totp::codeFor($secret));

        $raw = (string) DB::table('users')
            ->where('id', $this->user->id)
            ->value('mfa_recovery_codes');

        $this->assertStringNotContainsString($codes[0], $raw,
            'পুনরুদ্ধার কোডটা ডাটাবেজে সাদা অবস্থায় বসে আছে।');
    }

    // ── পর্দা ───────────────────────────────────────────────────────

    /** পর্দাটা খোলে, আর বন্ধ অবস্থায় কারণটা বলে। */
    public function test_the_screen_explains_why(): void
    {
        $this->actingAs($this->user)
            ->get(route('mfa'))
            ->assertOk()
            ->assertSee(__('auth.mfa_why'))
            ->assertSee(__('auth.mfa_turn_on'));
    }

    /** চালু থাকলে চাবিটা আর দেখানো হয় না। */
    public function test_the_key_is_not_shown_once_it_is_on(): void
    {
        $secret = $this->turnOn();

        $this->actingAs($this->user)
            ->get(route('mfa'))
            ->assertOk()
            ->assertSee(__('auth.mfa_is_on'))
            ->assertDontSee(Totp::readable($secret));
    }

    /**
     * বন্ধ করতে পাসওয়ার্ড লাগে।
     *
     * খোলা কম্পিউটারের সামনে বসে এক ক্লিকে দ্বিতীয় তালা খুলে ফেলা
     * গেলে MFA-র কোনো মানে থাকত না।
     */
    public function test_turning_it_off_needs_the_password(): void
    {
        $this->turnOn();

        $this->actingAs($this->user)
            ->delete(route('mfa.destroy'), ['password' => 'wrong'])
            ->assertSessionHasErrors('password');

        $this->assertTrue($this->mfa()->isOn($this->user->fresh()), 'ভুল পাসওয়ার্ডেই MFA বন্ধ হয়ে গেছে।');

        $this->actingAs($this->user)
            ->delete(route('mfa.destroy'), ['password' => 'password'])
            ->assertRedirect();

        $this->assertFalse($this->mfa()->isOn($this->user->fresh()));
    }

    /** বন্ধ করলে চাবি, তারিখ ও কোড — তিনটাই মুছে যায়। */
    public function test_turning_it_off_clears_everything(): void
    {
        $this->turnOn();

        $this->mfa()->turnOff($this->user);

        $fresh = $this->user->fresh();

        $this->assertNull($fresh->mfa_secret);
        $this->assertNull($fresh->mfa_confirmed_at);
        $this->assertSame(0, $this->mfa()->recoveryCodesLeft($fresh));
    }

    /** পাসওয়ার্ড হ্যাশটা অক্ষত — বন্ধ করা লগইন ভাঙে না। */
    public function test_the_password_still_works_after_all_this(): void
    {
        $this->turnOn();
        $this->mfa()->turnOff($this->user);

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));

        $this->tryLogin()->assertRedirect(route('dashboard'));
    }
}
