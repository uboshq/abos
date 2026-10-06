<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Support\Money;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Reports\PrincipalCommission;
use App\Modules\Supplier\Reports\PrincipalCommissionReport;
use Illuminate\Support\Carbon;

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
                    value: (string) Supplier::query()->inViewedBranch()->onlySuppliers()->count(),
                    hint: __('supplier::dashboard.total_hint'),
                    href: route('supplier.index'),
                ),
                new Stat(
                    label: __('supplier::dashboard.active'),
                    value: (string) Supplier::query()->inViewedBranch()->onlySuppliers()->where('is_active', true)->count(),
                    hint: __('supplier::dashboard.active_hint'),
                    href: route('supplier.index'),
                    tone: Stat::GOOD,
                ),
                new Stat(
                    label: __('supplier::dashboard.inactive'),
                    value: (string) Supplier::query()->inViewedBranch()->onlySuppliers()->where('is_active', false)->count(),
                    hint: __('supplier::dashboard.inactive_hint'),
                    href: route('supplier.index'),
                    tone: Stat::WARN,
                ),
                new Stat(
                    label: __('supplier::dashboard.new_this_month'),
                    value: (string) Supplier::query()->inViewedBranch()->onlySuppliers()->where('created_at', '>=', $month)->count(),
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
        );
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
        // ⓘ নতুন ড্যাশবোর্ডের অংশ — বাকিগুলোর সাথে একসাথে চালু হবে (config abos.dashboards_v2)
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('purchase.payment.view')) {
            return [];
        }

        $rows = app(AccountsFacts::class)->topDue('supplier', StandardChart::PAYABLE, 5);

        if ($rows === []) {
            return [];
        }

        // ⓘ হেডারে বাছা শাখার সরবরাহকারী — তালিকার বাকি ঘরগুলোর মতোই ([[EveryPartyListFollowsTheViewedBranchTest]])
        $names = Supplier::query()->inViewedBranch()->onlySuppliers()->whereIn('id', array_column($rows, 'party_id'))->get()->keyBy('id');
        $rows = array_values(array_filter($rows, fn (array $row) => $names->has($row['party_id'])));

        if ($rows === []) {
            return [];
        }

        return [new Breakdown(
            label: __('supplier::dashboard.most_owed'),
            parts: array_map(fn (array $row) => [
                'label' => $names[$row['party_id']]?->name() ?? '—',
                'value' => Money::format($row['amount']),
            ], $rows),
            hint: __('supplier::dashboard.most_owed_hint'),
        )];
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
        if (! auth()->user()?->can('supplier.report')) {
            return [];
        }

        $result = app(\App\Core\Engines\Report\ReportEngine::class)->run(
            PrincipalCommissionReport::KEY,
            ['branch_id' => \App\Core\Support\ViewedBranch::one()],
            1,
            50,
        );

        if ($result->rows === []) {
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
        $periods = collect($result->rows)
            ->map(fn (array $row) => PrincipalCommission::soFar((string) $row['period_from'], (string) $row['period_to']))
            ->unique();
        $oneCycle = $periods->count() === 1;

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
            rows: collect($result->rows),
            empty: __('supplier::principal.dashboard_empty'),
            href: route('supplier.report.show', ['slug' => 'principal-commission']),
            note: $oneCycle ? __('supplier::principal.dash_period', ['period' => $periods->first()]) : null,
            hero: true,
        )];
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
            hint: __('supplier::dashboard.ageing_hint', ['total' => \App\Core\Support\Money::format($totals['outstanding'] ?? '0')]),
        )];
    }
}
