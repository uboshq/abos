<?php

declare(strict_types=1);

namespace App\Modules\Notification\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Dashboard\Viz\Card;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\DateRange;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Series;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Support\NotificationKinds;
use App\Core\Support\ViewedBranch;
use App\Models\Notification;
use App\Models\NotificationChannel;
use App\Models\NotificationEvent;
use App\Models\NotificationJob;
use Illuminate\Support\Carbon;

/**
 * ⭐ বিজ্ঞপ্তির ড্যাশবোর্ড — মোট, না-পড়া, পৌঁছেছে, ব্যর্থ, অপেক্ষায়, মাধ্যমের সাফল্য; সাম্প্রতিক খবর
 * (মালিকের স্পেক §৩; বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ৪)।
 *
 * ⓘ সব সংখ্যা মাথার শাখা ধরে ([[ViewedBranch::narrow()]] — খবরের শাখা); ডেলিভারির সংখ্যা তার খবরের শাখা দিয়ে।
 * ⓘ প্রতিটা সংখ্যা নিজের তালিকা বা রিপোর্টে খোলে (নিয়ম ১); চাবি না থাকলে সংখ্যাটা ঢাকা থাকে।
 * ⓘ সময়: গত ৩০ দিন (সংখ্যা), গত ১৪ দিন (রেখা)। তারিখ, মডিউল, মাধ্যম, অবস্থা ধরে বিশ্লেষণ রিপোর্টে।
 */
