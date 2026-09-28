<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Security\MfaService;
use App\Core\Security\Totp;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * দুই ধাপ চাওয়া যেত কেবল সুপার অ্যাডমিনের কাছে, আর কারও কাছে নয়।
 *
 * ── ⭐ মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"ব্যবহারকারী লিস্টে ২ স্টেপের অন-অফ বোতাম দাও।"*
 *
 * ── ⓘ কেন কলাম, অনুমতি নয় ────────────────────────────────────────────
 * ⚠️ এটা *"কে কী করতে পারে"* নয় — *"এই অ্যাকাউন্টে দ্বিতীয় তালা লাগবে
 * কি না"*। ⛔ অনুমতি দিয়ে করলে ওটা ভূমিকা-ছকে বসত, আর একজনের জন্য চালু
 * করা মানে ঐ ভূমিকার **সবার** জন্য চালু — অথচ মালিক ব্যবহারকারী ধরে
 * চেয়েছেন।
 *
 * ── ⚠️ আর চালুর-দিনের সুইচটা এই পথটা বন্ধ করে না ─────────────────────
 * `ABOS_SUPER_ADMIN_TWO_STEP` আছে সুপার অ্যাডমিনের তালা **কবে পড়বে**
 * সেটা বাছতে। ⛔ ওটা দিয়ে প্রশাসকের হাতে বসানো তালাও খুলে গেলে একটা
 * `.env` লাইন দিয়ে গোটা ব্যবস্থার দ্বিতীয় তালা খুলে যেত — নিচে ওটার
 * নিজের দাবি আছে।
 */
