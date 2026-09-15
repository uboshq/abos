<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Notifications\EmailChangeLink;
use App\Notifications\EmailChangeWarning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * ইমেইল বদলানো — দুই ধাপ, আর মাঝপথে পুরনোটা কাজ করতেই থাকে।
 *
 * ── ⭐ মালিকের বাছাই, ১৪ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * তিনটা পথ দেখানো হয়েছিল, তিনি বেছেছেন যাচাই করে বদলানো।
 *
 * ── ⚠️ কেন সাথে সাথে বদলানো যায় না ──────────────────────────────────
 * ইমেইলটা লগইনের পরিচয় **আর** পাসওয়ার্ড ফিরে পাওয়ার একমাত্র পথ।
 * ⛔ একটা অক্ষর ভুল লিখলেই মানুষ নিজের অ্যাকাউন্ট থেকে চিরতরে বেরিয়ে
 * যেতেন — ঢুকতে পারতেন না, আর রিসেট লিংকও ভুল ঠিকানায় যেত।
 */
final class ChangingYourEmailTakesTwoStepsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $company = Company::create(['code' => 'EM', 'name_en' => 'Email Co']);

        $this->user = User::factory()->create([
            'name' => 'Md. Al-Amin',
            'email' => 'purono@abos.test',
            'login_id' => 'alamin',
            'password' => Hash::make('chabi-1234'),
        ]);

        $this->user->companies()->attach($company, ['is_active' => true]);
        $this->user->forceFill(['current_company_id' => $company->id])->save();
    }

    private function request(string $email = 'notun@abos.test', string $password = 'chabi-1234'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->post(route('profile.email.request'), [
            'email' => $email,
            'current_password' => $password,
        ]);
    }

    /**
     * ⛔ অনুরোধে ইমেইলটা বদলায় **না** — কেবল অপেক্ষায় বসে।
     */
    public function test_the_request_alone_changes_nothing(): void
    {
        $this->request()->assertRedirect();

        $this->user->refresh();

        $this->assertSame('purono@abos.test', $this->user->email,
            'অনুরোধেই ঠিকানা বদলে গেছে — অথচ নতুনটা এখনো নিজেকে প্রমাণ করেনি।');

        $this->assertSame('notun@abos.test', $this->user->pending_email);

        /*
         * ⚠️ টোকেনটা সাদা অবস্থায় রাখা হয়নি — ⓘ ডাটাবেজ পড়তে পারে এমন
         * যে কেউ (ব্যাকআপ ফাইল, একজন কৌতূহলী প্রশাসক) নাহলে লিংকটা নিজে
         * বানিয়ে অন্যের ইমেইল নিজের নামে বসিয়ে নিতে পারতেন।
         */
        $this->assertSame(64, strlen((string) $this->user->pending_email_token));
        $this->assertNotSame('', (string) $this->user->pending_email_token);
    }

    /**
     * ⭐ দুইটা চিঠি যায়, আর দুইটা আলাদা ঠিকানায়।
     *
     * ⓘ অনুমতি চাওয়া নতুন ঠিকানায়, খবর দেওয়া পুরনোটায়। ⚠️ পুরনোটায়
     * খবর না গেলে কেউ খোলা সেশন পেয়ে ইমেইলটা নিজের করে নিতে পারতেন আর
     * আসল মানুষটা কিছুই জানতেন না।
     */
    public function test_both_addresses_hear_about_it(): void
    {
        $this->request();

        Notification::assertSentOnDemand(EmailChangeLink::class);
        Notification::assertSentTo($this->user, EmailChangeWarning::class);
    }

    /**
     * ⛔ পাসওয়ার্ড ভুল হলে কিছুই হয় না।
     */
    public function test_a_wrong_password_stops_it(): void
    {
        $this->request(password: 'eta-vul')->assertSessionHasErrors('current_password');

        $this->assertNull($this->user->refresh()->pending_email);
        Notification::assertNothingSent();
    }

    /**
     * ⛔ অন্যের ঠিকানা নেওয়া যায় না।
     */
    public function test_an_address_that_belongs_to_someone_else_is_refused(): void
    {
        User::factory()->create(['email' => 'onno@abos.test', 'login_id' => 'onno']);

        $this->request('onno@abos.test')->assertSessionHasErrors('email');

        $this->assertNull($this->user->refresh()->pending_email);
    }

    /**
     * ⭐ আর লিংকে চাপ দিলে ঠিকানাটা সত্যিই বদলায়।
     *
     * ⓘ টোকেনটা চিঠি থেকে তুলে নেওয়া হয়, ডাটাবেজ থেকে নয় — ⚠️ ডাটাবেজে
     * আছে কেবল তার হ্যাশ, আর সেটা দিয়ে লিংক বানানো যায় না। ⭐ এভাবেই
     * পরীক্ষাটা ব্যবহারকারীর পথেই হাঁটে।
     */
    public function test_the_link_completes_the_change(): void
    {
        $this->request();

        $token = null;

        Notification::assertSentOnDemand(
            EmailChangeLink::class,
            function (EmailChangeLink $notification, array $channels, object $notifiable) use (&$token): bool {
                $token = (new \ReflectionProperty($notification, 'token'))->getValue($notification);

                return $notifiable->routes['mail'] === 'notun@abos.test';
            },
        );

        $this->assertNotNull($token, 'চিঠি থেকে টোকেনটাই পাওয়া গেল না।');

        $this->get(route('profile.email.confirm', ['token' => $token]))->assertRedirect();

        $this->user->refresh();

        $this->assertSame('notun@abos.test', $this->user->email);
        $this->assertNull($this->user->pending_email);
        $this->assertNull($this->user->pending_email_token);

        /* ⓘ ঠিকানাটা এইমাত্র নিজেকে প্রমাণ করল, তাই যাচাইয়ের সময়টাও বসে। */
        $this->assertNotNull($this->user->email_verified_at);
    }

    /**
     * ⛔ মেয়াদ শেষ হওয়া লিংক কিছুই করে না।
     */
    public function test_an_expired_link_does_nothing(): void
    {
        $this->request();

        /* ⓘ ঘড়ি এগিয়ে দেওয়ার বদলে অনুরোধের সময়টা পিছিয়ে দেওয়া —
           একই ফল, আর বাকি পরীক্ষাগুলোর ঘড়ি ছোঁয়া হয় না। */
        $minutes = (int) config('abos.email_change_expire', 60);
        $this->user->forceFill(['pending_email_at' => now()->subMinutes($minutes + 1)])->save();

        $token = null;

        Notification::assertSentOnDemand(
            EmailChangeLink::class,
            function (EmailChangeLink $notification) use (&$token): bool {
                $token = (new \ReflectionProperty($notification, 'token'))->getValue($notification);

                return true;
            },
        );

        $this->get(route('profile.email.confirm', ['token' => $token]));

        $this->assertSame('purono@abos.test', $this->user->refresh()->email);
    }

    /**
     * ⛔ ভুল টোকেনেও কিছু হয় না, আর কারণটা বলা হয় না।
     */
    public function test_a_made_up_token_does_nothing(): void
    {
        $this->request();

        $this->get(route('profile.email.confirm', ['token' => str_repeat('a', 64)]));

        $this->assertSame('purono@abos.test', $this->user->refresh()->email);
        $this->assertSame('notun@abos.test', $this->user->pending_email);
    }
}
