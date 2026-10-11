<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Core\Support\ViewedBranch;
use App\Models\Approval;
use App\Models\AuditTrail;
use App\Models\Branch;
use App\Models\User;
use App\Modules\CreditControl\Reports\CreditPositionReports;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerCreditChange;
use App\Modules\Customer\Models\DealerBinding;
use App\Modules\Sales\Services\CreditExposure;

/**
 * ⭐ বাকি নিয়ন্ত্রণের ড্যাশবোর্ড — এক পর্দায় কার কত বাকি, কে আটকে, কী অপেক্ষায় (বাকি নিয়ন্ত্রণ ধাপ ১.২, ১১ অক্টোবর ২০২৬)।
 *
 * ⛔ এখানে কোনো সূত্র নেই: প্রতিটা গ্রাহকের অঙ্ক দেয়ালের নিজের হিসাব থেকে ([[CreditExposure::positionsFor()]],
 * [[CreditExposure::openBills()]]); এখানে কেবল যোগ আর গোনা। ⓘ তালিকার রিপোর্টও ঠিক একই হিসাব পড়ে
 * ([[CreditPositionReports]]), তাই সংখ্যায় চাপলে যে তালিকা খোলে তার যোগফল সংখ্যাটাই।
 *
 * ⓘ দেয়াল: হেডারে বাছা শাখা আর ব্যবহারকারীর নাগাল ([[Customer::scopeInViewedBranch()]]), আর বিক্রয়কর্মী কেবল নিজের
 * ডিলার (গ্রাহকের মডেলের ডিলার-দেয়াল, ⛔১৬)।
 */
