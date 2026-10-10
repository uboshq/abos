<?php

declare(strict_types=1);

namespace App\Modules\Notification\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\NotificationKinds;
use App\Models\NotificationChannel;
use App\Models\NotificationSuppression;
use App\Modules\Notification\Reports\Filters\ChannelFilter;
use App\Modules\Notification\Reports\Filters\ModuleFilter;
use App\Modules\Notification\Reports\Filters\PriorityFilter;
use App\Modules\Notification\Reports\Filters\StatusFilter;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ বিজ্ঞপ্তির ১৭টা রিপোর্ট — মালিকের স্পেক §১৭ (বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ৪), রিপোর্ট ইঞ্জিনের উপর।
 *
 * ── ⛔ দেয়াল ─────────────────────────────────────────────────────────
 *   · কোম্পানি — প্রতিটা কোয়েরিতে `company_id`।
 *   · শাখা — খবরের সারির (`notifications.branch_id`) শাখা দিয়ে, [[ReportEngine::branchWall()]]; ডেলিভারির সারিগুলো সেই খবরের
 *     সাথে জোড়া, তাই একই দেয়াল। কেবল দুইটা রিপোর্ট গোটা কোম্পানির (মাধ্যমের প্রাপ্যতা আর নিরীক্ষা — ওদের কোনো শাখা নেই), আর
 *     শাখায় আটকানো মানুষের কাছে ওগুলো বন্ধ (`WHOLE_COMPANY`)।
 *   · ONLY_FULL_GROUP_BY — যা বাছা হয় তার সব দলে।
 *
 * ⓘ ছাঁকনি: তারিখ, শাখা, আর যেখানে খাটে — মডিউল, প্রাপক, গুরুত্ব, মাধ্যম, অবস্থা। CSV/XLSX/PDF রিপোর্টের পর্দা থেকে, কেবল
 * রপ্তানির চাবি থাকলে ([[NotificationReportController]])।
 */
final class NotificationReports
{
    public const PERMISSION = 'notification.reports';

    public static function registerAll(ReportEngine $engine): void
    {
        foreach (self::definitions() as $definition) {
            $engine->register($definition);
        }
    }

