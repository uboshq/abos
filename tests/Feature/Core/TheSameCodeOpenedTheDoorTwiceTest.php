<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Security\MfaService;
use App\Core\Security\Totp;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * একই ছয় অঙ্ক দরজা দুইবার খুলত — নিরীক্ষা, ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ভাঙা ছিল ────────────────────────────────────────────────────
 * [[Totp::verify()]] কেবল জিজ্ঞেস করত "এই কোডটা এই সময়ে ঠিক কি না" —
 * "এটা আগে একবার চলেছে কি না" কখনো নয়। ⚠️ ঘড়ির পার্থক্যের জন্য আগে-পরে
 * এক ধাপ মানা হয়, তাই একটা কোড প্রায় দেড় মিনিট বেঁচে থাকে — আর ঐ পুরো
 * সময়টায় কাঁধের উপর দিয়ে দেখা কোডটা দিয়ে দ্বিতীয় কেউ ঢুকতে পারতেন।
 *
 * ⓘ RFC 6238 §5.2 ঠিক এটাই নিষেধ করে: একটা কোড একবার মিললে দ্বিতীয়বার
 * নয়।
 *
 * ── ⭐ প্রতিটা দাবি একই মানুষ, একই চাবি ───────────────────────────────
 * প্রথমবার খোলে, দ্বিতীয়বার খোলে না — মাঝে কেবল "আগে চলেছে" বদলায়।
 */
final class TheSameCodeOpenedTheDoorTwiceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    private function mfa(): MfaService
    {
        return app(MfaService::class);
    }

    /**
     * চাবি বসিয়ে চালু — চালুর কোডটা **আগের** ধাপের, যাতে এই ধাপের কোডটা
     * পরীক্ষার জন্য অব্যবহৃত থাকে।
     */
    private function turnOn(User $user): string
    {
        $secret = $this->mfa()->begin($user);

        $this->assertNotNull($this->mfa()->confirm($user->fresh(), Totp::codeFor($secret, time() - 30)));

        return $secret;
    }

    /* ── সেবার স্তরে ──────────────────────────────────────────────── */

    public function test_a_code_works_once_and_the_same_code_again_is_refused(): void
    {
        $secret = $this->turnOn($this->owner);
        $code = Totp::codeFor($secret);

        $this->assertTrue($this->mfa()->verify($this->owner->fresh(), $code), 'প্রথমবার কোডটা চলার কথা।');
        $this->assertFalse($this->mfa()->verify($this->owner->fresh(), $code), 'একই কোড দ্বিতীয়বার চলেছে।');
    }

    /**
     * পরের ধাপের কোড চলে — প্রত্যাখ্যানটা কোডের, মানুষের নয়।
     *
     * ⓘ এটা না থাকলে "সব কোডই প্রত্যাখ্যান" ধরনের একটা ভুলও উপরের দাবিটা
     * পাশ করাত।
     */
    public function test_the_next_steps_code_still_works_after_one_was_spent(): void
    {
        $secret = $this->turnOn($this->owner);

        $this->assertTrue($this->mfa()->verify($this->owner->fresh(), Totp::codeFor($secret)));
        $this->assertTrue($this->mfa()->verify($this->owner->fresh(), Totp::codeFor($secret, time() + 30)));
    }

    /**
     * নতুন একটা চলার পর পুরনো, অব্যবহৃত কোডও নয়।
     *
     * ⚠️ ঘড়ির ছাড়ের জন্য আগের ধাপের কোডটাও তখনো "ঠিক" — কিন্তু মানুষটা
     * ততক্ষণে নতুনটা দিয়ে ঢুকে গেছেন, তাই পুরনোটা এখন কেবল আরেকজনের হাতে
     * থাকতে পারে।
     */
    public function test_an_older_unused_code_is_refused_once_a_newer_one_was_accepted(): void
    {
        $secret = $this->mfa()->begin($this->owner);
        $this->assertNotNull($this->mfa()->confirm($this->owner->fresh(), Totp::codeFor($secret, time() - 30)));

        $this->assertTrue($this->mfa()->verify($this->owner->fresh(), Totp::codeFor($secret, time() + 30)));
        $this->assertFalse($this->mfa()->verify($this->owner->fresh(), Totp::codeFor($secret)));
    }

    /**
     * চালুর কোডটা দিয়ে লগইন নয়।
     *
     * ⓘ চালুর মুহূর্তে কোডটা পর্দায় টাইপ হয় — প্রায়ই অন্যের সামনে।
     */
    public function test_the_code_that_switched_two_step_on_is_spent(): void
    {
        $secret = $this->mfa()->begin($this->owner);
        $code = Totp::codeFor($secret);

        $this->assertNotNull($this->mfa()->confirm($this->owner->fresh(), $code));
        $this->assertFalse($this->mfa()->verify($this->owner->fresh(), $code));
    }

    /**
     * ⚠️ একজনের খরচ করা ধাপ আরেকজনকে আটকায় না।
     *
     * ⛔ চিহ্নটা ব্যবহারকারী ছাড়া কেবল ধাপ ধরে রাখলে একই আধা মিনিটে
     * দুইজন ঢুকতে পারতেন না।
     */
    public function test_one_users_spent_step_does_not_block_another_user(): void
    {
        $sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $mine = $this->turnOn($this->owner);
        $theirs = $this->turnOn($sales);

        $this->assertTrue($this->mfa()->verify($this->owner->fresh(), Totp::codeFor($mine)));
        $this->assertTrue($this->mfa()->verify($sales->fresh(), Totp::codeFor($theirs)));
    }

    /* ── দরজার স্তরে ──────────────────────────────────────────────── */

    /**
     * ⭐ আসল দরজায়: একই কোডে প্রথমবার ভেতরে, দ্বিতীয়বার কোডের ঘরে আটকে।
     */
    public function test_the_login_door_refuses_a_replayed_code(): void
    {
        $secret = $this->turnOn($this->owner);
        $code = Totp::codeFor($secret);

        $this->post(route('login.store'), [
            'identifier' => $this->owner->email, 'password' => 'password', 'code' => $code,
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->owner);

        $this->post(route('logout'));
        $this->assertGuest();

        $this->post(route('login.store'), [
            'identifier' => $this->owner->email, 'password' => 'password', 'code' => $code,
        ])->assertSessionHasErrors('code');

        $this->assertGuest();
    }
}
