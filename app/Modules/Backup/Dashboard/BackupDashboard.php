<?php

declare(strict_types=1);

namespace App\Modules\Backup\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\DateRange;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Series;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Services\BackupService;
use App\Modules\Backup\Models\BackupDestination;
use App\Modules\Backup\Models\BackupPolicy;
use App\Modules\Backup\Models\BackupRun;
use Illuminate\Support\Carbon;

/**
 * ব্যাকআপের ড্যাশবোর্ড — চারটা সংখ্যা, আর প্রতিটাই একটা প্রশ্নের উত্তর।
 *
 * ── কোন প্রশ্নগুলো, আর কেন এই ক্রমে ───────────────────────────────────
 *
 *   ১. শেষ ব্যাকআপ কবে?        "কিছু হারালে কতটা ফেরত পাব"
 *   ২. কয়টা জায়গায় কপি আছে?   "মেশিনটা গেলে কী বাঁচবে"
 *   ৩. শেষ যাচাই কবে?          "কপিটা আদৌ ফেরে কি না"
 *   ৪. শেষ ব্যর্থতা কবে?       "কিছু চুপচাপ ভেঙে আছে কি"
 *
 * প্রথমটা সবচেয়ে চেনা প্রশ্ন। **দ্বিতীয়টা সবচেয়ে জরুরি**, আর সেটাই
 * আজ লাইভে শূন্য: ৭৩টা ব্যাকআপ, সবগুলো একই ডিস্কে।
 *
 * ── ⚠️ কেন "সফল" শব্দটা কোথাও নেই ────────────────────────────────────
 * ব্লুপ্রিন্টে "Backup Health ৯৬%" জাতীয় একটা স্কোর চাওয়া হয়েছিল।
 * স্কোরটা পরে আসবে, কিন্তু নিয়মটা এখনই: **প্রতিটা সংখ্যা এমন হতে হবে
 * যেটা ক্লিক করে উৎসে যাওয়া যায়।** "৯৬%" লেখা একটা টাইল কাউকে কিছু
 * করতে সাহায্য করে না; "শেষ কপি ৭ দিন আগে" করে।
 */
