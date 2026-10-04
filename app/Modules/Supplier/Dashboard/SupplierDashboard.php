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
            ],

            panels: [...self::mostOwed(), ...self::ageing()],

            listings: [
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
