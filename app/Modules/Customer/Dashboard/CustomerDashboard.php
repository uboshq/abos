<?php

declare(strict_types=1);

namespace App\Modules\Customer\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Modules\Customer\Models\Customer;
use Illuminate\Support\Carbon;

/**
 * গ্রাহক মডিউলের ড্যাশবোর্ড।
 *
 * ── কেন এখানে টাকার সংখ্যা নেই ───────────────────────────────────────
 * গ্রাহকের বকেয়া বিক্রয়ের প্রশ্ন, আর সেটা বিক্রয়ের পর্দায় আছে। এখানে
 * টেনে আনলে Customer মডিউলকে Sales-এর উপর দাঁড়াতে হত — ঠিক যে চক্রটা
 * [[Customer]] মডেলে একবার ভেঙে সরানো হয়েছিল (`lastPurchaseOn()`)।
 *
 * এই পর্দার প্রশ্ন তাই আলাদা: **তালিকাটা সুস্থ আছে তো** — কতজন সচল,
 * কতজন নিষ্ক্রিয়, আর নতুন কারা এলেন।
 */
final class CustomerDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        $month = Carbon::today()->startOfMonth()->toDateString();

        return new DashboardDefinition(
            title: __('customer::dashboard.title'),
            subtitle: __('customer::dashboard.subtitle'),

            tiles: [
                new Tile(label: __('customer::action.new'), href: route('customer.create'),
                    permission: 'customer.create', icon: 'plus'),
                new Tile(label: __('customer::menu.customers'), href: route('customer.index'),
                    permission: 'customer.view', icon: 'reports'),
            ],

            stats: [
                new Stat(
                    label: __('customer::dashboard.total'),
                    value: (string) Customer::query()->inViewedBranch()->count(),
                    hint: __('customer::dashboard.total_hint'),
                    href: route('customer.index'),
                ),
                new Stat(
                    label: __('customer::dashboard.active'),
                    value: (string) Customer::query()->inViewedBranch()->where('is_active', true)->count(),
                    hint: __('customer::dashboard.active_hint'),
                    href: route('customer.index'),
                    tone: Stat::GOOD,
                ),
                new Stat(
                    label: __('customer::dashboard.inactive'),
                    value: (string) Customer::query()->inViewedBranch()->where('is_active', false)->count(),
                    hint: __('customer::dashboard.inactive_hint'),
                    href: route('customer.index'),
                    tone: Stat::WARN,
                ),
                new Stat(
                    label: __('customer::dashboard.new_this_month'),
                    value: (string) Customer::query()->inViewedBranch()->where('created_at', '>=', $month)->count(),
                    hint: __('customer::dashboard.new_hint'),
                    href: route('customer.index'),
                ),
            ],

            panels: [...self::growth(), ...self::ageing()],

            listings: [
                new Listing(
                    label: __('customer::dashboard.newest'),
                    columns: [
                        ['key' => 'code', 'label' => __('customer::field.code'), 'width' => '7rem',
                            'render' => fn ($c) => $c->code],
                        ['key' => 'name', 'label' => __('customer::field.name'),
                            'render' => fn ($c) => $c->name()],
                        ['key' => 'phone', 'label' => __('customer::field.phone'), 'width' => '9rem',
                            'render' => fn ($c) => $c->phone ?? '—'],
                    ],
                    rows: Customer::query()->inViewedBranch()->latest('id')->limit(8)->get(),
                    empty: __('customer::dashboard.none'),
                    href: route('customer.index'),
                ),
            ],
        );
    }

    /**
     * ⭐ পাওনা-এর বয়স — চলতি, ৩০, ৬০, ৯০+ দিন (মালিকের ড্যাশবোর্ড নকশা, ৩ অক্টোবর ২০২৬)।
     *
     * ⓘ নিজের হিসাব নয়: "বয়স" রিপোর্টের ([[customer.ageing]]) পুরো ফলের যোগফল — রিপোর্ট আর চার্ট কখনো দুই কথা বলে না,
     * আর রিপোর্টের শাখার দেয়াল ([[ReportEngine::branchWall()]]) এখানেও খাটে; হেডারে বাছা শাখা থাকলে সেটাই।
     * ⛔ রিপোর্টের নিজের চাবি (`customer.report`) ছাড়া চার্টই নেই — রিপোর্ট যেখানে বন্ধ, ড্যাশবোর্ডেও বন্ধ।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ — বাকিগুলোর সাথে একসাথে চালু হবে (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function ageing(): array
    {
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('customer.report')) {
            return [];
        }

        $totals = app(\App\Core\Engines\Report\ReportEngine::class)
            ->run('customer.ageing', ['to' => \Illuminate\Support\Carbon::today()->toDateString(), 'branch_id' => \App\Core\Support\ViewedBranch::one()], 1, 1)
            ->totals;

        return [new Breakdown(
            label: __('customer::dashboard.ageing'),
            parts: array_map(fn (string $bucket) => [
                'label' => __('customer::field.'.$bucket),
                'value' => \App\Core\Support\Money::format($totals[$bucket] ?? '0'),
            ], ['bucket_current', 'bucket_30', 'bucket_60', 'bucket_90']),
            hint: __('customer::dashboard.ageing_hint', ['total' => \App\Core\Support\Money::format($totals['outstanding'] ?? '0')]),
        )];
    }

    /**
     * ⭐ গ্রাহক বৃদ্ধি — গত ছয় মাসে কতজন যোগ হলেন, আর তাঁদের কতজন এখনো চালু (মালিকের ড্যাশবোর্ড নকশা, ৩ অক্টোবর ২০২৬)।
     *
     * ⓘ উপরের "এ মাসে নতুন" সংখ্যার একই ভিত (দেখার শাখার গ্রাহক, যোগ হওয়ার দিন) — এ মাসের দণ্ড আর ঐ সংখ্যা এক।
     * ⓘ দুই দণ্ড একই মাপের (মানুষ): দ্বিতীয়টা প্রথমটার ভেতরের ভাগ, তাই ফাঁকটা বলে কতজন যোগ হয়েই বন্ধ হলেন।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ — বাকিগুলোর সাথে একসাথে চালু হবে (config abos.dashboards_v2)।
     *
     * @return list<Series>
     */
    private static function growth(): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        $start = \Illuminate\Support\Carbon::today()->startOfMonth()->subMonths(5);
        $expr = "DATE_FORMAT(created_at, '%Y-%m')";

        $rows = Customer::query()->inViewedBranch()
            ->where('created_at', '>=', $start)
            ->selectRaw("{$expr} as ym, COUNT(*) as added, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as still_on")
            ->groupByRaw($expr)
            ->toBase()->get()->keyBy('ym');

        $points = [];

        for ($month = $start->copy(); $month->lessThanOrEqualTo(\Illuminate\Support\Carbon::today()); $month->addMonth()) {
            $row = $rows[$month->format('Y-m')] ?? null;
            $points[] = [
                'label' => $month->translatedFormat('M'),
                'first' => (string) (int) ($row->added ?? 0),
                'second' => (string) (int) ($row->still_on ?? 0),
            ];
        }

        return [new \App\Core\Engines\Dashboard\Series(
            label: __('customer::dashboard.growth'),
            points: $points,
            firstLabel: __('customer::dashboard.growth_added'),
            secondLabel: __('customer::dashboard.growth_still_on'),
        )];
    }
}