    /** @return list<ReportDefinition> */
    public static function definitions(): array
    {
        $count = fn (string $key, string $label) => ['key' => $key, 'label' => 'notification::report.col.'.$label, 'type' => ReportColumn::QUANTITY];
        $text = fn (string $key, string $label, ?string $width = null) => array_filter(['key' => $key, 'label' => 'notification::report.col.'.$label, 'type' => ReportColumn::TEXT, 'width' => $width]);
        $percent = fn (string $key, string $label) => ['key' => $key, 'label' => 'notification::report.col.'.$label, 'type' => ReportColumn::PERCENT, 'total' => false];

        return [
            // ১. সারসংক্ষেপ — দিন ধরে
            self::make('summary', ['date_range', 'branch', 'notify_module_id', 'notify_priority_id', 'notify_recipient_id'], fn (array $f) => self::bells($f)
                ->selectRaw('DATE(n.created_at) as day, COUNT(*) as received, SUM(n.read_at IS NOT NULL) as read_n, SUM(n.read_at IS NULL) as unread_n, SUM(n.archived_at IS NOT NULL) as archived_n, COUNT(DISTINCT n.event_id) as events')
                ->groupByRaw('DATE(n.created_at)')->orderByRaw('DATE(n.created_at) DESC'),
                [['key' => 'day', 'label' => 'notification::report.col.day', 'type' => ReportColumn::DATE],
                    $count('events', 'events'), $count('received', 'received'), $count('read_n', 'read'), $count('unread_n', 'unread'), $count('archived_n', 'archived')]),

            // ২. প্রাপক ধরে
            self::make('user_wise', ['date_range', 'branch', 'notify_module_id', 'notify_priority_id'], fn (array $f) => self::bells($f)
                ->join('users as u', 'u.id', '=', 'n.user_id')
                ->selectRaw('u.name as recipient, COUNT(*) as received, SUM(n.read_at IS NOT NULL) as read_n, SUM(n.read_at IS NULL) as unread_n, SUM(n.priority = ?) as critical_n', ['critical'])
                ->groupBy('n.user_id', 'u.name')->orderByRaw('COUNT(*) DESC'),
                [$text('recipient', 'recipient'), $count('received', 'received'), $count('read_n', 'read'), $count('unread_n', 'unread'), $count('critical_n', 'critical')],
                rankBy: 'received'),

            // ৩. মডিউল ধরে
            self::make('module_wise', ['date_range', 'branch', 'notify_priority_id'], fn (array $f) => self::bells($f)
                ->selectRaw(self::module('n.module').' as module, COUNT(*) as received, SUM(n.read_at IS NOT NULL) as read_n, SUM(n.read_at IS NULL) as unread_n')
                ->groupBy('n.module')->orderByRaw('COUNT(*) DESC'),
                [$text('module', 'module'), $count('received', 'received'), $count('read_n', 'read'), $count('unread_n', 'unread')],
                rankBy: 'received'),

            // ৪. গুরুত্ব ধরে
            self::make('priority_wise', ['date_range', 'branch', 'notify_module_id'], fn (array $f) => self::bells($f)
                ->selectRaw(self::priority('n.priority').' as priority, COUNT(*) as received, SUM(n.read_at IS NOT NULL) as read_n, SUM(n.read_at IS NULL) as unread_n')
                ->groupBy('n.priority')->orderByRaw("FIELD(n.priority, 'critical', 'high', 'normal', 'low')"),
                [$text('priority', 'priority'), $count('received', 'received'), $count('read_n', 'read'), $count('unread_n', 'unread')]),

            // ৫. মাধ্যম ধরে পৌঁছানো
            self::make('channel_delivery', ['date_range', 'branch', 'notify_channel_id', 'notify_module_id', 'notify_recipient_id'], fn (array $f) => self::jobs($f)
                ->selectRaw(self::channel('j.channel')." as channel, COUNT(*) as total, SUM(j.status = 'sent') as sent_n, SUM(j.status = 'dead') as dead_n, SUM(j.status IN ('queued','processing','retrying','held')) as open_n, SUM(j.status = 'cancelled') as cancelled_n, ROUND(100 * SUM(j.status = 'sent') / COUNT(*), 2) as success")
                ->groupBy('j.channel')->orderBy('j.channel'),
                [$text('channel', 'channel'), $count('total', 'total'), $count('sent_n', 'delivered'), $count('dead_n', 'failed'), $count('open_n', 'pending'), $count('cancelled_n', 'cancelled'), $percent('success', 'success')]),

            // ৬. পৌঁছেছে / ব্যর্থ / অপেক্ষায় — অবস্থা ধরে
            self::make('delivery_status', ['date_range', 'branch', 'notify_channel_id', 'notify_status_id', 'notify_recipient_id'], fn (array $f) => self::jobs($f)
                ->selectRaw(self::named('j.status', self::words('notification::delivery.statuses.', ['queued', 'processing', 'sent', 'retrying', 'held', 'dead', 'cancelled'])).' as status, '.self::channel('j.channel').' as channel, COUNT(*) as total, SUM(j.attempts) as attempts_n')
                ->groupBy('j.status', 'j.channel')->orderBy('j.status')->orderBy('j.channel'),
                [$text('status', 'status'), $text('channel', 'channel'), $count('total', 'total'), $count('attempts_n', 'attempts')]),

            // ৭. পড়া / না-পড়া — শ্রেণি ধরে
            self::make('read_unread', ['date_range', 'branch', 'notify_module_id', 'notify_priority_id', 'notify_recipient_id'], fn (array $f) => self::bells($f)
                ->selectRaw(self::named('n.category', self::words('core.notify.category.', NotificationKinds::CATEGORIES)).' as category, COUNT(*) as received, SUM(n.read_at IS NOT NULL) as read_n, SUM(n.read_at IS NULL) as unread_n, ROUND(100 * SUM(n.read_at IS NOT NULL) / COUNT(*), 2) as read_rate')
                ->groupBy('n.category')->orderBy('n.category'),
                [$text('category', 'category'), $count('received', 'received'), $count('read_n', 'read'), $count('unread_n', 'unread'), $percent('read_rate', 'read_rate')]),

            // ৮. চেষ্টার ইতিহাস — প্রতিটা চেষ্টা
            self::make('attempt_history', ['date_range', 'branch', 'notify_channel_id'], fn (array $f) => self::attempts($f)
                ->join('users as u', 'u.id', '=', 'j.user_id')
                ->select(['a.created_at as at', 'u.name as recipient', DB::raw(self::channel('a.channel').' as channel'), 'a.provider', DB::raw(self::outcome('a.outcome').' as outcome'), 'a.provider_ref', 'a.error', 'a.attempt', 'a.duration_ms'])
                ->orderByDesc('a.id'),
                [['key' => 'at', 'label' => 'notification::report.col.when', 'type' => ReportColumn::DATE], $text('recipient', 'recipient'), $text('channel', 'channel'),
                    $text('provider', 'provider'), $text('outcome', 'outcome'), $text('provider_ref', 'reference'), $text('error', 'error'),
                    ['key' => 'attempt', 'label' => 'notification::report.col.attempt', 'type' => ReportColumn::QUANTITY, 'total' => false],
                    ['key' => 'duration_ms', 'label' => 'notification::report.col.duration', 'type' => ReportColumn::QUANTITY, 'total' => false]]),

            // ৯. প্রোভাইডারের ভুল — একই ভুল একসাথে
            self::make('provider_errors', ['date_range', 'branch', 'notify_channel_id'], fn (array $f) => self::attempts($f)
                ->where('a.outcome', '!=', 'sent')
                ->selectRaw(self::channel('a.channel').' as channel, a.provider as provider, '.self::outcome('a.outcome').' as outcome, a.error as error, COUNT(*) as total, MAX(a.created_at) as last_at')
                ->groupBy('a.channel', 'a.provider', 'a.outcome', 'a.error')->orderByRaw('COUNT(*) DESC'),
                [$text('channel', 'channel'), $text('provider', 'provider'), $text('outcome', 'outcome'), $text('error', 'error'), $count('total', 'total'),
                    ['key' => 'last_at', 'label' => 'notification::report.col.last', 'type' => ReportColumn::DATE]],
                rankBy: 'total'),

            // ১০. আবার চেষ্টা আর ব্যর্থ-তালিকা
            self::make('retry_dead_letter', ['date_range', 'branch', 'notify_channel_id'], fn (array $f) => self::jobs($f)
                ->selectRaw(self::channel('j.channel')." as channel, SUM(j.attempts > 1) as retried, SUM(j.attempts > 1 AND j.status = 'sent') as recovered, SUM(j.status = 'dead') as dead_n, SUM(j.status = 'cancelled') as cancelled_n, SUM(j.resolved_by IS NOT NULL) as by_hand")
                ->groupBy('j.channel')->orderBy('j.channel'),
                [$text('channel', 'channel'), $count('retried', 'retried'), $count('recovered', 'recovered'), $count('dead_n', 'failed'), $count('cancelled_n', 'cancelled'), $count('by_hand', 'by_hand')]),

            // ১১. নিয়ম কতবার খাটল
            self::make('rule_execution', ['date_range', 'branch', 'notify_module_id'], fn (array $f) => self::rules($f),
                [$text('rule', 'rule'), $text('event', 'event'), $count('events', 'events'), $count('received', 'received'),
                    ['key' => 'last_at', 'label' => 'notification::report.col.last', 'type' => ReportColumn::DATE]],
                rankBy: 'events'),

            // ১২. টেমপ্লেট কতবার বসল
            self::make('template_usage', ['date_range', 'branch'], fn (array $f) => self::bells($f)
                ->join('notification_events as e', 'e.id', '=', 'n.event_id')
                ->join('notification_template_versions as v', 'v.id', '=', 'e.template_version_id')
                ->join('notification_templates as t', 't.id', '=', 'v.template_id')
                ->selectRaw('t.code as code, t.name as template, v.version as version, COUNT(DISTINCT e.id) as events, COUNT(*) as received')
                ->groupBy('t.id', 't.code', 't.name', 'v.id', 'v.version')->orderByRaw('COUNT(*) DESC'),
                [$text('code', 'code', '9rem'), $text('template', 'template'), ['key' => 'version', 'label' => 'notification::report.col.version', 'type' => ReportColumn::QUANTITY, 'total' => false],
                    $count('events', 'events'), $count('received', 'received')]),

            // ১৩. ওপরে পাঠানো আর মনে করানো — অনুমোদন ইঞ্জিনের খবর
            self::make('escalation', ['date_range', 'branch', 'notify_recipient_id'], fn (array $f) => self::bells($f)
                ->whereIn('n.type', ['approval.reminder', 'approval.escalated'])
                ->join('users as u', 'u.id', '=', 'n.user_id')
                ->selectRaw(self::named('n.type', ['approval.reminder' => (string) __('core.notify.kind.approval_reminder'), 'approval.escalated' => (string) __('core.notify.kind.approval_escalated')]).' as kind, u.name as recipient, COUNT(*) as received, SUM(n.read_at IS NULL) as unread_n, MAX(n.created_at) as last_at')
                ->groupBy('n.type', 'n.user_id', 'u.name')->orderBy('n.type')->orderByRaw('COUNT(*) DESC'),
                [$text('kind', 'kind'), $text('recipient', 'recipient'), $count('received', 'received'), $count('unread_n', 'unread'),
                    ['key' => 'last_at', 'label' => 'notification::report.col.last', 'type' => ReportColumn::DATE]]),

            // ১৪. কত দেরিতে পৌঁছাল — খবর থেকে পৌঁছানো পর্যন্ত সেকেন্ড
            self::make('latency', ['date_range', 'branch', 'notify_channel_id'], fn (array $f) => self::jobs($f)
                ->where('j.status', 'sent')->whereNotNull('j.sent_at')
                ->selectRaw(self::channel('j.channel').' as channel, COUNT(*) as total, ROUND(AVG(TIMESTAMPDIFF(SECOND, n.created_at, j.sent_at))) as avg_s, MAX(TIMESTAMPDIFF(SECOND, n.created_at, j.sent_at)) as max_s, SUM(TIMESTAMPDIFF(SECOND, n.created_at, j.sent_at) <= 60) as within_minute')
                ->groupBy('j.channel')->orderBy('j.channel'),
                [$text('channel', 'channel'), $count('total', 'delivered'),
                    ['key' => 'avg_s', 'label' => 'notification::report.col.avg_seconds', 'type' => ReportColumn::QUANTITY, 'total' => false],
                    ['key' => 'max_s', 'label' => 'notification::report.col.max_seconds', 'type' => ReportColumn::QUANTITY, 'total' => false],
                    $count('within_minute', 'within_minute')]),

            // ১৫. মাধ্যমের প্রাপ্যতা — গোটা কোম্পানির; মাধ্যমের কোনো শাখা নেই
            self::make('channel_availability', ['date_range', 'notify_channel_id'], fn (array $f) => self::availability($f),
                [$text('channel', 'channel'), $text('enabled', 'enabled'), $count('attempts', 'attempts'), $count('sent_n', 'delivered'),
                    $percent('error_rate', 'error_rate'), ['key' => 'last_checked_at', 'label' => 'notification::report.col.last_check', 'type' => ReportColumn::DATE],
                    $text('last_check', 'last_check_result')],
                branchless: ReportDefinition::WHOLE_COMPANY),

            // ১৬. পছন্দ আর আটকানো — কেন কী পিছাল বা থামল
            self::make('suppression', ['date_range', 'branch', 'notify_channel_id'], fn (array $f) => self::suppressions($f),
                [$text('reason', 'reason'), $text('channel', 'channel'), $count('total', 'total'), $count('people', 'people')]),

            // ১৭. নিরীক্ষা — গোটা কোম্পানির; খাতার কোনো শাখা নেই
            self::make('audit_report', ['date_range'], fn (array $f) => DB::table('notification_audit_logs as l')
                ->leftJoin('users as u', 'u.id', '=', 'l.actor_id')
                ->where('l.company_id', $f['company_id'])
                ->tap(self::dates($f, 'l.created_at'))
                ->select(['l.created_at as at', 'u.name as actor', DB::raw(self::named('l.action', self::auditActions()).' as action'), 'l.target_type', 'l.target_id', DB::raw(self::named('l.outcome', self::words('notification::audit.outcomes.', ['done', 'denied', 'failed'])).' as outcome')])
                ->orderByDesc('l.id'),
                [['key' => 'at', 'label' => 'notification::report.col.when', 'type' => ReportColumn::DATE], $text('actor', 'actor'), $text('action', 'action'),
                    $text('target_type', 'target'), ['key' => 'target_id', 'label' => 'notification::report.col.target_id', 'type' => ReportColumn::QUANTITY, 'total' => false],
                    $text('outcome', 'outcome')],
                branchless: ReportDefinition::WHOLE_COMPANY),
        ];
    }

