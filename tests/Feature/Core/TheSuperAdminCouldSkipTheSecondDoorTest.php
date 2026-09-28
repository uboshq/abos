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
 * যাঁর হাতে সব চাবি, তিনিই দ্বিতীয় দরজাটা এড়িয়ে যেতে পারতেন।
 *
 * ── ⭐ নিরীক্ষা §৩, আর মালিকের সিদ্ধান্ত ২৮ সেপ্টেম্বর ২০২৬ ───────────
 * সুপার অ্যাডমিনের হাতে গোটা ব্যবস্থা: প্রতিটা কোম্পানি, প্রতিটা টাকার
 * ঘর, প্রতিটা ব্যবহারকারী। ⚠️ ঐ একটা পাসওয়ার্ড ফাঁস হলে আর কোনো দরজা
 * নেই।
 *
 * ⓘ দুই ধাপের যন্ত্রটা আগেই ছিল ([[MfaService]], [[Totp]], একবার-ব্যবহারের
 * উদ্ধার-কোড) — কেবল চালু করা ছিল **ঐচ্ছিক**। ⛔ অর্থাৎ যিনি সবচেয়ে বেশি
 * ঝুঁকিতে, তাঁর জন্যই তালাটা ঐচ্ছিক ছিল।
 *
 * ── ⛔ এখানে আসল বিপদ রোগ নয়, ওষুধ ────────────────────────────────────
 * ⚠️ একটা বাধ্যতামূলক তালা ভুল হলে ক্ষতিটা তাৎক্ষণিক আর সম্পূর্ণ: মালিক
 * নিজের ব্যবসায় ঢুকতে পারেন না। ⓘ তাই এই ফাইলের অর্ধেক দাবিই
 * **লক-আউট না হওয়ার** — তালাটা কাজ করে কি না, সেটা বরং সহজ অংশ।
 *
 * ⭐ তিনটা উদ্ধারের পথ, এই ক্রমে:
 *   ১. নিজের উদ্ধার-কোড (বসানোর দিন একবারই দেখানো হয়)
 *   ২. অন্য সুপার অ্যাডমিনের রিসেট, কারণ লেখা বাধ্যতামূলক
 *   ৩. একজনই সুপার অ্যাডমিন হলে সার্ভারে `abos:two-step-reset`
 * ⓘ শেষ দুইটা নিরীক্ষার খাতায় দাগ রাখে — বিনা দাগে খোলা তালাই সবচেয়ে
 * খারাপ ফল।
 */
