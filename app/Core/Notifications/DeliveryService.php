<?php

declare(strict_types=1);

namespace App\Core\Notifications;

use App\Core\Services\NotificationAudit;
use App\Core\Services\SettingsService;
use App\Core\Support\Actor;
use App\Core\Support\CompanyContext;
use App\Jobs\DeliverNotification;
use App\Models\Notification;
use App\Models\NotificationDeliveryAttempt;
use App\Models\NotificationEvent;
use App\Models\NotificationJob;
use App\Models\NotificationSuppression;
use App\Models\User;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * ⭐ খবর পৌঁছানো — কিউ, চেষ্টা, আবার চেষ্টা, ব্যর্থ-তালিকা (মালিকের স্পেক §১০, §১৪; বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ২)।
 *
 * ── এই কোডবেসে কিউ কীভাবে চলে ─────────────────────────────────────────
 * শেয়ার্ড cPanel-এ ডেমন নেই। ক্রন প্রতি মিনিটে `schedule:run` চালায়, আর সেটা `queue:work --stop-when-empty` চালায়
 * (Laravel-এর database কিউ) — তাই একটা খবর এক মিনিটের মধ্যে রওনা দেয় (স্পেক §১৫ "৬০ সেকেন্ডে শুরু")। আবার চেষ্টার সময় এলে
 * `abos:notifications-deliver` (প্রতি মিনিটে) সেই সারিগুলো আবার কিউয়ে দেয়, আর আটকে থাকা সারি ছাড়িয়ে আনে।
 *
 * ── ⛔ নিয়ম ───────────────────────────────────────────────────────────
 *   · সারি বসে লেনদেন পাকা হওয়ার পরে ([[NotificationService]]) — আর এখানকার কোনো ভুল উপরে যায় না: প্রোভাইডার বন্ধ থাকলেও
 *     ERP-র কাজ আর ঘণ্টা চলে (graceful degradation)।
 *   · একই খবর একজনের কাছে একই মাধ্যমে একবারই (`notify_job_once`); একটা চেষ্টা একবারই (সারি দখল করে তবে চেষ্টা)।
 *   · সাময়িক ভুল → আবার চেষ্টা, exponential backoff + jitter, প্রোভাইডার Retry-After বললে তার আগে নয়; সর্বোচ্চ সংখ্যা পেরোলে
 *     ব্যর্থ-তালিকা। স্থায়ী ভুল → সাথে সাথে ব্যর্থ-তালিকা, অন্ধ চেষ্টা নয়।
 *   · হাতে আবার চেষ্টা বা বাতিল — নিজের চাবিতে, আর নিরীক্ষার খাতায়।
 */
final class DeliveryService
{
    /** চলছে অবস্থায় এর বেশি থাকলে আটকে গেছে ধরা হয় */
    private const STUCK_MINUTES = 15;

    /** প্রথম আবার-চেষ্টার আগে কত সেকেন্ড; তারপর দ্বিগুণ হয় */
    private const BASE_SECONDS = 60;

    /** দুই চেষ্টার মাঝে সর্বোচ্চ ছয় ঘণ্টা */
    private const CAP_SECONDS = 21600;

    public function __construct(
        private readonly ChannelRegistry $channels,
        private readonly DeliveryRouter $router,
        private readonly PreferenceBook $prefs,
    ) {}