final class BackupDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        $latest = app(BackupService::class)->latest();

        $latestAt = $latest !== null && is_file($latest)
            ? Carbon::createFromTimestamp(filemtime($latest), config('app.timezone'))
            : null;

        $lastRun = BackupRun::query()->latest('started_at')->first();

        return new DashboardDefinition(
            title: __('backup::dashboard.title'),
            subtitle: __('backup::dashboard.subtitle'),

            tiles: [
                new Tile(label: __('backup::menu.backups'), href: route('backup.index'),
                    permission: 'backup.view', icon: 'backup'),
                new Tile(label: __('backup::menu.destinations'), href: route('backup.destination.index'),
                    permission: 'backup.configure', icon: 'settings'),
            ],

            stats: [
                new Stat(
                    label: __('backup::dashboard.last_backup'),
                    value: $latestAt === null
                        ? __('core.backup.none_yet')
                        : __('core.backup.days_ago', ['days' => (int) $latestAt->diffInDays(now())]),
                    hint: __('backup::dashboard.last_backup_hint'),
                    href: route('backup.index'),
                ),

                /*
                 * ⚠️ এই সংখ্যাটাই সবচেয়ে জরুরি, আর আজ এটা শূন্য।
                 *
                 * "ব্যাকআপ আছে" আর "ব্যাকআপ অন্য কোথাও আছে" এক কথা নয়।
                 * শূন্য মানে প্রতিটা কপি ওই একই মেশিনে — অর্থাৎ ঠিক যে
                 * একটা ক্ষেত্রে ব্যাকআপ সবচেয়ে দরকার, সেখানেই কিছু নেই।
                 */
                new Stat(
                    label: __('backup::dashboard.destinations'),
                    value: (string) BackupDestination::query()->where('is_active', true)->count(),
                    hint: __('backup::dashboard.destinations_hint'),
                    href: route('backup.destination.index'),
                ),

                /*
                 * শেষ কবে কপিটা সত্যিই ফিরিয়ে আনা গেছে।
                 *
                 * ⓘ নিচের ইঞ্জিন প্রতিটা রানেই এটা করে, কিন্তু ফলটা
                 * এতদিন কোথাও লেখা হত না — তাই প্রশ্নটার উত্তর কারও
                 * কাছে ছিল না। এখন আছে।
                 */
                new Stat(
                    label: __('backup::dashboard.last_verified'),
                    value: $lastRun?->restoreWasTested()
                        ? __('backup::dashboard.verified_yes')
                        : __('backup::dashboard.verified_no'),
                    hint: __('backup::dashboard.last_verified_hint'),
                    href: route('backup.index'),
                ),

                ...self::latestSize(),
            ],

            // ⓘ প্রথম চার্ট আগের জায়গাতেই — হোমে মডিউলের প্রথম চার্টটা বসে; নতুনটা তার পরে (৬ অক্টোবর ২০২৬)
            panels: [...self::monthsOfCopies(), ...self::lastThirtyDays()],

            listings: [...self::destinations(), ...self::policies()],
        );
    }

    /**
     * ⭐ শেষ সফল ব্যাকআপের মাপ (মালিকের ড্যাশবোর্ড নকশা, ৬ অক্টোবর ২০২৬)।
     *
     * ⓘ "সফল" মানে নিচের চার্টের একই শর্ত — `success`, প্রতিটা গন্তব্যে পৌঁছেছে। মাপটা রানের খাতার (`bak_runs.bytes`),
     * ফাইল খুঁজে নয় — ফাইলটা অন্য মেশিনে থাকতে পারে। ⚠️ হঠাৎ অনেক ছোট হলে কিছু একটা বাদ পড়েছে; সেজন্যই সংখ্যাটা।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Stat>
     */
    private static function latestSize(): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        $run = BackupRun::query()
            ->where('status', 'success')
            ->whereNotNull('bytes')
            ->latest('started_at')->latest('id')
            ->first();

        return [new Stat(
            label: __('backup::dashboard.latest_size'),
            value: $run === null ? __('backup::dashboard.latest_size_none') : self::size((int) $run->bytes),
            hint: $run === null
                ? __('backup::dashboard.latest_size_none_hint')
                : __('backup::dashboard.latest_size_hint', ['date' => DateRange::label($run->started_at, $run->started_at)]),
            href: route('backup.verification.index'),
        )];
    }

    /**
     * ⭐ গত ৩০ দিনের রান — যাচাই-করা, সফল, আংশিক, কেবল এই মেশিনে, ব্যর্থ (মালিকের ড্যাশবোর্ড নকশা, ৬ অক্টোবর ২০২৬)।
     *
     * ⓘ প্রতিটা রান ঠিক একটা ভাগে: যাচাই-করা = সফল **আর** ফিরিয়ে এনে দেখা গেছে ([[BackupRun::restoreWasTested()]]-এর
     * একই শর্ত: `test_restore` পাস); সফল = বাকি সফলগুলো। ⓘ `local_only` আলাদা ভাগ — কপি আছে, কিন্তু একই মেশিনে, আর
     * সেটা আংশিকের চেয়েও খারাপ খবর। ⓘ চলমান (`running`) রান কোনো ভাগে নয় — উপরের মাসের চার্টের একই নিয়ম।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function lastThirtyDays(): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        $from = Carbon::today()->subDays(29);

        $runs = BackupRun::query()
            ->where('started_at', '>=', $from->toDateTimeString())
            ->whereIn('status', ['success', 'partial', 'local_only', 'failed'])
            ->withExists(['verifications as restore_tested' => fn ($q) => $q->where('kind', 'test_restore')->where('status', 'passed')])
            ->get();

        $count = fn (callable $match) => (string) $runs->filter($match)->count();

        return [new Breakdown(
            label: __('backup::dashboard.last_30_days'),
            parts: [
                ['label' => __('backup::dashboard.run_verified'), 'value' => $count(fn ($r) => $r->status === 'success' && $r->restore_tested)],
                ['label' => __('backup::dashboard.run_success'), 'value' => $count(fn ($r) => $r->status === 'success' && ! $r->restore_tested)],
                ['label' => __('backup::dashboard.run_partial'), 'value' => $count(fn ($r) => $r->status === 'partial')],
                ['label' => __('backup::dashboard.run_local_only'), 'value' => $count(fn ($r) => $r->status === 'local_only')],
                ['label' => __('backup::dashboard.run_failed'), 'value' => $count(fn ($r) => $r->status === 'failed')],
            ],
            hint: __('backup::dashboard.last_30_days_hint', ['count' => $runs->count()]),
            chart: 'donut',
            range: DateRange::label($from, Carbon::today()),
        )];
    }

    /**
     * ⭐ গন্তব্যগুলো কেমন আছে — শেষ কবে পৌঁছানো গেছে (মালিকের ড্যাশবোর্ড নকশা, ৬ অক্টোবর ২০২৬)।
     *
     * ⓘ অবস্থা চারটা: বন্ধ · কখনো পৌঁছায়নি · শেষ চেষ্টায় ভুল (শেষ যাচাই শেষ সফলের পরে, আর ভুলের লেখা আছে) · ঠিক আছে।
     * ⚠️ "কত দিন আগে" গন্তব্যের নিজের হিসাবে ([[BackupDestination::daysSinceLastCopy()]]) — খুলে রাখা পেনড্রাইভ নিজে ভুল
     * নয়, ভুল হলো কতদিন ধরে পৌঁছানো যায়নি। ⛔ কেবল `backup.configure` — গন্তব্যের পর্দা যে চাবিতে খোলে; ঠিকানা বা
     * চাবি (`config`) কখনো এখানে আসে না, কেবল নাম আর ধরন। ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Listing>
     */
    private static function destinations(): array
    {
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('backup.configure')) {
            return [];
        }

        return [new Listing(
            label: __('backup::dashboard.destination_list'),
            columns: [
                ['key' => 'name', 'label' => __('backup::dashboard.col_name'),
                    'render' => fn (BackupDestination $d) => $d->name],
                ['key' => 'driver', 'label' => __('backup::dashboard.col_kind'), 'width' => '9rem',
                    'render' => fn (BackupDestination $d) => $d->driver.' · '.$d->kind],
                ['key' => 'state', 'label' => __('backup::dashboard.col_state'), 'width' => '10rem',
                    'render' => fn (BackupDestination $d) => self::destinationState($d)],
                ['key' => 'last', 'label' => __('backup::dashboard.col_last_copy'), 'width' => '8rem',
                    'render' => fn (BackupDestination $d) => $d->daysSinceLastCopy() === null
                        ? __('backup::screen.never')
                        : __('backup::screen.days_old', ['days' => $d->daysSinceLastCopy()])],
            ],
            rows: BackupDestination::query()->orderByDesc('is_active')->orderBy('name')->limit(10)->get(),
            empty: __('backup::screen.no_destinations'),
            href: route('backup.destination.index'),
        )];
    }

    private static function destinationState(BackupDestination $d): string
    {
        if (! $d->is_active) {
            return __('backup::dashboard.state_off');
        }

        if ($d->last_ok_at === null) {
            return __('backup::dashboard.state_never');
        }

        if (filled($d->last_error) && $d->last_checked_at !== null && $d->last_checked_at->greaterThan($d->last_ok_at)) {
            return __('backup::dashboard.state_failing');
        }

        return __('backup::dashboard.state_ok');
    }

    /**
     * ⭐ ব্যাকআপের সময়সূচি — পর্দায় রাখা নীতিগুলো (মালিকের ড্যাশবোর্ড নকশা, ৬ অক্টোবর ২০২৬)।
     *
     * ⓘ উৎস `bak_policies`। ⚠️ আজ রাতের ব্যাকআপ চলে সার্ভারের সেটিং থেকে (`abos.backup.daily_at`), নীতির সারি থেকে নয়
     * ([[RecoveryController]]-এর নীতির পর্দাও তাই বলে) — তাই তালিকা খালি হলে সেই সময়টাই লেখা থাকে, যাতে কেউ না ভাবেন
     * ব্যাকআপ চলেই না। ⛔ কেবল `backup.configure` — নীতির পর্দা যে চাবিতে খোলে।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Listing>
     */
    private static function policies(): array
    {
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('backup.configure')) {
            return [];
        }

        return [new Listing(
            label: __('backup::dashboard.schedule'),
            columns: [
                ['key' => 'name', 'label' => __('backup::dashboard.col_name'),
                    'render' => fn (BackupPolicy $p) => $p->name],
                ['key' => 'when', 'label' => __('backup::dashboard.col_when'), 'width' => '10rem',
                    'render' => fn (BackupPolicy $p) => (in_array($p->frequency, ['hourly', 'daily', 'weekly', 'monthly'], true)
                        ? __('backup::dashboard.every_'.$p->frequency)
                        : (string) $p->frequency).' · '.$p->run_at],
                ['key' => 'state', 'label' => __('backup::dashboard.col_state'), 'width' => '6rem',
                    'render' => fn (BackupPolicy $p) => $p->is_active ? __('backup::screen.active') : __('backup::screen.inactive')],
            ],
            rows: BackupPolicy::query()->orderByDesc('is_active')->orderBy('name')->limit(10)->get(),
            empty: __('backup::dashboard.schedule_empty', ['time' => (string) config('abos.backup.daily_at')]),
            href: route('backup.policy.index'),
        )];
    }

    /** বাইট থেকে পড়ার মতো মাপ — ব্যাকআপের পর্দার একই নিয়ম, বড় হলে জিবি। */
    private static function size(int $bytes): string
    {
        return match (true) {
            $bytes >= 1073741824 => round($bytes / 1073741824, 2).' GB',
            $bytes >= 1048576 => round($bytes / 1048576, 1).' MB',
            default => max(1, (int) round($bytes / 1024)).' KB',
        };
    }

    /**
     * ⭐ মাসে মাসে ব্যাকআপ — নিরাপদে বাইরে গেছে বনাম সমস্যা, গত ছয় মাস (মালিকের ড্যাশবোর্ড নকশা, ৩ অক্টোবর ২০২৬)।
     *
     * ⓘ "নিরাপদ" মানে কেবল `success` — প্রতিটা গন্তব্যে পৌঁছেছে। `partial`, `local_only`, `failed` তিনটাই সমস্যা:
     * কপিটা হয় নেই, নয় ওই একই মেশিনে — ঠিক যেদিন ব্যাকআপ লাগে, সেদিন কাজে আসে না ([[BackupRunner]])।
     * ⓘ চলমান (`running`) রান কোনো দিকেই গোনা নয়। ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Series>
     */
    private static function monthsOfCopies(): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        $start = Carbon::today()->startOfMonth()->subMonths(5);
        $expr = "DATE_FORMAT(started_at, '%Y-%m')";

        $rows = BackupRun::query()
            ->where('started_at', '>=', $start->toDateTimeString())
            ->whereIn('status', ['success', 'partial', 'local_only', 'failed'])
            ->selectRaw("{$expr} as ym, SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as safe, SUM(CASE WHEN status = 'success' THEN 0 ELSE 1 END) as trouble")
            ->groupByRaw($expr)
            ->toBase()->get()->keyBy('ym');

        $points = [];

        for ($month = $start->copy(); $month->lessThanOrEqualTo(Carbon::today()); $month->addMonth()) {
            $row = $rows[$month->format('Y-m')] ?? null;
            $points[] = [
                'label' => $month->translatedFormat('M'),
                'first' => (string) (int) ($row->safe ?? 0),
                'second' => (string) (int) ($row->trouble ?? 0),
            ];
        }

        return [new Series(
            label: __('backup::dashboard.months_of_copies'),
            points: $points,
            firstLabel: __('backup::dashboard.copies_safe'),
            secondLabel: __('backup::dashboard.copies_trouble'),
            // ⓘ সময়ের চার্ট — কোন দিন থেকে কোন দিন (মালিক, ৫ অক্টোবর ২০২৬; বসানো ৬ অক্টোবর ২০২৬)
            range: DateRange::label($start, Carbon::today()),
        )];
    }
}
