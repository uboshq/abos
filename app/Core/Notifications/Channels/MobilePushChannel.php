<?php

declare(strict_types=1);

namespace App\Core\Notifications\Channels;

use App\Core\Notifications\DeliveryChannel;
use App\Core\Notifications\DeliveryResult;
use App\Core\Services\FcmSender;
use App\Models\Notification;
use App\Models\NotificationChannel;
use App\Models\SyncDevice;
use App\Models\User;
use Throwable;

/**
 * ⭐ মোবাইল পুশ — আগে থেকে থাকা FCM ([[FcmSender]], ফোনের টোকেন `sync_devices.push_token`), নতুন করে বানানো নয় (ধাপ ২)।
 *
 * ⓘ সংযোগ `.env`-এর Firebase চাবি থেকে (`FIREBASE_CREDENTIALS`, `FIREBASE_PROJECT_ID`) — না থাকলে "সংযুক্ত নয়"।
 * ফোন থেকে অ্যাপ মোছা হলে (FCM "নেই") টোকেন সরে যায়, আগের [[SendPushToUser]]-এর একই নিয়ম।
 * ⛔ লক-স্ক্রিনে কেবল শিরোনাম — বার্তার লেখা নয়।
 */
final class MobilePushChannel implements DeliveryChannel
{
    public function __construct(private readonly FcmSender $fcm) {}

    public function key(): string
    {
        return NotificationChannel::MOBILE_PUSH;
    }

    public function provider(?NotificationChannel $config): string
    {
        return 'fcm';
    }

    public function connected(?NotificationChannel $config): bool
    {
        return $this->fcm->configured() && ($config?->enabled ?? true);
    }

    public function reaches(User $user): bool
    {
        return SyncDevice::query()->withoutGlobalScopes()->where('user_id', $user->id)->whereNotNull('push_token')->exists();
    }

    public function send(Notification $notification, User $user, ?NotificationChannel $config): DeliveryResult
    {
        if (! $this->connected($config)) {
            return DeliveryResult::permanent('mobile push is not connected (no Firebase credentials)');
        }

        $devices = SyncDevice::query()->withoutGlobalScopes()->where('user_id', $user->id)->whereNotNull('push_token')->get();

        if ($devices->isEmpty()) {
            return DeliveryResult::permanent('the recipient has no phone with the app');
        }

        $sent = false;
        $failed = false;

        try {
            foreach ($devices as $device) {
                $result = $this->fcm->send((string) $device->push_token, (string) $notification->title, null, [
                    'open' => 'notification', 'id' => (string) ($notification->public_id ?? ''),
                ]);

                if ($result === FcmSender::GONE) {
                    $device->forceFill(['push_token' => null, 'push_token_at' => null])->save();
                }

                $sent = $sent || $result === FcmSender::SENT;
                $failed = $failed || $result === FcmSender::FAILED;
            }
        } catch (Throwable $e) {
            DeliveryResult::report($e, 'mobile_push');

            return DeliveryResult::transient('fcm: '.class_basename($e));
        }

        return match (true) {
            $sent => DeliveryResult::sent(),
            $failed => DeliveryResult::transient('fcm did not accept the message'),
            default => DeliveryResult::permanent('no phone token is valid any more'),
        };
    }

    public function test(User $user, ?NotificationChannel $config): DeliveryResult
    {
        return $this->send(new Notification(['title' => (string) __('core.notify.channel_test_title')]), $user, $config);
    }
}