    /** @param  list<string>  $filters */
    private static function make(string $name, array $filters, Closure $query, array $columns, ?string $rankBy = null, ?string $branchless = null): ReportDefinition
    {
        return new ReportDefinition(
            key: 'notification.'.$name,
            title: 'notification::report.titles.'.$name,
            query: $query,
            columns: $columns,
            filters: $filters,
            rankBy: $rankBy,
            permission: self::PERMISSION,
            branchless: $branchless,
        );
    }

    /** খবরের সারি — কোম্পানি, শাখা, তারিখ, মডিউল, গুরুত্ব, প্রাপক */
    private static function bells(array $f): Builder
    {
        return DB::table('notifications as n')
            ->where('n.company_id', $f['company_id'])
            ->tap(ReportEngine::branchWall($f, 'n.branch_id'))
            ->tap(self::dates($f, 'n.created_at'))
            ->when(ModuleFilter::value($f['notify_module_id'] ?? null), fn ($q, $m) => $q->where('n.module', $m))
            ->when(PriorityFilter::value($f['notify_priority_id'] ?? null), fn ($q, $p) => $q->where('n.priority', $p))
            ->when($f['notify_recipient_id'] ?? null, fn ($q, $u) => $q->where('n.user_id', (int) $u));
    }

    /** ডেলিভারির সারি — খবরের সাথে জোড়া, তাই খবরের শাখার দেয়াল */
    private static function jobs(array $f): Builder
    {
        return DB::table('notification_jobs as j')
            ->join('notifications as n', 'n.id', '=', 'j.notification_id')
            ->where('j.company_id', $f['company_id'])
            ->when($f['notify_recipient_id'] ?? null, fn ($q, $u) => $q->where('j.user_id', (int) $u))
            ->tap(ReportEngine::branchWall($f, 'n.branch_id'))
            ->tap(self::dates($f, 'j.created_at'))
            ->when(ChannelFilter::value($f['notify_channel_id'] ?? null), fn ($q, $c) => $q->where('j.channel', $c))
            ->when(StatusFilter::value($f['notify_status_id'] ?? null), fn ($q, $s) => $q->where('j.status', $s))
            ->when(ModuleFilter::value($f['notify_module_id'] ?? null), fn ($q, $m) => $q->where('n.module', $m));
    }

