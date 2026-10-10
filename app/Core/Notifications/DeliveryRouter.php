<?php

declare(strict_types=1);

namespace App\Core\Notifications;

use App\Core\Support\NotificationKinds;
use App\Models\Notification;
use App\Models\NotificationChannel;
use App\Models\NotificationChoice;
use App\Models\User;

/**
 * ⭐ কোন খবর কোন মাধ্যমে যাবে — ধাপ ২-এর নিয়ম (ধাপ ৩-এ নিয়মের পর্দা এটার উপরে বসবে)।
 *
 *   · ইমেইল — আগের মতোই: মানুষটা নিজে যা বলেছেন, না বললে ধরনের নিজের নিয়ম ([[NotificationKinds::mailedByDefault()]])
 *   · Web Push — জরুরি আর বেশি গুরুত্বের খবর, যাঁর ব্রাউজার সাবস্ক্রাইব করা
 *   · মোবাইল পুশ — কেবল জরুরি খবর (ফোনের ট্র্যাকিং-পুশ আগের মতো নিজের পথে, [[TrackingNotices]])
 *   · SMS — ⛔ কখনো নিজে থেকে নয়: প্রোভাইডার বসানো নেই, আর জরুরি মানেই SMS নয় (স্পেক §৫)
 *
 * ⓘ মাধ্যম "সংযুক্ত নয়" বা মানুষটার ঠিকানা না থাকলে সেই মাধ্যমের সারিই বসে না — ব্যর্থ-তালিকা মিথ্যা ব্যর্থতায় ভরে না।
 */
final class DeliveryRouter
{
    /** @var array<int, array<string, bool>> চিঠির ব্যাপারে কে কী বলেছেন, একবার দেখা */
    private array $mailChoices = [];

    public function __construct(private readonly ChannelRegistry $channels) {}

    /** @return list<string> */
    public function channelsFor(Notification $bell, User $user): array
    {
        $company = (int) $bell->company_id;
        $out = [];

        if ($this->wantsMail((int) $user->id, (string) $bell->type)) {
            $out[] = NotificationChannel::EMAIL;
        }

        if (in_array($bell->priority, ['critical', 'high'], true)) {
            $out[] = NotificationChannel::WEB_PUSH;
        }

        if ($bell->priority === 'critical') {
            $out[] = NotificationChannel::MOBILE_PUSH;
        }

        return array_values(array_filter($out, function (string $key) use ($company, $user): bool {
            $channel = $this->channels->get($key);

            return $channel !== null && $this->channels->connected($company, $key) && $channel->reaches($user);
        }));
    }

    /**
     * এই মানুষটা এই ধরনের খবর চিঠিতেও চান কি না — তিনি নিজে কিছু বলে থাকলে সেটাই চূড়ান্ত; না বললে ধরনের নিয়ম।
     */
    public function wantsMail(int $userId, string $type): bool
    {
        if (! array_key_exists($userId, $this->mailChoices)) {
            $this->mailChoices[$userId] = NotificationChoice::mailChoicesFor($userId);
        }

        return $this->mailChoices[$userId][$type] ?? NotificationKinds::mailedByDefault($type);
    }
}
