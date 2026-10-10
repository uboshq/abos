<?php

declare(strict_types=1);

namespace App\Core\Notifications;

use App\Core\Support\CompanyContext;
use App\Jobs\DeliverNotification;
use App\Models\Notification;
use App\Models\NotificationChannel;
use App\Models\NotificationDigest;
use App\Models\NotificationJob;
use App\Models\User;
use App\Notifications\NewsDigestByMail;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * ⭐ সারসংক্ষেপ চিঠি — দিনে বা সপ্তাহে একবার, ধরে রাখা সব সাধারণ খবর একসাথে (মালিকের স্পেক §১৪ "Daily/Weekly Digest";
 * বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ৩)।
 *
 * ── কীভাবে ───────────────────────────────────────────────────────────
 * যিনি "দিনে একবার" বা "সপ্তাহে একবার" বেছেছেন, তাঁর সাধারণ আর কম গুরুত্বের চিঠি সাথে সাথে যায় না — ধরে রাখা হয়
 * (`held`, [[DeliveryService::queueFor()]])। `abos:notifications-digest` প্রতি ঘণ্টায় দেখে: তাঁর নিজের সময় অঞ্চলে বেছে নেওয়া
 * ঘণ্টা (আর সাপ্তাহিকে দিন) পেরিয়েছে, আর এই দিনের/সপ্তাহের সারসংক্ষেপ এখনো যায়নি — তাহলে একটা চিঠি, সব শিরোনামসহ।
 *
 * ⛔ জরুরি আর উঁচু গুরুত্বের খবর কখনো ধরে রাখা হয় না। একই দিনের সারসংক্ষেপ একবারই (`notify_digest_once`)।
 * ⓘ কেউ আবার "সাথে সাথে"-তে ফিরলে ধরে রাখা চিঠিগুলো ছেড়ে দেওয়া হয়, হারায় না।
 */
final class DigestService
{
    public function __construct(private readonly PreferenceBook $prefs) {}

    /** @return array{sent: int, released: int, failed: int, waiting: int} */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $out = ['sent' => 0, 'released' => 0, 'failed' => 0, 'waiting' => 0];

        $holders = NotificationJob::query()->withoutGlobalScopes()
            ->where('status', NotificationJob::HELD)
            ->select(['company_id', 'user_id'])->distinct()->get();

        foreach ($holders as $holder) {
            CompanyContext::forCompany((int) $holder->company_id, function () use ($holder, $now, &$out): void {
                $result = $this->runFor((int) $holder->user_id, (int) $holder->company_id, $now);
                $out[$result]++;
            });
        }

        return $out;
    }

    /** @return 'sent'|'released'|'failed'|'waiting' */
    private function runFor(int $userId, int $companyId, CarbonImmutable $now): string
    {
        $this->prefs->forget();
        $pref = $this->prefs->for($userId, $companyId);
        $held = NotificationJob::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)->where('user_id', $userId)->where('status', NotificationJob::HELD);

        // ⓘ আবার "সাথে সাথে" বেছেছেন — ধরে রাখা চিঠিগুলো ছেড়ে দেওয়া
        if (! in_array($pref->frequency, ['daily', 'weekly'], true)) {
            foreach ((clone $held)->pluck('id') as $id) {
                NotificationJob::query()->withoutGlobalScopes()->whereKey($id)->update(['status' => NotificationJob::QUEUED, 'next_attempt_at' => null]);
                DeliverNotification::dispatch((int) $id);
            }

            return 'released';
        }

        $local = $now->setTimezone($pref->timezone ?: $this->prefs->zone($companyId));
        $hour = (int) $pref->digest_hour;

        $due = $pref->frequency === 'daily'
            ? $local->hour >= $hour
            : ($local->dayOfWeek > (int) $pref->digest_day || ($local->dayOfWeek === (int) $pref->digest_day && $local->hour >= $hour));

        if (! $due) {
            return 'waiting';
        }

        $key = $pref->frequency === 'daily' ? 'D'.$local->format('Y-m-d') : 'W'.$local->format('o-W');

        $digest = NotificationDigest::query()->withoutGlobalScopes()->firstOrNew(
            ['company_id' => $companyId, 'user_id' => $userId, 'period_key' => $key],
            ['period' => $pref->frequency, 'status' => 'failed'],
        );

        if ($digest->exists && $digest->status === 'sent') {
            // ⓘ এই দিনের সারসংক্ষেপ গেছে — পরে আসা খবর পরের দিনেরটায়
            return 'waiting';
        }

        $jobs = (clone $held)->orderBy('id')->limit(200)->get();
        $user = User::query()->withoutGlobalScope('company')->find($userId);
        $bells = Notification::query()->withoutGlobalScopes()->whereIn('id', $jobs->pluck('notification_id'))->orderBy('id')->get();

        if ($user === null || $bells->isEmpty()) {
            return 'waiting';
        }

        try {
            $user->notifyNow(new NewsDigestByMail($bells, $pref->frequency));
        } catch (Throwable $e) {
            report($e);
            $digest->forceFill(['period' => $pref->frequency, 'items' => $bells->count(), 'status' => 'failed', 'error' => DeliveryResult::clean(class_basename($e).': '.$e->getMessage())])->save();

            return 'failed';
        }

        $digest->forceFill(['period' => $pref->frequency, 'items' => $bells->count(), 'status' => 'sent', 'error' => null, 'sent_at' => now()])->save();

        NotificationJob::query()->withoutGlobalScopes()->whereIn('id', $jobs->pluck('id'))->update([
            'status' => NotificationJob::SENT, 'sent_at' => now(), 'digest_id' => $digest->id,
            'provider_ref' => 'digest:'.$digest->id, 'attempts' => 1,
        ]);

        return 'sent';
    }

    /** ⓘ এই মাধ্যম আর এই গুরুত্বে খবর সারসংক্ষেপে ধরা যায় কি না */
    public static function holds(string $channel, string $priority, string $frequency): bool
    {
        return $channel === NotificationChannel::EMAIL
            && in_array($priority, ['normal', 'low'], true)
            && in_array($frequency, ['daily', 'weekly'], true);
    }
}