    /**
     * নতুন খবরের মাধ্যমগুলোর সারি বসানো আর কিউয়ে দেওয়া। ⛔ কখনো ব্যতিক্রম ছোড়ে না।
     */
    public function queueFor(Notification $bell, ?User $user = null): void
    {
        try {
            $user ??= User::query()->withoutGlobalScope('company')->find($bell->user_id);

            if ($user === null) {
                return;
            }

            $plan = $this->router->plan($bell, $user);

            foreach ($plan['suppressed'] as $key => $reason) {
                $this->suppress($bell, $key, $reason);
            }

            [$after, $quiet, $frequency] = $this->timing($bell, $user);

            // ⭐ একজনের কাছে দিনে সর্বোচ্চ কয়টা খবর বাইরের মাধ্যমে (স্পেক §১৪ "Frequency/Max Limit") — ⛔ জরুরি কখনো আটকায় না;
            //   ঘণ্টায় সব থাকে, আটকানোটা লেখা থাকে
            if ($plan['channels'] !== [] && $this->overLimit($bell, $user)) {
                foreach ($plan['channels'] as $key) {
                    $this->suppress($bell, $key, 'limit');
                }

                return;
            }

            foreach ($plan['channels'] as $key) {
                // ⭐ ধাপ ৩ — সারসংক্ষেপে ধরে রাখা, নয়তো নীরব সময় বা নিয়মের দেরি পর্যন্ত পিছানো
                $held = DigestService::holds($key, (string) $bell->priority, $frequency);
                $wait = $held ? null : collect([$after, $quiet])->filter()->max();

                $job = NotificationJob::query()->withoutGlobalScopes()->createOrFirst(
                    ['notification_id' => $bell->id, 'channel' => $key],
                    [
                        'company_id' => $bell->company_id,
                        'event_id' => $bell->event_id,
                        'user_id' => $user->id,
                        'status' => $held ? NotificationJob::HELD : NotificationJob::QUEUED,
                        'next_attempt_at' => $wait,
                        'max_attempts' => $this->maxAttempts(),
                        'provider' => $this->channels->get($key)?->provider($this->channels->config((int) $bell->company_id, $key)),
                    ],
                );

                if (! $job->wasRecentlyCreated) {
                    continue;
                }

                if ($held) {
                    $this->suppress($bell, $key, 'digest');
                } elseif ($quiet !== null && $wait == $quiet) {
                    $this->suppress($bell, $key, 'quiet_hours');
                } elseif ($wait === null) {
                    DeliverNotification::dispatch((int) $job->id);
                }
            }
        } catch (Throwable $e) {
            DeliveryResult::report($e, 'delivery');
        }
    }

    /**
     * ⭐ বাইরের মাধ্যমে কখন — নিয়মের দেরি, নীরব সময়ের শেষ (⛔ জরুরিতে নয়), আর মানুষটার ঘনত্ব (সাথে সাথে / দিনে / সপ্তাহে)।
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable, 2: string}
     */
    private function timing(Notification $bell, User $user): array
    {
        $company = (int) $bell->company_id;
        $deliverAfter = $bell->event_id === null ? null
            : NotificationEvent::query()->withoutGlobalScopes()->whereKey($bell->event_id)->value('deliver_after');
        $after = $deliverAfter === null ? null : CarbonImmutable::parse($deliverAfter);

        $pref = $this->prefs->for((int) $user->id, $company);
        $quiet = $bell->priority === 'critical' ? null : $pref->quietUntil(now(), $this->prefs->zone($company));
        $frequency = $bell->priority === 'critical' ? 'instant' : (string) ($pref->frequency ?: 'instant');

        return [$after !== null && $after->isFuture() ? $after : null, $quiet, $frequency];
    }

