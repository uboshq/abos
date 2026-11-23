<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Models\SyncDevice;

/**
 * ⭐ "You have news" — a silent FCM data push `{"abos":"notice"}` to a user's phones when a notification row is made
 * for them (speed work, 23 Nov 2026).
 *
 * ⓘ The phone used to learn of a new bell item only on its next poll of `/api/v1/notifications`. With the nudge it
 * asks at once — and with the door's ETag ([[AnswersUnchanged]]) the polls between nudges are cheap 304s.
 *
 * ⓘ The same sender and the same rules as the sync nudge ([[SyncNudge]]): data only (no title, nothing shown — the
 * phone fetches the news itself, so nothing private rides on Google's servers), dead tokens cleared, no push keys →
 * nothing at all, not even a query.
 * ⛔ At most one nudge per user per [[WINDOW]] seconds — ten notices in one posting are one push.
 */
final class NoticeNudge
{
    public const WINDOW = 10;

    public const DATA = ['abos' => 'notice'];

    public function __construct(private readonly FcmSender $fcm) {}

    public function touch(?int $userId, ?string $type = null): void
    {
        // ⓘ a tracking notice pushes itself, carrying the notice ([[PhoneNoticeTarget::PUSHES_ITSELF]]) — no second wake-up
        if ($userId === null || in_array($type, \App\Core\Notifications\PhoneNoticeTarget::PUSHES_ITSELF, true)) {
            return;
        }

        try {
            if ((string) config('services.firebase.credentials') === ''
                || ! cache()->add('notice-nudge:'.$userId, true, self::WINDOW)) {
                return;
            }

            // ⓘ a web request sends after its answer is out; a queue job or command (no response to wait for) sends now
            if (app()->runningInConsole()) {
                $this->send($userId);
            } else {
                dispatch(fn () => app(self::class)->send($userId))->afterResponse();
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** the push to every phone of the user that has a token — how many took it */
    public function send(int $userId): int
    {
        $sent = 0;

        $devices = SyncDevice::query()->withoutGlobalScopes()
            ->where('user_id', $userId)
            ->whereNotNull('push_token')
            ->get();

        foreach ($devices as $device) {
            $result = $this->fcm->sendData((string) $device->push_token, self::DATA);

            if ($result === FcmSender::OFF) {
                return 0;
            }

            if ($result === FcmSender::GONE) {
                $device->forceFill(['push_token' => null, 'push_token_at' => null])->save();
            }

            $sent += $result === FcmSender::SENT ? 1 : 0;
        }

        return $sent;
    }
}