    /** প্রতিটা চেষ্টা — ডেলিভারি আর খবরের সাথে জোড়া */
    private static function attempts(array $f): Builder
    {
        return DB::table('notification_delivery_attempts as a')
            ->join('notification_jobs as j', 'j.id', '=', 'a.job_id')
            ->join('notifications as n', 'n.id', '=', 'j.notification_id')
            ->where('a.company_id', $f['company_id'])
            ->tap(ReportEngine::branchWall($f, 'n.branch_id'))
            ->tap(self::dates($f, 'a.created_at'))
            ->when(ChannelFilter::value($f['notify_channel_id'] ?? null), fn ($q, $c) => $q->where('a.channel', $c));
    }

    /** নিয়ম কতবার খাটল — ঘটনার `rule_ids`-এ নিয়মটা আছে কি না (JSON_CONTAINS); প্রাপক খবরের সারি থেকে, শাখার দেয়ালসহ */
    private static function rules(array $f): Builder
    {
        $bells = self::bells($f)
            ->join('notification_events as e', 'e.id', '=', 'n.event_id')
            ->whereNotNull('e.rule_ids')
            ->select(['n.id', 'n.event_id', 'n.created_at', 'e.rule_ids']);

        return DB::table('notification_rules as r')
            ->joinSub($bells, 'b', fn ($join) => $join->whereRaw('JSON_CONTAINS(b.rule_ids, CAST(r.id AS CHAR))'))
            ->where('r.company_id', $f['company_id'])
            ->selectRaw('r.name as rule, r.event as event, COUNT(DISTINCT b.event_id) as events, COUNT(*) as received, MAX(b.created_at) as last_at')
            ->groupBy('r.id', 'r.name', 'r.event')->orderByRaw('COUNT(DISTINCT b.event_id) DESC');
    }

