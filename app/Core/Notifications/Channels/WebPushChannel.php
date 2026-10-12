<?php

declare(strict_types=1);

namespace App\Core\Notifications\Channels;

use App\Core\Notifications\DeliveryChannel;
use App\Core\Notifications\DeliveryResult;
use App\Models\Notification;
use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\User;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * ⭐ Web Push — ব্রাউজারের নিজের পুশ, VAPID চাবি দিয়ে (মালিকের স্পেক §৭ "Permission, Subscription, Expiry, Revocation"; ধাপ ২)।
 *
 * ── নির্ভরতা: `minishlink/web-push` ─────────────────────────────────────
 * Web Push-এর বার্তা ব্রাউজারের চাবি দিয়ে এনক্রিপ্ট (aes128gcm) আর VAPID দিয়ে সই করতে হয় — নিজে লিখলে এনক্রিপশনের ভুল
 * ধরা কঠিন। লাইব্রেরিটা PHP-র নিজের openssl আর curl দিয়ে চলে, কোনো ডেমন বা আলাদা সার্ভার লাগে না — শেয়ার্ড cPanel-এ চলে।
 *
 * ⓘ VAPID চাবি জোড়া মাধ্যমের পর্দার "চাবি বানান" বোতামে তৈরি হয়; গোপন অংশটা এনক্রিপ্ট করা থাকে। চাবি না থাকলে
 * "সংযুক্ত নয়"। প্রোভাইডার (ব্রাউজারের পুশ সার্ভিস) "সাবস্ক্রিপশন আর নেই" (৪০৪/৪১০) বললে সারিটা প্রত্যাহার হয়।
 * ⛔ বার্তায় কেবল শিরোনাম, ছোট লেখা আর ABOS-এর লিংক।
 */
final class WebPushChannel implements DeliveryChannel
{
    public function key(): string
    {
        return NotificationChannel::WEB_PUSH;
    }

    public function provider(?NotificationChannel $config): string
    {
        return 'vapid';
    }

    public function connected(?NotificationChannel $config): bool
    {
        return $config !== null && $config->enabled
            && $config->secret('vapid_public') !== null && $config->secret('vapid_private') !== null;
    }

    public function reaches(User $user): bool
    {
        return NotificationSubscription::query()->where('user_id', $user->id)->live()->exists();
    }

    public function send(Notification $notification, User $user, ?NotificationChannel $config): DeliveryResult
    {
        if (! $this->connected($config)) {
            return DeliveryResult::permanent('web push is not connected (no VAPID keys)');
        }

        // ⛔ পুরনো বা হাতে বসানো সারিতেও কেবল চেনা পুশ-সেবার ঠিকানা — অচেনা ঠিকানায় সার্ভার কখনো অনুরোধ পাঠায় না
        $subscriptions = NotificationSubscription::query()->where('user_id', $user->id)->live()->get()
            ->filter(fn (NotificationSubscription $s) => NotificationSubscription::knownEndpoint((string) $s->endpoint))->values();

        if ($subscriptions->isEmpty()) {
            return DeliveryResult::permanent('the recipient has no browser subscription');
        }

        $payload = json_encode([
            'title' => (string) $notification->title,
            'body' => (string) ($notification->body ?? ''),
            'url' => $notification->id ? route('notifications.open', $notification) : (string) $notification->url,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $push = new WebPush(['VAPID' => [
                'subject' => $config->sender_id ?: (string) config('app.url'),
                'publicKey' => (string) $config->secret('vapid_public'),
                'privateKey' => (string) $config->secret('vapid_private'),
            ]], ['TTL' => 86400], 15);

            foreach ($subscriptions as $subscription) {
                $push->queueNotification(Subscription::create([
                    'endpoint' => (string) $subscription->endpoint,
                    'keys' => (array) $subscription->keys,
                    'contentEncoding' => 'aes128gcm',
                ]), $payload);
            }

            $sent = false;
            $transient = null;
            $retryAfter = null;
            $why = 'no subscription accepted the message';

            foreach ($push->flush() as $report) {
                $row = $subscriptions->first(fn ($s) => (string) $s->endpoint === $report->getEndpoint());

                if ($report->isSuccess()) {
                    $sent = true;
                    $row?->forceFill(['last_used_at' => now()])->save();

                    continue;
                }

                $status = $report->getResponse()?->getStatusCode();

                if ($report->isSubscriptionExpired()) {
                    // ⓘ ব্রাউজার সাবস্ক্রিপশন ফিরিয়ে নিয়েছে — আর পাঠানো নয়
                    $row?->forceFill(['revoked_at' => now()])->save();
                    $why = 'subscription expired ('.$status.')';

                    continue;
                }

                if ($status === null || $status === 429 || $status >= 500) {
                    $transient = 'push service: '.($status ?? 'no response');
                    $after = $report->getResponse()?->getHeaderLine('Retry-After');
                    $retryAfter = is_numeric($after) ? (int) $after : $retryAfter;

                    continue;
                }

                $why = 'push service refused ('.$status.')';
            }
        } catch (Throwable $e) {
            DeliveryResult::report($e, 'web_push');

            return DeliveryResult::transient('web push: '.class_basename($e));
        }

        return match (true) {
            $sent => DeliveryResult::sent(),
            $transient !== null => DeliveryResult::transient($transient, $retryAfter),
            default => DeliveryResult::permanent($why),
        };
    }

    public function test(User $user, ?NotificationChannel $config): DeliveryResult
    {
        return $this->send(new Notification([
            'title' => (string) __('core.notify.channel_test_title'),
            'body' => (string) __('core.notify.channel_test_body'),
            'url' => route('notifications.index'),
        ]), $user, $config);
    }
}
