<?php

declare(strict_types=1);

namespace App\Modules\Backup\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Dashboard\Viz\Card;
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

            listings: [...self::runsList(), ...self::destinations(), ...self::policies()],

            /*
             * ⭐ খোলার পর্দায় ছয়টা গ্রাফ — মালিক, ২০ নভেম্বর ২০২৬ (নমুনা boards15 'bak': ক্যালেন্ডার, কাঁটা-মিটার, কলাম, বুলেট, সময়রেখা,
             *   প্রবাহ)। ⛔ গন্তব্যের নাম আর সময়সূচি কেবল `backup.configure`-এ — নিচের তালিকা দুটোর একই চাবি; চাবি না থাকলে ঐ দুই গ্রাফ
             *   আসে না, আর "গন্তব্য" সংখ্যাটা আগের মতো নিচে থাকে।
             * ⓘ গ্রাফে নেই, তাই নিচে আগের মতো: মাসে মাসে নিরাপদ বনাম সমস্যা (6 মাস), গন্তব্যের তালিকা।
             */
            // ⓘ রানের ফল (যাচাই-করা বনাম না) আর সব নীতির তালিকা ঢাকা নয় — গ্রাফে নেই বা অর্ধেক, তাই নিচে থাকে (নিরীক্ষা, ২৩ নভেম্বর ২০২৬)
            sections: [Card::board(fn (): array => self::charts($latestAt, $lastRun), covers: [
                __('backup::dashboard.last_backup'), __('backup::dashboard.last_verified'),
                __('backup::dashboard.latest_size'),
                ...(auth()->user()?->can('backup.configure') ? [__('backup::dashboard.destinations')] : []),
            ])],
        );
    }

    /**
     * ⭐ ব্যাকআপের গ্রাফের বোর্ড — রানের খাতা (`bak_runs`), যাচাই (`bak_verifications`), গন্তব্য আর নীতি থেকে; কিছুই বানানো নয়।
     *
     * ⓘ ক্যালেন্ডার: শনিবার থেকে শেষ পাঁচ সপ্তাহ, ✓ = সেদিন অন্তত একটা `success` রান (প্রতিটা গন্তব্যে পৌঁছেছে)।
     * ⓘ কাঁটা-মিটার: গত 30 দিনে যে রানগুলো ফাইল বানিয়েছে, তার কতগুলো সত্যিই ফিরিয়ে এনে দেখা গেছে (`test_restore` পাস)।
     * ⓘ বুলেট: প্রতিটা চালু গন্তব্যে গত 30 দিনের রানের কত ভাগ পৌঁছেছে (`destinations_ok`), লক্ষ্য 95%।
     * ⓘ সময়রেখা: এখন থেকে 24 ঘণ্টা — সার্ভারের রাতের ব্যাকআপ (`abos.backup.daily_at`) আর পর্দার চালু নীতি (ঘণ্টায়/প্রতিদিন)।
     * ⓘ প্রবাহ: কোন কপি (রাতের নিয়ম/হাতে/ডিপ্লয়) → কোথায় পৌঁছাল (গন্তব্য ধরে, বা কেবল এই সার্ভারে), গত 30 দিন — নমুনার "কোথায় কোন কপি"।
     * ⓘ ভাগ আর মাপ bcmath/intdiv-এ — float নয়।
     *
     * @return list<array<string, mixed>>
     */
    private static function charts(?Carbon $latestAt, ?BackupRun $lastRun): array
    {
        $configure = (bool) auth()->user()?->can('backup.configure');
        $today = Carbon::today();
        $from = $today->copy()->subDays(29);
        $range = DateRange::label($from, $today);

        $runs = BackupRun::query()
            ->where('started_at', '>=', $today->copy()->startOfWeek(Carbon::SATURDAY)->subWeeks(4)->toDateTimeString())
            ->whereIn('status', ['success', 'partial', 'local_only', 'failed'])
            ->withExists(['verifications as restore_tested' => fn ($q) => $q->where('kind', 'test_restore')->where('status', 'passed')])
            ->get();
        $recent = $runs->filter(fn (BackupRun $r) => $r->started_at !== null && $r->started_at->greaterThanOrEqualTo($from))->values();

        // ক্যালেন্ডার — শনিবার থেকে, আজ পর্যন্ত (29–35 দিন)
        $safeDays = $runs->filter(fn (BackupRun $r) => $r->status === 'success')->map(fn (BackupRun $r) => $r->started_at->toDateString())->flip();
        $days = [];
        $safe = 0;
        for ($day = $today->copy()->startOfWeek(Carbon::SATURDAY)->subWeeks(4); $day->lte($today); $day->addDay()) {
            $ok = isset($safeDays[$day->toDateString()]) ? 1 : 0;
            // ⓘ মাথার "x / 30" কেবল শেষ 30 দিনের — ঘরগুলো শনিবার থেকে, তাই কয়েকটা দিন বেশি দেখা যায়
            $safe += $day->greaterThanOrEqualTo($from) ? $ok : 0;
            $days[] = [$day->day, $ok];
        }

        // ফিরিয়ে আনা যায়? — ফাইল বানানো রানের কতগুলো পরীক্ষায় ফিরেছে
        $made = $recent->filter(fn (BackupRun $r) => $r->status !== 'failed');
        $tested = $made->filter(fn (BackupRun $r) => (bool) $r->restore_tested)->count();
        $restorable = $made->count() === 0 ? [] : [
            'v' => bcdiv((string) $tested, (string) $made->count(), 4),
            'label' => intdiv($tested * 100, $made->count()).'%',
            'sub' => __('backup::dashboard.restore_sub', ['n' => $tested, 'of' => $made->count()]),
        ];

        // মাসে মাসে মাপ — প্রতি মাসের শেষ সফল রানের মাপ, 6 মাস
        $start = $today->copy()->startOfMonth()->subMonths(5);
        $sizes = BackupRun::query()->where('status', 'success')->whereNotNull('bytes')
            ->where('started_at', '>=', $start->toDateTimeString())->orderBy('started_at')->orderBy('id')
            ->get(['started_at', 'bytes'])->keyBy(fn (BackupRun $r) => $r->started_at->format('Y-m'));
        $bytes = [];
        $months = [];
        for ($month = $start->copy(); $month->lte($today); $month->addMonth()) {
            $bytes[] = (int) ($sizes[$month->format('Y-m')]->bytes ?? 0);
            $months[] = $month->translatedFormat('M');
        }
        $inMb = max($bytes) >= 10485760;
        $sizeBars = array_map(fn (int $b) => $inMb ? intdiv($b, 1048576) : intdiv($b, 1024), $bytes);
        $latest = BackupRun::query()->where('status', 'success')->whereNotNull('bytes')->latest('started_at')->latest('id')->first();

        $cards = [
            Card::make(__('backup::dashboard.viz_runs'), 'calendar', ['days' => $days, 'marks' => true, 'color' => '#16a34a'],
                value: $safe.' / 30', sub: __('backup::dashboard.calendar_hint', ['last' => $latestAt === null
                    ? __('core.backup.none_yet')
                    : __('core.backup.days_ago', ['days' => (int) $latestAt->diffInDays(now())])]),
                href: route('backup.index'), colour: '#16a34a'),
            Card::make(__('backup::dashboard.last_verified'), 'gauge', $restorable,
                value: $restorable === [] ? '' : $restorable['label'],
                sub: __('backup::dashboard.restore_hint', ['range' => $range, 'last' => $lastRun?->restoreWasTested()
                    ? __('backup::dashboard.verified_yes') : __('backup::dashboard.verified_no')]),
                href: route('backup.verification.index'), colour: '#2160d8'),
            Card::make(__('backup::dashboard.size_by_month'), 'bars', [
                'v' => $sizeBars, 'l' => $months, 'color' => '#0ea5e9',
                'top' => array_map(fn (int $n) => $n === 0 ? '' : $n.($inMb ? ' MB' : ' KB'), $sizeBars),
            ], value: $latest === null ? '' : self::size((int) $latest->bytes),
                sub: __('backup::dashboard.size_by_month_hint', ['range' => DateRange::label($start, $today)]),
                href: route('backup.verification.index'), colour: '#0ea5e9'),
        ];

        if ($configure) {
            $cards[] = self::destinationsChart($recent);
            $cards[] = self::scheduleChart();
        }

        $cards[] = self::flowChart($recent, $range);

        return $cards;
    }

    /**
     * গন্তব্যগুলো কেমন আছে — প্রতিটা চালু গন্তব্যে গত 30 দিনের রানের কত ভাগ পৌঁছেছে (বুলেট, লক্ষ্য 95%)।
     *
     * @param  \Illuminate\Support\Collection<int, BackupRun>  $recent
     * @return array<string, mixed>
     */
    private static function destinationsChart($recent): array
    {
        $active = BackupDestination::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $total = $recent->count();
        $p = [];
        foreach ($active->take(6) as $d) {
            $landed = $recent->filter(fn (BackupRun $r) => collect($r->destinations_ok ?? [])->contains(fn ($x) => (int) ($x['id'] ?? 0) === (int) $d->id))->count();
            $p[] = [(string) $d->name, $total === 0 ? '0' : bcdiv((string) $landed, (string) $total, 4), '0.95'];
        }

        // ⓘ নমুনার বুলেট গন্তব্যের ভরা জায়গা মাপে — গন্তব্যের মাপ (কত জায়গা) খাতায় নেই, তাই মাপ রানের কত ভাগ পৌঁছাল
        return Card::make(__('backup::dashboard.viz_destinations'), 'bullet', ['p' => $p],
            value: (string) $active->count(), sub: __('backup::dashboard.destinations_chart_hint', ['count' => $total]),
            href: route('backup.destination.index'), colour: '#7c3aed');
    }

    /**
     * ব্যাকআপের সময়সূচি — এখন থেকে 24 ঘণ্টা (সময়রেখা)।
     *
     * ⓘ সার্ভারের রাতের ব্যাকআপ সবসময় প্রথম সারি — আজ সেটাই চলে ([[policies()]]-এর কথা)। নীতির মধ্যে কেবল ঘণ্টায় আর প্রতিদিনেরগুলো:
     *   সাপ্তাহিক বা মাসিক নীতির দিন খাতায় নেই, তাই আন্দাজে বসানো হয় না।
     *
     * @return array<string, mixed>
     */
    private static function scheduleChart(): array
    {
        $now = Carbon::now();
        $at = function (string $hhmm) use ($now): ?string {
            if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm, $m) !== 1) {
                return null;
            }
            $next = $now->copy()->setTime((int) $m[1], (int) $m[2]);
            if ($next->lessThan($now)) {
                $next->addDay();
            }

            return bcdiv((string) (int) $now->diffInMinutes($next), '1440', 4);
        };
        $slot = fn (string $s) => [$s, bcadd($s, '0.03', 4)];

        $p = [];
        $server = $at((string) config('abos.backup.daily_at'));
        if ($server !== null) {
            $p[] = [__('backup::dashboard.server_nightly'), ...$slot($server), '#2160d8'];
        }
        $colours = ['#16a34a', '#7c3aed', '#f97316', '#14b8a6', '#e8175d'];
        foreach (BackupPolicy::query()->where('is_active', true)->whereIn('frequency', ['hourly', 'daily'])->orderBy('name')->limit(5)->get() as $i => $policy) {
            if ($policy->frequency === 'hourly') {
                $p[] = [(string) $policy->name, '0', '1', $colours[$i]];
            } elseif (($s = $at((string) $policy->run_at)) !== null) {
                $p[] = [(string) $policy->name, ...$slot($s), $colours[$i]];
            }
        }

        return Card::make(__('backup::dashboard.viz_schedule'), 'gantt', [
            'ticks' => (array) __('backup::dashboard.ticks'), 'today' => 0, 'p' => $p,
        ], sub: __('backup::dashboard.schedule_chart_hint'), href: route('backup.policy.index'), colour: '#f97316');
    }

    /**
     * কোথায় কোন কপি — গত 30 দিন (প্রবাহ, নমুনার "কপি → গন্তব্য"): বাঁয়ে কোন কপি (রাতের নিয়মে / হাতে / আপডেটের আগে), ডানে কোথায় পৌঁছাল।
     *
     * ⓘ একটা রান যত গন্তব্যে পৌঁছায় (`destinations_ok`), তত কপি; `local_only` রানের কপি কেবল এই সার্ভারে। ব্যর্থ রানের কপি কোথাও নেই —
     *   তার গোনা নিচের "গত 30 দিনের রান — ফল ধরে" চার্টে।
     * ⛔ গন্তব্যের নাম কেবল `backup.configure`-এ (নিচের গন্তব্যের তালিকার একই চাবি); বাকিদের কাছে সব গন্তব্য মিলে "অন্য গন্তব্যে"।
     *
     * @param  \Illuminate\Support\Collection<int, BackupRun>  $recent
     * @return array<string, mixed>
     */
    private static function flowChart($recent, string $range): array
    {
        $names = auth()->user()?->can('backup.configure')
            ? BackupDestination::query()->pluck('name', 'id')->map(fn ($n) => (string) $n)->all()
            : null;
        $here = (string) __('backup::dashboard.flow_this_server');
        $away = (string) __('backup::dashboard.flow_elsewhere');
        $triggers = ['schedule' => __('backup::screen.trigger_schedule'), 'manual' => __('backup::screen.trigger_manual'), 'deploy' => __('backup::dashboard.trigger_deploy')];

        $copies = [];
        foreach ($recent as $r) {
            $from = (string) ($triggers[$r->triggered_by ?? 'schedule'] ?? $triggers['schedule']);
            $to = [];
            if ($r->status === 'local_only') {
                $to[] = $here;
            }
            foreach ((array) ($r->destinations_ok ?? []) as $d) {
                $id = (int) ($d['id'] ?? 0);
                $to[] = $names === null ? $away : ($names[$id] ?? '#'.$id);
            }
            foreach ($to as $place) {
                $copies[$from][$place] = ($copies[$from][$place] ?? 0) + 1;
            }
        }

        $left = [];
        $right = [];
        foreach ($copies as $from => $places) {
            foreach ($places as $place => $n) {
                $left[] = [(string) $from, $n, (string) $place];
                $right[$place] = ($right[$place] ?? 0) + $n;
            }
        }
        arsort($right);

        return Card::make(__('backup::dashboard.flow'), 'sankey', [
            'left' => $left, 'right' => array_map(fn ($place, $n) => [(string) $place, $n], array_keys($right), array_values($right)),
        ], value: (string) array_sum($right), sub: __('backup::dashboard.flow_hint', ['range' => $range]),
            href: route('backup.index'), colour: '#14b8a6');
    }

    /**
     * ⭐ ব্যাকআপের তালিকা — শেষ দশটা রান: কবে, কে চালাল, কী হলো, মাপ (নমুনার নিচের তালিকা, ২৩ নভেম্বর ২০২৬)।
     * ⓘ ব্যাকআপের পর্দার একই খাতা (`bak_runs`); ফাইলের নাম বা পথ নয়। ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Listing>
     */
    private static function runsList(): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        return [new Listing(
            label: __('backup::dashboard.runs_list'),
            columns: [
                ['key' => 'when', 'label' => __('backup::screen.col_when'), 'width' => '10rem',
                    'render' => fn (BackupRun $r) => $r->started_at?->format('d M Y, H:i') ?? '—'],
                ['key' => 'how', 'label' => __('backup::screen.col_how'), 'width' => '8rem',
                    'render' => fn (BackupRun $r) => match ($r->triggered_by ?? 'schedule') {
                        'manual' => __('backup::screen.trigger_manual'),
                        'deploy' => __('backup::dashboard.trigger_deploy'),
                        default => __('backup::screen.trigger_schedule'),
                    }],
                ['key' => 'result', 'label' => __('backup::screen.col_result'),
                    'render' => fn (BackupRun $r) => in_array($r->status, ['success', 'partial', 'local_only', 'failed', 'running'], true)
                        ? __('backup::screen.status_'.$r->status) : (string) $r->status],
                ['key' => 'size', 'label' => __('backup::dashboard.col_size'), 'width' => '7rem',
                    'render' => fn (BackupRun $r) => $r->bytes === null ? '—' : self::size((int) $r->bytes)],
            ],
            rows: BackupRun::query()->latest('started_at')->latest('id')->limit(10)->get(),
            empty: __('backup::dashboard.runs_list_empty'),
            href: route('backup.index'),
        )];
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

    /** বাইট থেকে পড়ার মতো মাপ — ব্যাকআপের পর্দার একই নিয়ম, বড় হলে জিবি। ⓘ ভাগ bcmath/intdiv-এ, float নয় (২০ নভেম্বর ২০২৬)। */
    private static function size(int $bytes): string
    {
        return match (true) {
            $bytes >= 1073741824 => bcdiv((string) $bytes, '1073741824', 2).' GB',
            $bytes >= 1048576 => bcdiv((string) $bytes, '1048576', 1).' MB',
            default => max(1, intdiv($bytes + 512, 1024)).' KB',
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
