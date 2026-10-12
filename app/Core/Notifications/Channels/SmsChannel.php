<?php

declare(strict_types=1);

namespace App\Core\Notifications\Channels;

use App\Core\Notifications\DeliveryChannel;
use App\Core\Notifications\DeliveryResult;
use App\Models\Notification;
use App\Models\NotificationChannel;
use App\Models\User;
use Throwable;

/**
 * ⭐ SMS — প্রোভাইডার বদলানো যায় এমন অ্যাডাপ্টার, কিন্তু আজ কোনো প্রোভাইডার বসানো নেই (মালিকের স্পেক §৭; ধাপ ২)।
 *
 * ⛔ স্পেকের নিয়ম: অনুমোদিত প্রোভাইডার ছাড়া কোনো মাধ্যম "সংযুক্ত" দেখানো যাবে না। তাই [[GATEWAYS]] খালি, আর মাধ্যমটা সবসময়
 * "সংযুক্ত নয়" — পর্দায় প্রোভাইডার, প্রেরকের নাম আর চাবি লেখা যায় (চাবি এনক্রিপ্ট করা), কিন্তু প্রোভাইডারের অ্যাডাপ্টার না
 * থাকলে কিছুই পাঠানো হয় না। মালিক প্রোভাইডার দিলে একটা [[SmsGateway]] লিখে এখানে নাম বসালেই চালু।
 * ⓘ বার্তায় কেবল শিরোনাম — ন্যূনতম তথ্য (স্পেক §১৩)।
 */
final class SmsChannel implements DeliveryChannel
{
    /** @var array<string, class-string<SmsGateway>> প্রোভাইডারের নাম → অ্যাডাপ্টার; ⛔ আজ খালি, মালিকের অনুমোদনের অপেক্ষায় */
    public const GATEWAYS = [];

    public function key(): string
    {
        return NotificationChannel::SMS;
    }

    public function provider(?NotificationChannel $config): string
    {
        return (string) ($config?->provider ?: 'none');
    }

    public function connected(?NotificationChannel $config): bool
    {
        return $this->gateway($config) !== null && $config?->enabled === true && filled($config->sender_id);
    }

    public function reaches(User $user): bool
    {
        return filled($user->mobile);
    }

    public function send(Notification $notification, User $user, ?NotificationChannel $config): DeliveryResult
    {
        $gateway = $this->gateway($config);

        if ($gateway === null || ! $this->connected($config)) {
            return DeliveryResult::permanent('sms is not connected: no approved provider');
        }

        if (! $this->reaches($user)) {
            return DeliveryResult::permanent('the recipient has no mobile number');
        }

        try {
            return $gateway->send((string) $user->mobile, mb_substr((string) $notification->title, 0, 160), (string) $config->sender_id, (array) ($config->credentials ?? []));
        } catch (Throwable $e) {
            DeliveryResult::report($e, 'sms');

            return DeliveryResult::transient('sms: '.class_basename($e));
        }
    }

    public function test(User $user, ?NotificationChannel $config): DeliveryResult
    {
        return $this->send(new Notification(['title' => (string) __('core.notify.channel_test_title')]), $user, $config);
    }

    private function gateway(?NotificationChannel $config): ?SmsGateway
    {
        $class = self::GATEWAYS[(string) $config?->provider] ?? null;

        return $class === null ? null : app($class);
    }
}
