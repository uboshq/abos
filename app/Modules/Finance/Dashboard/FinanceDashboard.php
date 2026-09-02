<?php

declare(strict_types=1);

namespace App\Modules\Finance\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Support\Money;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\Withdrawal;

/**
 * অর্থ মডিউলের ড্যাশবোর্ড।
 *
 * ── কেন মূলধন আর উত্তোলন পাশাপাশি ────────────────────────────────────
 * দুইটা একই প্রশ্নের দুই দিক: **মালিকের টাকা ব্যবসায় কত ঢুকেছে, আর কত
 * বেরিয়েছে।** আলাদা পর্দায় রাখলে কেউ একটা দেখে সিদ্ধান্ত নিতেন, আর
 * সেটা অর্ধেক ছবি।
 */
final class FinanceDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        return new DashboardDefinition(
            title: __('finance::dashboard.title'),
            subtitle: __('finance::dashboard.subtitle'),

            tiles: [
                new Tile(label: __('finance::menu.capital'), href: route('finance.capital.index'),
                    permission: 'finance.capital.view', icon: 'money'),
                new Tile(label: __('finance::menu.withdrawals'), href: route('finance.withdrawal.index'),
                    permission: 'finance.withdrawal.view', icon: 'report'),
            ],

            stats: [
                new Stat(
                    label: __('finance::dashboard.capital_in'),
                    value: Money::format(CapitalEntry::query()->where('entry_type', 'in')->sum('amount')),
                    hint: __('finance::dashboard.capital_in_hint'),
                    href: route('finance.capital.index'),
                    tone: Stat::GOOD,
                ),
                new Stat(
                    label: __('finance::dashboard.withdrawn'),
                    value: Money::format(Withdrawal::query()->sum('amount')),
                    hint: __('finance::dashboard.withdrawn_hint'),
                    href: route('finance.withdrawal.index'),
                    tone: Stat::WARN,
                ),
                new Stat(
                    label: __('finance::dashboard.deposits'),
                    value: (string) Deposit::query()->count(),
                    /*
                     * ⚠️ এখানে নামার কোনো লিংক নেই, ইচ্ছাকৃতভাবে।
                     *
                     * `finance.deposit.index` একটা **issuer** চায় — জমা
                     * সবসময় কোনো একটা প্রতিষ্ঠানের নামে থাকে, আর
                     * "সব জমা" বলে কোনো পাতা নেই। ভুল একটা issuer
                     * বেছে নিয়ে লিংক বানালে সংখ্যাটা এক জায়গায়
                     * দেখাত আর ক্লিক করলে অন্য জায়গায় নামত।
                     */
                    hint: __('finance::dashboard.deposits_hint'),
                ),
                new Stat(
                    label: __('finance::dashboard.contributors'),
                    value: (string) CapitalEntry::query()->distinct()->count('contributor_name'),
                    hint: __('finance::dashboard.contributors_hint'),
                    href: route('finance.capital.index'),
                ),
            ],

            listings: [
                new Listing(
                    label: __('finance::dashboard.recent_capital'),
                    columns: [
                        ['key' => 'no', 'label' => __('finance::dashboard.document'), 'width' => '9rem',
                            'render' => fn ($e) => $e->document_no],
                        ['key' => 'who', 'label' => __('finance::dashboard.contributor'),
                            'render' => fn ($e) => $e->contributor_name],
                        ['key' => 'amount', 'label' => __('finance::dashboard.amount'), 'width' => '9rem',
                            'render' => fn ($e) => Money::format($e->amount)],
                    ],
                    rows: CapitalEntry::query()->latest('id')->limit(8)->get(),
                    empty: __('finance::dashboard.no_capital'),
                    href: route('finance.capital.index'),
                ),
            ],
        );
    }
}
