<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Services;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\ReportSchedule;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * নির্ধারিত রিপোর্টের সূচি তৈরি ও পরের-সময় গোনা।
 *
 * ── কেন অনুমতিটা এখানেই বসে, নাহলে পরে বসে না ────────────────────────
 * ক্রন নিজে রিপোর্ট চালায়, কোনো কন্ট্রোলার নেই। তাই "এই রিপোর্টটা কে পেতে
 * পারেন" প্রশ্নটা সূচি বানানোর সময়েই বাঁধতে হয়: রিপোর্টের ঘোষিত অনুমতি
 * (ReportDefinition::permission) না থাকলে সূচি কেবল নিজের নির্মাতাকে পাঠায়,
 * থাকলে প্রতিটা প্রাপকের সেই অনুমতি যাচাই করে। একজনের বানানো সূচি যেন
 * অনুমতিহীন দশজনের ইমেইলে ক্রয়মূল্য না পাঠায়।
 */
final class ScheduleService
{
    /** @var list<string> */
    private const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    /** @var list<string> */
    private const FORMATS = ['csv', 'xlsx', 'json', 'pdf'];

    public function __construct(private readonly ReportEngine $reports) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ReportSchedule
    {
        return DB::transaction(function () use ($data) {
            $this->assertValid($data);

            $schedule = new ReportSchedule([
                'company_id' => CompanyContext::id(),
                'report_key' => $data['report_key'],
                'filters' => $data['filters'] ?? [],
                'format' => $data['format'] ?? 'xlsx',
                'frequency' => $data['frequency'],
                'at_time' => $this->cleanTime($data['at_time'] ?? '08:00'),
                'day_of_week' => $data['frequency'] === 'weekly' ? (int) ($data['day_of_week'] ?? 0) : null,
                'day_of_month' => $data['frequency'] === 'monthly' && ! ($data['on_month_end'] ?? false)
                    ? (int) ($data['day_of_month'] ?? 1) : null,
                'on_month_end' => $data['frequency'] === 'monthly' && (bool) ($data['on_month_end'] ?? false),
                'timezone' => $data['timezone'] ?? config('app.timezone'),
                'recipients' => $this->cleanRecipients($data['recipients'] ?? []),
                'created_by' => auth()->id(),
                'is_active' => true,
                'last_status' => null,
            ]);

            $schedule->next_run_at = $this->nextRunFor($schedule);
            $schedule->save();

            return $schedule;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ReportSchedule $schedule, array $data): ReportSchedule
    {
        return DB::transaction(function () use ($schedule, $data) {
            $this->assertValid($data);

            $schedule->fill([
                'report_key' => $data['report_key'],
                'filters' => $data['filters'] ?? [],
                'format' => $data['format'] ?? 'xlsx',
                'frequency' => $data['frequency'],
                'at_time' => $this->cleanTime($data['at_time'] ?? '08:00'),
                'day_of_week' => $data['frequency'] === 'weekly' ? (int) ($data['day_of_week'] ?? 0) : null,
                'day_of_month' => $data['frequency'] === 'monthly' && ! ($data['on_month_end'] ?? false)
                    ? (int) ($data['day_of_month'] ?? 1) : null,
                'on_month_end' => $data['frequency'] === 'monthly' && (bool) ($data['on_month_end'] ?? false),
                'timezone' => $data['timezone'] ?? $schedule->timezone,
                'recipients' => $this->cleanRecipients($data['recipients'] ?? []),
            ]);

            $schedule->next_run_at = $this->nextRunFor($schedule);
            $schedule->save();

            return $schedule->fresh();
        });
    }

    /** নিষ্ক্রিয় করা — মোছা নয়; ক্রন আর তুলবে না। */
    public function deactivate(ReportSchedule $schedule): ReportSchedule
    {
        $schedule->forceFill(['is_active' => false])->save();

        return $schedule->fresh();
    }

    public function activate(ReportSchedule $schedule): ReportSchedule
    {
        $schedule->forceFill([
            'is_active' => true,
            'next_run_at' => $this->nextRunFor($schedule),
        ])->save();

        return $schedule->fresh();
    }

    /**
     * চালানোর পর — শেষ অবস্থা লেখা, আর পরের সময় গোনা।
     */
    public function markRan(ReportSchedule $schedule, string $status): void
    {
        $schedule->forceFill([
            'last_run_at' => now(),
            'last_status' => mb_substr($status, 0, 32),
            'next_run_at' => $this->nextRunFor($schedule, now()),
        ])->save();
    }

    /**
     * পরের চালানোর সময় — সূচির timezone-এ গোনা, UTC-তে ফেরত।
     *
     * "সকাল ৮টা" সূচির ঘড়িতে; জমা হয় UTC-তে, কারণ ক্রন UTC-তেই তুলনা
     * করে। কড়া-ভবিষ্যৎ: এখন বা অতীত হলে পরের পর্বে ঠেলা হয়।
     */
    public function nextRunFor(ReportSchedule $schedule, ?Carbon $from = null): Carbon
    {
        $tz = $schedule->timezone ?: config('app.timezone');
        $now = ($from ? $from->copy() : now())->setTimezone($tz);

        [$hour, $minute] = $this->hourMinute((string) $schedule->at_time);

        $candidate = match ($schedule->frequency) {
            'weekly' => $this->nextWeekly($now, (int) $schedule->day_of_week, $hour, $minute),
            'monthly' => $this->nextMonthly($now, $schedule, $hour, $minute),
            default => $this->nextDaily($now, $hour, $minute),
        };

        return $candidate->setTimezone('UTC');
    }

    private function nextDaily(Carbon $now, int $hour, int $minute): Carbon
    {
        $candidate = $now->copy()->setTime($hour, $minute);

        return $candidate->lessThanOrEqualTo($now) ? $candidate->addDay() : $candidate;
    }

    private function nextWeekly(Carbon $now, int $dayOfWeek, int $hour, int $minute): Carbon
    {
        $candidate = $now->copy()->setTime($hour, $minute);

        // সর্বোচ্চ ৭ ধাপ — সঠিক বার আর কড়া-ভবিষ্যৎ, দুইটাই
        for ($i = 0; $i < 8; $i++) {
            if ($candidate->dayOfWeek === $dayOfWeek && $candidate->greaterThan($now)) {
                return $candidate;
            }

            $candidate = $candidate->addDay()->setTime($hour, $minute);
        }

        return $candidate;
    }

    /**
     * মাসিক — নির্দিষ্ট তারিখ বা মাসের শেষ দিন।
     *
     * ৩১ ফেব্রুয়ারিতে নেই; তাই মাসে যত দিন আছে তার বেশি চাইলে শেষ দিনেই
     * বসে, আর `on_month_end` সরাসরি শেষ দিন বলে।
     */
    private function nextMonthly(Carbon $now, ReportSchedule $schedule, int $hour, int $minute): Carbon
    {
        $candidate = $this->monthlyCandidate($now->copy(), $schedule, $hour, $minute);

        if ($candidate->greaterThan($now)) {
            return $candidate;
        }

        return $this->monthlyCandidate($now->copy()->addMonthNoOverflow()->startOfMonth(), $schedule, $hour, $minute);
    }

    private function monthlyCandidate(Carbon $month, ReportSchedule $schedule, int $hour, int $minute): Carbon
    {
        $day = $schedule->on_month_end
            ? $month->copy()->endOfMonth()->day
            : min((int) $schedule->day_of_month, $month->daysInMonth);

        return $month->copy()->day($day)->setTime($hour, $minute);
    }

    /**
     * ★ সূচি বৈধ কি না — বিশেষ করে অনুমতি।
     *
     * @param  array<string, mixed>  $data
     */
    private function assertValid(array $data): void
    {
        $key = (string) ($data['report_key'] ?? '');

        if (! in_array($key, $this->reports->keys(), true)) {
            throw ValidationException::withMessages([
                'report_key' => __('system_admin::schedule.unknown_report'),
            ]);
        }

        if (! in_array($data['frequency'] ?? '', self::FREQUENCIES, true)) {
            throw ValidationException::withMessages([
                'frequency' => __('system_admin::schedule.bad_frequency'),
            ]);
        }

        if (! in_array($data['format'] ?? 'xlsx', self::FORMATS, true)) {
            throw ValidationException::withMessages([
                'format' => __('system_admin::schedule.bad_format'),
            ]);
        }

        $this->assertRecipientsAllowed($key, $this->cleanRecipients($data['recipients'] ?? []));
    }

    /**
     * ★★ কে এই রিপোর্ট পেতে পারেন — A1-এর নিয়ম, নকশাতেই।
     *
     * রিপোর্টের ঘোষিত অনুমতি না থাকলে (null = "জানি না") সূচি কেবল নিজের
     * নির্মাতাকে পাঠাতে পারে — বাইরের কেউ নয়। থাকলে প্রতিটা প্রাপককে
     * অভ্যন্তরীণ ব্যবহারকারী হতে হবে ও সেই অনুমতি থাকতে হবে।
     *
     * @param  list<int>  $recipientIds
     */
    private function assertRecipientsAllowed(string $reportKey, array $recipientIds): void
    {
        $permission = $this->reports->get($reportKey)->permission;
        $selfId = (int) auth()->id();

        $others = array_values(array_filter($recipientIds, fn (int $id): bool => $id !== $selfId));

        if ($permission === null) {
            if ($others !== []) {
                throw ValidationException::withMessages([
                    'recipients' => __('system_admin::schedule.report_not_shareable'),
                ]);
            }

            return;
        }

        // নির্মাতার নিজেরও অনুমতি লাগে
        if (auth()->user() === null || ! auth()->user()->can($permission)) {
            throw ValidationException::withMessages([
                'report_key' => __('system_admin::schedule.you_cannot_see_this'),
            ]);
        }

        foreach ($others as $id) {
            $user = User::query()->find($id);

            if ($user === null || ! $user->can($permission)) {
                throw ValidationException::withMessages([
                    'recipients' => __('system_admin::schedule.recipient_cannot_see', [
                        'name' => $user?->name ?? (string) $id,
                    ]),
                ]);
            }
        }
    }

    /**
     * প্রাপক — কেবল সংখ্যা (user id), অনন্য, খালি বাদ।
     *
     * @param  mixed  $raw
     * @return list<int>
     */
    private function cleanRecipients(mixed $raw): array
    {
        $ids = array_map('intval', array_filter((array) $raw, static fn ($v): bool => is_numeric($v)));

        return array_values(array_unique($ids));
    }

    private function cleanTime(string $time): string
    {
        [$h, $m] = $this->hourMinute($time);

        return sprintf('%02d:%02d', $h, $m);
    }

    /** @return array{0: int, 1: int} */
    private function hourMinute(string $time): array
    {
        $parts = explode(':', $time);
        $hour = max(0, min(23, (int) ($parts[0] ?? 0)));
        $minute = max(0, min(59, (int) ($parts[1] ?? 0)));

        return [$hour, $minute];
    }
}
