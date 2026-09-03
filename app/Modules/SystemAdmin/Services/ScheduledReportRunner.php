<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Services;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Engines\Report\ReportExport;
use App\Core\Engines\Report\ReportResult;
use App\Core\Services\ListExport;
use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * সূচিমতো রিপোর্ট চালানো — কিন্তু সবসময় একজন মানুষের অনুমতিতে।
 *
 * ── কেন এটাই এই ফিচারের সবচেয়ে বিপজ্জনক অংশ ─────────────────────────
 * ক্রনের নিজের কোনো ব্যবহারকারী নেই। কিছু না ভেবে চালালে যে ক্রয়মূল্য/মুনাফা
 * কেউ পর্দায় দেখতে পান না, সেটাই তাঁর ফাইলে চলে যেত। তাই প্রতিটা সূচি
 * চলে তার নির্মাতার (`created_by`) পরিচয়ে — কলাম-দৃশ্যমানতা আর সারি-স্কোপ
 * দুইটাই তাঁর প্রসঙ্গ থেকে আসে।
 *
 * ⚠️ আর প্রতিটা সূচির পর পরিচয় **অবশ্যই** পরিষ্কার (`finally`) — নাহলে একই
 * প্রসেসে দশম রিপোর্ট প্রথম জনের পরিচয়ে রেন্ডার হয়ে যেত, যা ঠিক সেই ফাঁস
 * যেটা বন্ধ করাই এই কোডের কাজ।
 */
