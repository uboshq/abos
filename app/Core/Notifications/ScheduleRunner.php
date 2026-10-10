<?php

declare(strict_types=1);

namespace App\Core\Notifications;

use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\NotificationSchedule;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * ⭐ সূচিমতো খবর পাঠানো — `abos:notifications-schedule`, প্রতি মিনিটে (মালিকের স্পেক §৪ "Schedule: Scheduled Send, Time Zone,
 * Recurrence"; ধাপ ৩)।
 *
 * ⓘ সময় হওয়া প্রতিটা সূচি: দলের আজকের সদস্যদের ([[RecipientResolver]]) প্রকাশিত টেমপ্লেটে খবর, idempotency চাবি
 * `schedule:{id}:{সময়}` — ক্রন দুইবার চললেও একবারই। তারপর পরের সময়: দৈনিক/সাপ্তাহিক/মাসিক, সূচির নিজের সময় অঞ্চলে
 * গুনে (তাই ঘড়ি যেমনই হোক "সকাল নয়টা" সকাল নয়টাই থাকে); একবারের সূচি পাঠানোর পরে বন্ধ।
 *
 * ⛔ খবর কেবল জানায় — কোনো কাগজ বদলায় না।
 */
final class ScheduleRunner
{
    public function __construct(
        private readonly NotificationService $notify,
        private readonly RecipientResolver $recipients,
    ) {}

    /** @return array{ran: int, sent: int} */
    public function run(): array
    {
        $out = ['ran' => 0, 'sent' => 0];

        $due = NotificationSchedule::query()->withoutGlobalScopes()
            ->where('is_active', true)->whereNotNull('next_run_at')->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')->limit(100)->get();

        foreach ($due as $schedule) {
            CompanyContext::forCompany((int) $schedule->company_id, function () use ($schedule, &$out): void {
                try {
                    $out['sent'] += $this->fire($schedule);
                    $out['ran']++;
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }

        return $out;
    }

    /** একটা সূচি একবার — পাঠানো খবরের সংখ্যা */
    public function fire(NotificationSchedule $schedule): int
    {
        $runAt = CarbonImmutable::instance($schedule->next_run_at ?? now());
        $version = $schedule->template?->is_active ? $schedule->template->published : null;
        $sent = 0;

        if ($version !== null && $schedule->group?->is_active) {
            $users = $this->recipients->resolve(['groups' => [$schedule->group_id]]);

            $sent = $this->notify->sendMany(
                $users,
                'notification.scheduled',
                (string) $version->part('title', (string) config('app.locale')),
                $version->part('body', (string) config('app.locale')),
                route('notifications.index'),
                priority: $schedule->priority,
                key: 'schedule:'.$schedule->id.':'.$runAt->utc()->format('YmdHi'),
                template: $version,
            )->count();
        }

        $next = self::next($runAt, (string) $schedule->recurrence, (string) $schedule->timezone);

        $schedule->forceFill([
            'last_run_at' => now(),
            'runs' => (int) $schedule->runs + 1,
            'next_run_at' => $next,
            'is_active' => $next !== null,
        ])->saveQuietly();

        return $sent;
    }

    /** পরের সময় — সূচির সময় অঞ্চলে দেয়ালের ঘড়ি ধরে; পেরিয়ে যাওয়া সময় ধরে ধরে নয়, এখনকার পরেরটা */
    public static function next(CarbonImmutable $from, string $recurrence, string $zone): ?CarbonImmutable
    {
        if ($recurrence === 'none') {
            return null;
        }

        $local = $from->setTimezone($zone);
        $now = CarbonImmutable::now();

        do {
            $local = match ($recurrence) {
                'daily' => $local->addDay(),
                'weekly' => $local->addWeek(),
                'monthly' => $local->addMonthNoOverflow(),
                default => $local->addDay(),
            };
        } while ($local->utc() <= $now);

        return $local->setTimezone(config('app.timezone'));
    }
}
