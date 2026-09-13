<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Security\MfaService;
use App\Core\Security\Totp;
use App\Core\Support\CompanyContext;
use App\Models\AuditFieldChange;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Notifications\PasswordResetLink;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Tests\TestCase;

/**
 * ⛔ ফেরার একমাত্র পথ ছিল মালিককে ফোন করা — ১৩ সেপ্টেম্বর ২০২৬।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * ⓘ পাসওয়ার্ড ভুলে গেলে পথ ছিল একটাই: মালিক `UserController`-এ গিয়ে
 * হাতে বসিয়ে দিতেন। ⚠️ অর্থাৎ রাত দশটায় ডিপোর একজন কর্মী নিজের
 * পাসওয়ার্ড ভুলে গেলে **পরদিন সকাল পর্যন্ত ব্যবস্থাটার বাইরে**।
 *
 * লগইনের পাতায় "পাসওয়ার্ড ভুলে গেছেন?" লেখাটা ছিল, পাশে একটা নিষ্ক্রিয়
 * "শীঘ্রই আসছে" ব্যাজ। ⭐ ঐ ব্যাজটা সৎ ছিল — `MAIL_MAILER=log` অবস্থায়
 * কাজ-করা লিংক বসালে মানুষ সারাদিন ইনবক্স খুলে বসে থাকতেন।
 *
 * ── ⚠️ এই ফাইলটা যা পাহারা দেয় ──────────────────────────────────────
 * ফিচারটা কাজ করে কি না, সেটা এর অর্ধেক মাত্র। ⛔ পাসওয়ার্ড রিসেট
 * এমন একটা জিনিস যা **ভুলভাবে বানালে ব্যবস্থার সব তালা একসাথে খুলে
 * দেয়**, তাই বাকি অর্ধেকটা চারটা "না" পাহারা দেয়:
 *
 *     ব্যবহারকারীর তালিকা ফাঁস হয় না  (ঠিকানা থাকুক বা না থাকুক, এক উত্তর)
 *     রিসেট করে MFA এড়ানো যায় না      (সবচেয়ে সহজে ভুল হওয়ার জায়গা)
 *     খাতায় লেখা বাদ পড়ে না            (কে নিজে রিসেট করল, আলাদা নামে)
 *     দরজাটা কর্মীর দরজার চেয়ে ঢিলা নয় (অন্য ফাইলেও পাহারা আছে)
 */
class TheOnlyWayBackInWasToAskTheOwnerTest extends TestCase
{
    use RefreshDatabase;