final class ScheduledReportRunner
{
    /** এক ফাইলে সর্বোচ্চ সারি — এখন এক পাতায় টানা হয়। */
    private const MAX_ROWS = 100000;

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly ScheduleService $schedules,
        private readonly NotificationService $notify,
    ) {}

    /** যেসব সূচির সময় হয়েছে, সব চালানো — একটার ব্যর্থতা বাকিগুলো থামায় না। */
    public function runDue(): int
    {
        $count = 0;

        foreach (ReportSchedule::query()->due()->get() as $schedule) {
            try {
                $this->runOne($schedule);
            } catch (Throwable $e) {
                report($e);
                $this->schedules->markRan($schedule, 'error');
            }

            $count++;
        }

        return $count;
    }

    public function runOne(ReportSchedule $schedule): ?ReportRun
    {
        $owner = User::query()->withoutGlobalScope('company')->withTrashed()->find($schedule->created_by);

        // পাহারা ১: মালিক চলে গেছেন বা নিষ্ক্রিয় → পাঠাবে না, সূচি নিষ্ক্রিয়
        if ($owner === null || $owner->trashed() || $owner->is_active === false) {
            $this->schedules->deactivate($schedule);
            $schedule->forceFill(['last_status' => 'owner_gone'])->save();

            return null;
        }

        // পাহারা ২: রিপোর্টটাই আর নেই
        if (! in_array($schedule->report_key, $this->reports->keys(), true)) {
            $this->schedules->markRan($schedule, 'unknown_report');

            return null;
        }

        // পাহারা ৩: মালিক ওই রিপোর্টের অনুমতি হারিয়েছেন
        $permission = $this->reports->get($schedule->report_key)->permission;

        if ($permission !== null && ! $owner->can($permission)) {
            $this->schedules->markRan($schedule, 'owner_no_permission');

            return null;
        }

        $run = $this->generate($schedule, $owner);

        $this->schedules->markRan($schedule, $run->status);
        $this->notifyReady($schedule, $run, $owner);

        return $run;
    }

    /**
     * মালিকের পরিচয়ে রিপোর্ট চালিয়ে ফাইল বানানো — শেষে পরিচয় পরিষ্কার।
     */
    private function generate(ReportSchedule $schedule, User $owner): ReportRun
    {
        $previous = Auth::user();
        Auth::login($owner);

        try {
            return CompanyContext::forCompany($schedule->company_id, function () use ($schedule, $owner): ReportRun {
                CompanyContext::set($schedule->company_id, $this->ownerBranch($owner, $schedule->company_id));

                $result = $this->reports->run($schedule->report_key, $schedule->filters ?? [], 1, self::MAX_ROWS);

                $export = new ListExport;
                ReportExport::into($export, $result, $this->mutuallyVisibleColumns($result, $owner, $schedule));

                $bytes = match ($schedule->format) {
                    'xlsx' => $export->xlsx(),
                    'json' => $export->json(),
                    default => $export->csv(),
                };

                // কারা এই ফাইলটা নামাতে পারবেন — এখনকার ছবি (মালিক + প্রাপক)
                $snapshot = collect([$owner->id])
                    ->concat((array) $schedule->recipients)
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                return $this->store($schedule, $bytes, $export->rowCount(), $snapshot);
            });
        } finally {
            // ⚠️ পরের সূচি যেন এঁর পরিচয়ে না চলে
            Auth::logout();

            if ($previous !== null) {
                Auth::login($previous);
            }

            CompanyContext::clear();
        }
    }

    /**
     * যে কলামগুলো মালিক **আর** প্রতিটা প্রাপক — সবাই দেখতে পান।
     *
     * অনুমতি বলে কে কাগজটা পাবে; এই intersection বলে কাগজে কী থাকবে। একজন
     * প্রাপকের যদি ক্রয়মূল্য দেখার অনুমতি না থাকে, কলামটা ফাইলেই বসে না —
     * তাই কেউ পর্দায় না-দেখা সংখ্যা ইমেইলে পান না।
     *
     * @return list<\App\Core\Engines\Report\ReportColumn>
     */
    private function mutuallyVisibleColumns(ReportResult $result, User $owner, ReportSchedule $schedule): array
    {
        $columns = $result->columnsFor($owner);

        foreach ($schedule->recipientUsers() as $recipient) {
            $visible = array_map(fn ($c) => $c->key, $result->columnsFor($recipient));
            $columns = array_values(array_filter($columns, fn ($c) => in_array($c->key, $visible, true)));
        }

        return $columns;
    }

    /**
     * @param  list<int>  $recipients  কারা নামাতে পারবেন — রেন্ডারের ছবি
     */
    private function store(ReportSchedule $schedule, ?string $bytes, int $rowCount, array $recipients): ReportRun
    {
        $status = $bytes === null ? 'error' : ($rowCount === 0 ? 'empty' : 'ok');

        $run = ReportRun::create([
            'company_id' => $schedule->company_id,
            'report_schedule_id' => $schedule->id,
            'format' => $schedule->format,
            'recipients' => $recipients,
            'row_count' => $rowCount,
            'status' => $status,
            'ran_at' => now(),
        ]);

        if ($bytes !== null) {
            // private ডিস্ক — download-রুট ছাড়া নামানো যায় না
            $path = 'report-runs/'.$schedule->public_id.'/'.$run->public_id.'.'.$schedule->format;
            Storage::disk('local')->put($path, $bytes);
            $run->forceFill(['file_path' => $path])->save();
        }

        return $run;
    }

    /**
     * খবর মালিক ও প্রাপকদের — নিজ নিজ কোম্পানি-প্রসঙ্গে।
     *
     * পরিচয় ইতিমধ্যে পরিষ্কার (generate-এর finally), তাই এখানে auth নেই —
     * ফলে NotificationService মালিককেও পায় (নিজের খবর নিজে skip করার নিয়মটা
     * এখানে বাধা দেয় না)।
     */
    private function notifyReady(ReportSchedule $schedule, ReportRun $run, User $owner): void
    {
        if (! $run->hasFile()) {
            return;
        }

        CompanyContext::forCompany($schedule->company_id, function () use ($schedule, $run, $owner): void {
            $url = Route::has('system_admin.reports.download')
                ? route('system_admin.reports.download', $run)
                : null;

            $title = __('system_admin::schedule.notify.title');
            $body = __('system_admin::schedule.notify.body', [
                'report' => $this->reports->get($schedule->report_key)->title,
            ]);

            $recipients = collect([$owner])->concat($schedule->recipientUsers())->unique('id');

            foreach ($recipients as $user) {
                $this->notify->send($user, 'report_ready', $title, $body, $url);
            }
        });
    }

    /**
     * পুরনো ফাইল সরানো — নাহলে ছয় মাসে private ডিস্কে হাজারটা রিপোর্ট,
     * প্রতিটাতে কারো না কারো ক্রয়মূল্য।
     *
     * সব কোম্পানি জুড়ে (ক্রনের প্রসঙ্গ নেই), তাই company scope সরানো।
     * ফাইল ও সারি দুইটাই যায়; ReportRun soft-delete নয় বলে সারিটা সত্যিই মোছে।
     *
     * @return int কতগুলো রান মুছল
     */
    public function prune(int $days): int
    {
        $cutoff = now()->subDays(max(1, $days));
        $count = 0;

        ReportRun::query()
            ->withoutGlobalScope('company')
            ->where('ran_at', '<', $cutoff)
            ->chunkById(200, function ($runs) use (&$count): void {
                foreach ($runs as $run) {
                    if ($run->file_path !== null && Storage::disk('local')->exists($run->file_path)) {
                        Storage::disk('local')->delete($run->file_path);
                    }

                    $run->delete();
                    $count++;
                }
            });

        return $count;
    }

    private function ownerBranch(User $owner, int $companyId): ?int
    {
        $branch = $owner->companies()->whereKey($companyId)->first()?->pivot->default_branch_id;

        return $branch === null ? null : (int) $branch;
    }
}
