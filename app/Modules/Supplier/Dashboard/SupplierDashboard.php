<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
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
                    value: (string) Supplier::query()->count(),
                    hint: __('supplier::dashboard.total_hint'),
                    href: route('supplier.index'),
                ),
                new Stat(
                    label: __('supplier::dashboard.active'),
                    value: (string) Supplier::query()->where('is_active', true)->count(),
                    hint: __('supplier::dashboard.active_hint'),
                    href: route('supplier.index'),
                    tone: Stat::GOOD,
                ),
                new Stat(
                    label: __('supplier::dashboard.inactive'),
                    value: (string) Supplier::query()->where('is_active', false)->count(),
                    hint: __('supplier::dashboard.inactive_hint'),
                    href: route('supplier.index'),
                    tone: Stat::WARN,
                ),
                new Stat(
                    label: __('supplier::dashboard.new_this_month'),
                    value: (string) Supplier::query()->where('created_at', '>=', $month)->count(),
                    hint: __('supplier::dashboard.new_hint'),
                    href: route('supplier.index'),
                ),
            ],

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
                    rows: Supplier::query()->latest('id')->limit(8)->get(),
                    empty: __('supplier::dashboard.none'),
                    href: route('supplier.index'),
                ),
            ],
        );
    }
}
