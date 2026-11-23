<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Dashboard\Viz\Card;
use App\Core\Dashboard\Viz\Svg;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Reports\PrincipalCommission;
use App\Modules\Supplier\Reports\PrincipalCommissionReport;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * সরবরাহকারী মডিউলের ড্যাশবোর্ড।
 *
 * গ্রাহকের পর্দার মতোই, আর একই কারণে: দেনার অঙ্ক ক্রয়ের প্রশ্ন, তাই
 * সেটা ক্রয়ের পর্দায়। এখানে প্রশ্নটা তালিকার স্বাস্থ্য নিয়ে।
 */
final class SupplierDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        $month = Carbon::today()->startOfMonth()->toDateString();

        // ⓘ চার সংখ্যা একবারই গোনা — ঘর আর গ্রাফের বোর্ড একই অঙ্ক দেখায়
        $suppliers = fn () => Supplier::query()->inViewedBranch()->onlySuppliers();
        $total = $suppliers()->count();
        $active = $suppliers()->where('is_active', true)->count();
        $inactive = $suppliers()->where('is_active', false)->count();
        $new = $suppliers()->where('created_at', '>=', $month)->count();

        return new DashboardDefinition(
            title: __('supplier::dashboard.title'),
            subtitle: __('supplier::dashboard.subtitle'),

            tiles: [
                new Tile(label: __('supplier::action.new'), href: route('supplier.create'),
                    permission: 'supplier.create', icon: 'plus'),
                new Tile(label: __('supplier::menu.suppliers'), href: route('supplier.index'),
                    permission: 'supplier.view', icon: 'reports'),
            ],

            stats: [
                new Stat(
                    label: __('supplier::dashboard.total'),
                    value: (string) $total,
                    hint: __('supplier::dashboard.total_hint'),
                    href: route('supplier.index'),
                ),
                new Stat(
                    label: __('supplier::dashboard.active'),
                    value: (string) $active,
                    hint: __('supplier::dashboard.active_hint'),
                    href: route('supplier.index'),
                    tone: Stat::GOOD,
                ),
                new Stat(
                    label: __('supplier::dashboard.inactive'),
                    value: (string) $inactive,
                    hint: __('supplier::dashboard.inactive_hint'),
                    href: route('supplier.index'),
                    tone: Stat::WARN,
                ),
                new Stat(
                    label: __('supplier::dashboard.new_this_month'),
                    value: (string) $new,
                    hint: __('supplier::dashboard.new_hint'),
                    href: route('supplier.index'),
                ),

                // ⭐ এ মাসে কেনা — মালিকের নকশা, ৬ অক্টোবর ২০২৬ ([[SupplierCharts]])
                ...SupplierCharts::stats(),
            ],

            // ⚠️ প্রথম চার্টের জায়গা অপরিবর্তিত — নতুনটা শেষে (৬ অক্টোবর ২০২৬)
            panels: [...self::mostOwed(), ...self::ageing(), ...SupplierCharts::panels()],

            listings: [
                ...self::principals(),
                ...SupplierCharts::listings(),
                new Listing(
                    label: __('supplier::dashboard.newest'),
                    columns: [
                        ['key' => 'code', 'label' => __('supplier::field.code'), 'width' => '7rem',
                            'render' => fn ($s) => $s->code],
                        ['key' => 'name', 'label' => __('supplier::field.name'),
                            'render' => fn ($s) => $s->name()],
                        ['key' => 'phone', 'label' => __('supplier::field.phone'), 'width' => '9rem',
                            'render' => fn ($s) => $s->phone ?? '—'],
                    ],
                    rows: Supplier::query()->inViewedBranch()->onlySuppliers()->latest('id')->limit(8)->get(),
                    empty: __('supplier::dashboard.none'),
                    href: route('supplier.index'),
                ),
            ],

            /*
             * ⭐ খোলার পর্দায় ছয়টা গ্রাফ — মালিক, ২০ নভেম্বর ২০২৬ (নমুনা boards15 "sup"): সরবরাহকারী (ডোনাট), দেনার বয়স (স্তূপ-দাগ),
             *   সবচেয়ে বেশি যাঁদের দিতে হবে (আড়াআড়ি কলাম), প্রিন্সিপালের কমিশন (বুলেট), সময়মতো মাল (বাবল), পরিশোধের দিন (ক্যালেন্ডার)।
             *   যে সংখ্যা-চার্ট-তালিকা গ্রাফে এল, পাতায় আর আলাদা নয়; বাকি (এ মাসে কেনা, কেনার ধারা, প্রিন্সিপাল, সদ্য যুক্ত) নিচে।
             */
            sections: [Card::board(fn (): array => self::charts($total, $active, $inactive, $new), covers: [
                __('supplier::dashboard.total'), __('supplier::dashboard.active'), __('supplier::dashboard.inactive'),
                __('supplier::dashboard.new_this_month'), __('supplier::dashboard.ageing'), __('supplier::dashboard.most_owed'),
                // ⓘ "সরবরাহকারীর কাজ — শীর্ষ পাঁচ" আর ঢাকা নয়: বাবল কেবল % দেখায়, তালিকার কেনা-টাকা, দেরির সংখ্যা আর ফেরত-টাকা হারাত
                //   (ড্যাশবোর্ড যাচাই, ২৩ নভেম্বর ২০২৬, দফা ৭) — তালিকাটা নিচে
            ])],
        );
    }

    /**
     * ⭐ সরবরাহকারীর গ্রাফের বোর্ড — প্রতিটা অঙ্ক আগের ঘর, চার্ট, রিপোর্টের একই সংজ্ঞা থেকে, একই চাবিতে।
     * ⓘ টাকা স্ট্রিং হয়ে যায়; ছোট লেখায় (৳ 8.4 লা) কেবল পূর্ণ টাকার পূর্ণসংখ্যা — float নয়; টাকার অনুপাত bcmath-এ।
     *
     * @return list<array<string, mixed>>
     */
    private static function charts(int $total, int $active, int $inactive, int $new): array
    {
        $user = auth()->user();
        $short = fn (string $amount): string => (bccomp($amount, '0', 4) < 0 ? '−' : '').'৳ '.Svg::short((int) ltrim(Money::round($amount, 0), '-'));
        $t = fn (string $key, array $with = []): string => (string) __('supplier::dashboard.'.$key, $with);
        $name = fn (string $label, int $limit = 14): string => Str::limit(trim((string) Str::afterLast($label, ' — ')), $limit);
        $buckets = ['bucket_current', 'bucket_30', 'bucket_60', 'bucket_90'];

        // ── দেনার বয়স — বয়সের রিপোর্টটাই (বড় চারজন আর সবাই মিলে), রিপোর্টের চাবিতে ([[ageing()]]-এর একই ডাক)
        $ageRows = [];
        $agePayable = null;
        $ageTotals = [];
        // ⛔ লাইভ 1.3.7 (২১ নভেম্বর ২০২৬): গ্রাফের বোর্ড সুইচ বন্ধেও আঁকা হয়, অথচ এই তিন কার্ড (বয়স, সবচেয়ে বেশি দেনা, সময়মতো মাল) নতুন-ড্যাশবোর্ডের
        //   সুইচ দেখত — তাই ৳47.7 লাখ দেনা থাকতেও ফাঁকা। ⭐ বোর্ডের কার্ড কেবল চাবি মানে; আগের চার্ট (`panels`) সুইচ মানে আগের মতো।
        if ($user?->can('supplier.report')) {
            $result = app(\App\Core\Engines\Report\ReportEngine::class)
                ->run('supplier.ageing', ['to' => Carbon::today()->toDateString(), 'branch_id' => \App\Core\Support\ViewedBranch::one()], 1, 4);
            foreach ($result->rows as $row) {
                $ageRows[] = [$name((string) $row['supplier_name'], 10),array_map(fn (string $b) => (string) ($row[$b] ?? '0'), $buckets)];
            }
            if ($ageRows !== []) {
                $ageRows[] = [$t('viz_all'), array_map(fn (string $b) => (string) ($result->totals[$b] ?? '0'), $buckets)];
            }
            $agePayable = (string) ($result->totals['payable'] ?? '0');
            $ageTotals = array_map(fn (string $b) => (string) ($result->totals[$b] ?? '0'), $buckets);
        }

        // ── সবচেয়ে বেশি যাঁদের দিতে হবে — [[mostOwed()]]-এর একই পাঁচজন
        $owed = self::mostOwedRows(board: true);

        // ── প্রিন্সিপালের কমিশন — রিপোর্টের সারি; প্রিন্সিপালের ভাগের কতটা পাঠানো হলো (দাগ = পুরোটা)
        $principals = array_values(array_filter(self::principalRows(), fn (array $r) => bccomp((string) $r['share'], '0', 4) > 0));
        $bullet = array_map(fn (array $r) => [
            Str::limit((string) $r['supplier_name'], 12), bcdiv((string) $r['paid'], (string) $r['share'], 4), '1',
        ], array_slice($principals, 0, 6));
        $shareSum = array_reduce($principals, fn (string $s, array $r) => bcadd($s, (string) $r['share'], 4), '0');
        $paidSum = array_reduce($principals, fn (string $s, array $r) => bcadd($s, (string) $r['paid'], 4), '0');
        $sentPct = bccomp($shareSum, '0', 4) > 0 ? Money::round(bcmul(bcdiv($paidSum, $shareSum, 6), '100', 4), 0).'%' : '';

        // ── সময়মতো মাল — এ মাসের শীর্ষ পাঁচ সরবরাহকারী ([[SupplierCharts::listings()]]-এর একই ছাঁকনি)
        $bubbles = self::deliveryBubbles();

        // ── পরিশোধের দিন — শেষ পাঁচ সপ্তাহ, শনিবার থেকে; পরিশোধের চাবিতে
        $calendar = $user?->can('purchase.payment.view') ? self::paymentDays() : ['days' => [], 'total' => '0'];

        return [
            Card::make($t('viz_suppliers'), 'donut', [
                'c' => (string) $total, 'cs' => $t('viz_total'),
                'p' => [[$t('active'), $active, Svg::STATUS[0], (string) $active], [$t('inactive'), $inactive, '#94a3b8', (string) $inactive]],
            ], value: $t('viz_people', ['n' => $total]), sub: $t('viz_suppliers_sub', ['n' => $new]), href: route('supplier.index'), colour: Svg::PALETTE[0]),
            /*
             * ⓘ শিরোনাম নমুনার হুবহু ("দেনার বয়স"); ⭐ প্রতিটা ধাপের টাকা রঙের নামের পাশে — আগের বয়সের চার্ট ধাপে ধাপে টাকা দেখাত, গ্রাফ
             *   কেবল % দেখায়, তাই টাকাটা হারিয়েছিল (ড্যাশবোর্ড যাচাই, ২৩ নভেম্বর ২০২৬, দফা ৭ ও ৯)। অঙ্ক বয়সের রিপোর্টের মোট, একই চাবিতে।
             */
            Card::make($t('viz_ageing'), 'stack100', ['rows' => $ageRows, 'colors' => Svg::STATUS],
                value: $agePayable === null ? '' : $short($agePayable), sub: $t('viz_ageing_sub'),
                legend: array_map(fn (string $key, int $i) => [$t($key).(isset($ageTotals[$i]) ? ' · '.$short($ageTotals[$i]) : ''), Svg::STATUS[$i]],
                    ['viz_age_0', 'viz_age_30', 'viz_age_60', 'viz_age_90'], [0, 1, 2, 3]),
                href: route('supplier.report.show', ['slug' => 'ageing']), colour: '#f97316'),
            Card::make($t('most_owed'), 'hbars', [
                'v' => array_column($owed, 'amount'), 'l' => array_map(fn (array $r) => Str::limit($r['name'], 12), $owed),
                'top' => array_map(fn (array $r) => $short($r['amount']), $owed), 'color' => Svg::STATUS[3],
            ], sub: $t('viz_most_owed_sub'), href: route('supplier.report.show', ['slug' => 'ageing']), colour: '#e11d48'),
            Card::make($t('viz_commission'), 'bullet', ['p' => $bullet], value: $sentPct, sub: $t('viz_commission_sub'),
                href: route('supplier.report.show', ['slug' => 'principal-commission']), colour: Svg::PALETTE[1]),
            Card::make($t('viz_delivery'), 'bubble', ['p' => $bubbles, 'x' => $t('viz_delivery_x'), 'y' => $t('viz_delivery_y')],
                sub: $t('viz_delivery_sub'), href: route('supplier.index'), colour: Svg::PALETTE[2]),
            Card::make($t('viz_paydays'), 'calendar', ['days' => $calendar['days'], 'color' => Svg::STATUS[3]],
                value: $calendar['days'] === [] ? '' : $short($calendar['total']), sub: $t('viz_paydays_sub'), colour: Svg::STATUS[0]),
        ];
    }

    /**
     * ⭐ সময়মতো মাল — এ মাসে যাঁদের কাছ থেকে সবচেয়ে বেশি কেনা, প্রথম পাঁচজন: x = এ মাসের মাল গ্রহণের কতটা আসার তারিখ পেরিয়ে আসেনি,
     * y = কেনার কতটা ফেরত যায়নি, বৃত্ত = কেনা। ⓘ [[SupplierCharts::listings()]]-এর হুবহু ছাঁকনি (কোম্পানি, পাকা কাগজ, মোছা বাদ, হেডারে বাছা
     * শাখা) আর একই "দেরি"-র সংজ্ঞা; যাঁর এ মাসে মাল গ্রহণের কাগজই নেই (সরাসরি ক্রয়), তাঁর সময় মাপার কিছু নেই — বাদ।
     * ⛔ ক্রয়ের অঙ্ক — `purchase.bill.view` ছাড়া খালি (ক্রয় মডিউল না থাকলে চাবিও নেই)।
     *
     * @return list<array{0: string, 1: int, 2: string, 3: string}>
     */
    private static function deliveryBubbles(): array
    {
        // ⓘ কেবল গ্রাফের বোর্ড ডাকে, আর বোর্ড দুই অবস্থাতেই আঁকা হয় — তাই সুইচ নয়, কেবল চাবি (লাইভ 1.3.7)
        if (! auth()->user()?->can('purchase.bill.view')) {
            return [];
        }

        $range = [Carbon::today()->startOfMonth()->toDateString(), Carbon::today()->endOfMonth()->toDateString()];

        $top = self::posted(DB::table('pur_bills')->where('pur_bills.company_id', CompanyContext::id()), 'pur_bills')
            ->whereBetween('pur_bills.trx_date', $range)
            ->selectRaw('pur_bills.supplier_id, COALESCE(SUM(pur_bills.total), 0) as amount')
            ->groupBy('pur_bills.supplier_id')
            ->orderByDesc('amount')->orderBy('pur_bills.supplier_id')
            ->limit(5)->get();

        $ids = $top->pluck('supplier_id')->map(fn ($id) => (int) $id)->all();

        if ($ids === []) {
            return [];
        }

        $receipts = self::posted(DB::table('pur_receipts')
            ->leftJoin('pur_orders', 'pur_orders.id', '=', 'pur_receipts.purchase_order_id')
            ->where('pur_receipts.company_id', CompanyContext::id()), 'pur_receipts')
            ->whereBetween('pur_receipts.trx_date', $range)
            ->whereIn('pur_receipts.supplier_id', $ids)
            ->selectRaw('pur_receipts.supplier_id, COUNT(*) as n')
            ->selectRaw('SUM(CASE WHEN pur_orders.expected_on IS NOT NULL AND pur_receipts.trx_date > pur_orders.expected_on THEN 1 ELSE 0 END) as late')
            ->groupBy('pur_receipts.supplier_id')
            ->get()->keyBy('supplier_id');

        $returned = self::posted(DB::table('pur_returns')->where('pur_returns.company_id', CompanyContext::id()), 'pur_returns')
            ->whereBetween('pur_returns.trx_date', $range)
            ->whereIn('pur_returns.supplier_id', $ids)
            ->selectRaw('pur_returns.supplier_id, COALESCE(SUM(pur_returns.total), 0) as amount')
            ->groupBy('pur_returns.supplier_id')
            ->pluck('amount', 'supplier_id');

        // ⓘ আইডিগুলো উপরের দেখা-শাখার ক্রয় থেকে — নাম আনার একটাই জায়গা ([[SupplierCharts::namesOf()]])
        $names = SupplierCharts::namesOf($ids);
        $biggest = (string) $top->first()->amount;
        $out = [];

        foreach ($top as $row) {
            $got = $receipts->get($row->supplier_id);
            $bought = (string) $row->amount;

            if ($got === null || (int) $got->n === 0 || bccomp($bought, '0', 4) <= 0) {
                continue;
            }

            $kept = bcsub($bought, (string) ($returned[$row->supplier_id] ?? '0'), 4);
            $out[] = [
                Str::limit(Str::before($names->get((int) $row->supplier_id)?->name() ?? '—', ' '), 8),
                intdiv(((int) $got->n - (int) $got->late) * 100, (int) $got->n),
                bcmul(bcdiv($kept, $bought, 6), '100', 2),
                // ⓘ বৃত্ত 8–26 — বড় বৃত্ত 100%-এর কাছে মাথার কিনারায় কাটত
                bcadd('8', bcmul('18', bcdiv($bought, $biggest, 6), 4), 2),
            ];
        }

        return $out;
    }

    /**
     * ⭐ পরিশোধের দিন — শেষ পাঁচ সপ্তাহ (শনিবার থেকে), প্রতিদিন সরবরাহকারীকে কত দেওয়া হলো: খাতায় ওঠা ক্রয়ের পরিশোধ আর পরিশোধ-ভাউচার
     * (পণ্যের সরবরাহকারী — সেবাদাতা নয়)। ⓘ পরিশোধের টেবিল কাঁচা পড়া — সরবরাহকারী মডিউল ক্রয়কে চেনে না ([[SupplierCharts]]-এর একই কারণ)।
     * ⓘ পাঁচ সপ্তাহে একটা পয়সাও না গেলে খালি — শূন্যের ক্যালেন্ডার আঁকা হয় না।
     *
     * @return array{days: list<array{0: int, 1: string}>, total: string}
     */
    private static function paymentDays(): array
    {
        $today = Carbon::today();
        $start = $today->copy()->subDays(28);
        $start->subDays(($start->dayOfWeek + 1) % 7);   // শনিবারে ফেরা (শনি = 6)
        $range = [$start->toDateString(), $start->copy()->addDays(34)->toDateString()];

        $byDay = self::posted(DB::table('pur_payments')->where('pur_payments.company_id', CompanyContext::id()), 'pur_payments')
            ->whereBetween('pur_payments.trx_date', $range)
            ->whereIn('pur_payments.supplier_id', Supplier::onlySuppliersIds())
            ->selectRaw('DATE(pur_payments.trx_date) as d, COALESCE(SUM(pur_payments.amount), 0) as amount')
            ->groupByRaw('DATE(pur_payments.trx_date)')
            ->pluck('amount', 'd')->map(fn ($v) => (string) $v)->all();

        $vouchers = Voucher::query()->posted()->where('type', Voucher::PAYMENT)
            ->where('party_type', Supplier::drillSourceType())
            ->whereIn('party_id', Supplier::onlySuppliersIds())
            ->whereBetween('trx_date', $range)
            ->selectRaw('DATE(trx_date) as d, COALESCE(SUM(amount), 0) as amount')
            ->groupByRaw('DATE(trx_date)')
            ->toBase()->pluck('amount', 'd');
        foreach ($vouchers as $day => $amount) {
            $byDay[$day] = bcadd($byDay[$day] ?? '0', (string) $amount, 4);
        }

        $days = [];
        $total = '0';
        for ($i = 0; $i < 35; $i++) {
            $day = $start->copy()->addDays($i);
            $amount = $day->greaterThan($today) ? '0' : ($byDay[$day->toDateString()] ?? '0');
            $days[] = [$day->day, $amount];
            $total = bcadd($total, $amount, 4);
        }

        return ['days' => bccomp($total, '0', 4) > 0 ? $days : [], 'total' => $total];
    }

    /** পাকা কাগজ, মোছা বাদ, আর হেডারে বাছা শাখা ([[DataScope::inView()]]) — [[SupplierCharts]]-এর হুবহু ছাঁকনি */
    private static function posted(Builder $query, string $table): Builder
    {
        $query->whereIn($table.'.status', DocumentStatus::POSTED)->whereNull($table.'.deleted_at');

        return app(DataScope::class)->inView($query, $table.'.branch_id');
    }

    /**
     * ⭐ সবচেয়ে বেশি যাঁদের দিতে হবে — শীর্ষ পাঁচ (মালিকের ড্যাশবোর্ড নকশা, ২ অক্টোবর ২০২৬)।
     *
     * ⓘ সংজ্ঞা হিসাবের ড্যাশবোর্ডের "কাকে কত দিতে হবে"-র একটাই ([[AccountsFacts::topDue()]], খাত ২১১১-এর জের),
     * তাই দুই পর্দা কখনো দুই উত্তর দেয় না। ⛔ টাকার অঙ্ক — কেবল পরিশোধ দেখার চাবি থাকলে; সরবরাহকারীর তালিকা
     * দেখার চাবি দিয়ে দেনার অঙ্ক খোলে না। চাবি না থাকলে চার্টটাই নেই।
     *
     * @return list<Breakdown>
     */
    private static function mostOwed(): array
    {
        $rows = self::mostOwedRows();

        if ($rows === []) {
            return [];
        }

        return [new Breakdown(
            label: __('supplier::dashboard.most_owed'),
            parts: array_map(fn (array $row) => [
                'label' => $row['name'],
                'value' => Money::format($row['amount']),
            ], $rows),
            hint: __('supplier::dashboard.most_owed_hint'),
        )];
    }

    /**
     * সবচেয়ে বেশি যাঁদের দিতে হবে — নাম আর টাকা (স্ট্রিং); আগের চার্ট আর গ্রাফের বোর্ড দুইটাই এটা পড়ে। চাবি না থাকলে ফাঁকা।
     *
     * @return list<array{name: string, amount: string}>
     */
    private static function mostOwedRows(bool $board = false): array
    {
        // ⓘ নতুন ড্যাশবোর্ডের অংশ — বাকিগুলোর সাথে একসাথে চালু হবে (config abos.dashboards_v2); ⭐ গ্রাফের বোর্ড (`$board`) সুইচ ছাড়াই — সে দুই অবস্থাতেই আঁকা হয়
        if ((! $board && ! config('abos.dashboards_v2')) || ! auth()->user()?->can('purchase.payment.view')) {
            return [];
        }

        // ⛔ ছাঁকার আগে বড় তালিকা, ছাঁকার পরে পাঁচ — আগে শীর্ষ পাঁচের পরে ছাঁকা হত, তাই অন্য শাখার বা সেবাদাতার বড় দেনা ঢুকে
        // চার্টে দুইজন থাকত আর ষষ্ঠ-অষ্টম প্রিন্সিপাল হারাত (Supplier নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[TheMostOwedChartLostPrincipalsTest]])
        $rows = app(AccountsFacts::class)->topDue('supplier', StandardChart::PAYABLE, 500);

        if ($rows === []) {
            return [];
        }

        // ⓘ হেডারে বাছা শাখার সরবরাহকারী — তালিকার বাকি ঘরগুলোর মতোই ([[EveryPartyListFollowsTheViewedBranchTest]])
        $names = Supplier::query()->inViewedBranch()->onlySuppliers()->whereIn('id', array_column($rows, 'party_id'))->get()->keyBy('id');
        $rows = array_slice(array_values(array_filter($rows, fn (array $row) => $names->has($row['party_id']))), 0, 5);

        return array_map(fn (array $row) => [
            'name' => $names[$row['party_id']]?->name() ?? '—',
            'amount' => $row['amount'],
        ], $rows);
    }

    /**
     * ⭐ প্রিন্সিপালের কমিশন — চলতি চক্রে এ পর্যন্ত কমিশন আর জের, প্রিন্সিপাল ধরে (মালিক, ৫ অক্টোবর ২০২৬)।
     *
     * ⓘ নিজের হিসাব নয়: রিপোর্টটাই চালানো হয় ([[PrincipalCommissionReport]]), তাই পর্দা আর রিপোর্ট কখনো দুই কথা বলে
     * না; শাখার দেয়াল আর হেডারে বাছা শাখাও রিপোর্টের। ⛔ রিপোর্টের চাবি (`supplier.report`) ছাড়া তালিকাটাই নেই, আর
     * কোনো প্রিন্সিপাল বসানো না থাকলেও নেই — ফাঁকা বাক্স রোজ চোখে পড়ার কিছু নয়।
     * ⓘ হোমের "হাতে ও ব্যাংকে মোট" বাক্সের রূপে ([[x-dashboard.hero-listing]])।
     *
     * @return list<Listing>
     */
    private static function principals(): array
    {
        $rows = self::principalRows();

        if ($rows === []) {
            return [];
        }

        /*
         * ⭐ মালিকের বাক্স, ৬ অক্টোবর ২০২৬: *"'হাতে ও ব্যাংকে মোট' main dashboard-এর মতো একটা same box … প্রিন্সিপাল, মোট
         * ইনফ্লো, কমিশন, প্রিন্সিপালকে পাঠানো, বাকি ইনফ্লো"* — উদাহরণ *"Star Line | 12,04,346.00 | 48,173.84 | 0.00 |
         * দিতে হবে: 11,56,172.16"*।
         *   · নাম = সংক্ষিপ্ত নাম, না থাকলে নাম; কোড নয় (রিপোর্টের সারিতেই, [[PrincipalCommission::shortName()]])
         *   · বাকি ইনফ্লো = অংশ − পাঠানো (রিপোর্টের জের), কথায়: "দিতে হবে: …" / "কোম্পানির কাছে পাব: …" — খালি বিয়োগ নয়;
         *     আলাদা মন্তব্যের কলাম আর নেই (de2353dc-এরটা এখানে মিশে গেল)
         *   · সময়কাল শিরোনামের নিচে, সবার চক্র এক হলে; আলাদা হলে প্রতিটা সারিতে একটা কলাম
         */
        $periods = collect($rows)
            ->map(fn (array $row) => PrincipalCommission::soFar((string) $row['period_from'], (string) $row['period_to']))
            ->unique();
        $oneCycle = $periods->count() === 1;

        /*
         * ⭐ সর্বমোট — মালিক, ১৯ অক্টোবর ২০২৬: চার প্রিন্সিপালের সারি ছিল, নিচে মোট নেই। ⓘ সারিগুলোরই যোগফল, bcmath-এ —
         * পর্দার সারি আর মোট কখনো দুই কথা বলে না। বাকি ইনফ্লো চিহ্নসহ যোগ হয় (দিতে হবে +, পাব −), তারপর একই কথায়
         * ([[owed()]]) — দুই দিকের সারি থাকলে নিট দিকটাই বলে। শাখার বাছাই রিপোর্টের, তাই মোটেরও।
         */
        $sum = fn (string $key): string => collect($rows)
            ->reduce(fn (string $carry, array $row): string => bcadd($carry, (string) ($row[$key] ?? '0'), 4), '0');

        return [new Listing(
            label: __('supplier::principal.dashboard_title'),
            columns: [
                ['key' => 'principal', 'label' => __('supplier::principal.principal'),
                    'render' => fn (array $row) => $row['supplier_name']],
                ...($oneCycle ? [] : [['key' => 'period', 'label' => __('supplier::principal.period'), 'width' => '13rem',
                    'render' => fn (array $row) => PrincipalCommission::soFar((string) $row['period_from'], (string) $row['period_to'])]]),
                ['key' => 'inflow', 'label' => __('supplier::principal.dash_inflow'), 'width' => '9rem',
                    'render' => fn (array $row) => Money::format($row['inflow'])],
                ['key' => 'commission', 'label' => __('supplier::principal.commission'), 'width' => '8rem',
                    'render' => fn (array $row) => self::earned((string) $row['commission'])],
                ['key' => 'paid', 'label' => __('supplier::principal.dash_sent'), 'width' => '9rem',
                    'render' => fn (array $row) => Money::format($row['paid'])],
                ['key' => 'balance', 'label' => __('supplier::principal.dash_balance'), 'width' => '14rem',
                    'render' => fn (array $row) => self::owed((string) $row['balance'])],
            ],
            rows: collect($rows),
            empty: __('supplier::principal.dashboard_empty'),
            href: route('supplier.report.show', ['slug' => 'principal-commission']),
            note: $oneCycle ? __('supplier::principal.dash_period', ['period' => $periods->first()]) : null,
            hero: true,
            total: [
                'principal' => (string) __('core.table.grand_total'),
                'inflow' => Money::format($sum('inflow')),
                'commission' => self::earned($sum('commission')),
                'paid' => Money::format($sum('paid')),
                'balance' => self::owed($sum('balance')),
            ],
        )];
    }

    /**
     * প্রিন্সিপালের কমিশন রিপোর্টের সারি — রিপোর্টটাই চালানো (শাখার দেয়াল আর হেডারে বাছা শাখা রিপোর্টের); তালিকা আর গ্রাফের বোর্ড
     * দুইটাই এটা পড়ে। ⛔ রিপোর্টের চাবি (`supplier.report`) ছাড়া ফাঁকা।
     *
     * @return list<array<string, mixed>>
     */
    private static function principalRows(): array
    {
        if (! auth()->user()?->can('supplier.report')) {
            return [];
        }

        return app(\App\Core\Engines\Report\ReportEngine::class)->run(
            PrincipalCommissionReport::KEY,
            ['branch_id' => \App\Core\Support\ViewedBranch::one()],
            1,
            50,
        )->rows;
    }

    /**
     * বাকি ইনফ্লো কথায় — "দিতে হবে: 11,56,172.16" (জের ধনাত্মক: আমরা প্রিন্সিপালকে দেব) বা "কোম্পানির কাছে পাব: …"
     * (ঋণাত্মক: বেশি পাঠানো হয়ে গেছে)। ⛔ খালি বিয়োগ চিহ্ন কখনো নয়; শূন্য হলে কেবল অঙ্ক।
     */
    /**
     * ⓘ কমিশন ঋণাত্মক ("আসল" ভিত্তিতে কেনা দামের নিচে বিক্রি) হলে কথায়, খালি বিয়োগ নয় (৬ অক্টোবর ২০২৬)।
     * ⭐ public — ফোনের হোমের প্রিন্সিপালের ঘর ([[DashboardTodayController]]) এই লেখাটাই পাঠায়, নিজে গড়ে না।
     */
    public static function earned(string $commission): string
    {
        $rounded = Money::round($commission, 2);

        return bccomp($rounded, '0', 2) < 0
            ? (string) __('supplier::principal.commission_lost', ['amount' => Money::format(ltrim($rounded, '-'))])
            : Money::format($rounded);
    }

    private static function owed(string $balance): string
    {
        $rounded = Money::round($balance, 2);
        $amount = Money::format(ltrim($rounded, '-'));

        return match (bccomp($rounded, '0', 2)) {
            1 => (string) __('supplier::principal.dash_to_pay', ['amount' => $amount]),
            -1 => (string) __('supplier::principal.dash_to_get', ['amount' => $amount]),
            default => $amount,
        };
    }

    /**
     * ⭐ দেনা-এর বয়স — চলতি, ৩০, ৬০, ৯০+ দিন (মালিকের ড্যাশবোর্ড নকশা, ৩ অক্টোবর ২০২৬)।
     *
     * ⓘ নিজের হিসাব নয়: "বয়স" রিপোর্টের ([[supplier.ageing]]) পুরো ফলের যোগফল — রিপোর্ট আর চার্ট কখনো দুই কথা বলে না,
     * আর রিপোর্টের শাখার দেয়াল ([[ReportEngine::branchWall()]]) এখানেও খাটে; হেডারে বাছা শাখা থাকলে সেটাই।
     * ⛔ রিপোর্টের নিজের চাবি (`supplier.report`) ছাড়া চার্টই নেই — রিপোর্ট যেখানে বন্ধ, ড্যাশবোর্ডেও বন্ধ।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ — বাকিগুলোর সাথে একসাথে চালু হবে (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function ageing(): array
    {
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('supplier.report')) {
            return [];
        }

        $totals = app(\App\Core\Engines\Report\ReportEngine::class)
            ->run('supplier.ageing', ['to' => \Illuminate\Support\Carbon::today()->toDateString(), 'branch_id' => \App\Core\Support\ViewedBranch::one()], 1, 1)
            ->totals;

        return [new Breakdown(
            label: __('supplier::dashboard.ageing'),
            parts: array_map(fn (string $bucket) => [
                'label' => __('supplier::field.'.$bucket),
                'value' => \App\Core\Support\Money::format($totals[$bucket] ?? '0'),
            ], ['bucket_current', 'bucket_30', 'bucket_60', 'bucket_90']),
            // ⓘ রিপোর্টের মোটের কলাম `payable` — ⛔ আগে `outstanding` পড়ত, যে কলাম রিপোর্টে নেই, তাই লেখায় সবসময় 0.00 (৬ অক্টোবর ২০২৬-এর যাচাই)
            hint: __('supplier::dashboard.ageing_hint', ['total' => \App\Core\Support\Money::format($totals['payable'] ?? '0')]),
        )];
    }
}
