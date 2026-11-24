<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Core\Support\CompanyContext;
use Illuminate\Support\Facades\Cache;

/**
 * Online dot and "লিখছেন…" — kept in the cache, never in a table.
 *
 * ⓘ The host is cPanel (no websockets), so both ride on the app's short polling (contract §6): every messenger request
 * marks its caller as seen; a chat open on a phone polls every 3–5 s, so "seen in the last 60 s" = online.
 * ⓘ The cache store is the database on live; both keys expire by themselves, so nothing piles up.
 */
final class Presence
{
    public const ONLINE_SECONDS = 60;

    public const TYPING_SECONDS = 6;

    public function seen(int $userId): void
    {
        Cache::put($this->seenKey($userId), now()->getTimestamp(), self::ONLINE_SECONDS * 2);
    }

    public function online(int $userId): bool
    {
        $at = Cache::get($this->seenKey($userId));

        return is_int($at) && $at >= now()->getTimestamp() - self::ONLINE_SECONDS;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, bool>
     */
    public function onlineMany(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $keys = [];
        foreach ($userIds as $id) {
            $keys[$this->seenKey($id)] = $id;
        }

        $floor = now()->getTimestamp() - self::ONLINE_SECONDS;
        $out = [];
        foreach (Cache::many(array_keys($keys)) as $key => $at) {
            $out[$keys[$key]] = is_int($at) && $at >= $floor;
        }

        return $out;
    }

    public function typing(int $threadId, int $userId): void
    {
        $key = $this->typingKey($threadId);
        $rows = Cache::get($key, []);
        $rows = is_array($rows) ? $rows : [];
        $rows[$userId] = now()->getTimestamp();
        Cache::put($key, $this->fresh($rows), self::TYPING_SECONDS * 2);
    }

    /** A sent message ends "typing" at once. */
    public function stoppedTyping(int $threadId, int $userId): void
    {
        $key = $this->typingKey($threadId);
        $rows = Cache::get($key, []);
        if (is_array($rows) && isset($rows[$userId])) {
            unset($rows[$userId]);
            Cache::put($key, $rows, self::TYPING_SECONDS * 2);
        }
    }

    /** @return list<int> who typed in the last few seconds, never `$except` */
    public function typists(int $threadId, int $except): array
    {
        $rows = Cache::get($this->typingKey($threadId), []);

        return array_values(array_filter(
            array_map('intval', array_keys($this->fresh(is_array($rows) ? $rows : []))),
            fn (int $id): bool => $id !== $except,
        ));
    }

    /**
     * @param  array<int|string, mixed>  $rows
     * @return array<int|string, int>
     */
    private function fresh(array $rows): array
    {
        $floor = now()->getTimestamp() - self::TYPING_SECONDS;

        return array_filter($rows, fn ($at): bool => is_int($at) && $at >= $floor);
    }

    private function seenKey(int $userId): string
    {
        // ⓘ the person, not the open company — the messenger spans the group (owner, 24 Nov 2026), so "online" must too
        return 'messenger.seen.'.$userId;
    }

    private function typingKey(int $threadId): string
    {
        return 'messenger.typing.'.$threadId;
    }
}
