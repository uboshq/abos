<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ইমেইল বদলানোর নিশ্চিতকরণ — চিঠিটা **নতুন ঠিকানায়** যায়।
 *
 * ── ⭐ কেন নতুন ঠিকানায়, পুরনোটায় নয় ─────────────────────────────────
 * প্রশ্নটা এখানে একটাই: *"এই ঠিকানাটা কি সত্যিই আপনার?"* ⓘ আর তার
 * উত্তর কেবল ঐ ইনবক্সের মালিকই দিতে পারেন। ⛔ পুরনো ঠিকানায় পাঠিয়ে
 * "হ্যাঁ" নিলে প্রমাণ হত কেবল পুরনোটা তাঁর — নতুনটা নিয়ে কিছুই জানা হত
 * না, আর একটা অক্ষর ভুল লিখলে অ্যাকাউন্টটা এমন একটা ঠিকানায় চলে যেত
 * যেখানে তিনি কোনোদিন পৌঁছাতে পারতেন না।
 *
 * ⚠️ পুরনো ঠিকানাটাও চুপ থাকে না — সে একটা সতর্কবার্তা পায়
 * ([[EmailChangeWarning]])। দুইটা চিঠির কাজ দুই রকম: এটা **অনুমতি
 * চায়**, ওটা **খবর দেয়**।
 *
 * ── ⚠️ চিঠি সত্যিই যাবে কি না, সেটা কোডের হাতে নয় ───────────────────
 * `MAIL_MAILER=log` থাকলে এই চিঠি `storage/logs/laravel.log`-এ লেখা হয়
 * আর কেউ কিছু পান না — ১৪ সেপ্টেম্বর ২০২৬-এ `.env`-এ ওটাই বসানো আছে।
 * ⓘ [[PasswordResetLink]]-এ একই কথা লেখা, আর একই কারণে: SMTP না বসানো
 * পর্যন্ত এই পথটা লোকালে পরীক্ষা করা যায়, বাস্তবে চলে না।
 */
final class EmailChangeLink extends Notification
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
         * ভাষাটা তাঁর নিজের রেকর্ড থেকে, অনুরোধের চলতি ভাষা থেকে নয় —
         * ⓘ চিঠিটা লেখা হয় এক মুহূর্তে, পড়া হয় আরেক মুহূর্তে।
         */
        $locale = in_array($notifiable->locale, ['bn', 'en'], true)
            ? $notifiable->locale
            : (string) config('app.locale');

        $minutes = (int) config('abos.email_change_expire', 60);

        return (new MailMessage)
            ->subject(__('core.profile.email_mail_subject', [], $locale))
            ->greeting(__('core.profile.email_mail_greeting', ['name' => $notifiable->name], $locale))
            ->line(__('core.profile.email_mail_line', [], $locale))
            ->action(
                __('core.profile.email_mail_action', [], $locale),
                route('profile.email.confirm', ['token' => $this->token]),
            )
            ->line(__('core.profile.email_mail_expiry', ['minutes' => $minutes], $locale))
            /*
             * ⚠️ "আপনি না চাইলে কিছু করবেন না" — এই লাইনটা বাদ দেওয়া
             * যায় না। ⓘ কেউ ভুল করে অন্যের ঠিকানা বসালে ঐ মানুষটা একটা
             * অচেনা চিঠি পান, আর তাঁর জানা দরকার যে **চুপ করে থাকলেই
             * কিছু হবে না** — নাহলে তিনি ভাববেন কিছু একটা করতে হবে।
             */
            ->line(__('core.profile.email_mail_ignore', [], $locale));
    }
}