    /** মাধ্যমের প্রাপ্যতা — সাজানো মাধ্যম আর তার চেষ্টা; গোটা কোম্পানির */
    private static function availability(array $f): Builder
    {
        $tries = DB::table('notification_delivery_attempts as a')
            ->where('a.company_id', $f['company_id'])
            ->tap(self::dates($f, 'a.created_at'))
            ->selectRaw("a.channel, COUNT(*) as attempts, SUM(a.outcome = 'sent') as sent_n")
            ->groupBy('a.channel');

        return DB::table('notification_channels as c')
            ->leftJoinSub($tries, 't', 't.channel', '=', 'c.channel')
            ->where('c.company_id', $f['company_id'])
            ->when(ChannelFilter::value($f['notify_channel_id'] ?? null), fn ($q, $ch) => $q->where('c.channel', $ch))
            ->selectRaw(self::channel('c.channel').' as channel, '.self::named("IF(c.enabled, 'on', 'off')", ['on' => (string) __('notification::channel.enabled'), 'off' => (string) __('notification::channel.disabled')])." as enabled, COALESCE(t.attempts, 0) as attempts, COALESCE(t.sent_n, 0) as sent_n,
                IF(COALESCE(t.attempts, 0) = 0, 0, ROUND(100 * (t.attempts - t.sent_n) / t.attempts, 2)) as error_rate,
                c.last_checked_at as last_checked_at, CASE WHEN c.last_check_ok IS NULL THEN '' WHEN c.last_check_ok = 1 THEN ".DB::getPdo()->quote((string) __('notification::report.check_ok')).' ELSE '.DB::getPdo()->quote((string) __('notification::report.check_failed')).' END as last_check')
            ->orderBy('c.channel');
    }

    /** আটকানো আর পিছানো — কারণ আর মাধ্যম ধরে; খবরের শাখার দেয়ালসহ */
    private static function suppressions(array $f): Builder
    {
        return DB::table('notification_suppressions as s')
            ->leftJoin('notifications as n', fn ($join) => $join->on('n.event_id', '=', 's.event_id')->on('n.user_id', '=', 's.user_id'))
            ->where('s.company_id', $f['company_id'])
            ->tap(ReportEngine::branchWall($f, 'n.branch_id'))
            ->tap(self::dates($f, 's.created_at'))
            ->when(ChannelFilter::value($f['notify_channel_id'] ?? null), fn ($q, $c) => $q->where('s.channel', $c))
            ->selectRaw(self::named('s.reason', self::words('notification::report.reasons.', NotificationSuppression::REASONS)).' as reason, '.self::channel('s.channel').' as channel, COUNT(*) as total, COUNT(DISTINCT s.user_id) as people')
            ->groupBy('s.reason', 's.channel')->orderByRaw('COUNT(*) DESC');
    }

    /**
     * ⓘ সংকেত থেকে পড়ার মতো নাম — SQL-এর CASE, যাতে খোঁজা, যোগফল আর রপ্তানি একই লেখা দেখে। দলে থাকা ঘরের প্রকাশ, তাই
     * ONLY_FULL_GROUP_BY-তে চলে। অচেনা সংকেত নিজেই দেখায়।
     *
     * @param  array<string, string>  $labels  সংকেত => নাম
     */
    private static function named(string $column, array $labels): string
    {
        $pdo = DB::getPdo();
        $cases = '';

        foreach ($labels as $code => $label) {
            $cases .= ' WHEN '.$pdo->quote((string) $code).' THEN '.$pdo->quote($label);
        }

        return $cases === '' ? $column : "(CASE {$column}{$cases} ELSE {$column} END)";
    }

    /** @return array<string, string> */
    private static function words(string $prefix, array $codes): array
    {
        $out = [];

        foreach ($codes as $code) {
            $out[$code] = (string) __($prefix.$code);
        }

        return $out;
    }

    private static function outcome(string $column): string
    {
        return self::named($column, self::words('notification::delivery.outcomes.', ['sent', 'transient', 'permanent']));
    }

    /** @return array<string, string> */
    private static function auditActions(): array
    {
        return array_map('strval', (array) __('notification::audit.actions'));
    }

    private static function channel(string $column): string
    {
        return self::named($column, self::words('notification::channel.names.', NotificationChannel::ALL));
    }

    private static function priority(string $column): string
    {
        return self::named($column, self::words('core.notify.priority.', NotificationKinds::PRIORITIES));
    }

    private static function module(string $column): string
    {
        $codes = collect(array_keys(NotificationKinds::all()))
            ->map(fn ($t) => NotificationKinds::classify($t)['module'])->unique()->all();
        $out = [];

        foreach ($codes as $code) {
            $out[$code] = NotificationKinds::sourceLabel($code);
        }

        return self::named($column, $out);
    }

    /** তারিখের সীমা — "শুরু থেকে" বাছলে নিচের সীমা নেই */
    private static function dates(array $f, string $column): Closure
    {
        return function ($query) use ($f, $column): void {
            if (empty($f['all_time']) && ! empty($f['from'])) {
                $query->where($column, '>=', $f['from'].' 00:00:00');
            }

            if (! empty($f['to'])) {
                $query->where($column, '<=', $f['to'].' 23:59:59');
            }
        };
    }
}