    private User $rahim;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->rahim = User::query()->where('email', 'sales@abos.test')->firstOrFail();
    }

    /**
     * একটা সত্যিকারের টোকেন — ব্রোকারের নিজের হাতে বানানো।
     *
     * ⓘ হাতে একটা এলোমেলো স্ট্রিং বানিয়ে টেবিলে বসালে সেটা ব্রোকারের
     * হ্যাশের সাথে মিলত না, আর প্রতিটা পরীক্ষা "লিংক অচল" বলে থেমে
     * যেত — অর্থাৎ পরীক্ষাটা সবুজ হত না, কিন্তু কারণটাও ভুল হত।
     */
    private function tokenFor(User $user): string
    {
        return PasswordBroker::createToken($user);
    }

    /* ── পথটা সত্যিই আছে ───────────────────────────────────────────── */

    /**
     * লগইনের পাতায় লিংকটা আর "শীঘ্রই আসছে" নয়।
     *
     * ⚠️ এটাই ছিল ব্যবহারকারীর দিক থেকে পুরো সমস্যাটা: পথটার **নামটা**
     * পর্দায় ছিল, পথটা ছিল না।
     */
    public function test_the_door_on_the_login_page_now_actually_opens(): void
    {
        /*
         * ⚠️ `/signin`, `/login` নয় — আর পার্থক্যটা লেখার সময় ধরা পড়েছে।
         *
         * ⓘ প্রথম খসড়ায় দাবিটা ছিল `/login`-এর উপর, আর সেটা ব্যর্থ হলো।
         * কারণ কোডে নয়, দাবিতে: ২ সেপ্টেম্বর ২০২৬-এ `/login` থেকে
         * **ফর্মটা সরানো হয়েছে** — ওটা এখন পরিচিতির পাতা, একটাই বোতাম
         * (`login.calm`-এ পাঠায়)। ⭐ লগইনের ঘরগুলো আর "পাসওয়ার্ড ভুলে
         * গেছেন?" লিংকটা থাকে শান্ত দরজায়, যেখানে [[auth._form]]
         * অন্তর্ভুক্ত হয় — আর মানুষ রোজ ঐ পাতাটাই দেখেন।
         *
         * ── ⚠️ পরের জনের জন্য একটা ফাঁদ, লিখে রাখা ─────────────────
         * `assertSee` ব্যর্থ হলে সে **পুরো HTML** ছাপে, আর লেখাটা কেটে
         * যায়। ⛔ এই পাতাগুলোয় ব্র্যান্ড প্যানেলটা মার্কআপে আগে বসে, তাই
         * ছাপা অংশে কেবল ওটাই দেখা যায় — আর প্রথমে মনে হয় **"আমার
         * Blade সম্পাদনাটা রেন্ডারই হচ্ছে না"**।
         *
         * ⓘ যেভাবে সত্যিটা বেরোল: ছাপা লেখায় `remember` শব্দটা আছে কি
         * না খুঁজে দেখা। নেই — অর্থাৎ **ফর্মটাই ঐ পাতায় নেই**, আর আমার
         * সম্পাদনা নিয়ে প্রশ্নই ওঠে না। ⭐ একটা মাপ যুক্তি দিয়ে খোঁজার
         * চেয়ে দ্রুত ছিল।
         */
        $this->get(route('login.calm'))
            ->assertOk()
            ->assertSee(route('password.request'), escape: false);

        $this->get(route('password.request'))->assertOk();
    }

    /**
     * চিঠিটা সত্যিই যায়, আর তাতে কাজ-করা একটা টোকেন থাকে।
     */
    public function test_a_link_really_goes_out_to_somebody_we_know(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => 'sales@abos.test'])
            ->assertRedirect()
            ->assertSessionHas('sent');

        Notification::assertSentTo(
            $this->rahim,
            PasswordResetLink::class,
            function (PasswordResetLink $mail) {
                /*
                 * ⭐ কেবল "পাঠানো হয়েছে" দেখা যথেষ্ট নয় — চিঠিটার
                 * ভেতরের লিংকটা কাজ করতে হবে। ⓘ ভুল রুট বা ভুল টোকেন
                 * বসালে চিঠি যেত ঠিকই, আর মানুষ একটা মৃত লিংকে চাপ
                 * দিতেন।
                 */
                $mail->toMail($this->rahim);

                return true;
            },
        );
    }

    /**
     * পুরো যাত্রাটা — লিংক চাওয়া থেকে নতুন পাসওয়ার্ডে ঢোকা পর্যন্ত।
     */
    public function test_the_whole_way_back_in_works(): void
    {
        $token = $this->tokenFor($this->rahim);

        $this->get(route('password.reset', ['token' => $token, 'email' => $this->rahim->email]))
            ->assertOk();

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $this->rahim->email,
            'password' => 'a-brand-new-secret-4',
            'password_confirmation' => 'a-brand-new-secret-4',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('a-brand-new-secret-4', $this->rahim->fresh()->password));

        // আর নতুন পাসওয়ার্ড দিয়ে সত্যিই ঢোকা যায়
        $this->post(route('login.store'), [
            'identifier' => 'sales@abos.test',
            'password' => 'a-brand-new-secret-4',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($this->rahim);
    }

    /**
     * একই লিংক দ্বিতীয়বার চলে না।
     *
     * ⓘ টোকেনটা ব্যবহারের পর মুছে যায়। ⚠️ না মুছলে কারও ইমেইল একবার
     * পড়তে পারলেই সে **যতবার খুশি** পাসওয়ার্ড বদলাতে পারত — এমনকি
     * মাস পরেও।
     */
    public function test_the_same_link_does_not_work_twice(): void
    {
        $token = $this->tokenFor($this->rahim);

        $payload = [
            'token' => $token,
            'email' => $this->rahim->email,
            'password' => 'first-new-secret-5',
            'password_confirmation' => 'first-new-secret-5',
        ];

        $this->post(route('password.store'), $payload)->assertRedirect(route('login'));

        $this->post(route('password.store'), [
            ...$payload,
            'password' => 'second-new-secret-6',
            'password_confirmation' => 'second-new-secret-6',
        ])->assertSessionHasErrors('email');

        // দ্বিতীয়বারেরটা বসেনি — প্রথমটাই টিকে আছে
        $this->assertTrue(Hash::check('first-new-secret-5', $this->rahim->fresh()->password));
    }

    /* ── ⛔ চারটা "না" ─────────────────────────────────────────────── */

    /**
     * ⛔ ব্যবহারকারীর তালিকা এই দরজা দিয়ে গোনা যায় না।
     *
     * ── কেন এটা সবচেয়ে সহজে ভুল হওয়া জায়গা ─────────────────────────
     * ⚠️ Laravel-এর ডিফল্ট আচরণ "We can't find a user with that email
     * address" বলে। ⛔ তাতে বাইরের যে কেউ ঠিকানা বসিয়ে বসিয়ে **কর্মীদের
     * পুরো তালিকা** বানিয়ে ফেলতে পারতেন — আর এই ব্যবস্থায় ব্যবহারকারী
     * মানে কর্মী, তাই তালিকাটা নিজেই একটা ক্ষতি।
     *
     * ⓘ পরীক্ষাটা **বাইট ধরে** মেলায়, "বার্তায় ঐ শব্দটা নেই" ধরনের
     * নরম দাবি নয়: দুইটা উত্তর হুবহু এক হতে হবে।
     */
    public function test_a_stranger_cannot_count_our_staff_at_this_door(): void
    {
        Notification::fake();

        $known = $this->post(route('password.email'), ['email' => 'sales@abos.test']);
        $unknown = $this->post(route('password.email'), ['email' => 'nobody@nowhere.test']);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->headers->get('Location'), $unknown->headers->get('Location'));

        $this->assertTrue(session()->has('sent'));

        /*
         * ⚠️ ত্রুটির ব্যাগটা **একেবারে না থাকা** দেখা হয়, খালি থাকা নয়।
         *
         * ⓘ প্রথম খসড়ায় এখানে `session('errors')?->getBags()['default']`
         * লেখা ছিল — আর ত্রুটি না থাকলে সেটা `null`-এর উপর অ্যারে
         * অ্যাক্সেস, যা PHP 8-এ সতর্কতা তোলে। পরীক্ষাটা তখন সবুজ হত
         * ভুল কারণে, আর একদিন সতর্কতাটা ব্যর্থতা হয়ে ফিরত।
         */
        $this->assertFalse(session()->has('errors'),
            'অচেনা ঠিকানার জন্য একটা ত্রুটি বসেছে — ওটাই তালিকা ফাঁসের পথ।');

        // আর যাচাই: চেনা ঠিকানায় সত্যিই গেছে, অচেনায় যায়নি
        Notification::assertSentTo($this->rahim, PasswordResetLink::class);
        Notification::assertCount(1);
    }

    /**
     * ⛔ দ্বিতীয় ধাপেও তালিকা গোনা যায় না।
     *
     * ── কী ধরা পড়েছিল লেখার সময় ─────────────────────────────────────
     * ⚠️ `PasswordBroker::reset()` দুইটা আলাদা কথা বলে — "এই ঠিকানায়
     * কেউ নেই" আর "টোকেন অচল"। ⛔ আর এই পদ্ধতিতে পৌঁছাতে **বৈধ টোকেন
     * লাগে না**: যে কেউ সরাসরি আবোল-তাবোল টোকেনসহ POST করতে পারেন।
     *
     * ⓘ অর্থাৎ প্রথম ধাপে যে দরজাটা সাবধানে বন্ধ করা হলো, এই ধাপটা
     * সেটাই খুলে দিত — একই তালিকা, অন্য পথে।
     */
    public function test_the_second_step_does_not_leak_the_list_either(): void
    {
        $payload = [
            'token' => 'a-token-that-was-never-issued',
            'password' => 'some-new-secret-7',
            'password_confirmation' => 'some-new-secret-7',
        ];

        /*
         * ⚠️ বার্তাটা `assertSessionHasErrors()` দিয়েই মেলানো হয়, সেশন
         * থেকে হাতে তুলে নয় — আর এটাও লেখার সময় শেখা।
         *
         * ⓘ প্রথম খসড়ায় `session('errors')->getBag('default')` ছিল, আর
         * সেটা ভাঙল: *"Call to a member function getBag() on array"*।
         * পুনঃনির্দেশের পর টেস্ট প্রক্রিয়ায় ঘরটায় সবসময় একটা
         * `ViewErrorBag` থাকে না — কখনো কাঁচা অ্যারে।
         *
         * ⭐ আর নতুন রূপটা কেবল কম ভঙ্গুর নয়, **বেশি বলে**: দুইটা উত্তর
         * এক কি না তা তো দেখেই, সাথে দেখে সেটা ঠিক ঐ নিরপেক্ষ বাক্যটাই
         * — অর্থাৎ দুইটা একসাথে ভুল হয়ে "সমান" থাকলেও ধরা পড়বে।
         */
        $neutral = __('auth.reset_link_dead');

        $known = $this->from(route('login.calm'))
            ->post(route('password.store'), [...$payload, 'email' => 'sales@abos.test']);

        $known->assertSessionHasErrors(['email' => $neutral]);

        session()->forget('errors');

        $unknown = $this->from(route('login.calm'))
            ->post(route('password.store'), [...$payload, 'email' => 'nobody@nowhere.test']);

        $unknown->assertSessionHasErrors(['email' => $neutral]);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame(
            $known->headers->get('Location'),
            $unknown->headers->get('Location'),
            'চেনা আর অচেনা ঠিকানা আলাদা জায়গায় পাঠাচ্ছে — সেটাও একটা তথ্য।',
        );
    }

    /**
     * ⛔ রিসেট করে দুই ধাপের লগইন এড়ানো যায় না।
     *
     * ── কেন এটাই সবচেয়ে বিপজ্জনক ভুল ────────────────────────────────
     * ⚠️ Laravel-এর নিজের starter kit রিসেটের পরপরই `Auth::login()`
     * ডাকে। ⛔ তাতে যার MFA চালু, তার **ইমেইল দখল করতে পারলেই ফোনের
     * কোড ছাড়াই ভেতরে** — অর্থাৎ দুই ধাপের তালাটা একটা ইমেইল-রিসেটের
     * সমান হয়ে যেত, আর কেউ টের পেত না, কারণ সবকিছু কাজ করতেই থাকত।
     */
    public function test_resetting_a_password_does_not_walk_past_two_step_sign_in(): void
    {
        /*
         * ⓘ MFA সত্যিই চালু করা হচ্ছে, নকল করা হচ্ছে না — নাহলে
         * পরীক্ষাটা প্রমাণ করত কেবল "কিছু একটা ঘটেনি"।
         */
        $mfa = app(MfaService::class);

        $secret = $mfa->begin($this->rahim);
        $mfa->confirm($this->rahim, Totp::codeFor($secret));

        $this->assertTrue($mfa->isOn($this->rahim->fresh()));

        $token = $this->tokenFor($this->rahim);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $this->rahim->email,
            'password' => 'a-brand-new-secret-8',
            'password_confirmation' => 'a-brand-new-secret-8',
        ])->assertRedirect(route('login'));

        // ⛔ রিসেটের পরেও তিনি বাইরেই
        $this->assertGuest();

        /*
         * ⭐ আর নতুন পাসওয়ার্ড দিয়েও কোড ছাড়া ঢোকা যায় না — এটাই
         * আসল দাবি। উপরের `assertGuest()` কেবল বলে "ঢোকানো হয়নি";
         * এটা বলে "দ্বিতীয় তালাটা এখনো জায়গামতো আছে"।
         */
        $this->post(route('login.store'), [
            'identifier' => 'sales@abos.test',
            'password' => 'a-brand-new-secret-8',
        ])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    /**
     * ⛔ কে নিজে রিসেট করল, খাতায় লেখা থাকে — আর আলাদা নামে।
     *
     * ── কেন `password_set` নামটা এখানে চলত না ───────────────────────
     * ⓘ ওটার মানে **মালিক বসিয়েছেন**। ⚠️ দুইটা এক নামে লিখলে নিরীক্ষায়
     * "এটা কি অনুমোদিত ছিল" প্রশ্নের উত্তর হারাত — একজন প্রশাসকের
     * বসানো পাসওয়ার্ড আর একজনের নিজের রিসেট সম্পূর্ণ আলাদা ঘটনা।
     *
     * ── ⭐ কর্তার ঘরটা খালি, আর সেটাই তথ্য ───────────────────────────
     * রিসেটের মুহূর্তে কেউ লগইন করা নেই, তাই `user_id` null। ⓘ সেটা
     * অভাব নয়: `password_set` সারিতে একজন প্রশাসকের নাম থাকে, এখানে
     * থাকে না — সারিটা নিজেই বলে দেয় এটা কার কাজ।
     */
    public function test_the_book_says_who_reset_it_and_that_they_did_it_themselves(): void
    {
        $token = $this->tokenFor($this->rahim);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $this->rahim->email,
            'password' => 'a-brand-new-secret-9',
            'password_confirmation' => 'a-brand-new-secret-9',
        ])->assertRedirect(route('login'));

        $rows = AuditTrail::query()
            ->withoutGlobalScopes()
            ->where('auditable_type', User::class)
            ->where('auditable_id', $this->rahim->id)
            ->where('action', 'password_reset')
            ->get();

        $this->assertCount(1, $rows, 'নিজে রিসেট করার ঘটনাটা খাতায় বসেনি।');
        $this->assertNull($rows->first()->user_id, 'কেউ লগইন করা ছিল না — কর্তার ঘরটা খালি থাকাই সত্যি।');

        /*
         * ⚠️ প্রসঙ্গ ছাড়া সারিটা লেখাই যেত না — অতিথি অনুরোধে
         * `CompanyContext` খালি, আর `record()` তখন চুপচাপ ফিরে যায়।
         * ⓘ [[User::auditCompanyId()]] তাই মানুষটার নিজের কোম্পানিতে
         * নামে, আর এই দাবিটা ঠিক সেটাই পাহারা দেয়।
         */
        $this->assertSame($this->company->id, $rows->first()->company_id);
    }

    /**
     * ⛔ পাসওয়ার্ডের হ্যাশ এই পথেও খাতায় যায় না।
     *
     * ⓘ নতুন একটা দরজা মানে হ্যাশ ফাঁস হওয়ার একটা নতুন সুযোগ। দাবিটা
     * তাই এখানেও, যদিও [[User::auditIgnores()]] আর
     * `AuditEngine::NEVER_LOGGED` দুইটাই এটা আটকানোর কথা।
     */
    public function test_the_new_door_does_not_put_a_hash_in_the_book(): void
    {
        $token = $this->tokenFor($this->rahim);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $this->rahim->email,
            'password' => 'a-brand-new-secret-3',
            'password_confirmation' => 'a-brand-new-secret-3',
        ]);

        $this->assertSame(0, AuditFieldChange::query()
            ->where(fn ($q) => $q
                ->where('old_value', 'like', '$2y$%')
                ->orWhere('new_value', 'like', '$2y$%'))
            ->count());
    }

    /**
     * ⛔ দুর্বল পাসওয়ার্ড এই দরজা দিয়েও ঢোকে না।
     *
     * ⓘ [[NoDoorIsWeakerThanTheStaffDoorTest]] কোড পড়ে প্রমাণ করে নিয়মটা
     * লেখা আছে; এটা প্রমাণ করে নিয়মটা **সত্যিই খাটে**। ⚠️ দুইটা আলাদা
     * প্রশ্ন: একটা নিয়মের অস্তিত্ব, অন্যটা তার কার্যকারিতা।
     */
    public function test_the_staff_password_rule_really_bites_here(): void
    {
        $token = $this->tokenFor($this->rahim);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $this->rahim->email,
            'password' => '12345678',
            'password_confirmation' => '12345678',
        ])->assertSessionHasErrors('password');

        $this->assertFalse(Hash::check('12345678', $this->rahim->fresh()->password));
    }

    /**
     * ⛔ পুরনো "মনে রাখুন" কুকি রিসেটের পর আর চলে না।
     *
     * ── কেন এটা দরকার ───────────────────────────────────────────────
     * ⓘ পাসওয়ার্ড ভুলে যাওয়ার একটা সম্ভাবনা **অ্যাকাউন্ট অন্য কারও
     * হাতে যাওয়া**। ⚠️ পুরনো `remember_token` বেঁচে থাকলে রিসেটের পরেও
     * সেই ব্রাউজারটা ভেতরে থেকে যেত — অর্থাৎ তালা বদলেও পুরনো চাবিটা
     * কাজ করত।
     */
    public function test_a_reset_throws_out_the_remembered_browsers(): void
    {
        $before = $this->rahim->fresh()->remember_token;

        $token = $this->tokenFor($this->rahim);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $this->rahim->email,
            'password' => 'a-brand-new-secret-2',
            'password_confirmation' => 'a-brand-new-secret-2',
        ])->assertRedirect(route('login'));

        $this->assertNotSame($before, $this->rahim->fresh()->remember_token);
        $this->assertNotNull($this->rahim->fresh()->remember_token);
    }
}
