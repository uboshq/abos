<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Dashboard\Viz\Card;
use App\Core\Dashboard\Viz\Svg;
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
use App\Modules\Customer\Models\CustomerCreditRequest;
use App\Modules\Customer\Models\DealerBinding;
use App\Modules\Sales\Services\CreditExposure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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

            // ⭐ খোলার পর্দায় ছয়টা গ্রাফ — মালিক, ২০ নভেম্বর ২০২৬ (নমুনা boards15 "ok"); যে সংখ্যা-চার্ট গ্রাফে এল, পাতায় আর আলাদা নয়
            sections: [Card::board(fn (): array => self::charts($figures), covers: [
                __('credit_control::dashboard.limits'), __('credit_control::dashboard.exposure'), __('credit_control::dashboard.used'),
                __('credit_control::dashboard.overdue'), __('credit_control::dashboard.ageing'), __('credit_control::dashboard.by_branch'),
                __('credit_control::dashboard.by_salesperson'), __('credit_control::dashboard.over_limit'),
                __('credit_control::dashboard.overdue_stopped'), __('credit_control::dashboard.blocked'),
            ], permission: 'credit_control.view')],
        );
    }

    /**
     * ⭐ বাকি নিয়ন্ত্রণের গ্রাফের বোর্ড — সীমার ব্যবহার (কাঁটা-মিটার), মেয়াদ ধরে খোলা বিল (রঙিন কলাম), শাখা ধরে ব্যবহার বনাম সীমা
     *   (বুলেট), বিক্রয়কর্মী ধরে (আড়াআড়ি কলাম), কেন বাকি বন্ধ (ডোনাট), ছয় মাসের সীমা বদল (স্তূপ-এলাকা)।
     * ⓘ সব অঙ্ক [[figures()]]-এর — নিচের তালিকা আর রিপোর্টের একই হিসাব; এখানে কেবল সাজানো।
     *
     * @param  array{on: bool, ids: list<int>, totals: array<string, mixed>, ageing: array<string, string>, positions: array<int, array<string, mixed>>, customers: array<int, Customer>}  $figures
     * @return list<array<string, mixed>>
     */
    private static function charts(array $figures): array
    {
        $t = $figures['totals'];
        $report = fn (string $slug, array $more = []) => route('credit_control.report.show', ['slug' => $slug] + $more + self::branch());
        $positive = fn (string $amount): string => bccomp($amount, '0', 4) > 0 ? $amount : '0';

        // ── সীমার কতটা ব্যবহার — উপরের "সীমার ব্যবহার"-এর একই ভাগ ──────────────────
        $used = $t['used_percent'];
        $gauge = Card::make(__('credit_control::dashboard.viz_used'), 'gauge', $used === null ? [] : [
            'v' => bcdiv((string) $t['exposure'], (string) $t['limit'], 4),
            'label' => Money::round((string) $used, 0).'%',
            'sub' => __('credit_control::dashboard.viz_used_sub', ['exposure' => self::short((string) $t['exposure']), 'limit' => self::short((string) $t['limit'])]),
            // ⓘ বেশি ব্যবহার খারাপ — রং উল্টো: 80%-এর ওপরে লাল, 50%-এর ওপরে হলুদ
            'color' => bccomp((string) $used, '80', 1) >= 0 ? Svg::STATUS[3] : (bccomp((string) $used, '50', 1) >= 0 ? '#f59e0b' : Svg::STATUS[0]),
        ], value: $used === null ? '' : Money::round((string) $used, 0).'%', sub: __('credit_control::dashboard.viz_used_hint'),
            href: $report('positions'), colour: '#f59e0b');

        // ── মেয়াদ ধরে খোলা বিল ─────────────────────────────────────────────
        $buckets = array_keys($figures['ageing']);
        $ageing = Card::make(__('credit_control::dashboard.ageing'), 'bars', [
            'v' => array_values($figures['ageing']),
            'l' => array_map(fn (string $b) => __('credit_control::dashboard.viz_bucket_'.$b), $buckets),
            'top' => array_map(fn (string $a) => self::short($a), array_values($figures['ageing'])),
            'colors' => ['#16a34a', '#84cc16', '#eab308', '#f59e0b', '#f97316', '#ef4444', '#e11d48'],
        ], value: bccomp((string) $t['overdue'], '0', 4) === 0 ? '' : self::short((string) $t['overdue']),
            sub: __('credit_control::dashboard.viz_ageing_sub'), href: $report('overdue-bills'), colour: '#f97316');

        // ── শাখা ধরে ব্যবহার বনাম সীমা ───────────────────────────────────────
        $byBranch = [];
        foreach ($figures['positions'] as $id => $p) {
            $branch = (int) ($figures['customers'][$id]->branch_id ?? 0);
            $byBranch[$branch]['exposure'] = bcadd($byBranch[$branch]['exposure'] ?? '0', $positive($p['exposure']), 4);
            $byBranch[$branch]['limit'] = bcadd($byBranch[$branch]['limit'] ?? '0', (string) $p['limit'], 4);
        }
        $byBranch = array_filter($byBranch, fn (array $b) => bccomp($b['limit'], '0', 4) > 0);
        uasort($byBranch, fn (array $a, array $b) => bccomp($b['exposure'], $a['exposure'], 4));
        $byBranch = array_slice($byBranch, 0, 6, true);
        $branchNames = Branch::query()->whereIn('id', array_keys($byBranch))->get()->keyBy('id');
        $bullet = Card::make(__('credit_control::dashboard.by_branch'), 'bullet', $byBranch === [] ? [] : [
            'p' => array_map(fn (int $branch, array $b) => [
                Str::limit($branch === 0 ? __('credit_control::dashboard.no_branch') : ($branchNames[$branch]?->name() ?? '#'.$branch), 14),
                bcdiv($b['exposure'], $b['limit'], 4), '1',
            ], array_keys($byBranch), array_values($byBranch)),
        ], sub: __('credit_control::dashboard.viz_branch_sub'), href: $report('positions'), colour: '#2160d8');

        // ── বিক্রয়কর্মী ধরে — নিচের পুরনো চার্টের একই ভাগ, বড় ছয়জন, বাকি "অন্যরা" ─────────────
        $sums = array_filter(self::salespersonSums($figures), fn (string $s) => bccomp($s, '0', 4) > 0);
        $shown = array_slice($sums, 0, 6, true);
        $rest = array_reduce(array_slice($sums, 6, null, true), fn (string $c, string $s) => bcadd($c, $s, 4), '0');
        $names = self::salespersonNames(array_keys($shown));
        $labels = array_map(fn (int $user) => Str::limit(self::salespersonLabel($user, $names), 14), array_keys($shown));
        if (bccomp($rest, '0', 4) > 0) {
            $shown[] = $rest;
            $labels[] = __('credit_control::dashboard.viz_others');
        }
        $hbars = Card::make(__('credit_control::dashboard.by_salesperson'), 'hbars', [
            'v' => array_values($shown), 'l' => $labels,
            'top' => array_map(fn (string $a) => self::short($a), array_values($shown)),
        ], sub: __('credit_control::dashboard.viz_salesperson_sub'), href: $report('positions'), colour: '#7c3aed');

        // ── কেন বাকি বন্ধ — দেয়ালের তিন অবস্থা; "হাতে বন্ধ" কেবল বিক্রয়ের রিপোর্টের চাবিতে (নিচের পুরনো ঘরের একই চাবি) ────
        $stops = [
            [__('credit_control::dashboard.over_limit'), (int) $t['over_limit'], Svg::STATUS[3], number_format((int) $t['over_limit'])],
            [__('credit_control::dashboard.overdue_stopped'), (int) $t['overdue_stopped'], Svg::STATUS[2], number_format((int) $t['overdue_stopped'])],
        ];
        if (auth()->user()?->can('sales.report')) {
            $stops[] = [__('credit_control::dashboard.blocked'), (int) $t['blocked'], '#7c3aed', number_format((int) $t['blocked'])];
        }
        $stopped = array_sum(array_column($stops, 1));
        $donut = Card::make(__('credit_control::dashboard.viz_stopped'), 'donut', [
            'c' => number_format($stopped), 'cs' => __('credit_control::dashboard.viz_people_word'), 'p' => $stops,
        ], value: $stopped === 0 ? '' : __('credit_control::dashboard.viz_people', ['n' => number_format($stopped)]),
            sub: __('credit_control::dashboard.viz_stopped_sub'), href: $report('over-limit'), colour: '#e11d48');

        // ── সীমা বদল — গত ছয় মাস, বাড়ানো বনাম কমানো (সীমা বদলের খাতা, এই দেয়ালের গ্রাহক) ─────────────
        $start = Carbon::today()->startOfMonth()->subMonths(5);
        $changes = $figures['ids'] === [] ? collect() : CustomerCreditChange::query()->whereIn('customer_id', $figures['ids'])
            ->where('field', 'credit_limit')->where('created_at', '>=', $start)
            ->get(['old_value', 'new_value', 'created_at']);
        $up = [];
        $down = [];
        $months = [];
        for ($month = $start->copy(); $month->lessThanOrEqualTo(Carbon::today()); $month->addMonth()) {
            $inMonth = $changes->filter(fn (CustomerCreditChange $c) => $c->created_at->format('Y-m') === $month->format('Y-m'));
            $cmp = fn (CustomerCreditChange $c) => bccomp((string) ($c->new_value ?? '0'), (string) ($c->old_value ?? '0'), 4);
            $up[] = $inMonth->filter(fn ($c) => $cmp($c) > 0)->count();
            $down[] = $inMonth->filter(fn ($c) => $cmp($c) < 0)->count();
            $months[] = $month->translatedFormat('M');
        }
        $area = Card::make(__('credit_control::dashboard.viz_changes'), 'stackedArea', [
            'series' => [$up, $down], 'colors' => [Svg::STATUS[0], Svg::STATUS[3]], 'l' => $months,
        ], sub: __('credit_control::dashboard.viz_changes_sub'), legend: [
            [__('credit_control::dashboard.viz_raised'), Svg::STATUS[0]], [__('credit_control::dashboard.viz_lowered'), Svg::STATUS[3]],
        ], colour: '#0ea5e9');

        return [$gauge, $ageing, $bullet, $hbars, $donut, $area];
    }

    /**
     * বিক্রয়কর্মী ধরে ব্যবহৃত বাকি, বড় থেকে ছোট — [[bySalesperson()]]-এর একই যোগ আর একই ক্রম (তাই নাম এক সারিতে মেলে)।
     *
     * @param  array{positions: array<int, array<string, mixed>>}  $figures
     * @return array<int, string>
     */
    private static function salespersonSums(array $figures): array
    {
        if ($figures['positions'] === []) {
            return [];
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

        return $sums;
    }

    /**
     * বিক্রয়কর্মীর নাম — কেবল এই কোম্পানির মানুষ।
     *
     * @param  list<int>  $ids
     * @return \Illuminate\Support\Collection<int, string>
     */
    private static function salespersonNames(array $ids): \Illuminate\Support\Collection
    {
        return User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->whereIn('id', $ids)
            ->pluck('name', 'id');
    }

    /** @param  \Illuminate\Support\Collection<int, string>  $names */
    private static function salespersonLabel(int $user, \Illuminate\Support\Collection $names): string
    {
        return $user === 0 ? __('credit_control::dashboard.unbound') : (string) ($names[$user] ?? '#'.$user);
    }

    /** ⓘ নমুনার রূপ: কোটির ওপরে "৳X.XX কো", লাখের ওপরে "৳X.X লা", নিচে হাজারে "৳Xk" — ইংরেজি অঙ্ক, bcmath */
    private static function short(string $amount): string
    {
        $amount = $amount === '' ? '0' : $amount;
        $a = ltrim($amount, '-');
        $sign = bccomp($amount, '0', 4) < 0 ? '−' : '';

        return match (true) {
            bccomp($a, '10000000', 4) >= 0 => $sign.'৳'.bcdiv($a, '10000000', 2).' '.__('credit_control::dashboard.crore'),
            bccomp($a, '100000', 4) >= 0 => $sign.'৳'.bcdiv($a, '100000', 1).' '.__('credit_control::dashboard.lakh'),
            bccomp($a, '1000', 4) >= 0 => $sign.'৳'.bcdiv($a, '1000', 0).'k',
            default => $sign.'৳'.bcadd($a, '0', 0),
        };
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

        // ⛔ নিষ্ক্রিয় হলেও জের থাকলে গোনা — তালিকার একই দরজা ([[CreditPositionReports::activeOrStillOwing()]])
        $customers = Customer::query()->inViewedBranch()
            ->tap(fn ($q) => CreditPositionReports::activeOrStillOwing($q, (int) \App\Core\Support\CompanyContext::id()))
            ->withOutstanding()->get()
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
        // ⓘ দুই পথের অনুরোধ — গ্রাহকের ফর্মে সরাসরি বাড়ানো, আর বাকির পাতার অনুরোধের কাগজ (ধাপ ২.৫, [[CustomerCreditRequest]])
        $totals['pending_requests'] = $customers === [] ? 0 : Approval::query()
            ->where('module', 'customer')
            ->where('action', 'credit_limit')
            ->where('status', Approval::PENDING)
            ->where(fn ($q) => $q
                ->where(fn ($c) => $c->where('approvable_type', (new Customer)->getMorphClass())
                    ->whereIn('approvable_id', array_keys($customers)))
                ->orWhere(fn ($r) => $r->where('approvable_type', CustomerCreditRequest::class)
                    ->whereIn('approvable_id', CustomerCreditRequest::query()->whereIn('customer_id', array_keys($customers))->select('id'))))
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

        $sums = self::salespersonSums($figures);
        $names = self::salespersonNames(array_keys($sums));

        return new Breakdown(
            label: __('credit_control::dashboard.by_salesperson'),
            parts: array_map(fn (int $user) => [
                'label' => self::salespersonLabel($user, $names),
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
                    // ⭐ "নাম · পয়েন্ট · শাখা" — মালিক, ১৯ অক্টোবর ২০২৬ (স্থায়ী নিয়ম)
                    'render' => fn (CustomerCreditChange $c) => $c->customer?->nameWithPoint() ?? '—'],
                ['key' => 'what', 'label' => __('credit_control::profile.what'),
                    'render' => fn (CustomerCreditChange $c) => __('customer::credit_history.'.$c->field)],
                ['key' => 'change', 'label' => __('credit_control::dashboard.old_new'),
                    'render' => fn (CustomerCreditChange $c) => in_array($c->field, CustomerCreditChange::MONEY, true)
                        ? ($c->old_value === null ? '—' : Money::format($c->old_value)).' → '.($c->new_value === null ? '—' : Money::format($c->new_value))
                        : ($c->old_value ?? '—').' → '.($c->new_value ?? '—')],
            ],
            rows: $ids === [] ? collect() : CustomerCreditChange::query()->whereIn('customer_id', $ids)
                ->with(Customer::placeVia('customer'))->latest('id')->limit(3)->get(),
            empty: __('credit_control::dashboard.no_changes'),
        );
    }

    /** @param  list<int>  $ids */
    private static function recentBlocks(array $ids): Listing
    {
        $rows = $ids === [] ? collect() : AuditTrail::query()
            ->where('auditable_type', Customer::class)
            ->whereIn('auditable_id', $ids)
            ->whereIn('action', ['credit_blocked', 'credit_unblocked'])
            ->latest('id')->limit(3)->get();

        // ⓘ কেবল এই তিন সারির গ্রাহক, জায়গাসহ, এক প্রশ্নে — আগে দেখার দেয়ালের সব গ্রাহক তোলা হত তিনটা নামের জন্য
        $names = Customer::query()->inViewedBranch()->whereIn('customers.id', $rows->pluck('auditable_id')->map(fn ($id) => (int) $id)->all() ?: [0])
            ->with(Customer::PLACE)->get()->keyBy('id');

        return new Listing(
            label: __('credit_control::dashboard.recent_blocks'),
            columns: [
                ['key' => 'at', 'label' => __('credit_control::profile.when'), 'width' => '7rem',
                    'render' => fn (AuditTrail $t) => DateFormat::format($t->created_at)],
                ['key' => 'customer', 'label' => __('credit_control::report.customer'),
                    // ⭐ "নাম · পয়েন্ট · শাখা" — মালিক, ১৯ অক্টোবর ২০২৬ (স্থায়ী নিয়ম)
                    'render' => fn (AuditTrail $t) => $names->get((int) $t->auditable_id)?->nameWithPoint() ?? '—'],
                ['key' => 'what', 'label' => __('credit_control::profile.what'),
                    'render' => fn (AuditTrail $t) => __('credit_control::profile.'.$t->action)],
                ['key' => 'reason', 'label' => __('credit_control::profile.reason'),
                    'render' => fn (AuditTrail $t) => $t->reason ?? '—'],
            ],
            rows: $rows,
            empty: __('credit_control::dashboard.no_blocks'),
            href: route('sales.report.show', ['slug' => 'blocked-customers'] + self::branch()),
        );
    }
}
