<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ⛔ "পাসওয়ার্ড ভুলে গেছি" — চিঠিটা — ১৩ সেপ্টেম্বর ২০২৬।
 *
 * ── কেন এই ফিচারটা এতদিন ছিল না ──────────────────────────────────────
 * ⓘ আজ পর্যন্ত পাসওয়ার্ড ভুলে গেলে **একমাত্র পথ ছিল মালিককে বলা** —
 * তিনি `UserController`-এ গিয়ে হাতে বসিয়ে দিতেন। লগইনের পাতায় "পাসওয়ার্ড
 * ভুলে গেছেন?" লেখাটা ছিল, কিন্তু পাশে একটা "শীঘ্রই আসছে" ব্যাজ।
 *
 * ⭐ ঐ ব্যাজটা সৎ ছিল, আর সেটাই এখানে শেখার জিনিস: `MAIL_MAILER=log`
 * থাকা অবস্থায় কেউ যদি একটা কাজ-করা লিংক বসিয়ে দিতেন, মানুষ চাপ দিতেন,
 * "ইমেইল পাঠানো হয়েছে" দেখতেন, আর **সারাদিন ইনবক্স খুলে বসে থাকতেন**।
 * যে বোতাম মিথ্যা বলে, সেটা না থাকা বোতামের চেয়ে খারাপ।
 *
 * ── ⚠️ তাই একটা কথা পরের জনের জন্য ──────────────────────────────────
 * এই চিঠিটা সত্যিই বাইরে যাবে কেবল তখনই যখন `.env`-এ SMTP বসানো আছে।
 * `MAIL_MAILER=log` থাকলে সে `storage/logs/laravel.log`-এ লেখা হয়,
 * আর কেউ কিছু পান না। ⛔ আর `MAIL_FROM_ADDRESS` আজও Laravel-এর নিজের
 * `hello@example.com` — ওটা না বদলালে চিঠিটা হয় যাবে না, নয় স্প্যামে
 * পড়বে। **কোডে কোনো ঠিকানা লেখা নেই, ইচ্ছাকৃতভাবে** — সবটাই
 * `config/mail.php` হয়ে `.env` থেকে আসে, তাই সার্ভার বদলালে কোড
 * ছুঁতে হয় না।
 */
final class PasswordResetLink extends Notification
{
    public function __construct(private readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /*
         * ভাষাটা তাঁর নিজের রেকর্ড থেকে, অনুরোধের চলতি ভাষা থেকে নয়।
         *
         * ⓘ চিঠিটা লেখা হয় এক মুহূর্তে, পড়া হয় আরেক মুহূর্তে — হয়তো
         * ঘণ্টাখানেক পরে, তাঁর ফোনে। ⚠️ অনুরোধের ভাষা ধরলে কেউ ইংরেজি
         * পর্দা থেকে অন্যের হয়ে রিসেট চাইলে চিঠিটাও ইংরেজিতে যেত।
         *
         * অচেনা কিছু বসানো থাকলে অ্যাপের ডিফল্টে নামা হয়, ভাঙা নয়।
         */
        $locale = in_array($notifiable->locale, ['bn', 'en'], true)
            ? $notifiable->locale
            : (string) config('app.locale');

        /*
         * ⚠️ ইমেইলটা লিংকেই থাকে, আর সেটা Laravel-এর ব্রোকারের দাবি:
         * টোকেন যাচাই হয় (ইমেইল + টোকেন) জোড়া ধরে। ⓘ ইমেইল ছাড়া
         * লিংকটা খুললে পরের পর্দায় সেটা আবার টাইপ করতে বলতে হত, আর
         * তখন মানুষ ভুল ঠিকানা লিখে "লিংকটা কাজ করছে না" বলতেন।
         */
        $url = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        /*
         * মেয়াদটা কনফিগ থেকে, হাতে লেখা সংখ্যা নয়।
         *
         * ⛔ চিঠিতে "৬০ মিনিট" লিখে রেখে পরে কেউ `config/auth.php`-এ
         * ৩০ করলে **চিঠিটা মিথ্যা বলত**, আর কেউ ধরতে পারত না।
         */
        $minutes = (int) config(
            'auth.passwords.'.config('auth.defaults.passwords').'.expire',
            60,
        );

        return (new MailMessage)
            ->subject(__('auth.reset_mail_subject', [], $locale))
            ->view('mail.password_reset', [
                'name' => $notifiable->name,
                'url' => $url,
                'minutes' => $minutes,
                'locale' => $locale,
            ]);
    }
}
