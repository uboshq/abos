<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Watch;

use App\Core\Support\Money;
use App\Models\User;
use App\Modules\Accounts\Dashboard\AccountsBoard;
use App\Modules\Inventory\Dashboard\InventoryBoard;
use App\Modules\Sales\Dashboard\SalesBoard;
use Throwable;

/**
 * ⭐ সকালের সারাংশ — যার যার কাজ ধরে (পূর্ণ পরিকল্পনা অংশ ১ আর ৮, ধাপ ১)।
 *
 * ⓘ "কাজ" মানে ভূমিকার নাম নয়, চাবি: বিক্রির চাবি থাকলে বিক্রয়ের ভাগ, হিসাবের চাবি থাকলে হিসাবের ভাগ, মজুদের চাবি থাকলে
 *   মজুদের ভাগ। মালিকের সব চাবি, তাই তিন ভাগই; গুদামরক্ষক কেবল মজুদ — আর খরচের চাবি না থাকলে টাকার অঙ্ক ঢাকা
 *   ([[InventoryBoard::short()]])। SR নিজের ডিলারের বিক্রিই দেখেন (বিক্রয়ের দেয়াল বোর্ড নিজেই মানে)।
 * ⛔ নিজে কিছু গোনে না — বোর্ডের "এক নজরে" ([[SalesBoard::glance()]], [[AccountsBoard::glance()]], [[InventoryBoard::corner()]])।
 */
final class Brief
{
    /**
     * @return list<array{module: string, title: string, rows: list<array{label: string, value: string, sub: string, href: string}>}>
     */
    public function for(User $user): array
    {
        // ⓘ নজরের একই মনে রাখা — মানুষ, কোম্পানি, শাখা, ভাষা ধরে ([[Watch::cacheKey()]])
        return \Illuminate\Support\Facades\Cache::remember(Watch::cacheKey($user, 'brief'), Watch::CACHE_SECONDS, fn (): array => $this->build($user));
    }

    /** @return list<array{module: string, title: string, rows: list<array{label: string, value: string, sub: string, href: string}>}> */
    private function build(User $user): array
    {
        $out = [];

        foreach ([
            'sales' => ['sales.invoice.view', fn (): array => $this->sales()],
            'accounts' => ['accounts.view', fn (): array => $this->accounts()],
            'inventory' => ['inventory.stock.view', fn (): array => $this->stock()],
        ] as $module => [$permission, $read]) {
            if (! $user->can($permission)) {
                continue;
            }

            try {
                $rows = $read();
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if ($rows !== []) {
                $out[] = ['module' => $module, 'title' => (string) __('assistant::brief.'.$module), 'rows' => $rows];
            }
        }

        return $out;
    }

    /** @return list<array{label: string, value: string, sub: string, href: string}> */
    private function sales(): array
    {
        $g = app(SalesBoard::class)->glance();
        $today = $g['cols']['today'];
        $month = $g['cols']['month'];
        $href = route('module.dashboard', ['module' => 'sales']);

        return [
            ['label' => (string) __('assistant::brief.sales_today'), 'value' => '৳'.Money::format($today['sales'], 0),
                'sub' => (string) __('assistant::brief.bills', ['n' => $today['count']]), 'href' => $href],
            ['label' => (string) __('assistant::brief.collected_today'), 'value' => '৳'.Money::format($today['collected'], 0),
                'sub' => $today['rate'] === null ? '' : (string) __('assistant::brief.rate', ['pct' => $today['rate']]), 'href' => $href],
            ['label' => (string) __('assistant::brief.sales_month'), 'value' => '৳'.Money::format($month['sales'], 0),
                'sub' => $g['yoy'] === null ? '' : (string) __('assistant::brief.yoy', ['pct' => ($g['yoy'] >= 0 ? '+' : '').$g['yoy']]), 'href' => $href],
            ['label' => (string) __('assistant::brief.undelivered'), 'value' => (string) $g['undelivered'][0],
                'sub' => '৳'.Money::format((string) $g['undelivered'][1], 0), 'href' => $href],
        ];
    }

    /** @return list<array{label: string, value: string, sub: string, href: string}> */
    private function accounts(): array
    {
        return array_map(fn (array $g): array => [
            'label' => (string) __('accounts::board.g.'.$g['key']),
            'value' => (string) $g['value'],
            'sub' => trim((string) ($g['sub'] ?? '').(($g['delta'] ?? '') !== '' ? ' · '.$g['delta'] : ''), ' ·'),
            'href' => (string) ($g['href'] ?? ''),
        ], app(AccountsBoard::class)->glance());
    }

    /** @return list<array{label: string, value: string, sub: string, href: string}> */
    private function stock(): array
    {
        $c = app(InventoryBoard::class)->corner();
        $href = route('module.dashboard', ['module' => 'inventory']);
        $rows = [['label' => (string) __('inventory::board.value_title'), 'value' => (string) $c['cost'], 'sub' => (string) $c['sub'], 'href' => $href]];

        foreach (array_slice($c['rows'], 0, 4) as $r) {
            $rows[] = ['label' => (string) $r['label'], 'value' => (string) $r['value'], 'sub' => '', 'href' => $href];
        }

        return $rows;
    }
}
