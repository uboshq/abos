<?php

declare(strict_types=1);

namespace App\Core\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * অ্যাপ বন্ধ থাকলেও ফোনে বার্তা — Firebase Cloud Messaging, HTTP v1 (মালিকের আদেশ, ২ অক্টোবর ২০২৬)।
 *
 * ── ⛔ চাবি ───────────────────────────────────────────────────────────────
 * সার্ভিস-অ্যাকাউন্টের JSON গোপন — ওয়েব রুটের বাইরের ফাইলে, `.env`-এ কেবল পথ (`FIREBASE_CREDENTIALS`)।
 * ⛔ এর ভেতরের কিছু কখনো লগ, ব্যতিক্রম-বার্তা বা উত্তরে যায় না — ভুল হলে কেবল "চাবি পড়া গেল না"।
 *
 * ── ⓘ নতুন প্যাকেজ ছাড়া ─────────────────────────────────────────────────
 * Google-এর OAuth: RS256-এ সই করা JWT → `oauth2.googleapis.com/token` → এক ঘণ্টার access token, ৫০ মিনিট
 * ক্যাশে। সই `openssl_sign` — PHP-র নিজের।
 *
 * ── ফলাফল ─────────────────────────────────────────────────────────────────
 * `sent` · `gone` (টোকেন বাতিল — UNREGISTERED/404; ডাকনেওয়ালা টোকেনটা মুছবে) · `failed` (পরে আবার চেষ্টা)।
 * চাবি বসানো না থাকলে `off` — কিছুই পাঠানো হয় না, ভুলও হয় না (ডেমো, পরীক্ষা, স্থানীয় যন্ত্র)।
 */
final class FcmSender
{
    public const SENT = 'sent';

    public const GONE = 'gone';

    public const FAILED = 'failed';

    public const OFF = 'off';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /**
     * @param  array<string, string>  $data  চাপলে কোথায় যাবে ইত্যাদি — সব মান লেখা (FCM-এর নিয়ম)
     */
    public function send(string $token, string $title, ?string $body, array $data = []): string
    {
        return $this->post([
            'token' => $token,
            'notification' => array_filter(['title' => $title, 'body' => $body]),
            'data' => array_map('strval', $data),
            'android' => ['priority' => 'HIGH'],
        ]);
    }

    /**
     * ⭐ নীরব বার্তা — কেবল `data`, কোনো শিরোনাম বা লেখা নয় (রিয়েল-টাইম সিঙ্ক, মালিক, ১০ অক্টোবর ২০২৬)।
     *
     * ⓘ ফোন দেখায় না, কেবল খোলা অ্যাপ শোনে ([[SyncNudge]])। ⛔ ভেতরে ব্যবসার কোনো তথ্য নয় — কেবল "সিঙ্ক করো"।
     *
     * @param  array<string, string>  $data
     */
    public function sendData(string $token, array $data): string
    {
        return $this->post([
            'token' => $token,
            'data' => array_map('strval', $data),
            'android' => ['priority' => 'HIGH'],
        ]);
    }

    /** @param  array<string, mixed>  $message */
    private function post(array $message): string
    {
        $credentials = $this->credentials();
        $project = (string) config('services.firebase.project_id');

        if ($credentials === null || $project === '') {
            return self::OFF;
        }

        $response = Http::withToken($this->accessToken($credentials))
            ->timeout(15)
            ->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", ['message' => $message]);

        if ($response->successful()) {
            return self::SENT;
        }

        $status = (string) data_get($response->json(), 'error.details.0.errorCode', '');

        return $response->status() === 404 || $status === 'UNREGISTERED' ? self::GONE : self::FAILED;
    }

    /** @return array{client_email: string, private_key: string, token_uri: string}|null */
    private function credentials(): ?array
    {
        $path = (string) config('services.firebase.credentials');

        // ⓘ বসানো হয়নি — ডেমো, পরীক্ষা, স্থানীয় যন্ত্র: চুপচাপ বন্ধ, কোনো ভুল নয়
        if ($path === '') {
            return null;
        }

        $json = is_file($path) && is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            // ⛔ সমন্বয়কের শর্ত ৪: ৫০০ নয়, চুপচাপ বাদ — ভুলের খাতায় দিনে একবার; ⛔ ফাইলের কিছুই বার্তায় নয়
            if (Cache::add('fcm.credentials_missing_logged', true, now()->addDay())) {
                report(new RuntimeException('FCM: FIREBASE_CREDENTIALS is set but the file is missing or unreadable.'));
            }

            return null;
        }

        return [
            'client_email' => (string) $json['client_email'],
            'private_key' => (string) $json['private_key'],
            'token_uri' => (string) ($json['token_uri'] ?? 'https://oauth2.googleapis.com/token'),
        ];
    }

    /** @param  array{client_email: string, private_key: string, token_uri: string}  $c */
    private function accessToken(array $c): string
    {
        return Cache::remember('fcm.access_token.'.sha1($c['client_email']), now()->addMinutes(50), function () use ($c): string {
            $now = time();
            $segments = [
                $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                $this->b64(json_encode([
                    'iss' => $c['client_email'], 'scope' => self::SCOPE, 'aud' => $c['token_uri'],
                    'iat' => $now, 'exp' => $now + 3600,
                ])),
            ];
            $signature = '';

            if (! openssl_sign(implode('.', $segments), $signature, $c['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('FCM: could not sign the token request.');
            }

            $response = Http::asForm()->timeout(15)->post($c['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', [...$segments, $this->b64($signature)]),
            ]);

            $token = (string) $response->json('access_token', '');

            if (! $response->successful() || $token === '') {
                throw new RuntimeException('FCM: Google refused the token request (HTTP '.$response->status().').');
            }

            return $token;
        });
    }

    private function b64(string|false $raw): string
    {
        return rtrim(strtr(base64_encode((string) $raw), '+/', '-_'), '=');
    }
}
