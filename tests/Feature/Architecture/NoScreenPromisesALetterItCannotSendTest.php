<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Support\MailReach;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * ⛔ যে চিঠি যাবে না, সেটা "পাঠানো হয়েছে" বলা যাবে না।
 *
 * ── কী ভাঙা ছিল, ১৫ সেপ্টেম্বর ২০২৬ ──────────────────────────────────
 * লাইভে `MAIL_MAILER=log`। ⚠️ ঐ অবস্থায় Laravel চিঠিটা **সফলভাবে**
 * পাঠায় — `storage/logs/laravel.log`-এ। কোনো ব্যতিক্রম ওঠে না, কিছুই
 * ফেরে না, তাই কোড নির্দ্বিধায় সফলতা ঘোষণা করত।
 *
 * ⛔ দুইটা পর্দায় এর দাম দিতে হত:
 *
 *   প্রোফাইল → "লিংক পাঠানো হয়েছে", আর `pending_email` বসে যেত।
 *              পর্দায় লেখা উঠত "অপেক্ষায়" — একটা অপেক্ষা যার শেষ নেই।
 *   পাসওয়ার্ড → যিনি এখানে আসেন তিনি **ইতিমধ্যে বাইরে**। তাঁকে
 *   ভুলে গেছি   "পাঠিয়ে দিয়েছি" বলা মানে তাঁর একমাত্র ফেরার পথটা
 *              কেড়ে নেওয়া, আর তিনি প্রশাসককে বলতেও যান না।
 *
 * ── ⭐ কেন এটা পাহারা, এক লাইনের সারাই নয় ────────────────────────────
 * ⓘ আজকের দিনের সবচেয়ে বেশিবার ফিরে আসা আকৃতিটাই এটা: **সফল দেখানো
 * ব্যর্থতা**। ⚠️ আর এই বিশেষটার লক্ষণ কেবল ব্যবহারকারীর অপেক্ষা —
 * কোনো লগ নেই, কোনো লাল নেই, কোনো টেস্ট নেই। ⛔ তাই আগামীকাল কেউ
 * তৃতীয় একটা চিঠি পাঠানোর পথ লিখলে সে আবার একই ফাঁদে পড়ত।
 *
 * ⚠️ এই পাহারাটা **সব পথ** ধরতে পারে না, আর সেটা সৎভাবে বলে রাখা ভালো:
 * সে কেবল এই দুইটা পথ জানে। নতুন পথের জন্য নতুন দাবি লিখতে হবে।
 */
final class NoScreenPromisesALetterItCannotSendTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['code' => 'ML', 'name_en' => 'Mail Co']);

        $this->user = User::factory()->create([
            'email' => 'keu@abos.test',
            'login_id' => 'keu',
            'password' => Hash::make('chabi-1234'),
        ]);

        $this->user->companies()->attach($company, ['is_active' => true]);
        $this->user->forceFill(['current_company_id' => $company->id])->save();
    }

    /**
     * ⭐ প্রথমে যন্ত্রটাই ঠিক মাপে কি না।
     *
     * ⚠️ এটা ছাড়া নিচের দাবিগুলো অর্থহীন: `silent()` যদি ভুল করে সবসময়
     * `true` ফেরাত, তখন ওরা সবুজ থাকত অথচ কিছুই প্রমাণ করত না।
     */
    public function test_it_knows_which_mailers_go_nowhere(): void
    {
        foreach (['log', 'array', '', null] as $mailer) {
            config(['mail.default' => $mailer]);
            $this->assertTrue(MailReach::silent(), var_export($mailer, true).' নীরব, অথচ ধরা পড়ল না।');
        }

        foreach (['smtp', 'ses', 'postmark', 'sendmail'] as $mailer) {
            config(['mail.default' => $mailer]);
            $this->assertFalse(MailReach::silent(), $mailer.' চিঠি পাঠায়, অথচ নীরব বলা হলো।');
        }
    }

    /**
     * ⛔ ইমেইল বদলের অনুরোধ — চিঠি না গেলে ডাটাবেজে কিছুই লেখা হয় না।
     */
    public function test_the_email_change_refuses_instead_of_pretending(): void
    {
        Notification::fake();
        config(['mail.default' => 'log']);

        $this->actingAs($this->user)
            ->post(route('profile.email.request'), [
                'email' => 'notun@abos.test',
                'current_password' => 'chabi-1234',
            ])
            ->assertSessionHasErrors('email');

        /*
         * ⚠️ দুইটা আলাদা দাবি, আর দুইটাই দরকার। ⓘ কেবল ভুলের বার্তা
         * মাপলে এমন একটা সারাই সবুজ থাকত যেটা বার্তা দেখায় **কিন্তু
         * সারিটাও লিখে ফেলে** — তখন পর্দায় চিরকালের "অপেক্ষায়"
         * লেখাটা থেকেই যেত।
         */
        $this->assertNull($this->user->refresh()->pending_email,
            'চিঠি যাবে না, তবু অপেক্ষমাণ ঠিকানাটা বসে গেছে।');

        Notification::assertNothingSent();
    }

    /**
     * ⛔ "পাসওয়ার্ড ভুলে গেছি" — একই নিয়ম, আর এখানে দামটা বেশি।
     */
    public function test_the_password_reset_refuses_instead_of_pretending(): void
    {
        Notification::fake();
        config(['mail.default' => 'log']);

        $page = $this->post(route('password.email'), ['email' => 'keu@abos.test']);

        $page->assertSessionHasErrors('email');
        $page->assertSessionMissing('sent');

        Notification::assertNothingSent();
    }

    /**
     * ⭐ আর মেইলার বসানো থাকলে দুইটাই আগের মতোই চলে।
     *
     * ⚠️ এই দাবিটা না থাকলে উপরের দুইটা "চিঠি পাঠানো একেবারে বন্ধ করে
     * দিলেও" সবুজ থাকত — ⓘ যে পাহারা কেবল "না" প্রমাণ করে, সে "হ্যাঁ"-টা
     * ভেঙে ফেলেও চুপ থাকে।
     */
    public function test_a_working_mailer_changes_nothing(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp']);

        $this->actingAs($this->user)
            ->post(route('profile.email.request'), [
                'email' => 'notun@abos.test',
                'current_password' => 'chabi-1234',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('notun@abos.test', $this->user->refresh()->pending_email);
    }
}
