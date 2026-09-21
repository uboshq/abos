<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Models\User;
use App\Notifications\EmailChangeLink;
use App\Notifications\EmailChangeWarning;
use App\Notifications\PasswordResetLink;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

/**
 * ⛔ চিঠিটা সত্যিই লেখা যায় কি না — ফাঁকা জায়গা রেখে নয়।
 *
 * ── কী ভাঙা ছিল, ১৫ সেপ্টেম্বর ২০২৬ ──────────────────────────────────
 * `EmailChangeLink` যায় **বেনামে**: নতুন ঠিকানাটা তখনো কারো অ্যাকাউন্ট
 * নয়, তাই `Notification::route('mail', $email)` দিয়ে পাঠানো হয়। ⓘ ফলে
 * `$notifiable` একটা `AnonymousNotifiable`, আর তার `name` বা `locale`
 * **কোনোটাই নেই**।
 *
 * ⛔ চিঠিটা তবু চলে যেত, কেবল সম্বোধনটা হত *"নমস্কার ,"* — নামের জায়গা
 * ফাঁকা। PHP ব্যতিক্রম তোলে না, কেবল একটা warning।
 *
 * ── ⚠️ আর কেন একুশটা সবুজ টেস্টের একটাও এটা ধরেনি ───────────────────
 * সবগুলো `Notification::fake()` ব্যবহার করে, আর সে **`toMail()` কখনো
 * চালায় না** — সে কেবল গোনে কোন চিঠি কাকে পাঠানো হয়েছে। ⓘ অর্থাৎ
 * চিঠির ভেতরে কী লেখা হলো, সেটা কেউ দেখেইনি।
 *
 * ⭐ ধরা পড়েছে একটা **সত্যিকারের চিঠি পাঠিয়ে**, SMTP বসানোর পর। আর
 * সেটাই এই ফাইলটার কারণ: এখানে `toMail()` সত্যি সত্যি চালানো হয়, আর
 * ফলটা পড়ে দেখা হয়।
 *
 * ⚠️ এটা পাঠানো পরীক্ষা করে না — কোনো চিঠি বাইরে যায় না। এটা কেবল
 * দেখে **লেখাটা সম্পূর্ণ হলো কি না**।
 */
final class TheLetterKnowsWhoItIsWritingToTest extends TestCase
{
    /**
     * চিঠিটার ভেতরের সব লেখা এক জায়গায়।
     */
    private function wordsOf(MailMessage $mail): string
    {
        return implode("\n", array_merge(
            [(string) $mail->subject],
            array_map(fn ($line): string => (string) $line, $mail->greeting !== null ? [$mail->greeting] : []),
            array_map(fn ($line): string => (string) $line, $mail->introLines),
            array_map(fn ($line): string => (string) $line, $mail->outroLines),
        ));
    }

    /**
     * ⛔ বেনামে পাঠানো চিঠিতেও নামটা থাকে।
     */
    public function test_the_email_change_letter_carries_a_name(): void
    {
        $mail = (new EmailChangeLink('token-ta-ekhane', 'Md. Al-Amin', 'bn'))
            ->toMail(new AnonymousNotifiable);

        $words = $this->wordsOf($mail);

        $this->assertStringContainsString('Md. Al-Amin', $words,
            'চিঠিতে প্রাপকের নাম নেই — সম্বোধনটা ফাঁকা যাচ্ছে।');

        /*
         * ⚠️ `:name` রয়ে গেলে মানে অনুবাদের চাবিটা বদল পায়নি — ⓘ তখন
         * পর্দায় কাঁচা প্লেসহোল্ডার ছাপা হত, আর সেটা নাম না থাকার
         * চেয়েও বাজে দেখায়।
         */
        $this->assertStringNotContainsString(':name', $words);

        /* ⓘ চাবিগুলো সত্যিই অনুবাদ পেয়েছে কি না — কাঁচা চাবি ছাপা হয়নি। */
        $this->assertStringNotContainsString('core.profile.', $words,
            'অনুবাদ না পেয়ে কাঁচা চাবি ছাপা হচ্ছে।');
    }

    /**
     * ⛔ আর লিংকটাও ভেতরে থাকে — নাহলে চিঠিটার কোনো মানে নেই।
     */
    public function test_the_email_change_letter_carries_its_link(): void
    {
        $mail = (new EmailChangeLink('chabi-tokenta', 'Keu', 'bn'))
            ->toMail(new AnonymousNotifiable);

        $this->assertStringContainsString('chabi-tokenta', (string) $mail->actionUrl,
            'চিঠিতে টোকেনসহ লিংকটাই নেই।');
    }

    /**
     * ⭐ দুই ভাষাতেই লেখা যায়, আর দুইটা আলাদা।
     *
     * ⚠️ এই দাবিটা না থাকলে ভাষার ব্যবস্থাটা ভেঙে গেলেও উপরের দাবিগুলো
     * সবুজ থাকত — ⓘ দুইটাই তখন এক ভাষায় লেখা হত, আর কেউ জানত না।
     */
    public function test_both_languages_produce_different_words(): void
    {
        $bn = $this->wordsOf((new EmailChangeLink('t', 'Keu', 'bn'))->toMail(new AnonymousNotifiable));
        $en = $this->wordsOf((new EmailChangeLink('t', 'Keu', 'en'))->toMail(new AnonymousNotifiable));

        $this->assertNotSame($bn, $en, 'দুই ভাষায় একই লেখা — অনুবাদ কাজ করছে না।');
    }

    /**
     * ⛔ পুরনো ঠিকানার সতর্কবার্তাটাও সম্পূর্ণ।
     *
     * ⓘ এটা যায় একজন সত্যিকারের ব্যবহারকারীকে, তাই `$notifiable` থেকেই
     * নাম ও ভাষা পায়। ⚠️ তবু মাপা হচ্ছে: কালকে কেউ এটাকেও বেনামে
     * পাঠালে একই ফাঁদে পড়ত।
     */
    public function test_the_warning_letter_is_complete(): void
    {
        $user = new User(['name' => 'Md. Al-Amin']);
        $user->locale = 'bn';

        $words = $this->wordsOf((new EmailChangeWarning('notun@abos.test'))->toMail($user));

        $this->assertStringContainsString('Md. Al-Amin', $words);
        $this->assertStringContainsString('notun@abos.test', $words,
            'কোন ঠিকানায় সরানোর কথা, সেটাই লেখা নেই।');
        $this->assertStringNotContainsString(':email', $words);
        $this->assertStringNotContainsString('core.profile.', $words);
    }

    /**
     * ⭐ আর পাসওয়ার্ড রিসেটের চিঠিটাও — এটা এই রিপোর সবচেয়ে পুরনো চিঠি।
     *
     * ⓘ ওটা সবসময় একজন সত্যিকারের ব্যবহারকারীকেই যায়, তাই ভাঙার কথা
     * নয়। ⚠️ তবু এখানে আছে, কারণ এই ফাইলের প্রশ্নটা একটা শ্রেণির:
     * **চিঠিটা সম্পূর্ণ লেখা হলো তো?**
     */
    public function test_the_password_reset_letter_is_complete(): void
    {
        $user = new User(['name' => 'Md. Al-Amin', 'email' => 'keu@abos.test']);
        $user->locale = 'bn';

        $words = $this->wordsOf((new PasswordResetLink('token-ta'))->toMail($user));

        $this->assertStringNotContainsString('auth.', $words, 'কাঁচা চাবি ছাপা হচ্ছে।');
        $this->assertNotSame('', trim($words));
    }
}
