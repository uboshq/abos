<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Our server to LiveKit's own room doors (twirp, JSON): make a room with a ceiling, put someone out, mute, change what
 * someone may send, close the room.
 *
 * ⓘ Every answer is true (done) or false (the call server did not do it) — the caller says it in words. ⛔ Never inside a
 *   database transaction: a slow call server must not hold a row.
 */
class LiveKitRooms
{
    public function __construct(private readonly LiveKitToken $token) {}

    public function make(string $room, int $max): bool
    {
        return $this->ask($room, 'CreateRoom', ['name' => $room, 'empty_timeout' => 300, 'max_participants' => $max]) !== null;
    }

    /** the room is deleted on the calls server; no record of ours is closed here ([[MeetingService]] locks its own row) */
    public function deleteRoom(string $room): bool
    {
        return $this->ask($room, 'DeleteRoom', ['room' => $room]) !== null;
    }

    public function putOut(string $room, int $userId): bool
    {
        return $this->ask($room, 'RemoveParticipant', ['room' => $room, 'identity' => (string) $userId]) !== null;
    }

    /**
     * Mute one person's microphone (or everyone's but `$except`). Unmuting is never forced — it is asked.
     */
    public function mute(string $room, ?int $userId, ?int $except = null): bool
    {
        $list = $this->ask($room, 'ListParticipants', ['room' => $room]);

        if ($list === null) {
            return false;
        }

        $done = true;

        foreach ((array) ($list['participants'] ?? []) as $one) {
            $id = (int) ($one['identity'] ?? 0);

            if (($userId !== null && $id !== $userId) || $id === $except) {
                continue;
            }

            foreach ((array) ($one['tracks'] ?? []) as $track) {
                // ⓘ proto JSON leaves a default out: MICROPHONE is 2, so it is always named; `muted` is there only when true
                if (in_array($track['source'] ?? null, ['MICROPHONE', 2], true) && ($track['muted'] ?? false) !== true) {
                    $done = $this->ask($room, 'MutePublishedTrack', ['room' => $room, 'identity' => (string) $id,
                        'track_sid' => (string) ($track['sid'] ?? ''), 'muted' => true]) !== null && $done;
                }
            }
        }

        return $done;
    }

    /** @param  list<string>  $sources  token words (microphone …) */
    public function maySend(string $room, int $userId, array $sources): bool
    {
        return $this->ask($room, 'UpdateParticipant', ['room' => $room, 'identity' => (string) $userId, 'permission' => [
            'can_subscribe' => true, 'can_publish' => true, 'can_publish_data' => true, 'can_update_metadata' => true,
            'can_publish_sources' => array_map('strtoupper', $sources),
        ]]) !== null;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null  null = not done
     */
    private function ask(string $room, string $door, array $body): ?array
    {
        $base = (string) preg_replace('~^ws~', 'http', rtrim($this->token->url(), '/'));

        try {
            $answer = Http::timeout(5)->connectTimeout(3)->withToken($this->token->forServer($room))->acceptJson()
                ->post($base.'/twirp/livekit.RoomService/'.$door, $body);
        } catch (\Throwable $e) {
            Log::warning('LiveKit room door failed', ['door' => $door, 'why' => $e->getMessage()]);

            return null;
        }

        if (! $answer->successful()) {
            Log::warning('LiveKit room door refused', ['door' => $door, 'status' => $answer->status()]);

            return null;
        }

        return (array) $answer->json();
    }
}