final class CreditDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        $figures = self::figures();
        $t = $figures['totals'];

        return new DashboardDefinition(
            title: __('credit_control::dashboard.title'),
            subtitle: $figures['on'] ? __('credit_control::dashboard.subtitle') : __('credit_control::dashboard.subtitle_off'),
            stats: [
                self::stat('limits', Money::format($t['limit']), 'positions'),
                self::stat('exposure', Money::format($t['exposure']), 'positions'),
                self::stat('available', Money::format($t['available']), 'positions', Stat::GOOD),
                self::stat('overdue', Money::format($t['overdue']), 'overdue-bills', Stat::WARN),
                self::stat('used', $t['used_percent'] === null ? '—' : $t['used_percent'].'%', 'positions'),
                self::stat('over_limit', (string) $t['over_limit'], 'over-limit', Stat::BAD),
                self::stat('overdue_stopped', (string) $t['overdue_stopped'], 'overdue-stopped', Stat::BAD),
                new Stat(
                    label: __('credit_control::dashboard.blocked'),
                    value: (string) $t['blocked'],
                    hint: __('credit_control::dashboard.blocked_hint'),
                    href: route('sales.report.show', ['slug' => 'blocked-customers'] + self::branch()),
                    tone: Stat::BAD,
                    permission: 'sales.report',
                ),
                self::stat('pending_requests', (string) $t['pending_requests'], 'pending-requests', Stat::WARN),
            ],
            panels: array_values(array_filter([
                self::ageing($figures['ageing']),
                self::byBranch($figures),
                self::bySalesperson($figures),
            ])),
            listings: [self::recentChanges($figures['ids']), self::recentBlocks($figures['ids'])],
        );
    }

    /**
     * ড্যাশবোর্ডের সব যোগফল — দেখার দেয়ালের ভিতরের সক্রিয় গ্রাহকদের।
     *
     * ⓘ "ব্যবহৃত" যোগে অগ্রিমে থাকা (ঋণাত্মক) গ্রাহক শূন্য ধরা হয় — একজনের অগ্রিম আরেকজনের বাকি কমায় না।
     *
     * @return array{on: bool, ids: list<int>, totals: array<string, mixed>, ageing: array<string, string>, positions: array<int, array<string, mixed>>, customers: array<int, Customer>}
     */
    public static function figures(): array
    {
        $credit = app(CreditExposure::class);

        $customers = Customer::query()->inViewedBranch()->where('customers.is_active', true)->withOutstanding()->get()
            ->keyBy(fn (Customer $c) => (int) $c->id)->all();
        $positions = $credit->positionsFor($customers);

        $totals = ['limit' => '0', 'exposure' => '0', 'available' => '0', 'overdue' => '0',
            'over_limit' => 0, 'overdue_stopped' => 0, 'blocked' => 0];

        foreach ($positions as $p) {
            $totals['limit'] = bcadd($totals['limit'], $p['limit'], 4);
            $totals['exposure'] = bcadd($totals['exposure'], bccomp($p['exposure'], '0', 4) > 0 ? $p['exposure'] : '0', 4);
            $totals['available'] = bcadd($totals['available'], $p['available'], 4);
            $totals['over_limit'] += $p['state'] === 'over_limit' ? 1 : 0;
            $totals['overdue_stopped'] += $p['state'] === 'overdue' ? 1 : 0;
            $totals['blocked'] += $p['state'] === 'blocked' ? 1 : 0;
        }

        $ageing = array_fill_keys(array_keys(CreditPositionReports::BUCKETS), '0');

        foreach ($credit->openBills($customers) as $bills) {
            foreach ($bills as $bill) {
                $bucket = CreditPositionReports::bucketOf($bill['days_overdue']);
                $ageing[$bucket] = bcadd($ageing[$bucket], $bill['unpaid'], 4);

                if ($bill['days_overdue'] > 0) {
                    $totals['overdue'] = bcadd($totals['overdue'], $bill['unpaid'], 4);
                }
            }
        }

        $totals['used_percent'] = bccomp($totals['limit'], '0', 4) > 0
            ? bcdiv(bcmul($totals['exposure'], '100', 4), $totals['limit'], 1) : null;
        $totals['pending_requests'] = $customers === [] ? 0 : Approval::query()
            ->where('module', 'customer')
            ->where('action', 'credit_limit')
            ->where('status', Approval::PENDING)
            ->where('approvable_type', (new Customer)->getMorphClass())
            ->whereIn('approvable_id', array_keys($customers))
            ->count()
            // ⭐ বাকির পাতা থেকে লেখা অনুরোধ — নিজের কাগজে (ধাপ ২.৫)
            + \App\Modules\CreditControl\Models\CreditLimitRequest::query()
                ->where('status', \App\Modules\CreditControl\Models\CreditLimitRequest::PENDING)
                ->whereIn('customer_id', array_keys($customers))
                ->count();

        return [
            'on' => $credit->isOn(),
            'ids' => array_keys($customers),
            'totals' => $totals,
            'ageing' => $ageing,
            'positions' => $positions,
            'customers' => $customers,
        ];
    }

    private static function stat(string $key, string $value, string $slug, string $tone = Stat::NEUTRAL): Stat
    {
        return new Stat(
            label: __('credit_control::dashboard.'.$key),
            value: $value,
            hint: __('credit_control::dashboard.'.$key.'_hint'),
            href: route('credit_control.report.show', ['slug' => $slug] + self::branch()),
            tone: $tone,
            permission: 'credit_control.view',
        );
    }

    /** @return array<string, int> হেডারে একটা শাখা বাছা থাকলে তালিকাও সেই শাখায় খোলে */
    private static function branch(): array
    {
        $one = ViewedBranch::one();

        return $one === null ? [] : ['branch_id' => $one];
    }

    /** @param  array<string, string>  $ageing */
    private static function ageing(array $ageing): Breakdown
    {
        $href = route('credit_control.report.show', ['slug' => 'overdue-bills'] + self::branch());

        return new Breakdown(
            label: __('credit_control::dashboard.ageing'),
            parts: array_map(fn (string $bucket) => [
                'label' => __('credit_control::report.bucket_'.$bucket),
                'value' => Money::format($ageing[$bucket]),
                'href' => $bucket === 'not_due' ? route('credit_control.report.show', ['slug' => 'positions'] + self::branch()) : $href,
            ], array_keys($ageing)),
            hint: __('credit_control::dashboard.ageing_hint'),
            chart: 'columns',
            dense: true,
        );
    }

    /** @param  array{positions: array<int, array<string, mixed>>, customers: array<int, Customer>}  $figures */
    private static function byBranch(array $figures): ?Breakdown
    {
        $sums = [];

        foreach ($figures['positions'] as $id => $p) {
            $branch = (int) ($figures['customers'][$id]->branch_id ?? 0);
            $sums[$branch] = bcadd($sums[$branch] ?? '0', bccomp($p['exposure'], '0', 4) > 0 ? $p['exposure'] : '0', 4);
        }

        if ($sums === []) {
            return null;
        }

        arsort($sums, SORT_NUMERIC);
        $names = Branch::query()->whereIn('id', array_keys($sums))->get()->keyBy('id');

        return new Breakdown(
            label: __('credit_control::dashboard.by_branch'),
            parts: array_map(fn (int $branch) => [
                'label' => $branch === 0 ? __('credit_control::dashboard.no_branch') : ($names[$branch]?->name() ?? '#'.$branch),
                'value' => Money::format($sums[$branch]),
                'href' => $branch === 0 ? null : route('credit_control.report.show', ['slug' => 'positions', 'branch_id' => $branch]),
            ], array_keys($sums)),
            hint: __('credit_control::dashboard.by_branch_hint'),
            chart: 'hbars',
        );
    }

    /**
     * বিক্রয়কর্মী ধরে — যিনি আজ ডিলারের সাথে বাঁধা ([[DealerBinding]]); একাধিক হলে প্রথম বাঁধন, যাতে এক টাকা দুইবার না গোনে।
     *
     * @param  array{positions: array<int, array<string, mixed>>}  $figures
     */
    private static function bySalesperson(array $figures): ?Breakdown
    {
        if ($figures['positions'] === []) {
            return null;
        }

        $owner = DealerBinding::query()->current()
            ->whereIn('customer_id', array_keys($figures['positions']))
            ->orderBy('id')
            ->get(['customer_id', 'user_id'])
            ->unique('customer_id')
            ->pluck('user_id', 'customer_id');

        $sums = [];

        foreach ($figures['positions'] as $id => $p) {
            $user = (int) ($owner[$id] ?? 0);
            $sums[$user] = bcadd($sums[$user] ?? '0', bccomp($p['exposure'], '0', 4) > 0 ? $p['exposure'] : '0', 4);
        }

        arsort($sums, SORT_NUMERIC);
        $names = User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->whereIn('id', array_keys($sums))
            ->pluck('name', 'id');

        return new Breakdown(
            label: __('credit_control::dashboard.by_salesperson'),
            parts: array_map(fn (int $user) => [
                'label' => $user === 0 ? __('credit_control::dashboard.unbound') : (string) ($names[$user] ?? '#'.$user),
                'value' => Money::format($sums[$user]),
                'href' => $user === 0 ? null : route('credit_control.report.show', ['slug' => 'positions', 'salesman_id' => $user] + self::branch()),
            ], array_keys($sums)),
            hint: __('credit_control::dashboard.by_salesperson_hint'),
            chart: 'hbars',
        );
    }

    /** @param  list<int>  $ids */
    private static function recentChanges(array $ids): Listing
    {
        return new Listing(
            label: __('credit_control::dashboard.recent_changes'),
            columns: [
                ['key' => 'at', 'label' => __('credit_control::profile.when'), 'width' => '7rem',
                    'render' => fn (CustomerCreditChange $c) => DateFormat::format($c->created_at)],
                ['key' => 'customer', 'label' => __('credit_control::report.customer'),
                    'render' => fn (CustomerCreditChange $c) => $c->customer?->name() ?? '—'],
                ['key' => 'what', 'label' => __('credit_control::profile.what'),
                    'render' => fn (CustomerCreditChange $c) => __('customer::credit_history.'.$c->field)],
                ['key' => 'change', 'label' => __('credit_control::dashboard.old_new'),
                    'render' => fn (CustomerCreditChange $c) => in_array($c->field, CustomerCreditChange::MONEY_FIELDS, true)
                        ? ($c->old_value === null ? '—' : Money::format($c->old_value)).' → '.($c->new_value === null ? '—' : Money::format($c->new_value))
                        : ($c->old_value ?? '—').' → '.($c->new_value ?? '—')],
            ],
            rows: $ids === [] ? collect() : CustomerCreditChange::query()->whereIn('customer_id', $ids)
                ->with('customer')->latest('id')->limit(3)->get(),
            empty: __('credit_control::dashboard.no_changes'),
        );
    }

    /** @param  list<int>  $ids */
    private static function recentBlocks(array $ids): Listing
    {
        $names = Customer::query()->inViewedBranch()->whereIn('customers.id', $ids)->get()->keyBy('id');

        return new Listing(
            label: __('credit_control::dashboard.recent_blocks'),
            columns: [
                ['key' => 'at', 'label' => __('credit_control::profile.when'), 'width' => '7rem',
                    'render' => fn (AuditTrail $t) => DateFormat::format($t->created_at)],
                ['key' => 'customer', 'label' => __('credit_control::report.customer'),
                    'render' => fn (AuditTrail $t) => $names[$t->auditable_id]?->name() ?? '—'],
                ['key' => 'what', 'label' => __('credit_control::profile.what'),
                    'render' => fn (AuditTrail $t) => __('credit_control::profile.'.$t->action)],
                ['key' => 'reason', 'label' => __('credit_control::profile.reason'),
                    'render' => fn (AuditTrail $t) => $t->reason ?? '—'],
            ],
            rows: $ids === [] ? collect() : AuditTrail::query()
                ->where('auditable_type', Customer::class)
                ->whereIn('auditable_id', $ids)
                ->whereIn('action', ['credit_blocked', 'credit_unblocked'])
                ->latest('id')->limit(3)->get(),
            empty: __('credit_control::dashboard.no_blocks'),
            href: route('sales.report.show', ['slug' => 'blocked-customers'] + self::branch()),
        );
    }
}
