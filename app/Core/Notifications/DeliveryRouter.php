<?php

declare(strict_types=1);

namespace App\Core\Notifications;

use App\Core\Support\NotificationKinds;
use App\Models\Notification;
use App\Models\NotificationChannel;
use App\Models\NotificationChoice;
use App\Models\NotificationEvent;
use App\Models\User;

/**
 * ⭐ কোন খবর কোন মাধ্যমে যাবে।
 *
 * ── স্বাভাবিক পথ (ধাপ ২) ─────────────────────────────────────────────
 *   · ইমেইল — মানুষটা নিজে যা বলেছেন, না বললে ধরনের নিজের নিয়ম ([[NotificationKinds::mailedByDefault()]])
 *   · Web Push — জরুরি আর বেশি গুরুত্বের খবর, যাঁর ব্রাউজার সাবস্ক্রাইব করা
 *   · মোবাইল পুশ — কেবল জরুরি খবর (ফোনের ট্র্যাকিং-পুশ আগের মতো নিজের পথে, [[TrackingNotices]])
 *   · SMS — ⛔ কখনো নিজে থেকে নয়: প্রোভাইডার বসানো নেই, আর জরুরি মানেই SMS নয় (স্পেক §৫)
 *
 * ── ⭐ ধাপ ৩ — নিয়ম আর পছন্দ ──────────────────────────────────────────
 *   · নিয়মে মাধ্যম বসানো থাকলে ([[NotificationEvent::$channels]]) স্বাভাবিক পথের বদলে সেগুলো। ⓘ কেউ নিজে "এই খবর চিঠিতে
 *     নয়" বলে থাকলে নিয়মও চিঠি পাঠায় না।
 *   · নিজের পছন্দে বন্ধ করা মাধ্যম বন্ধ — জরুরিতেও (ঘণ্টায় তো থাকেই)।
 *   · চুপ করা শ্রেণির খবর বাইরের কোনো মাধ্যমে নয় — ⛔ জরুরি ছাড়া; জরুরি কখনো চুপ হয় না।
 *
 * ⓘ মাধ্যম "সংযুক্ত নয়" বা মানুষটার ঠিকানা না থাকলে সেই মাধ্যমের সারিই বসে না — ব্যর্থ-তালিকা মিথ্যা ব্যর্থতায় ভরে না।
 */
final class DeliveryRouter
{
    /** @var array<int, array<string, bool>> চিঠির ব্যাপারে কে কী বলেছেন, একবার দেখা */
    private array $mailChoices = [];

    public function __construct(
        private readonly ChannelRegistry $channels,
        private readonly PreferenceBook $prefs,
    ) {}

    /** @return list<string> */
    public function channelsFor(Notification $bell, User $user): array
    {
        return $this->plan($bell, $user)['channels'];
    }

    /**
     * মাধ্যম আর কোনটা কেন বাদ গেল (পছন্দে বন্ধ, চুপ করা শ্রেণি) — বাদের কারণ লেখা থাকে।
     *
     * @return array{channels: list<string>, suppressed: array<string, string>}
     */
    public function plan(Notification $bell, User $user): array
    {
        $company = (int) $bell->company_id;
        $critical = $bell->priority === 'critical';
        $ruled = $bell->event_id === null ? null : NotificationEvent::query()->withoutGlobalScopes()->whereKey($bell->event_id)->value('channels');
        $ruled = is_string($ruled) ? json_decode($ruled, true) : $ruled;

        if (is_array($ruled) && $ruled !== []) {
            // ⓘ নিয়মের মাধ্যম — চিঠিতে কেবল যদি তিনি নিজে "না" বলে না থাকেন
            $out = array_values(array_filter(array_intersect(NotificationChannel::ALL, $ruled), fn (string $key) => $key !== NotificationChannel::EMAIL
                || ($this->mailChoice((int) $user->id, (string) $bell->type) ?? true)));
        } else {
            $out = [];

            if ($this->wantsMail((int) $user->id, (string) $bell->type)) {
                $out[] = NotificationChannel::EMAIL;
            }

            if (in_array($bell->priority, ['critical', 'high'], true)) {
                $out[] = NotificationChannel::WEB_PUSH;
            }

            if ($critical) {
                $out[] = NotificationChannel::MOBILE_PUSH;
            }
        }

        $pref = $this->prefs->for((int) $user->id, $company);
        $suppressed = [];

        $out = array_values(array_filter($out, function (string $key) use ($pref, $bell, $critical, &$suppressed): bool {
            if (! $pref->allowsChannel($key)) {
                $suppressed[$key] = 'preference';

                return false;
            }

            if (! $critical && $pref->mutes((string) $bell->category)) {
                $suppressed[$key] = 'muted';

                return false;
            }

            return true;
        }));

        return [
            'channels' => array_values(array_filter($out, function (string $key) use ($company, $user): bool {
                $channel = $this->channels->get($key);

                return $channel !== null && $this->channels->connected($company, $key) && $channel->reaches($user);
            })),
            'suppressed' => $suppressed,
        ];
    }

    /**
     * এই মানুষটা এই ধরনের খবর চিঠিতেও চান কি না — তিনি নিজে কিছু বলে থাকলে সেটাই চূড়ান্ত; না বললে ধরনের নিয়ম।
     */
    public function wantsMail(int $userId, string $type): bool
    {
        return $this->mailChoice($userId, $type) ?? NotificationKinds::mailedByDefault($type);
    }

    /** তিনি নিজে কী বলেছেন — কিছু না বললে `null` */
    private function mailChoice(int $userId, string $type): ?bool
    {
        if (! array_key_exists($userId, $this->mailChoices)) {
            $this->mailChoices[$userId] = NotificationChoice::mailChoicesFor($userId);
        }

        return $this->mailChoices[$userId][$type] ?? null;
    }
}