    /** আজ এই মানুষটার কাছে বাইরের মাধ্যমে কয়টা খবর গেছে — সীমা পেরোলে `true` (`notification.daily_limit`, ০ = সীমা নেই) */
    private function overLimit(Notification $bell, User $user): bool
    {
        $limit = max(0, (int) app(SettingsService::class)->get('notification.daily_limit', 0));

        if ($limit === 0 || $bell->priority === 'critical') {
            return false;
        }

        $today = NotificationJob::query()->withoutGlobalScopes()
            ->where('company_id', $bell->company_id)->where('user_id', $user->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->distinct()->count('notification_id');

        return $today >= $limit;
    }

    /** কোনো মাধ্যমে আটকানো বা পিছানো — কেন, লেখা থাকে (স্পেক §১৭ "Preference & Suppression") */
    private function suppress(Notification $bell, string $channel, string $reason): void
    {
        NotificationSuppression::query()->withoutGlobalScopes()->create([
            'company_id' => $bell->company_id, 'user_id' => $bell->user_id, 'event_id' => $bell->event_id,
            'type' => (string) $bell->type, 'channel' => $channel, 'reason' => $reason,
        ]);
    }

    /**
     * একটা চেষ্টা — সারিটা দখল করতে পারলে তবেই (দুইটা কর্মী একই সারি একসাথে নয়)।
     */
    public function attempt(int $jobId): void
    {
        $claimed = NotificationJob::query()->withoutGlobalScopes()
            ->whereKey($jobId)
            ->whereIn('status', [NotificationJob::QUEUED, NotificationJob::RETRYING])
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->update(['status' => NotificationJob::PROCESSING, 'claimed_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $job = NotificationJob::query()->withoutGlobalScopes()->findOrFail($jobId);

        CompanyContext::forCompany((int) $job->company_id, fn () => $this->run($job));
    }

    private function run(NotificationJob $job): void
    {
        $started = hrtime(true);
        $channel = $this->channels->get((string) $job->channel);
        $config = $this->channels->config((int) $job->company_id, (string) $job->channel);
        $bell = Notification::query()->withoutGlobalScopes()->find($job->notification_id);
        $user = User::query()->withoutGlobalScope('company')->find($job->user_id);

        // ⭐ ধাপ ৩ — মেয়াদ পেরোনো খবর আর বাইরে যায় না (নিয়মের "Expiry"); ঘণ্টায় তো আছেই
        $expires = $job->event_id === null ? null : NotificationEvent::query()->withoutGlobalScopes()->whereKey($job->event_id)->value('expires_at');

        if ($expires !== null && now()->greaterThan($expires)) {
            $job->forceFill(['status' => NotificationJob::CANCELLED, 'claimed_at' => null, 'next_attempt_at' => null, 'resolution' => 'expired'])->save();

            if ($bell !== null) {
                $this->suppress($bell, (string) $job->channel, 'expired');
            }

            return;
        }

        try {
            $result = match (true) {
                $channel === null => DeliveryResult::permanent('unknown channel'),
                $bell === null || $user === null => DeliveryResult::permanent('the notification or its recipient is gone'),
                default => $channel->send($bell, $user, $config),
            };
        } catch (Throwable $e) {
            DeliveryResult::report($e, 'delivery');
            $result = DeliveryResult::transient('adapter: '.class_basename($e));
        }

        $attempt = $job->attempts + 1;

        NotificationDeliveryAttempt::query()->create([
            'company_id' => $job->company_id,
            'job_id' => $job->id,
            'attempt' => $attempt,
            'channel' => $job->channel,
            'provider' => $job->provider,
            'outcome' => $result->outcome,
            'provider_ref' => $result->providerRef,
            'error' => $result->error,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);

        $update = ['attempts' => $attempt, 'claimed_at' => null];

        if ($result->ok()) {
            $update += ['status' => NotificationJob::SENT, 'sent_at' => now(), 'provider_ref' => $result->providerRef,
                'error_kind' => null, 'last_error' => null, 'next_attempt_at' => null];
        } elseif ($result->outcome === DeliveryResult::PERMANENT || $attempt >= $job->max_attempts) {
            $update += ['status' => NotificationJob::DEAD, 'dead_at' => now(), 'error_kind' => $result->outcome,
                'last_error' => $result->error, 'next_attempt_at' => null];
        } else {
            $update += ['status' => NotificationJob::RETRYING, 'error_kind' => $result->outcome, 'last_error' => $result->error,
                'next_attempt_at' => now()->addSeconds(self::backoff($attempt, $result->retryAfter))];
        }

        $job->forceFill($update)->save();
    }

    /**
     * ⭐ পরের চেষ্টার আগে কত সেকেন্ড — ৬০, ১২০, ২৪০ … ছয় ঘণ্টা পর্যন্ত, ±২০% jitter (সবাই একসাথে আবার না ঝাঁপায়);
     * প্রোভাইডার Retry-After বললে তার কম নয়।
     */
    public static function backoff(int $attempt, ?int $retryAfter = null, ?float $jitter = null): int
    {
        $base = min(self::CAP_SECONDS, self::BASE_SECONDS * (2 ** max(0, $attempt - 1)));
        $jitter ??= random_int(80, 120) / 100;

        return max((int) round($base * $jitter), (int) ($retryAfter ?? 0));
    }

    /**
     * ⭐ সময় হওয়া আবার-চেষ্টাগুলো কিউয়ে, আটকে থাকা সারি ছাড়ানো, আর হারানো সারি আবার পাঠানো — `abos:notifications-deliver`।
     *
     * @return array{retried: int, unstuck: int, requeued: int}
     */
    public function retryDue(): array
    {
        $unstuck = NotificationJob::query()->withoutGlobalScopes()
            ->where('status', NotificationJob::PROCESSING)
            ->where('claimed_at', '<', now()->subMinutes(self::STUCK_MINUTES))
            ->update(['status' => NotificationJob::RETRYING, 'claimed_at' => null, 'next_attempt_at' => now()]);

        $due = NotificationJob::query()->withoutGlobalScopes()
            ->where('status', NotificationJob::RETRYING)
            ->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')->limit(500)->pluck('id');

        // ⓘ কিউয়ে দেওয়া হয়েছিল, অথচ দশ মিনিটেও কেউ ধরেনি (কিউয়ের সারি হারিয়েছে) — আবার; আর নীরব সময় বা নিয়মের দেরিতে
        //   পিছানো সারির সময় হলো (ধাপ ৩)
        $lost = NotificationJob::query()->withoutGlobalScopes()
            ->where('status', NotificationJob::QUEUED)
            ->where(fn ($q) => $q->where(fn ($w) => $w->whereNull('next_attempt_at')->where('created_at', '<', now()->subMinutes(10)))
                ->orWhere('next_attempt_at', '<=', now()))
            ->limit(500)->pluck('id');

        foreach ($due->concat($lost) as $id) {
            DeliverNotification::dispatch((int) $id);
        }

        return ['retried' => $due->count(), 'unstuck' => $unstuck, 'requeued' => $lost->count()];
    }

    /** ⭐ ব্যর্থ বা বাতিল সারি হাতে আবার চেষ্টা — চেষ্টার সংখ্যা নতুন করে গোনা শুরু */
    public function retryByHand(NotificationJob $job): bool
    {
        if (! in_array($job->status, [NotificationJob::DEAD, NotificationJob::CANCELLED], true)) {
            return false;
        }

        $job->forceFill([
            'status' => NotificationJob::QUEUED, 'max_attempts' => $job->attempts + $this->maxAttempts(),
            'next_attempt_at' => null, 'dead_at' => null, 'resolved_by' => null, 'resolution' => null,
        ])->save();

        app(NotificationAudit::class)->record('delivery_retry', $job, 'done', ['channel' => $job->channel, 'attempts' => $job->attempts]);

        DeliverNotification::dispatch((int) $job->id);

        return true;
    }

    /** ⭐ অপেক্ষার বা ব্যর্থ সারি বাতিল — কারণসহ ("Resolution") */
    public function cancel(NotificationJob $job, string $resolution): bool
    {
        if (! in_array($job->status, [...NotificationJob::OPEN, NotificationJob::DEAD], true) || $job->status === NotificationJob::PROCESSING) {
            return false;
        }

        $job->forceFill([
            'status' => NotificationJob::CANCELLED, 'next_attempt_at' => null,
            'resolved_by' => Actor::userId(), 'resolution' => mb_substr(trim($resolution), 0, 255),
        ])->save();

        app(NotificationAudit::class)->record('delivery_cancel', $job, 'done', ['channel' => $job->channel]);

        return true;
    }

    private function maxAttempts(): int
    {
        return max(1, min(10, (int) app(SettingsService::class)->get('notification.max_attempts', 5)));
    }
}
