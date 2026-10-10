<?php

declare(strict_types=1);

namespace App\Modules\Notification\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
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
        );
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
