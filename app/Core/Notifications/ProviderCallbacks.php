<?php

declare(strict_types=1);

namespace App\Core\Notifications;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\NotificationChannel;
use App\Models\NotificationJob;
use App\Models\NotificationProviderEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * ⭐ প্রোভাইডারের ফেরত-খবর — স্বাক্ষর যাচাই, তারপর রসিদ বা bounce ডেলিভারির সারিতে (মালিকের স্পেক §৭, §১১, §১৩;
 * বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ২-এর অনুসরণ)।
 *
 * ── চুক্তি (প্রোভাইডার-নিরপেক্ষ) ────────────────────────────────────────
 *   POST /api/v1/notification-callbacks/{কোম্পানির public_id}/{মাধ্যম}
 *   X-ABOS-Timestamp: ইউনিক্স সেকেন্ড (৫ মিনিটের বেশি পুরনো বা সামনের নয়)
 *   X-ABOS-Signature: sha256=hex(HMAC-SHA256(timestamp + "." + body, মাধ্যমের webhook_secret))
 *   body: {"events": [{"provider_ref": "...", "event": "delivered|bounced|complained|failed", "reason": "...", "at": "ISO-8601"}]}
 * ⓘ আসল প্রোভাইডার (SES, Mailgun, SMS গেটওয়ে) বাছা হলে তার নিজের ছাঁচ এই চুক্তিতে বদলানোর ছোট একটা সংযোজক লাগবে।
 *
 * ── ⛔ নিয়ম ───────────────────────────────────────────────────────────
 *   · স্বাক্ষর না মিললে, গোপন চাবি বসানো না থাকলে, বা সময় পুরনো হলে — কিছুই বসে না (৪০১)।
 *   · একই ফেরত-খবর দুইবার এলে একবারই (`notify_provider_once`)।
 *   · bounce/অভিযোগ/ব্যর্থ → সারিটা ব্যর্থ-তালিকায়, স্থায়ী ভুল হিসেবে (আর অন্ধ চেষ্টা নয়); রসিদ → `delivered_at`।
 *   · কারণের লেখা ছেঁটে রাখা ([[DeliveryResult::clean()]]) — ঠিকানা বা টোকেন নয়।
 */
final class ProviderCallbacks
{
    /** স্বাক্ষরের সময় কত সেকেন্ড পর্যন্ত মানা হয় */
    public const WINDOW_SECONDS = 300;

    /** এক অনুরোধে সর্বোচ্চ কয়টা ঘটনা */
    public const MAX_EVENTS = 100;

    /**
     * @return array{status: int, accepted: int}
     */
    public function receive(string $companyPublicId, string $channel, string $body, ?string $timestamp, ?string $signature): array
    {
        if (! in_array($channel, NotificationChannel::ALL, true)) {
            return ['status' => 404, 'accepted' => 0];
        }

        $company = Company::query()->where('public_id', $companyPublicId)->first();

        if ($company === null) {
            return ['status' => 404, 'accepted' => 0];
        }

        return CompanyContext::forCompany((int) $company->id, function () use ($company, $channel, $body, $timestamp, $signature): array {
            $config = app(ChannelRegistry::class)->config((int) $company->id, $channel);
            $secret = $config?->secret('webhook_secret');

            if (! $this->signed($secret, $body, $timestamp, $signature)) {
                return ['status' => 401, 'accepted' => 0];
            }

            $payload = json_decode($body, true);
            $events = is_array($payload) && is_array($payload['events'] ?? null) ? array_slice($payload['events'], 0, self::MAX_EVENTS) : null;

            if ($events === null) {
                return ['status' => 422, 'accepted' => 0];
            }

            $accepted = 0;

            foreach ($events as $event) {
                $accepted += $this->apply((int) $company->id, $channel, (string) ($config?->provider ?? ''), is_array($event) ? $event : []) ? 1 : 0;
            }

            return ['status' => 200, 'accepted' => $accepted];
        });
    }

    /** স্বাক্ষর আর সময় — গোপন চাবি না থাকলে কখনো মেলে না */
    public function signed(?string $secret, string $body, ?string $timestamp, ?string $signature): bool
    {
        if ($secret === null || $timestamp === null || $signature === null || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::WINDOW_SECONDS) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return hash_equals($expected, trim($signature));
    }

    /** @param  array<string, mixed>  $event */
    private function apply(int $companyId, string $channel, string $provider, array $event): bool
    {
        $ref = trim((string) ($event['provider_ref'] ?? ''));
        $kind = (string) ($event['event'] ?? '');

        if ($ref === '' || mb_strlen($ref) > 191 || ! in_array($kind, NotificationProviderEvent::EVENTS, true)) {
            return false;
        }

        $at = isset($event['at']) && is_string($event['at']) && strtotime($event['at']) !== false ? Carbon::parse($event['at']) : now();
        $reason = isset($event['reason']) && is_string($event['reason']) ? DeliveryResult::clean($event['reason']) : null;
        $job = NotificationJob::query()->where('channel', $channel)->where('provider_ref', $ref)->first();

        try {
            NotificationProviderEvent::query()->create([
                'company_id' => $companyId, 'job_id' => $job?->id, 'channel' => $channel, 'provider' => $provider ?: null,
                'event' => $kind, 'provider_ref' => $ref, 'reason' => $reason, 'occurred_at' => $at,
                'payload_hash' => hash('sha256', $channel.'|'.$ref.'|'.$kind.'|'.$at->toIso8601String()),
            ]);
        } catch (UniqueConstraintViolationException) {
            // ⓘ একই ফেরত-খবর আগেই এসেছে
            return false;
        }

        if ($job === null) {
            return true;
        }

        if ($kind === 'delivered') {
            $job->forceFill(['delivered_at' => $at, 'receipt' => 'delivered'])->save();

            return true;
        }

        $job->forceFill([
            'status' => NotificationJob::DEAD, 'receipt' => $kind, 'error_kind' => DeliveryResult::PERMANENT,
            'last_error' => mb_substr($kind.($reason ? ': '.$reason : ''), 0, 255), 'dead_at' => now(), 'next_attempt_at' => null,
        ])->save();

        return true;
    }
}
