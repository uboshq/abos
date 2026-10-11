<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Models\Branch;
use App\Models\User;
use App\Modules\CreditControl\Services\CreditRisk;
use App\Modules\Customer\Models\CustomerCreditRequest;
use App\Modules\Customer\Models\DealerBinding;
use App\Modules\Sales\Models\CreditDecision;
use App\Modules\Sales\Services\CreditExposure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ বাকি নিয়ন্ত্রণের বাকি রিপোর্টগুলো — রিপোর্ট ইঞ্জিনে, প্রতিটায় চাবি `credit_control.view` আর কোম্পানি, শাখা, বিক্রয়কর্মীর দেয়াল
 * (বাকি নিয়ন্ত্রণ ধাপ ৩.২, ১২ অক্টোবর ২০২৬)।
 *
 * ⓘ মালিকের তালিকা থেকে যা আগেই আছে ([[CreditPositionReports]]): বাকির অবস্থা (= বাকির পাতা, ব্যবহার, অবশিষ্ট, ব্যবহৃত — এক
 * রিপোর্টের কলাম), সীমা পার, মেয়াদ পেরোনো বিল, মেয়াদে বন্ধ, অপেক্ষার অনুরোধ। এখানে নতুনগুলো: বয়সের ছক, বেশি ঝুঁকির গ্রাহক,
 * পরিশোধের আচরণ, দেয়ালে থামা, দেয়ালের সাক্ষ্য, সীমা বদলের নিরীক্ষা, অনুরোধের ইতিহাস, শেষ হয়ে আসা অস্থায়ী সীমা, শাখা আর
 * বিক্রয়কর্মী ধরে ব্যবহৃত বাকি। ঝুঁকির ধারা [[CreditRiskTrend]]-এ।
 *
 * ⛔ এক হিসাব: প্রতিটা অঙ্ক দেয়ালের নিজের হিসাব ([[CreditExposure]]) বা মাত্রার উপকরণ ([[CreditRisk::factsFor()]]) থেকে।
 */
final class CreditControlLists
{
    public const AGEING = 'credit_control.ageing';

    public const HIGH_RISK = 'credit_control.high_risk';

    public const BEHAVIOUR = 'credit_control.payment_behaviour';

    public const BREACHES = 'credit_control.limit_breaches';

    public const EVIDENCE = 'credit_control.wall_evidence';

    public const CHANGES = 'credit_control.limit_changes';

    public const REQUESTS = 'credit_control.limit_requests';

    public const TEMPORARY = 'credit_control.temporary_limits';

    public const BY_BRANCH = 'credit_control.exposure_by_branch';

