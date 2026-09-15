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
 * ── ⓘ চিঠি সত্যিই যাবে কি না ─────────────────────────────────────────
 * ১৫ সেপ্টেম্বর ২০২৬-এ SMTP বসানো হয়েছে (`mail.adi.com.bd`), আর একটা
 * সত্যিকারের চিঠি পাঠিয়ে ইনবক্সে পৌঁছানো **মেপে দেখা হয়েছে**।
 * ⚠️ তবু মেইলার কোনোদিন `log`-এ ফিরে গেলে এই চিঠি চুপচাপ লগ ফাইলে
 * লেখা হত — সেটা ঠেকায় [[App\Core\Support\MailReach]], পাঠানোর আগেই।
 */
final class EmailChangeLink extends Notification
{
    /**
     * ⛔ নাম ও ভাষা সাথে করে আনতে হয় — ১৫ সেপ্টেম্বর ২০২৬।
     *
     * ── কী ভাঙা ছিল, আর কেন কোনো টেস্ট ধরেনি ────────────────────────
     * ⚠️ এই চিঠিটা যায় **বেনামে** — নতুন ঠিকানাটা এখনো কারো অ্যাকাউন্ট
     * নয়, তাই `Notification::route('mail', $email)` দিয়ে পাঠানো হয়।
     * ⓘ ফলে `$notifiable` একটা `AnonymousNotifiable`, আর তার
     * `name` বা `locale` **কোনোটাই নেই**।
     *
     * ⛔ চিঠিটা তবু যেত, কেবল সম্বোধনটা হত *"নমস্কার ,"* — নামের
     * জায়গাটা ফাঁকা। PHP কেবল একটা warning তোলে, ব্যতিক্রম নয়।
     *
     * ⚠️ আর ধরা পড়েনি কারণ পরীক্ষাগুলো `Notification::fake()` ব্যবহার
     * করে, আর সে `toMail()` **কখনো চালায় না** — সে কেবল গোনে কোন
     * চিঠি কাকে পাঠানো হয়েছে। ⓘ আজকের চেনা আকৃতি: সবুজ পাহারা যা
     * আসল জিনিসটা দেখেইনি। ⭐ ধরা পড়েছে সত্যিকারের একটা চিঠি পাঠিয়ে।
     *
     * ⭐ তাই এখন নাম ও ভাষা পাঠানোর সময়েই সাথে দেওয়া হয়। পাহারা:
     * [[TheLetterKnowsWhoItIsWritingToTest]]
     */
    public function __construct(
        private readonly string $token,
        private readonly string $name,

        /*
         * ⛔ ঘরটার নাম `lang`, `locale` নয় — আর সেটা বাধ্য হয়ে।
         *
         * ⚠️ `Illuminate\Notifications\Notification`-এ আগে থেকেই একটা
         * `$locale` ঘর আছে, আর সেটা readonly নয়। ⓘ একই নামে readonly
         * ঘর বসাতে গিয়ে PHP ক্লাসটা **লোডই করতে পারেনি**:
         *
         *   Cannot redeclare non-readonly property
         *   Illuminate\Notifications\Notification::$locale as readonly
         *
         * ⚠️ আর `php -l` এটা ধরেনি — সে কেবল বাক্যগঠন দেখে, উত্তরাধিকার
         * নয়। ধরা পড়েছে ক্লাসটা সত্যিই লোড করে।
         */
        private readonly string $lang,
    ) {}

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
         *
         * ⚠️ `$notifiable` থেকে নেওয়া যায় না: সে বেনামি, তার কোনো
         * রেকর্ডই নেই। তাই পাঠানোর সময় সাথে দেওয়া হয়েছে।
         */
        $locale = in_array($this->lang, ['bn', 'en'], true)
            ? $this->lang
            : (string) config('app.locale');

        $minutes = (int) config('abos.email_change_expire', 60);

        return (new MailMessage)
            ->subject(__('core.profile.email_mail_subject', [], $locale))
            ->greeting(__('core.profile.email_mail_greeting', ['name' => $this->name], $locale))
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
