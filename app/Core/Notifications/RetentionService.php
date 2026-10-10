<?php

declare(strict_types=1);

namespace App\Core\Notifications;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notification;
use App\Models\NotificationDeliveryAttempt;
use App\Models\NotificationDigest;
use App\Models\NotificationEvent;
use App\Models\NotificationJob;
use App\Models\NotificationSuppression;

/**
 * ⭐ আর্কাইভ আর রাখার মেয়াদ — প্রতি ঘণ্টায়, বারবার চালালেও একই ফল (মালিকের স্পেক §৪ "Archive: Retention", §১১, §১৩; ধাপ ৪)।
 *
 * ── কী করে ───────────────────────────────────────────────────────────
 *   · আর্কাইভ (`notification.archive_after_days`, ডিফল্ট ৯০; ০ = কখনো নয়): এত দিনের পুরনো **পড়া** খবর প্রাপকের ঘণ্টা থেকে
 *     আর্কাইভে; যে ঘটনার সব প্রাপকের খবর আর্কাইভে, সেই ঘটনাও। ⓘ না-পড়া খবর কখনো নিজে থেকে আর্কাইভ হয় না।
 *   · মুছে ফেলা (`notification.retention_days`, ডিফল্ট ০ = কিছুই মোছা হয় না; বসালে কমপক্ষে ৯০): এত দিনের পুরনো চেষ্টার লগ,
 *     আটকানোর খাতা, সারসংক্ষেপ, শেষ হওয়া ডেলিভারি, আর আর্কাইভ করা খবর ও খালি হয়ে যাওয়া ঘটনা।
 *   · ⛔ নিরীক্ষার খাতা (`notification_audit_logs`) কখনো মোছা হয় না — ওটা নিরীক্ষা, ওটার মেয়াদ এখানে নয়।
 *   · ⛔ চলমান ডেলিভারি (অপেক্ষায়, চলছে, আবার চেষ্টা, ধরে রাখা) কখনো মোছা হয় না।
 *
 * ⓘ বড় কোম্পানিতে এক টানে লাখ সারি নয় — প্রতিবার সীমিত সংখ্যায়, বাকিটা পরের বার (বারবার চালালে একই জায়গায় পৌঁছায়)।
 */
final class RetentionService
{
    /** এক ধাপে সর্বোচ্চ কত সারি */
    private const BATCH = 5000;

    /** মোছার মেয়াদ বসালে কমপক্ষে এত দিন */
    public const MIN_RETENTION_DAYS = 90;

    /** @return array{archived: int, events: int, purged: int} */
    public function run(): array
    {
        $out = ['archived' => 0, 'events' => 0, 'purged' => 0];

        foreach (Company::query()->pluck('id') as $companyId) {
            CompanyContext::forCompany((int) $companyId, function () use ($companyId, &$out): void {
                $done = $this->runFor((int) $companyId);

                foreach ($done as $k => $v) {
                    $out[$k] += $v;
                }
            });
        }

        return $out;
    }

    /** @return array{archived: int, events: int, purged: int} */
    public function runFor(int $companyId): array
    {
        $settings = app(SettingsService::class);
        $archiveDays = max(0, (int) $settings->get('notification.archive_after_days', 90));
        $retentionDays = max(0, (int) $settings->get('notification.retention_days', 0));
        $out = ['archived' => 0, 'events' => 0, 'purged' => 0];

        if ($archiveDays > 0) {
            $cutoff = now()->subDays($archiveDays);

            $ids = Notification::query()->withoutGlobalScopes()->where('company_id', $companyId)
                ->whereNull('archived_at')->whereNotNull('read_at')->where('created_at', '<', $cutoff)
                ->orderBy('id')->limit(self::BATCH)->pluck('id');
            $out['archived'] = $ids->isEmpty() ? 0 : Notification::query()->withoutGlobalScopes()->whereIn('id', $ids)->update(['archived_at' => now()]);

            $out['events'] = NotificationEvent::query()->withoutGlobalScopes()->where('company_id', $companyId)
                ->whereNull('archived_at')->where('created_at', '<', $cutoff)
                ->whereNotExists(fn ($q) => $q->from('notifications')->whereColumn('notifications.event_id', 'notification_events.id')
                    ->whereNull('notifications.archived_at'))
                ->limit(self::BATCH)->update(['archived_at' => now()]);
        }

        if ($retentionDays > 0) {
            $cutoff = now()->subDays(max(self::MIN_RETENTION_DAYS, $retentionDays));
            $purge = fn ($query) => $query->limit(self::BATCH)->delete();

            $out['purged'] += $purge(NotificationDeliveryAttempt::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('created_at', '<', $cutoff));
            $out['purged'] += $purge(NotificationSuppression::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('created_at', '<', $cutoff));
            $out['purged'] += $purge(NotificationJob::query()->withoutGlobalScopes()->where('company_id', $companyId)
                ->whereIn('status', [NotificationJob::SENT, NotificationJob::DEAD, NotificationJob::CANCELLED])->where('updated_at', '<', $cutoff));
            $out['purged'] += $purge(NotificationDigest::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('created_at', '<', $cutoff)
                ->whereNotExists(fn ($q) => $q->from('notification_jobs')->whereColumn('notification_jobs.digest_id', 'notification_digests.id')));
            $out['purged'] += $purge(Notification::query()->withoutGlobalScopes()->where('company_id', $companyId)
                ->whereNotNull('archived_at')->where('created_at', '<', $cutoff)
                ->whereNotExists(fn ($q) => $q->from('notification_jobs')->whereColumn('notification_jobs.notification_id', 'notifications.id')));
            $out['purged'] += $purge(NotificationEvent::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('created_at', '<', $cutoff)
                ->whereNotExists(fn ($q) => $q->from('notifications')->whereColumn('notifications.event_id', 'notification_events.id')));
        }

        return $out;
    }
}