final class TwoStepCouldOnlyBeAskedOfTheSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->admin = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $clerk = User::query()
            ->where('email', '!=', $this->admin->email)
            ->get()
            ->first(fn (User $u) => ! $u->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE));

        $this->assertNotNull($clerk, 'ডেমোতে সুপার অ্যাডমিন ছাড়া আর কেউ নেই।');

        $this->clerk = $clerk;

        /*
         * ── ⚠️ লাইভের আকৃতিতেই মাপতে হবে ─────────────────────────────
         * ⛔ `phpunit.xml` চালুর-দিনের সুইচটা **বন্ধ** রাখে, তাই এখানে
         * ভূমিকার শর্তটা এমনিতে কখনো চলে না। ⚠️ একবার এই কারণেই একটা
         * মিউট্যান্ট বেঁচে গিয়েছিল: ভূমিকার শর্তটা ফেলে দিলেও এই ফাইলের
         * আটটা দাবিই সবুজ ছিল, কারণ শর্তটা কখনো পরীক্ষাই হয়নি।
         */
        config(['abos.super_admin_two_step' => true]);

        /*
         * ⓘ আর তাতে প্রশাসকের নিজের তালাও পড়ে যায় — তাই তাঁর চাবিটা আগে
         * বসিয়ে নিই, নইলে তাঁর নিজের অনুরোধটাই বসানোর পর্দায় আটকাত।
         * ⭐ লাইভেও ঠিক এই আকৃতি: যিনি বোতামটা চাপছেন, তাঁর দুই ধাপ বসানো।
         */
        $this->setUpTwoStepFor($this->admin);

        /*
         * ⛔ আর নতুন করে পড়ে নেওয়া — কারণ `actingAs()` যে বস্তুটা পায়
         * সেটাই মিডলওয়্যার `$request->user()` হিসেবে দেখে। ⚠️ চাবি বসানোর
         * **আগে** পড়া বস্তুটা দিলে তার ঘরে চাবি নেই, আর প্রশাসক নিজেই
         * নিজের অনুরোধে আটকে যেতেন — ধরতে একটা মাপ লেগেছিল।
         */
        $this->admin = $this->admin->fresh();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ⭐ একই ব্যবহারকারী, চালু তারপর বন্ধ ───────────────────────────

    public function test_turning_it_on_sends_that_user_to_the_setup_page(): void
    {
        /*
         * ⚠️ একই কর্মী দুইবার, কেবল বোতামের অবস্থা আলাদা। ⛔ দুইজন আলাদা
         * মানুষ নিলে পার্থক্যটা ভূমিকার কারণেও হতে পারত।
         */
        $this->actingAs($this->clerk)
            ->get(route('profile'))
            ->assertSuccessful();

        $this->turnOnFor($this->clerk);

        $this->actingAs($this->clerk->fresh())
            ->get(route('profile'))
            ->assertRedirect(route('mfa'));
    }

    public function test_turning_it_off_with_a_reason_opens_the_screens_again(): void
    {
        $this->turnOnFor($this->clerk);

        $this->actingAs($this->admin)
            ->put(route('system_admin.user.two_step.set', $this->clerk), [
                'required' => 0,
                'reason' => 'ফোন হারিয়ে গেছে, নতুন ফোন আসতে দুই দিন',
            ])
            ->assertRedirect();

        $this->actingAs($this->clerk->fresh())
            ->get(route('profile'))
            ->assertSuccessful();
    }

    public function test_turning_it_off_writes_why_into_the_audit_trail(): void
    {
        /*
         * ⛔ বিনা দাগে খোলা তালা তালা না থাকার চেয়েও খারাপ: সে
         * নিরাপত্তার চেহারা দেয় আর *"কে কখন খুলেছিল"* প্রশ্নের উত্তর
         * দেয় না।
         */
        $this->turnOnFor($this->clerk);

        $this->actingAs($this->admin)
            ->put(route('system_admin.user.two_step.set', $this->clerk), [
                'required' => 0,
                'reason' => 'ফোন হারিয়ে গেছে',
            ]);

        $row = AuditTrail::query()->where('action', 'two_step_off')->latest('id')->first();

        $this->assertNotNull($row, 'তালা খুলল, অথচ খাতায় কোনো দাগ নেই।');
        $this->assertStringContainsString('ফোন', (string) $row->reason);
    }

    public function test_turning_it_off_also_clears_the_key_they_had_set(): void
    {
        /*
         * ⚠️ চাবিটা রেখে দিলে পরে আবার চালু করার দিন **পুরনো ফোনের**
         * কোড চলত। ⛔ আর যে ফোনটা হারিয়ে যাওয়ার কারণে তালা খোলা হলো,
         * সেটাই তখন চাবি হয়ে থাকত।
         */
        $this->turnOnFor($this->clerk);
        $this->setUpTwoStepFor($this->clerk);

        $this->assertTrue(app(MfaService::class)->isOn($this->clerk->fresh()));

        $this->actingAs($this->admin)
            ->put(route('system_admin.user.two_step.set', $this->clerk), [
                'required' => 0,
                'reason' => 'ফোন হারিয়ে গেছে',
            ]);

        $this->assertFalse(app(MfaService::class)->isOn($this->clerk->fresh()),
            'তালা বন্ধ হলো, কিন্তু বসানো চাবিটা রয়ে গেছে।');
    }

    // ── ⛔ যা করা যায় না ─────────────────────────────────────────────

    public function test_no_reason_means_no_change(): void
    {
        $this->turnOnFor($this->clerk);

        $this->actingAs($this->admin)
            ->put(route('system_admin.user.two_step.set', $this->clerk), [
                'required' => 0,
                'reason' => '',
            ])
            ->assertSessionHasErrors('reason');

        $this->assertTrue(
            (bool) $this->clerk->fresh()->two_step_required,
            'কারণ ছাড়াই তালাটা খুলে গেছে।',
        );
    }

    public function test_nobody_switches_off_their_own(): void
    {
        /*
         * ⚠️ পারলে "বাধ্যতামূলক" শব্দটার কোনো মানে থাকত না: এক ক্লিকে
         * নিজের তালা খুলে নেওয়া যেত।
         */
        $this->turnOnFor($this->admin);

        $this->actingAs($this->admin->fresh())
            ->put(route('system_admin.user.two_step.set', $this->admin), [
                'required' => 0,
                'reason' => 'নিজেই খুলে নিচ্ছি',
            ])
            ->assertSessionHasErrors('reason');

        $this->assertTrue((bool) $this->admin->fresh()->two_step_required);
    }

    public function test_the_go_live_switch_does_not_unlock_a_hand_set_lock(): void
    {
        /*
         * ⭐ সুইচটা সুপার অ্যাডমিনের তালাটা পিছিয়ে দিতে পারে, কিন্তু
         * প্রশাসক যাঁর জন্য হাতে চালু করেছেন তাঁর তালা নয়। ⛔ নাহলে
         * একটা `.env` লাইন গোটা ব্যবস্থার দ্বিতীয় তালা খুলে দিত।
         */
        config(['abos.super_admin_two_step' => false]);

        $this->turnOnFor($this->clerk);

        $this->actingAs($this->clerk->fresh())
            ->get(route('profile'))
            ->assertRedirect(route('mfa'));
    }

    public function test_the_switch_being_on_does_not_ask_an_ordinary_user(): void
    {
        /*
         * ⭐ শর্তটার **দুইটা** পথ, আর এই দাবিটা পাহারা দেয় যে ওরা আলাদা:
         * ভূমিকার পথটা খোলা থাকা অবস্থাতেও সাধারণ কর্মীর তালা পড়ে না।
         *
         * ⛔ ভূমিকার শর্তটা ফেলে দিলে (বা কোনোদিন ভুলে বাদ পড়লে) গুদামের
         * প্রতিটি কর্মীর কাছে ফোন-কোড চাওয়া হত, আর কারও কাছে ফোন না
         * থাকায় গোটা দোকান বন্ধ হয়ে যেত।
         *
         * ⚠️ একই সুইচ, একই অনুরোধ, কেবল মানুষ দুইজন — তাই পার্থক্যটা
         * ভূমিকা ছাড়া আর কিছু থেকে আসতে পারে না।
         */
        $this->assertFalse((bool) $this->clerk->two_step_required,
            'কর্মীর ঘরটা আগে থেকেই চালু — তাহলে ভূমিকার পথটা মাপা যেত না।');

        $this->actingAs($this->clerk)
            ->get(route('profile'))
            ->assertSuccessful();

        /* ⓘ আর সুপার অ্যাডমিনের বেলায় ঐ একই সুইচ তালা ফেলেই দেয় */
        $locked = User::query()->where('email', $this->admin->email)->firstOrFail();
        app(MfaService::class)->turnOff($locked);

        $this->actingAs($locked->fresh())
            ->get(route('profile'))
            ->assertRedirect(route('mfa'));
    }

    // ── ⓘ তালিকায় বোতামটা সত্যিই আঁকা হয় ───────────────────────────

    public function test_the_user_list_shows_the_state_for_every_row(): void
    {
        /*
         * ⛔ কোড থাকলেও তালিকায় না এলে মালিক বোতামটা কোনোদিন পেতেন না —
         * এই প্রকল্পের সবচেয়ে চেনা ফাঁদ, আর আজকের লটের সুইচটাও ঠিক
         * এভাবেই মাসখানেক অদৃশ্য ছিল।
         */
        /* ⓘ প্রশাসকের দুই ধাপ `setUp()`-এই বসানো — লাইভের মতোই */
        $this->assertTrue(app(MfaService::class)->isOn($this->admin));

        $page = $this->actingAs($this->admin)->get(route('system_admin.user.index'));
        $page->assertOk();

        $html = $page->getContent();

        $this->assertStringContainsString('data-two-step-state', $html,
            'ব্যবহারকারীর তালিকায় দুই ধাপের ঘরটাই নেই।');

        $this->assertStringContainsString(__('auth.two_step_turn_on'), $html,
            'চালু করার বোতামটা কোনো সারিতেই নেই।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function turnOnFor(User $user): void
    {
        $this->actingAs($this->admin->fresh())
            ->put(route('system_admin.user.two_step.set', $user), ['required' => 1])
            ->assertRedirect();

        $this->assertTrue((bool) $user->fresh()->two_step_required,
            'চালু করার অনুরোধটা কিছুই বদলায়নি।');
    }

    /** সত্যিকারের পথে দুই ধাপ বসানো — চাবি, তারপর আসল কোড। */
    private function setUpTwoStepFor(User $user): void
    {
        $mfa = app(MfaService::class);
        $mfa->begin($user);

        $fresh = $user->fresh();
        $this->assertNotNull($mfa->confirm($fresh, Totp::codeFor($fresh->mfa_secret)),
            'দুই ধাপ বসানোই গেল না।');
    }
}
