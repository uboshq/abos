<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ⚠️ পুরনো ঠিকানায় খবর — "আপনার ইমেইল বদলানোর অনুরোধ এসেছে"।
 *
 * ── ⭐ কেন এই চিঠিটা লাগে ─────────────────────────────────────────────
 * নিশ্চিতকরণের চিঠিটা যায় **নতুন** ঠিকানায়, আর সেটাই ঠিক। ⛔ কিন্তু
 * ঐ ব্যবস্থার একটা ফাঁক আছে: কেউ যদি খোলা সেশন পেয়ে যান — ডিপোতে
 * একজন লগআউট না করে উঠে গেলেন — তিনি ইমেইলটা **নিজের ঠিকানায়** বদলে
 * নিতে পারতেন, নিজের ইনবক্সে লিংক পেতেন, চাপ দিতেন, আর আসল মানুষটা
 * কিছুই জানতেন না। পরে পাসওয়ার্ড রিসেটও ঐ নতুন ঠিকানাতেই যেত।
 *
 * ⭐ এই চিঠিটা সেই নীরবতাটা ভাঙে। ⓘ এটা অনুমতি চায় না — চাওয়ার কিছু
 * নেই, কাজটা এখনো হয়ইনি। এটা কেবল **খবর দেয়**, যাতে যাঁর অ্যাকাউন্ট
 * তিনি জানতে পারেন আর সময় থাকতে পাসওয়ার্ড বদলে ফেলতে পারেন।
 *
 * ⚠️ তাই এতে কোনো লিংক নেই। ⓘ "এটা আমি নই" বোতাম দিলে ওটাই আরেকটা
 * আক্রমণের দরজা হত, আর আসল প্রতিকার একটাই: পাসওয়ার্ড বদলানো।
 */
final class EmailChangeWarning extends Notification
{
    public function __construct(private readonly string $newEmail) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = in_array($notifiable->locale, ['bn', 'en'], true)
            ? $notifiable->locale
            : (string) config('app.locale');

        /*
         * ⚠️ চিঠিটা **পুরনো** ঠিকানায় পাঠাতে হয়, আর ততক্ষণে
         * `pending_email` বসে গেছে কিন্তু `email` বদলায়নি — তাই
         * `$notifiable`-এর নিজের ঠিকানাটাই এখনো পুরনোটা।
         */
        return (new MailMessage)
            ->subject(__('core.profile.email_warn_subject', [], $locale))
            ->greeting(__('core.profile.email_mail_greeting', ['name' => $notifiable->name], $locale))
            ->line(__('core.profile.email_warn_line', ['email' => $this->newEmail], $locale))
            ->line(__('core.profile.email_warn_ok', [], $locale))
            ->line(__('core.profile.email_warn_not_you', [], $locale));
    }
}
