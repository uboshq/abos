<?php

declare(strict_types=1);

namespace App\Core\Notifications\Channels;

use App\Core\Notifications\DeliveryChannel;
use App\Core\Notifications\DeliveryResult;
use App\Core\Notifications\PhoneNoticeTarget;
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
    /** পুশটা নিজের পথে গেছে — চেষ্টার খাতায় প্রোভাইডারের সূত্রের জায়গায় এই লেখা */
    public const OWN_PUSH = 'own-push';

    public function __construct(
        private readonly FcmSender $fcm,
        private readonly PhoneNoticeTarget $target,
    ) {}

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
        /*
         * ⛔ একটা খবরে একটাই পুশ — ট্র্যাকিংয়ের খবর ([[PhoneNoticeTarget::PUSHES_ITSELF]]) নিজের পথে ফোনে যায় ([[TrackingNotices]],
         * `open: tracking`, যা পুরনো অ্যাপ চেনে ও ট্র্যাকিং খোলে)। নিয়মে মোবাইল পুশ চালু থাকলে এখান থেকেও যেত — একই মানুষ দুইটা
         * পুশ পেতেন ([[ATrackingNoticePushesOnceTest]], ২৪ অক্টোবর ২০২৬)। ⓘ "পৌঁছেছে" লেখা, কারণ পুশটা সত্যিই গেছে, অন্য পথে —
         * ব্যর্থ-তালিকা মিথ্যা ব্যর্থতায় ভরে না।
         */
        if (PhoneNoticeTarget::pushedItself($notification)) {
            return DeliveryResult::sent(self::OWN_PUSH);
        }

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
            /*
             * ⭐ `open` আর `id` যেমন ছিল (পুরনো অ্যাপ); নতুন ঘর কেবল যোগ — ধরন, কাগজ, গন্তব্য ([[PhoneNoticeTarget::pushFields()]];
             * চুক্তি `docs/Contract — ফোনের খবর কোথায় খোলে.md`)। ⛔ সব মান লেখা — FCM-এর নিয়ম।
             */
            $data = ['open' => 'notification', 'id' => (string) ($notification->public_id ?? '')]
                + $this->target->pushFields($notification, $user);

            foreach ($devices as $device) {
                $result = $this->fcm->send((string) $device->push_token, (string) $notification->title, null, $data);

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
