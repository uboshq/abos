<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Dashboard;

use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\DepreciationEntry;
use App\Modules\Accounts\Models\FixedAsset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ⭐ স্থায়ী সম্পদের এক পাতা — মোট দাম, সঞ্চিত ক্ষয়, খাতার দাম, এ মাসের অবচয়, অবস্থা ধরে, আর শেষ হতে চলা ওয়ারেন্টি-বিমা
 * (স্থায়ী সম্পদ ধাপ ৫; ড্যাশবোর্ড ইঞ্জিনের ইট দিয়ে — [[Stat]], [[Breakdown]], [[Listing]], [[Tile]])।
 *
 * ⓘ সংখ্যাগুলো সম্পদের মডেল থেকে, তাই শাখার দেয়াল মডেলের নিজের ([[ScopedToUserBranch]]) — শাখায় সীমিত মানুষ নিজের
 * শাখার অঙ্ক দেখেন। প্রতিটা সংখ্যা তার প্রতিবেদনে খোলে।
 */
final class FixedAssetDashboard
{
    /** ⓘ কত দিনের মধ্যে শেষ হলে "আসছে" — মালিকের প্রশ্ন, আপাতত ৬০ দিন */
    public const EXPIRY_DAYS = 60;

    public static function dashboard(): DashboardDefinition
    {
        $inBooks = FixedAsset::query()->inService()->get();
        $cost = Money::sumOf($inBooks, fn (FixedAsset $a) => (string) $a->cost);
        $accumulated = Money::sumOf($inBooks, fn (FixedAsset $a) => $a->accumulated());

        $month = Carbon::today()->endOfMonth()->toDateString();
        $thisMonth = Money::of((string) (DepreciationEntry::query()
            ->whereIn('fixed_asset_id', FixedAsset::query()->select('id'))
            ->where('period_end', $month)->sum('amount') ?: '0'));

        $report = fn (string $slug) => route('accounts.report.show', ['slug' => $slug]);

        $byStatus = FixedAsset::query()->where('status', '!=', FixedAsset::AWAITING)
            ->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');

        return new DashboardDefinition(
            title: __('accounts::asset_report.dashboard'),
            subtitle: __('accounts::asset_report.dashboard_hint'),
            stats: [
                new Stat(label: __('accounts::asset_report.total_cost'), value: Money::format($cost),
                    hint: __('accounts::asset_report.total_cost_hint', ['count' => $inBooks->count()]), href: $report('asset-register'), permission: 'accounts.report'),
                new Stat(label: __('accounts::asset_report.accumulated'), value: Money::format($accumulated),
                    hint: __('accounts::asset_report.accumulated_hint'), href: $report('asset-movement'), permission: 'accounts.report'),
                new Stat(label: __('accounts::asset_report.nbv'), value: Money::format(bcsub($cost, $accumulated, 4)),
                    hint: __('accounts::asset_report.nbv_hint'), href: $report('asset-nbv'), permission: 'accounts.report'),
                new Stat(label: __('accounts::asset_report.this_month'), value: Money::format($thisMonth),
                    hint: __('accounts::asset_report.this_month_hint'), href: $report('asset-depreciation'), permission: 'accounts.report'),
            ],
            panels: [
                new Breakdown(
                    label: __('accounts::asset_report.by_status'),
                    parts: collect(FixedAsset::STATUSES)->reject(fn ($s) => $s === FixedAsset::AWAITING)
                        ->map(fn ($s) => ['label' => __('accounts::asset.status_'.$s), 'value' => (string) (int) ($byStatus[$s] ?? 0)])
                        ->values()->all(),
                    hint: __('accounts::asset_report.by_status_hint'),
                ),
            ],
            listings: [
                new Listing(
                    label: __('accounts::asset_report.expiring_soon', ['days' => self::EXPIRY_DAYS]),
                    columns: [
                        ['key' => 'ends_on', 'label' => __('accounts::asset_report.ends_on'), 'width' => '8rem', 'render' => fn ($r) => $r['ends_on']],
                        ['key' => 'what', 'label' => __('accounts::asset_report.what'), 'width' => '7rem', 'render' => fn ($r) => $r['what']],
                        ['key' => 'name', 'label' => __('accounts::asset_report.name'), 'render' => fn ($r) => $r['name']],
                    ],
                    rows: self::expiring(),
                    empty: __('accounts::asset_report.nothing_expiring'),
                    href: $report('asset-expiring'),
                ),
            ],
            tiles: [
                new Tile(label: __('accounts::menu.assets'), href: route('accounts.asset.index'), permission: 'accounts.asset.view'),
                new Tile(label: __('accounts::menu.asset_verifications'), href: route('accounts.asset.verify.index'), permission: 'accounts.asset.view'),
                new Tile(label: __('accounts::asset_report.tax_page'), href: route('accounts.asset.tax'), permission: 'accounts.asset.manage'),
                new Tile(label: __('accounts::menu.books_check'), href: route('accounts.integrity'), permission: 'accounts.report'),
            ],
        );
    }

    /** @return Collection<int, array{ends_on: string, what: string, name: string}> */
    private static function expiring(): Collection
    {
        $from = Carbon::today();
        $to = Carbon::today()->addDays(self::EXPIRY_DAYS);
        $rows = collect();

        foreach (['warranty_ends_on' => 'warranty', 'insured_until' => 'insurance'] as $column => $what) {
            FixedAsset::query()->inService()->whereBetween($column, [$from->toDateString(), $to->toDateString()])
                ->orderBy($column)->limit(10)->get()
                ->each(fn (FixedAsset $a) => $rows->push([
                    'ends_on' => $a->{$column}?->format('d M Y'), 'what' => __('accounts::asset_report.'.$what), 'name' => $a->name, 'sort' => $a->{$column},
                ]));
        }

        return $rows->sortBy('sort')->take(10)->values();
    }
}