    public const BY_SALESPERSON = 'credit_control.exposure_by_salesperson';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::ageing());
        $engine->register(self::highRisk());
        $engine->register(self::behaviour());
        $engine->register(self::decisions(self::BREACHES, 'credit_control::lists.breaches_title', CreditDecision::STOP));
        $engine->register(self::decisions(self::EVIDENCE, 'credit_control::lists.evidence_title', null));
        $engine->register(self::changes());
        $engine->register(self::requests());
        $engine->register(self::temporary());
        $engine->register(self::byBranch());
        $engine->register(self::bySalesperson());
    }

    /** বয়সের ছক — গ্রাহক × সাত ধাপ, বিলের বাকি দেয়ালের হিসাবে */
    private static function ageing(): ReportDefinition
    {
        $buckets = array_keys(CreditPositionReports::BUCKETS);

        return new ReportDefinition(
            key: self::AGEING,
            permission: 'credit_control.view',
            title: 'credit_control::lists.ageing_title',
            filters: ['branch', 'salesman_id'],
            query: function (array $f) use ($buckets): Builder {
                $sums = array_fill_keys($buckets, []);

                foreach (app(CreditExposure::class)->openBills(CreditPositionReports::customers($f)) as $id => $bills) {
                    foreach ($bills as $bill) {
                        $b = CreditPositionReports::bucketOf($bill['days_overdue']);
                        $sums[$b][$id] = bcadd($sums[$b][$id] ?? '0', $bill['unpaid'], 4);
                    }
                }

                $ids = array_values(array_unique(array_merge(...array_map('array_keys', array_values($sums)))));
                $query = CreditPositionReports::base($f, $ids);
                $total = [];

                foreach ($buckets as $b) {
                    $query->selectRaw(CreditPositionReports::cases($sums[$b]).' as '.$b);
                    foreach ($sums[$b] as $id => $v) {
                        $total[$id] = bcadd($total[$id] ?? '0', $v, 4);
                    }
                }

                return $query->selectRaw(CreditPositionReports::cases($total).' as total')
                    ->orderByRaw(CreditPositionReports::cases($total).' DESC')
                    ->orderBy('customers.code');
            },
            columns: [
                ...CreditPositionReports::identityColumns(),
                ...array_map(fn (string $b) => ['key' => $b, 'label' => 'credit_control::report.bucket_'.$b, 'type' => ReportColumn::MONEY], $buckets),
                ['key' => 'total', 'label' => 'credit_control::lists.total', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /** বেশি আর চরম ঝুঁকির গ্রাহক — মাত্রা, নম্বর আর প্রতিটা কারণ ([[CreditRisk]]) */
    private static function highRisk(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::HIGH_RISK,
            permission: 'credit_control.view',
            title: 'credit_control::lists.high_risk_title',
            filters: ['branch', 'salesman_id'],
            query: function (array $f): Builder {
                $customers = CreditPositionReports::customers($f);
                $risk = app(CreditRisk::class);
                $facts = $risk->factsFor($customers);
                $ratings = array_filter($risk->rateAll($customers, $facts),
                    fn (array $r) => in_array($r['level'], [CreditRisk::HIGH, CreditRisk::CRITICAL], true));

                return CreditPositionReports::base($f, array_keys($ratings))
                    ->selectRaw(CreditPositionReports::texts(array_map(fn (array $r) => __('credit_control::risk.'.$r['level']), $ratings)).' as level')
                    ->selectRaw(CreditPositionReports::cases(array_map(fn (array $r) => (string) $r['score'], $ratings)).' as score')
                    ->selectRaw(CreditPositionReports::texts(array_map(fn (array $r) => implode(' · ', $r['reasons']), $ratings)).' as reasons')
                    ->selectRaw(CreditPositionReports::cases(array_map(fn (array $x) => $x['exposure'], array_intersect_key($facts, $ratings))).' as exposure')
                    ->selectRaw(CreditPositionReports::cases(array_map(fn (array $x) => $x['overdue'], array_intersect_key($facts, $ratings))).' as overdue')
                    ->orderByRaw(CreditPositionReports::cases(array_map(fn (array $r) => (string) $r['score'], $ratings)).' DESC')
                    ->orderBy('customers.code');
            },
            columns: [
                ...CreditPositionReports::identityColumns(),
                ['key' => 'level', 'label' => 'credit_control::risk.title'],
                ['key' => 'score', 'label' => 'credit_control::risk.score', 'type' => ReportColumn::QUANTITY, 'total' => false],
                ['key' => 'reasons', 'label' => 'credit_control::risk.reasons'],
                ['key' => 'exposure', 'label' => 'credit_control::report.exposure', 'type' => ReportColumn::MONEY],
                ['key' => 'overdue', 'label' => 'credit_control::report.overdue', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /** পরিশোধের আচরণ — মাত্রার কাঁচা উপকরণ, প্রতিটা গ্রাহকে ([[CreditRisk::factsFor()]]) */
    private static function behaviour(): ReportDefinition
    {
        $fields = ['credit_days', 'open', 'overdue_bills', 'oldest', 'stops', 'bounces', 'blocks'];

        return new ReportDefinition(
            key: self::BEHAVIOUR,
            permission: 'credit_control.view',
            title: 'credit_control::lists.behaviour_title',
            filters: ['branch', 'salesman_id'],
            query: function (array $f) use ($fields): Builder {
                $customers = CreditPositionReports::customers($f);
                $risk = app(CreditRisk::class);
                $facts = $risk->factsFor($customers);
                $ratings = $risk->rateAll($customers, $facts);
                $query = CreditPositionReports::base($f, array_keys($facts));

                foreach ($fields as $field) {
                    $query->selectRaw(CreditPositionReports::cases(array_map(fn (array $x) => (string) $x[$field], $facts)).' as '.$field);
                }

                return $query
                    ->selectRaw(CreditPositionReports::cases(array_map(fn (array $x) => $x['overdue'], $facts)).' as overdue')
                    ->selectRaw(CreditPositionReports::texts(array_map(fn (array $r) => __('credit_control::risk.'.$r['level']), $ratings)).' as level')
                    ->orderByRaw(CreditPositionReports::cases(array_map(fn (array $r) => (string) $r['score'], $ratings)).' DESC')
                    ->orderBy('customers.code');
            },
            columns: [
                ...CreditPositionReports::identityColumns(),
                ...array_map(fn (string $k) => ['key' => $k, 'label' => 'credit_control::lists.'.$k, 'type' => ReportColumn::QUANTITY, 'total' => false], $fields),
                ['key' => 'overdue', 'label' => 'credit_control::report.overdue', 'type' => ReportColumn::MONEY],
                ['key' => 'level', 'label' => 'credit_control::risk.title'],
            ],
        );
    }

    /** দেয়ালের সাক্ষ্য — সব সিদ্ধান্ত, বা কেবল থামাগুলো (সীমা পারের চেষ্টা) */
    private static function decisions(string $key, string $title, ?string $result): ReportDefinition
    {
        return new ReportDefinition(
            key: $key,
            permission: 'credit_control.view',
            title: $title,
            filters: ['date_range', 'branch', 'salesman_id'],
            query: function (array $f) use ($result): Builder {
                $pdo = DB::getPdo();
                $stage = 'CASE d.stage';
                foreach (CreditDecision::STAGES as $s) {
                    $stage .= ' WHEN '.$pdo->quote($s).' THEN '.$pdo->quote((string) __('credit_control::profile.stage_'.$s));
                }
                $why = 'CASE d.reason';
                foreach (CreditDecision::REASONS as $r) {
                    $why .= ' WHEN '.$pdo->quote($r).' THEN '.$pdo->quote((string) __('credit_control::profile.reason_'.$r));
                }

                return self::onCustomers(DB::table('sal_credit_decisions as d')->join('customers', 'customers.id', '=', 'd.customer_id'), $f, 'd')
                    ->when($result !== null, fn ($q) => $q->where('d.result', $result))
                    ->whereBetween(DB::raw('DATE(d.created_at)'), [$f['from'], $f['to']])
                    ->selectRaw('DATE(d.created_at) as decided_on, d.paper_no')
                    ->selectRaw($stage.' ELSE d.stage END as stage')
                    ->selectRaw($why.' ELSE d.reason END as reason')
                    ->selectRaw('d.adding, d.paying_now, d.credit_limit, d.exposure_after, d.message')
                    ->orderByDesc('d.created_at')
                    ->orderByDesc('d.id');
            },
            columns: [
                ['key' => 'decided_on', 'label' => 'credit_control::profile.when', 'type' => ReportColumn::DATE],
                ...CreditPositionReports::identityColumns(),
                ['key' => 'stage', 'label' => 'credit_control::profile.where'],
                ['key' => 'paper_no', 'label' => 'credit_control::profile.paper'],
                ['key' => 'adding', 'label' => 'credit_control::profile.this_paper', 'type' => ReportColumn::MONEY],
                ['key' => 'paying_now', 'label' => 'credit_control::lists.paying_now', 'type' => ReportColumn::MONEY],
                ['key' => 'exposure_after', 'label' => 'credit_control::profile.after', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'credit_limit', 'label' => 'credit_control::profile.limit', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'reason', 'label' => 'credit_control::profile.result'],
                ['key' => 'message', 'label' => 'credit_control::lists.message'],
            ],
        );
    }

    /** সীমা বদলের নিরীক্ষা — প্রতিটা বদল: কে, কখন, আগে, পরে, কেন, কোন সইয়ে ([[CustomerCreditChange]]) */
    private static function changes(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::CHANGES,
            permission: 'credit_control.view',
            title: 'credit_control::lists.changes_title',
            filters: ['date_range', 'branch', 'salesman_id'],
            query: function (array $f): Builder {
                $pdo = DB::getPdo();
                $what = 'CASE c.field';
                foreach (['credit_limit', 'credit_days', 'temp_credit_limit', 'temp_credit_limit_until'] as $field) {
                    $what .= ' WHEN '.$pdo->quote($field).' THEN '.$pdo->quote((string) __('customer::credit_history.'.$field));
                }

                return self::onCustomers(DB::table('customer_credit_changes as c')->join('customers', 'customers.id', '=', 'c.customer_id'), $f, 'c')
                    ->leftJoin('users as u', 'u.id', '=', 'c.changed_by')
                    ->whereBetween(DB::raw('DATE(c.created_at)'), [$f['from'], $f['to']])
                    ->selectRaw('DATE(c.created_at) as changed_on')
                    ->selectRaw($what.' ELSE c.field END as what')
                    ->selectRaw('c.old_value, c.new_value, u.name as changed_by, c.reason, c.approval_id')
                    ->orderByDesc('c.created_at')
                    ->orderByDesc('c.id');
            },
            columns: [
                ['key' => 'changed_on', 'label' => 'credit_control::profile.when', 'type' => ReportColumn::DATE],
                ...CreditPositionReports::identityColumns(),
                ['key' => 'what', 'label' => 'credit_control::profile.what'],
                ['key' => 'old_value', 'label' => 'credit_control::profile.old'],
                ['key' => 'new_value', 'label' => 'credit_control::profile.new'],
                ['key' => 'changed_by', 'label' => 'credit_control::profile.who'],
                ['key' => 'reason', 'label' => 'credit_control::profile.reason'],
                ['key' => 'approval_id', 'label' => 'credit_control::profile.signature', 'type' => ReportColumn::TEXT],
            ],
        );
    }

    /** সীমা বাড়ানোর অনুরোধের ইতিহাস — না হওয়া, ফিরিয়ে নেওয়া আর মেয়াদ পেরোনোগুলোসহ */
    private static function requests(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::REQUESTS,
            permission: 'credit_control.view',
            title: 'credit_control::lists.requests_title',
            filters: ['date_range', 'branch', 'salesman_id'],
            query: function (array $f): Builder {
                $pdo = DB::getPdo();
                $label = fn (string $s) => $pdo->quote((string) __('customer::credit_request.status_'.$s));
                // ⓘ পর্দার অবস্থা [[CustomerCreditRequest::shownStatus()]]-এর একই নিয়ম
                $status = "CASE WHEN r.status = 'pending' AND a.status = 'cancelled' THEN ".$label('cancelled')
                    ." WHEN r.status = 'pending' AND r.kind = 'temporary' AND r.temp_until < ".$pdo->quote(Carbon::today()->toDateString()).' THEN '.$label('expired');
                foreach ([CustomerCreditRequest::PENDING, CustomerCreditRequest::APPROVED, CustomerCreditRequest::REJECTED,
                    CustomerCreditRequest::CANCELLED, CustomerCreditRequest::EXPIRED, CustomerCreditRequest::VOID] as $s) {
                    $status .= " WHEN r.status = '{$s}' THEN ".$label($s);
                }
                $kind = "CASE r.kind WHEN 'temporary' THEN ".$pdo->quote((string) __('customer::credit_request.kind_temporary'))
                    .' ELSE '.$pdo->quote((string) __('customer::credit_request.kind_limit')).' END';

                return self::onCustomers(DB::table('customer_credit_requests as r')->join('customers', 'customers.id', '=', 'r.customer_id'), $f, 'r')
                    ->leftJoin('approvals as a', 'a.id', '=', 'r.approval_id')
                    ->leftJoin('users as u', 'u.id', '=', 'r.requested_by')
                    ->whereBetween(DB::raw('DATE(r.created_at)'), [$f['from'], $f['to']])
                    ->selectRaw('DATE(r.created_at) as asked_on, r.current_limit, r.requested_limit, r.temp_until, r.reason, u.name as asked_by')
                    ->selectRaw($kind.' as kind')
                    ->selectRaw($status.' ELSE r.status END as status')
                    ->orderByDesc('r.created_at')
                    ->orderByDesc('r.id');
            },
            columns: [
                ['key' => 'asked_on', 'label' => 'customer::credit_request.asked_on', 'type' => ReportColumn::DATE],
                ...CreditPositionReports::identityColumns(),
                ['key' => 'kind', 'label' => 'customer::credit_request.kind'],
                ['key' => 'current_limit', 'label' => 'credit_control::report.limit', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'requested_limit', 'label' => 'customer::credit_request.amount', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'temp_until', 'label' => 'customer::credit_request.temp_until', 'type' => ReportColumn::DATE],
                ['key' => 'asked_by', 'label' => 'customer::credit_request.asked_by'],
                ['key' => 'reason', 'label' => 'customer::credit_request.reason'],
                ['key' => 'status', 'label' => 'customer::credit_request.status'],
            ],
        );
    }

    /** চলতি অস্থায়ী সীমা — শেষ দিন কাছেরটা আগে, আর কত দিন বাকি */
    private static function temporary(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::TEMPORARY,
            permission: 'credit_control.view',
            title: 'credit_control::lists.temporary_title',
            filters: ['branch', 'salesman_id'],
            query: function (array $f): Builder {
                $today = Carbon::today()->toDateString();

                return CreditPositionReports::base($f, array_keys(CreditPositionReports::customers($f)))
                    ->whereNotNull('customers.temp_credit_limit')
                    ->where('customers.temp_credit_limit_until', '>=', $today)
                    ->selectRaw('COALESCE(customers.credit_limit, 0) as credit_limit, customers.temp_credit_limit, customers.temp_credit_limit_until')
                    ->selectRaw('DATEDIFF(customers.temp_credit_limit_until, ?) as days_left', [$today])
                    ->orderBy('customers.temp_credit_limit_until')
                    ->orderBy('customers.code');
            },
            columns: [
                ...CreditPositionReports::identityColumns(),
                ['key' => 'credit_limit', 'label' => 'credit_control::profile.limit', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'temp_credit_limit', 'label' => 'credit_control::profile.temporary_limit', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'temp_credit_limit_until', 'label' => 'customer::credit_request.temp_until', 'type' => ReportColumn::DATE],
                ['key' => 'days_left', 'label' => 'credit_control::lists.days_left', 'type' => ReportColumn::QUANTITY, 'total' => false],
            ],
        );
    }

    /** শাখা ধরে — সীমা, ব্যবহৃত, অবশিষ্ট, মেয়াদ পেরোনো, গ্রাহক (গ্রাহকের নিজের শাখা) */
    private static function byBranch(): ReportDefinition
    {
        return self::grouped(self::BY_BRANCH, 'credit_control::lists.by_branch_title', 'credit_control::lists.branch',
            fn (array $customers) => array_map(fn ($c) => (int) ($c->branch_id ?? 0), $customers),
            fn (array $keys) => Branch::query()->whereIn('id', $keys)->get()->mapWithKeys(fn (Branch $b) => [$b->id => $b->name()])->all(),
            'credit_control::dashboard.no_branch');
    }

    /** বিক্রয়কর্মী ধরে — যিনি আজ ডিলারের সাথে বাঁধা; একাধিক হলে প্রথম বাঁধন ([[CreditDashboard::bySalesperson()]]-এর নিয়ম) */
    private static function bySalesperson(): ReportDefinition
    {
        return self::grouped(self::BY_SALESPERSON, 'credit_control::lists.by_salesperson_title', 'credit_control::lists.salesperson',
            fn (array $customers) => (function () use ($customers): array {
                $owner = DealerBinding::query()->current()->whereIn('customer_id', array_keys($customers) ?: [0])
                    ->orderBy('id')->get(['customer_id', 'user_id'])->unique('customer_id')->pluck('user_id', 'customer_id');

                return array_map(fn ($c) => (int) ($owner[$c->id] ?? 0), $customers);
            })(),
            fn (array $keys) => User::query()->whereIn('id', $keys)->pluck('name', 'id')->all(),
            'credit_control::dashboard.unbound');
    }

    /**
     * দল ধরে যোগ — প্রতিটা দলের একটা সারি, অঙ্ক দেয়ালের হিসাবে (অগ্রিমের ঋণাত্মক ব্যবহৃত শূন্য ধরা, ড্যাশবোর্ডের মতোই)।
     *
     * @param  \Closure(array<int, \App\Modules\Customer\Models\Customer>): array<int, int>  $groupOf
     * @param  \Closure(list<int>): array<int, string>  $names
     */
    private static function grouped(string $key, string $title, string $groupLabel, \Closure $groupOf, \Closure $names, string $none): ReportDefinition
    {
        return new ReportDefinition(
            key: $key,
            permission: 'credit_control.view',
            title: $title,
            filters: ['branch', 'salesman_id'],
            // ⓘ নিজেই দল ধরে — শাখায় আবার ভাগ করলে একই অঙ্ক দুইবার দেখাত
            splitByBranch: false,
            query: function (array $f) use ($groupOf, $names, $none): Builder {
                $customers = CreditPositionReports::customers($f);
                $credit = app(CreditExposure::class);
                $positions = $credit->positionsFor($customers);
                $groups = $groupOf($customers);
                $overdue = [];
                foreach ($credit->openBills($customers) as $id => $bills) {
                    foreach ($bills as $bill) {
                        if ($bill['days_overdue'] > 0) {
                            $overdue[$id] = bcadd($overdue[$id] ?? '0', $bill['unpaid'], 4);
                        }
                    }
                }

                $sum = [];
                foreach ($positions as $id => $p) {
                    $g = $groups[$id] ?? 0;
                    $sum[$g] ??= ['customers' => 0, 'limit' => '0', 'exposure' => '0', 'available' => '0', 'overdue' => '0'];
                    $sum[$g]['customers']++;
                    $sum[$g]['limit'] = bcadd($sum[$g]['limit'], $p['limit'], 4);
                    $sum[$g]['exposure'] = bcadd($sum[$g]['exposure'], bccomp($p['exposure'], '0', 4) > 0 ? $p['exposure'] : '0', 4);
                    $sum[$g]['available'] = bcadd($sum[$g]['available'], $p['available'], 4);
                    $sum[$g]['overdue'] = bcadd($sum[$g]['overdue'], $overdue[$id] ?? '0', 4);
                }

                $label = $names(array_values(array_filter(array_keys($sum))));
                $rows = null;
                foreach ($sum ?: [0 => null] as $g => $s) {
                    $row = DB::query()->selectRaw(
                        '? as group_name, CAST(? AS SIGNED) as customers, CAST(? AS DECIMAL(18,4)) as credit_limit, '
                        .'CAST(? AS DECIMAL(18,4)) as exposure, CAST(? AS DECIMAL(18,4)) as available, CAST(? AS DECIMAL(18,4)) as overdue',
                        $s === null ? ['—', 0, 0, 0, 0, 0] : [
                            $g === 0 ? (string) __($none) : (string) ($label[$g] ?? '#'.$g),
                            $s['customers'], $s['limit'], $s['exposure'], $s['available'], $s['overdue'],
                        ],
                    );
                    $rows = $rows === null ? $row : $rows->unionAll($row);
                }

                return DB::query()->fromSub($rows, 'g')
                    ->when($sum === [], fn ($q) => $q->whereRaw('1 = 0'))
                    ->select('g.*')
                    ->orderByDesc('g.exposure');
            },
            columns: [
                ['key' => 'group_name', 'label' => $groupLabel],
                ['key' => 'customers', 'label' => 'credit_control::lists.customers', 'type' => ReportColumn::QUANTITY],
                ['key' => 'credit_limit', 'label' => 'credit_control::report.limit', 'type' => ReportColumn::MONEY],
                ['key' => 'exposure', 'label' => 'credit_control::report.exposure', 'type' => ReportColumn::MONEY],
                ['key' => 'available', 'label' => 'credit_control::report.available', 'type' => ReportColumn::MONEY],
                ['key' => 'overdue', 'label' => 'credit_control::report.overdue', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * গ্রাহকের দেয়াল কাগজের সারিতে — কোম্পানি, শাখা (গ্রাহকের), বিক্রয়কর্মী কেবল নিজের ডিলার, আর বিক্রয়কর্মী ছাঁকনি।
     *
     * @param  array<string, mixed>  $f
     */
    private static function onCustomers(Builder $query, array $f, string $alias): Builder
    {
        return $query
            ->where($alias.'.company_id', $f['company_id'])
            ->where('customers.company_id', $f['company_id'])
            ->tap(ReportEngine::branchWall($f, 'customers.branch_id'))
            ->tap(ReportEngine::dealerWall($f, 'customers.id'))
            ->when(! empty($f['salesman_id']), fn ($q) => $q->whereIn('customers.id', DealerBinding::query()
                ->current()->where('user_id', (int) $f['salesman_id'])->select('customer_id')))
            ->select(['customers.id', 'customers.code as customer_code'])
            ->selectRaw(CreditPositionReports::name().' as customer_name')
            ->selectRaw("'".CreditPositionReports::PROFILE."' as party_type_literal");
    }
}
