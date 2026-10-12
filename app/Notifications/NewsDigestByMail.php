<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Notification as Bell;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * ⭐ দিনের বা সপ্তাহের সারসংক্ষেপ — ধরে রাখা সব খবর এক চিঠিতে (বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ৩; [[DigestService]])।
 *
 * ⓘ প্রতিটা খবরের কেবল শিরোনাম, উৎস আর সময় — বার্তার পুরো লেখা নয় (স্পেক §১৩ "ন্যূনতম Sensitive Data")। পড়তে হলে
 * সফটওয়্যারে ঢুকে, যেখানে শাখার দেয়াল আবার যাচাই হয়।
 */
final class NewsDigestByMail extends Notification
{
    /** @param  Collection<int, Bell>  $bells */
    public function __construct(private readonly Collection $bells, private readonly string $period) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = in_array($notifiable->locale, ['bn', 'en'], true) ? $notifiable->locale : (string) config('app.locale');

        return (new MailMessage)
            ->subject((string) __('core.notify.digest_subject.'.$this->period, ['count' => $this->bells->count()], $locale))
            ->view('mail.news_digest', [
                'name' => $notifiable->name,
                'period' => $this->period,
                'bells' => $this->bells,
                'url' => route('notifications.index'),
                'locale' => $locale,
            ]);
    }
}
