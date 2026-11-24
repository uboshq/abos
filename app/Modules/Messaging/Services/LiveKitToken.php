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
        return $this->sign([
            'sub' => (string) $user->id,
            'name' => (string) $user->name,
            'video' => [
                'roomJoin' => true,
                'room' => $room,
                'canPublish' => true,
                'canSubscribe' => true,
            ],
        ], self::TTL);
    }

    /** ⭐ a meeting's token is short — the page asks for a new one; a removed person's runs out soon */
    public const MEETING_TTL = 600;

    /** @param  list<string>  $sources  microphone | camera | screen_share | screen_share_audio */
    public function forMeeting(User $user, string $room, array $sources): string
    {
        return $this->sign([
            'sub' => (string) $user->id,
            'name' => (string) $user->name,
            'video' => [
                'roomJoin' => true,
                'room' => $room,
                'canPublish' => true,
                'canPublishSources' => $sources,
                'canSubscribe' => true,
                'canUpdateOwnMetadata' => true,
            ],
        ], self::MEETING_TTL);
    }

    /** Our own server to LiveKit's room doors — one minute, one room; never leaves the server. */
    public function forServer(string $room): string
    {
        return $this->sign(['video' => ['room' => $room, 'roomAdmin' => true, 'roomCreate' => true, 'roomList' => true]], 60);
    }

    /**
     * Is this webhook really from our LiveKit? Signature, issuer, time, and the hash of the exact body.
     */
    public function webhookIsOurs(string $authorization, string $body): bool
    {
        $parts = explode('.', trim($authorization));

        if (! $this->configured() || count($parts) !== 3) {
            return false;
        }

        $sign = self::b64(hash_hmac('sha256', $parts[0].'.'.$parts[1], $this->secret(), true));
        $header = $this->unb64($parts[0]);
        $claims = $this->unb64($parts[1]);
        $now = now()->getTimestamp();

        return ($header['alg'] ?? null) === 'HS256'
            && hash_equals($sign, $parts[2])
            && ($claims['iss'] ?? null) === $this->key()
            && is_int($claims['exp'] ?? null) && $claims['exp'] >= $now
            && (int) ($claims['nbf'] ?? 0) <= $now + 60
            && is_string($claims['sha256'] ?? null)
            && hash_equals(base64_encode(hash('sha256', $body, true)), $claims['sha256']);
    }

    /** @param  array<string, mixed>  $claims */
    private function sign(array $claims, int $ttl): string
    {
        $now = now()->getTimestamp();

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $claims = ['iss' => $this->key(), 'nbf' => $now, 'exp' => $now + $ttl] + $claims;

        $signing = self::b64($this->json($header)).'.'.self::b64($this->json($claims));

        return $signing.'.'.self::b64(hash_hmac('sha256', $signing, $this->secret(), true));
    }

    /** Base64url without padding (RFC 7515). */
    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    private function unb64(string $part): array
    {
        $raw = base64_decode(strtr($part, '-_', '+/'), true);
        $value = $raw === false ? null : json_decode($raw, true);

        return is_array($value) ? $value : [];
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