final class NotificationDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        $since = Carbon::today()->subDays(29);
        $range = DateRange::label($since, Carbon::today());

        $bells = fn () => ViewedBranch::narrow(Notification::query(), 'notifications.branch_id');
        $jobs = fn () => NotificationJob::query()->whereIn('notification_jobs.notification_id',
            ViewedBranch::narrow(Notification::query()->select('notifications.id'), 'notifications.branch_id'));

        $total = $bells()->where('notifications.created_at', '>=', $since)->count();
        $unread = $bells()->whereNull('notifications.read_at')->whereNull('notifications.archived_at')->count();
        $delivered = $jobs()->where('status', NotificationJob::SENT)->where('notification_jobs.created_at', '>=', $since)->count();
        $failed = $jobs()->where('status', NotificationJob::DEAD)->count();
        $pending = $jobs()->whereIn('status', NotificationJob::OPEN)->count();
        $finished = $jobs()->whereIn('status', [NotificationJob::SENT, NotificationJob::DEAD])->where('notification_jobs.created_at', '>=', Carbon::today()->subDays(6))->count();
        $sentWeek = $jobs()->where('status', NotificationJob::SENT)->where('notification_jobs.created_at', '>=', Carbon::today()->subDays(6))->count();

        return new DashboardDefinition(
            title: __('notification::dashboard.title'),
            subtitle: __('notification::dashboard.subtitle'),
            stats: [
                new Stat(__('notification::dashboard.total'), (string) $total, __('notification::dashboard.total_hint'),
                    href: route('notification.report.show', ['slug' => 'summary']), permission: 'notification.reports'),
                new Stat(__('notification::dashboard.unread'), (string) $unread, __('notification::dashboard.unread_hint'),
                    href: route('notification.report.show', ['slug' => 'read-unread']), tone: $unread > 0 ? Stat::WARN : Stat::NEUTRAL, permission: 'notification.reports'),
                new Stat(__('notification::dashboard.delivered'), (string) $delivered, __('notification::dashboard.delivered_hint'),
                    href: route('notification.report.show', ['slug' => 'channel-delivery']), tone: Stat::GOOD, permission: 'notification.reports'),
                new Stat(__('notification::dashboard.failed'), (string) $failed, __('notification::dashboard.failed_hint'),
                    href: route('notification.deliveries.failed'), tone: $failed > 0 ? Stat::BAD : Stat::NEUTRAL, permission: 'notification.deliveries'),
                new Stat(__('notification::dashboard.pending'), (string) $pending, __('notification::dashboard.pending_hint'),
                    href: route('notification.deliveries.queue'), permission: 'notification.deliveries'),
                new Stat(__('notification::dashboard.health'), $finished === 0 ? '—' : (string) intdiv($sentWeek * 100, $finished).'%',
                    __('notification::dashboard.health_hint'), href: route('notification.deliveries.health'),
                    tone: $finished > 0 && $sentWeek * 100 < $finished * 95 ? Stat::WARN : Stat::GOOD, permission: 'notification.deliveries'),
            ],
            panels: [
                new Series(__('notification::dashboard.trend'), self::trend($bells, $jobs),
                    __('notification::dashboard.trend_bell'), __('notification::dashboard.trend_out'), 'bars',
                    DateRange::label(Carbon::today()->subDays(13), Carbon::today())),
                new Breakdown(__('notification::dashboard.by_channel'), self::byChannel($jobs, $since),
                    __('notification::dashboard.by_channel_hint'), 'hbars', $range),
            ],
            listings: [
                new Listing(
                    label: __('notification::dashboard.recent'),
                    columns: [
                        ['key' => 'title', 'label' => __('core.notify.col.title'), 'render' => fn (NotificationEvent $e) => $e->title],
                        ['key' => 'module', 'label' => __('core.notify.col.source'), 'width' => '9rem', 'render' => fn (NotificationEvent $e) => NotificationKinds::sourceLabel($e->module)],
                        ['key' => 'priority', 'label' => __('core.notify.col.priority'), 'width' => '6rem', 'render' => fn (NotificationEvent $e) => __('core.notify.priority.'.$e->priority)],
                        ['key' => 'when', 'label' => __('core.notify.col.when'), 'width' => '8rem', 'render' => fn (NotificationEvent $e) => $e->created_at?->format('d/m H:i')],
                    ],
                    rows: ViewedBranch::narrow(NotificationEvent::query(), 'notification_events.branch_id')->orderByDesc('id')->limit(10)->get(),
                    empty: __('notification::dashboard.recent_empty'),
                    href: route('notification.center.index'),
                ),
            ],
            tiles: [
                new Tile(__('notification::menu.center'), route('notification.center.index'), 'notification.center', 'bell'),
                new Tile(__('notification::menu.rules'), route('notification.rules.index'), 'notification.rules', 'filter'),
                new Tile(__('notification::menu.templates'), route('notification.templates.index'), 'notification.templates', 'documents'),
                new Tile(__('notification::menu.channels'), route('notification.channels.index'), 'notification.channels', 'settings'),
            ],

            /*
             * ⭐ খোলার পর্দায় ছয়টা গ্রাফ — মালিক, ২০ নভেম্বর ২০২৬ (নমুনা boards15 'not': ফানেল, ডোনাট, স্তূপ-এলাকা, বুলেট, আড়াআড়ি কলাম,
             *   হিটম্যাপ)। ⛔ বোর্ড রিপোর্টের চাবিতে (`notification.reports`) — উপরের সংখ্যাগুলোর একই চাবি; চাবি না থাকলে বোর্ড আসে না, আগের
             *   পাতা হুবহু। মাধ্যমের সাফল্যের বুলেট আর ব্যর্থের দাগ কেবল ডেলিভারির চাবিতে (`notification.deliveries`)।
             * ⓘ ব্যর্থ-তালিকা আর অপেক্ষার সংখ্যা (সব সময়ের) গ্রাফে নেই — নিচে আগের মতো, সাম্প্রতিক বিজ্ঞপ্তির তালিকার সাথে।
             */
            sections: [Card::board(fn (): array => self::charts($bells, $jobs, $since, $total, $unread, $delivered), covers: [
                __('notification::dashboard.total'), __('notification::dashboard.unread'), __('notification::dashboard.delivered'),
                __('notification::dashboard.trend'), __('notification::dashboard.by_channel'),
                ...(auth()->user()?->can('notification.deliveries') ? [__('notification::dashboard.health')] : []),
            ], permission: 'notification.reports')],
        );
    }

    /**
     * ⭐ বিজ্ঞপ্তির গ্রাফের বোর্ড — সব সংখ্যা উপরের একই ছাঁকনিতে (মাথার শাখা, [[ViewedBranch::narrow()]])।
     *
     * ⓘ ফানেল: গত 30 দিনের ঘণ্টার খবর → দেখা (`seen_at`) → পড়া (`read_at`)। ⓘ স্তূপ-এলাকা: দিনে ঘণ্টায় কত, বাইরে কত পৌঁছাল, কত ব্যর্থ।
     * ⓘ বুলেট: গত 7 দিনে শেষ হওয়া ডেলিভারির কত ভাগ পৌঁছেছে, মাধ্যম ধরে; লক্ষ্য 95%। ⓘ ভাগ bcmath-এ, লেখা হয়ে — float নয়।
     *
     * @return list<array<string, mixed>>
     */
    private static function charts(\Closure $bells, \Closure $jobs, Carbon $since, int $total, int $unread, int $delivered): array
    {
        $deliveries = (bool) auth()->user()?->can('notification.deliveries');
        $range = DateRange::label($since, Carbon::today());

        // ফানেল — খবর → দেখা → পড়া
        $seen = $bells()->where('notifications.created_at', '>=', $since)->whereNotNull('notifications.seen_at')->count();
        $read = $bells()->where('notifications.created_at', '>=', $since)->whereNotNull('notifications.read_at')->count();

        // মাধ্যম ধরে — উপরের "মাধ্যম ধরে ডেলিভারি"-র একই গোনা
        $channels = array_values(array_filter(self::byChannel($jobs, $since), fn (array $p) => (int) $p['value'] > 0));
        $jobsTotal = array_sum(array_map(fn (array $p) => (int) $p['value'], $channels));

        // 30 দিন, দিনে দিনে — ঘণ্টায়, বাইরে পৌঁছেছে, ব্যর্থ
        $bellDays = $bells()->where('notifications.created_at', '>=', $since)
            ->selectRaw('DATE(notifications.created_at) as d, COUNT(*) as n')->groupByRaw('DATE(notifications.created_at)')->pluck('n', 'd');
        $outDays = $jobs()->where('status', NotificationJob::SENT)->where('notification_jobs.created_at', '>=', $since)
            ->selectRaw('DATE(notification_jobs.created_at) as d, COUNT(*) as n')->groupByRaw('DATE(notification_jobs.created_at)')->pluck('n', 'd');
        $deadDays = $deliveries ? $jobs()->where('status', NotificationJob::DEAD)->where('notification_jobs.created_at', '>=', $since)
            ->selectRaw('DATE(notification_jobs.created_at) as d, COUNT(*) as n')->groupByRaw('DATE(notification_jobs.created_at)')->pluck('n', 'd') : collect();
        [$inBell, $out, $dead, $labels] = [[], [], [], []];
        for ($i = 0, $day = $since->copy(); $day->lte(Carbon::today()); $day->addDay(), $i++) {
            $key = $day->toDateString();
            $inBell[] = (int) ($bellDays[$key] ?? 0);
            $out[] = (int) ($outDays[$key] ?? 0);
            $dead[] = (int) ($deadDays[$key] ?? 0);
            $labels[] = in_array($i, [0, 10, 20], true) ? $day->format('d/m') : ($day->isToday() ? __('notification::dashboard.today') : '');
        }

        // মাধ্যমের সাফল্য — গত 7 দিনে শেষ হওয়া, মাধ্যম ধরে
        $health = [];
        $healthValue = '';
        if ($deliveries) {
            $finished = $jobs()->whereIn('status', [NotificationJob::SENT, NotificationJob::DEAD])
                ->where('notification_jobs.created_at', '>=', Carbon::today()->subDays(6))
                ->selectRaw("channel, SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as ok, COUNT(*) as n")
                ->groupBy('channel')->toBase()->get()->keyBy('channel');
            [$ok, $all] = [0, 0];
            foreach (NotificationChannel::ALL as $channel) {
                $row = $finished[$channel] ?? null;
                if ($row === null || (int) $row->n === 0) {
                    continue;
                }
                $ok += (int) $row->ok;
                $all += (int) $row->n;
                $health[] = [(string) __('notification::channel.names.'.$channel), bcdiv((string) (int) $row->ok, (string) (int) $row->n, 4), '0.95'];
            }
            $healthValue = $all === 0 ? '' : intdiv($ok * 100, $all).'%';
        }

        // কোন কাজের বিজ্ঞপ্তি — মডিউল ধরে, বড় পাঁচটা আর বাকিরা
        $modules = $bells()->where('notifications.created_at', '>=', $since)
            ->selectRaw('notifications.module as m, COUNT(*) as n')->groupBy('notifications.module')->orderByDesc('n')->toBase()->get();
        $topModules = $modules->take(5)->map(fn ($r) => [NotificationKinds::sourceLabel($r->m === null ? null : (string) $r->m), (int) $r->n])->all();
        $rest = (int) $modules->slice(5)->sum('n');
        if ($rest > 0) {
            $topModules[] = [(string) __('notification::dashboard.others'), $rest];
        }

        // কখন পড়া হয় — গত 30 দিনে পড়া, বার × সময়
        $weekdays = [6, 0, 1, 2, 3, 4, 5];
        $bands = [[6, 12], [12, 15], [15, 18], [18, 21], [21, 30]];
        $grid = array_fill_keys($weekdays, array_fill(0, count($bands), 0));
        foreach ($bells()->where('notifications.read_at', '>=', $since)->pluck('notifications.read_at') as $at) {
            $at = Carbon::parse($at);
            $hour = $at->hour < 6 ? $at->hour + 24 : $at->hour;
            foreach ($bands as $k => [$a, $b]) {
                if ($hour >= $a && $hour < $b) {
                    $grid[$at->dayOfWeek][$k]++;
                }
            }
        }

        $wd = (array) __('notification::dashboard.weekdays');

        return [
            Card::make(__('notification::dashboard.funnel'), 'funnel', ['p' => [
                [__('notification::dashboard.funnel_bell'), $total, number_format($total)],
                [__('notification::dashboard.funnel_seen'), $seen, number_format($seen)],
                [__('notification::dashboard.funnel_read'), $read, number_format($read)],
            ]], value: (string) $total, sub: __('notification::dashboard.funnel_hint', ['range' => $range, 'unread' => $unread]),
                href: route('notification.report.show', ['slug' => 'read-unread']), colour: '#2160d8'),
            Card::make(__('notification::dashboard.by_channel'), 'donut', [
                'c' => (string) $jobsTotal, 'cs' => __('notification::dashboard.deliveries'),
                'p' => array_map(fn (array $p) => [$p['label'], (int) $p['value'], null, $p['value']], $channels),
            ], sub: __('notification::dashboard.by_channel_hint'), href: route('notification.report.show', ['slug' => 'channel-delivery']), colour: '#16a34a'),
            Card::make(__('notification::dashboard.daily'), 'stackedArea', [
                'series' => $deliveries ? [$inBell, $out, $dead] : [$inBell, $out],
                'colors' => ['#7c3aed', '#0ea5e9', '#e11d48'], 'l' => $labels,
            ], value: (string) $delivered, sub: __('notification::dashboard.daily_hint', ['range' => $range]), legend: array_slice([
                [__('notification::dashboard.trend_bell'), '#7c3aed'], [__('notification::dashboard.trend_out'), '#0ea5e9'], [__('notification::dashboard.failed'), '#e11d48'],
            ], 0, $deliveries ? 3 : 2), colour: '#7c3aed'),
            ...($deliveries ? [Card::make(__('notification::dashboard.health'), 'bullet', ['p' => $health],
                value: $healthValue, sub: __('notification::dashboard.health_chart_hint'), href: route('notification.deliveries.health'), colour: '#0ea5e9')] : []),
            Card::make(__('notification::dashboard.by_module'), 'hbars', [
                'v' => array_map(fn (array $m) => $m[1], $topModules), 'l' => array_map(fn (array $m) => $m[0], $topModules),
            ], sub: __('notification::dashboard.by_module_hint', ['range' => $range]),
                href: route('notification.report.show', ['slug' => 'module-wise']), colour: '#f59e0b'),
            Card::make(__('notification::dashboard.when_read'), 'heat', [
                'rows' => array_map(fn (int $d) => (string) ($wd[$d] ?? $d), $weekdays),
                'cols' => (array) __('notification::dashboard.bands'),
                'v' => array_values($grid), 'color' => '#e8175d',
            ], sub: __('notification::dashboard.when_read_hint', ['range' => $range]), colour: '#e8175d'),
        ];
    }

    /**
     * গত ১৪ দিন — ঘণ্টায় কত খবর, আর বাইরের মাধ্যমে কত পৌঁছাল; শূন্যের দিনও বিন্দু।
     *
     * @return list<array<string, string>>
     */
    private static function trend(\Closure $bells, \Closure $jobs): array
    {
        $from = Carbon::today()->subDays(13);
        $bell = $bells()->where('notifications.created_at', '>=', $from)
            ->selectRaw('DATE(notifications.created_at) as d, COUNT(*) as n')->groupByRaw('DATE(notifications.created_at)')->pluck('n', 'd');
        $out = $jobs()->where('status', NotificationJob::SENT)->where('notification_jobs.created_at', '>=', $from)
            ->selectRaw('DATE(notification_jobs.created_at) as d, COUNT(*) as n')->groupByRaw('DATE(notification_jobs.created_at)')->pluck('n', 'd');

        $points = [];

        for ($day = $from->copy(); $day->lte(Carbon::today()); $day->addDay()) {
            $key = $day->toDateString();
            $points[] = ['label' => $day->format('d/m'), 'first' => (string) ($bell[$key] ?? 0), 'second' => (string) ($out[$key] ?? 0)];
        }

        return $points;
    }

    /** @return list<array{label: string, value: string}> */
    private static function byChannel(\Closure $jobs, Carbon $since): array
    {
        $counts = $jobs()->where('notification_jobs.created_at', '>=', $since)
            ->selectRaw('channel, COUNT(*) as n')->groupBy('channel')->pluck('n', 'channel');

        return array_map(fn (string $channel) => [
            'label' => (string) __('notification::channel.names.'.$channel),
            'value' => (string) ($counts[$channel] ?? 0),
        ], NotificationChannel::ALL);
    }
}