final class TheSuperAdminCouldSkipTheSecondDoorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        /* ⓘ phpunit.xml বাকি পরীক্ষার জন্য দরজা বন্ধ রাখে; এই ফাইল নিয়মটাই মাপে, তাই চালু */
        config(['abos.super_admin_two_step' => true]);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->admin = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $this->assertTrue($this->admin->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE),
            'বসানো ব্যবহারকারীটা সুপার অ্যাডমিন নন — তাহলে এই ফাইলের কোনো দাবিই কিছু মাপছে না।');
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ⭐ তালাটা: একই ব্যবহারকারী, বন্ধ তারপর চালু ───────────────────

    public function test_without_two_steps_every_screen_sends_the_super_admin_to_set_it_up(): void
    {
        /*
         * ⚠️ একই ব্যবহারকারী দুইবার, কেবল দুই ধাপের অবস্থা আলাদা।
         * ⛔ দুইজন আলাদা মানুষ নিলে পার্থক্যটা ভূমিকা বা অনুমতির কারণেও
         * হতে পারত, আর দাবিটা কিছুই প্রমাণ করত না।
         */
        /*
         * ⓘ বন্ধ অবস্থাটা এখানে **নিজে বানাতে হয়**, কারণ [[DemoSeeder]]
         * মালিকের দুই ধাপ আগে থেকেই বসিয়ে রাখে। ⚠️ ঐ ফিকশ্চারটা না
         * থাকলে এই গাছে কাজ করা প্রতিটা সেশনের HTTP পরীক্ষা /two-step-এ
         * পাঠানো হত — একবার তাই হয়েছিলও।
         */
        app(MfaService::class)->turnOff($this->admin);

        $this->actingAs($this->admin->fresh())
            ->get(route('inventory.stock.index'))
            ->assertRedirect(route('mfa'));

        $this->turnOnTwoSteps();

        $this->actingAs($this->admin->fresh())
            ->get(route('inventory.stock.index'))
            ->assertOk();
    }

    /**
     * ⓘ চালুর দিন পেছানো যায় কেবল সার্ভারের সুইচে — মালিক, ২৮ সেপ্টেম্বর ২০২৬: *"2step rate kori"*।
     * ⚠️ একই মানুষ: সুইচ বন্ধ → পর্দা খোলে; সুইচ চালু (ডিফল্ট) → আবার বসানোর পাতায়। ⛔ সুইচ
     * কোনোদিন ডিফল্টে বন্ধ হলে এই দাবির দ্বিতীয় অর্ধেক লাল হয়।
     */
    public function test_the_rollout_switch_only_postpones_and_is_on_by_default(): void
    {
        app(MfaService::class)->turnOff($this->admin);

        config(['abos.super_admin_two_step' => false]);
        $this->actingAs($this->admin->fresh())->get(route('inventory.stock.index'))->assertOk();

        /* ⓘ ফাইলের নিজের ডিফল্ট — .env-এ কিছু না থাকলে চালু (পরীক্ষার phpunit.xml এটা বন্ধ রাখে,
           তাই লেখাটাই মাপা হয়) */
        $this->assertStringContainsString("env('ABOS_SUPER_ADMIN_TWO_STEP', true)",
            (string) file_get_contents(base_path('config/abos.php')), '⛔ দুই ধাপের সুইচ ডিফল্টে বন্ধ।');

        config(['abos.super_admin_two_step' => true]);
        $this->actingAs($this->admin->fresh())->get(route('inventory.stock.index'))->assertRedirect(route('mfa'));
    }

    public function test_the_setup_page_itself_stays_open_or_nobody_could_ever_start(): void
    {
        /*
         * ⛔ এটা না থাকলে মিডলওয়্যারটা নিজের সাথে নিজে লড়ত: বসানোর
         * পর্দাতেও পাঠাত, আর সেই পর্দাও আটকে দিত — অসীম রিডাইরেক্ট, আর
         * কেউ কোনোদিন চালু করতে পারতেন না।
         */
        $this->actingAs($this->admin)->get(route('mfa'))->assertOk();
    }

    public function test_a_locked_out_admin_can_still_sign_out(): void
    {
        /*
         * ⓘ আটকে থাকা মানুষ অন্তত বেরোতে পারেন — নাহলে ব্রাউজার বন্ধ
         * করা ছাড়া উপায় থাকত না।
         */
        $this->actingAs($this->admin)->post(route('logout'))->assertRedirect();
    }

    public function test_an_ordinary_user_is_not_forced_into_it(): void
    {
        /*
         * ⚠️ সবার জন্য বাধ্যতামূলক করলে যে গুদাম-কর্মীর ফোনেই অ্যাপ নেই
         * তিনি কাজ করতে পারতেন না, আর ব্যবস্থাটা একদিনেই বন্ধ হত।
         * ⭐ তাঁদের জন্য এটা ঐচ্ছিকই থাকে।
         */
        $clerk = User::query()
            ->where('email', '!=', $this->admin->email)
            ->get()
            ->first(fn (User $u) => ! $u->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE));

        $this->assertNotNull($clerk, 'ডেমোতে সুপার অ্যাডমিন ছাড়া আর কেউ নেই।');

        /*
         * ⚠️ দাবিটা *"পাতাটা খোলে"* নয়, *"বসানোর পর্দায় পাঠানো হয় না"*।
         *
         * ⛔ প্রথমে `assertSuccessful()` লিখেছিলাম আর ৪০৩ পেয়েছিলাম —
         * ঐ কর্মীর ঐ পর্দার অনুমতিই নেই। ⓘ কিন্তু ৪০৩ মানে মিডলওয়্যার
         * তাঁকে ছেড়ে দিয়েছে, অর্থাৎ যা মাপার কথা সেটা **ঠিকই আছে**;
         * দাবিটাই বেশি কথা বলছিল।
         *
         * ⭐ অনুমতি আর এই তালা দুইটা আলাদা প্রশ্ন, আর একটা দাবিতে
         * দুইটা মিশিয়ে ফেললে অনুমতি বদলানোর দিন এটা মিথ্যা লাল দিত।
         */
        $response = $this->actingAs($clerk)->get(route('inventory.stock.index'));

        $this->assertNotSame(route('mfa'), $response->headers->get('Location'),
            'সাধারণ ব্যবহারকারীকেও দুই ধাপ বসাতে পাঠানো হচ্ছে — '
            .'যাঁর ফোনে অ্যাপ নেই তিনি কাজই করতে পারবেন না।');
    }

    // ── ⭐ ভুল কোড, আর খরচ হয়ে যাওয়া উদ্ধার-কোড ──────────────────────

    public function test_a_wrong_code_does_not_turn_it_on(): void
    {
        $mfa = app(MfaService::class);
        $mfa->begin($this->admin);

        $this->assertNull($mfa->confirm($this->admin->fresh(), '000000'),
            'ভুল কোডেও দুই ধাপ চালু হয়ে গেছে।');

        $this->assertFalse($mfa->isOn($this->admin->fresh()));
    }

    public function test_a_backup_code_works_once_and_never_again(): void
    {
        /*
         * ⭐ এটাই ফোন হারানোর প্রথম দরজা। ⚠️ আর *"একবার"* কথাটা এখানে
         * আসল: কোডটা খরচ না হলে যে কাগজটা হারিয়ে গেছে সেটা চিরকালের
         * একটা চাবি হয়ে থাকত।
         */
        $codes = $this->turnOnTwoSteps();
        $mfa = app(MfaService::class);

        $this->assertTrue($mfa->verify($this->admin->fresh(), $codes[0]),
            'উদ্ধার-কোডটা প্রথমবারেই চলল না।');

        $this->assertFalse($mfa->verify($this->admin->fresh(), $codes[0]),
            'একই উদ্ধার-কোড দ্বিতীয়বারও চলছে — কাগজটা হারালে ওটা চিরকালের চাবি।');

        $this->assertSame(count($codes) - 1, $mfa->recoveryCodesLeft($this->admin->fresh()));
    }

    // ── ⭐ উদ্ধার, আর তার দাগ ────────────────────────────────────────

    public function test_another_super_admin_can_reset_it_and_the_book_remembers_why(): void
    {
        $this->turnOnTwoSteps();

        $second = $this->aSecondSuperAdmin();

        $this->actingAs($second)
            ->delete(route('system_admin.user.two_step.reset', $this->admin), [
                'reason' => 'ফোন হারিয়ে গেছে, উদ্ধার-কোডও নেই',
            ])
            ->assertRedirect();

        $this->assertFalse(app(MfaService::class)->isOn($this->admin->fresh()),
            'রিসেটের পরেও দুই ধাপ চালু রয়ে গেছে।');

        $row = AuditTrail::query()
            ->where('action', 'two_step_reset')
            ->latest('id')
            ->first();

        $this->assertNotNull($row, implode(PHP_EOL, [
            'তালা খুলল, অথচ নিরীক্ষার খাতায় কোনো দাগ নেই।',
            '',
            '⛔ বিনা দাগে খোলা একটা তালা তালা না থাকার চেয়েও খারাপ:',
            'সে নিরাপত্তার চেহারা দেয়, আর কে কখন খুলেছিল তার উত্তর দেয় না।',
        ]));

        $this->assertStringContainsString('ফোন', (string) $row->reason);
    }

    public function test_a_reset_without_a_reason_is_refused(): void
    {
        /*
         * ⛔ কারণ ছাড়া সারিটা থাকত, কিন্তু ছয় মাস পরে *"কেন খোলা
         * হয়েছিল"* প্রশ্নের উত্তর থাকত না — আর ঐ প্রশ্নটার জন্যই খাতাটা।
         */
        $this->turnOnTwoSteps();

        $this->actingAs($this->aSecondSuperAdmin())
            ->delete(route('system_admin.user.two_step.reset', $this->admin), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertTrue(app(MfaService::class)->isOn($this->admin->fresh()),
            'কারণ ছাড়াই তালাটা খুলে গেছে।');
    }

    public function test_a_super_admin_cannot_unlock_their_own_door_from_here(): void
    {
        /*
         * ⚠️ পারলে বাধ্যতামূলক শব্দটার কোনো মানে থাকত না: এক ক্লিকে
         * নিজের তালা খুলে নেওয়া যেত। ⓘ নিজেরটা বন্ধ করার পথ আছে, কিন্তু
         * সেটা নিজের পর্দায়, আর পাসওয়ার্ড দিয়ে।
         */
        $this->turnOnTwoSteps();

        $this->actingAs($this->admin->fresh())
            ->delete(route('system_admin.user.two_step.reset', $this->admin), ['reason' => 'নিজেই খুলে নিচ্ছি'])
            ->assertSessionHasErrors('reason');

        $this->assertTrue(app(MfaService::class)->isOn($this->admin->fresh()));
    }

    public function test_the_last_resort_is_the_console_and_it_also_leaves_a_trail(): void
    {
        /*
         * ⭐ একজনই সুপার অ্যাডমিন হলে উপরের পর্দাটা কাজে আসে না — ওটা
         * চালাতে **অন্য একজন** লাগে। ⓘ তখন সার্ভারের শেলই শেষ পথ।
         *
         * ⚠️ এটা নতুন কোনো ক্ষমতা নয়: যাঁর শেল আছে তিনি এমনিতেই ডাটাবেস
         * খুলতে পারেন। ⭐ কমান্ডটা কেবল ঐ পথটাকে দাগ রেখে যাওয়া পথ বানায়।
         */
        $this->turnOnTwoSteps();

        $before = AuditTrail::query()->where('action', 'two_step_reset')->count();

        $this->artisan('abos:two-step-reset', [
            'email' => $this->admin->email,
            '--reason' => 'একমাত্র সুপার অ্যাডমিন, ফোন হারানো',
        ])->assertExitCode(0);

        $this->assertFalse(app(MfaService::class)->isOn($this->admin->fresh()));

        $this->assertSame($before + 1,
            AuditTrail::query()->where('action', 'two_step_reset')->count(),
            'কমান্ড তালা খুলেছে, অথচ খাতায় কিছু বসেনি।');
    }

    public function test_the_console_refuses_without_a_reason(): void
    {
        $this->turnOnTwoSteps();

        $this->artisan('abos:two-step-reset', ['email' => $this->admin->email])
            ->assertExitCode(1);

        $this->assertTrue(app(MfaService::class)->isOn($this->admin->fresh()),
            'কারণ ছাড়াই কমান্ড তালাটা খুলে দিয়েছে।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * সত্যিকারের পথে দুই ধাপ চালু — চাবি বসানো, তারপর আসল কোড।
     *
     * ⛔ `mfa_confirmed_at` হাতে বসানো হয় না: তাতে উদ্ধার-কোড তৈরিই হত
     * না, আর ওগুলোর দাবিগুলো একটা এমন অবস্থার উপর চলত যা বাস্তবে ঘটে না।
     *
     * @return list<string> উদ্ধার-কোডগুলো, একবারই দেখানো
     */
    private function turnOnTwoSteps(): array
    {
        $mfa = app(MfaService::class);
        $mfa->begin($this->admin);

        $user = $this->admin->fresh();
        $codes = $mfa->confirm($user, Totp::codeFor($user->mfa_secret));

        $this->assertNotNull($codes, 'দুই ধাপ চালুই হলো না — বাকি দাবিগুলো অর্থহীন।');

        $this->admin = $this->admin->fresh();

        return $codes;
    }

    /** ⓘ দ্বিতীয় একজন সুপার অ্যাডমিন — উদ্ধারের দ্বিতীয় পথটার জন্য। */
    private function aSecondSuperAdmin(): User
    {
        $second = User::query()->where('email', '!=', $this->admin->email)->firstOrFail();

        CompanyContext::forCompany(
            (int) CompanyContext::id(),
            fn () => $second->assignRole(PermissionSyncer::SUPER_ADMIN_ROLE),
        );

        $second = $second->fresh();

        /*
         * ⚠️ দ্বিতীয়জনেরও দুই ধাপ চালু করতে হয় — নাহলে মিডলওয়্যার
         * **তাঁকেই** বসানোর পর্দায় পাঠাত, আর রিসেটের অনুরোধটা কোনোদিন
         * কন্ট্রোলারে পৌঁছাত না। ⓘ প্রথম লেখায় এটা বাদ পড়েছিল।
         */
        $mfa = app(MfaService::class);
        $mfa->begin($second);
        $second = $second->fresh();
        $mfa->confirm($second, Totp::codeFor($second->mfa_secret));

        return $second->fresh();
    }
}
