<?php

declare(strict_types=1);

namespace App\Core\Notifications\Channels;

use App\Core\Notifications\DeliveryChannel;
use App\Core\Notifications\DeliveryResult;
use App\Core\Support\MailReach;
use App\Models\Notification;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Notifications\NewsByMail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * ⭐ ইমেইল — Laravel-এর mail, SMTP `.env` থেকে (মালিকের স্পেক §৭; ধাপ ২)।
 *
 * ⓘ চিঠির লেখা আগের [[NewsByMail]]-ই — শিরোনাম, ছোট বার্তা, লিংক; গোপন কিছু নয় (স্পেক §১৩)। ⛔ SMTP-র চাবি ডাটাবেসে
 * রাখা হয় না — `.env`-এ থাকে, যেমন ছিল; তাই এই মাধ্যমের পর্দায় চাবির ঘর নেই, কেবল চালু/বন্ধ আর প্রেরকের নাম দেখা।
 *
 * ⓘ `MAIL_MAILER=log` (বা খালি) হলে "সংযুক্ত নয়" — চিঠি সত্যিই বাইরে যায় না ([[MailReach]])।
 */
final class EmailChannel implements DeliveryChannel
{
    public function key(): string
    {
        return NotificationChannel::EMAIL;
    }

    public function provider(?NotificationChannel $config): string
    {
        return (string) config('mail.default', 'smtp');
    }

    public function connected(?NotificationChannel $config): bool
    {
        return ! MailReach::silent() && ($config?->enabled ?? true);
    }

    public function reaches(User $user): bool
    {
        return filled($user->email);
    }

    public function send(Notification $notification, User $user, ?NotificationChannel $config): DeliveryResult
    {
        if (! $this->connected($config)) {
            return DeliveryResult::permanent('email channel is not connected');
        }

        if (! $this->reaches($user)) {
            return DeliveryResult::permanent('the recipient has no e-mail address');
        }

        try {
            $user->notifyNow(new NewsByMail($notification));
        } catch (TransportExceptionInterface $e) {
            return DeliveryResult::transient('mail transport: '.$e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return DeliveryResult::transient('mail: '.class_basename($e));
        }

        return DeliveryResult::sent();
    }

    public function test(User $user, ?NotificationChannel $config): DeliveryResult
    {
        $probe = new Notification([
            'type' => 'system.channel_test',
            'title' => (string) __('core.notify.channel_test_title'),
            'body' => (string) __('core.notify.channel_test_body'),
            'url' => route('notifications.index'),
        ]);

        return $this->send($probe, $user, $config);
    }
}
