<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Models\User;

/**
 * A LiveKit access token for one person in one call room (contract §4) — plain HS256 JWT, no package.
 *
 * ⭐ Claims: iss = API key, sub = user id, name, nbf, exp (2 h), video grant {roomJoin, room, canPublish, canSubscribe}.
 * ⛔ The key and secret live only in `.env` (LIVEKIT_API_KEY / LIVEKIT_API_SECRET); empty = calls off.
 */
final class LiveKitToken
{
    public const TTL = 7200;

    public function configured(): bool
    {
        return $this->url() !== '' && $this->key() !== '' && $this->secret() !== '';
    }

    public function url(): string
    {
        return trim((string) config('services.livekit.url'));
    }

    public function for(User $user, string $room): string
    {
        $now = now()->getTimestamp();

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $this->key(),
            'sub' => (string) $user->id,
            'name' => (string) $user->name,
            'nbf' => $now,
            'exp' => $now + self::TTL,
            'video' => [
                'roomJoin' => true,
                'room' => $room,
                'canPublish' => true,
                'canSubscribe' => true,
            ],
        ];

        $signing = self::b64($this->json($header)).'.'.self::b64($this->json($claims));

        return $signing.'.'.self::b64(hash_hmac('sha256', $signing, $this->secret(), true));
    }

    /** Base64url without padding (RFC 7515). */
    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** @param  array<string, mixed>  $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function key(): string
    {
        return trim((string) config('services.livekit.key'));
    }

    private function secret(): string
    {
        return (string) config('services.livekit.secret');
    }
}
