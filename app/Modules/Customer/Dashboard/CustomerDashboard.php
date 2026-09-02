<?php

declare(strict_types=1);

namespace App\Modules\Customer\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
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
                    permission: 'customer.create', icon: 'people'),
                new Tile(label: __('customer::menu.customers'), href: route('customer.index'),
                    permission: 'customer.view', icon: 'report'),
            ],

            stats: [
                new Stat(
                    label: __('customer::dashboard.total'),
                    value: (string) Customer::query()->count(),
                    hint: __('customer::dashboard.total_hint'),
                    href: route('customer.index'),
                ),
                new Stat(
                    label: __('customer::dashboard.active'),
                    value: (string) Customer::query()->where('is_active', true)->count(),
                    hint: __('customer::dashboard.active_hint'),
                    href: route('customer.index'),
                    tone: Stat::GOOD,
                ),
                new Stat(
                    label: __('customer::dashboard.inactive'),
                    value: (string) Customer::query()->where('is_active', false)->count(),
                    hint: __('customer::dashboard.inactive_hint'),
                    href: route('customer.index'),
                    tone: Stat::WARN,
                ),
                new Stat(
                    label: __('customer::dashboard.new_this_month'),
                    value: (string) Customer::query()->where('created_at', '>=', $month)->count(),
                    hint: __('customer::dashboard.new_hint'),
                    href: route('customer.index'),
                ),
            ],

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
                    rows: Customer::query()->latest('id')->limit(8)->get(),
                    empty: __('customer::dashboard.none'),
                    href: route('customer.index'),
                ),
            ],
        );
    }
}
