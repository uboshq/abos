<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Watch;

use App\Models\AssistantDismissal;
use App\Models\User;
use App\Modules\Accounts\Dashboard\AccountsBoard;
use App\Modules\Finance\Dashboard\FinanceBoard;
use App\Modules\Inventory\Dashboard\InventoryBoard;
use App\Modules\Sales\Dashboard\SalesBoard;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * ⭐ কোপাইলটের নজর — অস্বাভাবিক কিছু, চার ড্যাশবোর্ডের সমস্যা কেন্দ্র থেকে (পূর্ণ পরিকল্পনা অংশ ১, ধাপ ১)।
 *
 * ── ⛔ নিজে কিছু গোনে না ───────────────────────────────────────────────
 * হিসাব ([[AccountsBoard::problems()]]), বিক্রয় ([[SalesBoard::problems()]]), মজুদ ([[InventoryBoard::problems()]]) আর অর্থের
 * ১৩ সপ্তাহের নগদ ([[FinanceBoard::forecast()]]) — ড্যাশবোর্ডের একই অঙ্ক, একই চাবি। তাই নজর আর ড্যাশবোর্ড কখনো আলাদা কথা বলে না।
 *
 * ⓘ প্রত্যেকে কেবল নিজের চাবির উৎস দেখেন — বোর্ডের সারির একই চাবি (হিসাব accounts.view, বিক্রয় sales.invoice.view, মজুদ
 *   inventory.stock.view); বোর্ডের ভেতরের খরচ-চাবি আর শাখার দেয়াল বোর্ড নিজেই মানে।
 * ⓘ "এটা ঠিক আছে" বলা নজর [[AssistantDismissal::DAYS]] দিন চুপ ([[AssistantDismissal]])।
 * ⓘ একটা উৎস ভাঙলে বাকিরা দেখায় — একটা ভুল গোটা পাতা ফেলে না (ভুলটা লগে যায়)।
 */
final class Watch
{
    /** ⓘ জরুরি আগে — বোর্ডের একই রং-ধাপ */
    public const ORDER = ['urgent' => 0, 'today' => 1, 'week' => 2];

    /** ⓘ কতক্ষণ মনে রাখা — ৫ মিনিট; বোর্ডের সংখ্যা এর চেয়ে দ্রুত বদলালেও নজরের জন্য যথেষ্ট টাটকা */
    public const CACHE_SECONDS = 300;

    /** ⓘ মানুষ, কোম্পানি, দেখার শাখা (হেডারের বাছাই), ভাষা — যেকোনোটা বদলালে নতুন হিসাব */
    public static function cacheKey(User $user, string $part = 'watch'): string
    {
        return implode(':', ['assistant', $part, \App\Core\Support\CompanyContext::id(), $user->id,
            (int) \App\Core\Support\CompanyContext::branchId(), (int) (bool) $user->view_all_branches, app()->getLocale()]);
    }

    /**
     * @return list<array{id: string, module: string, title: string, count: string, detail: string, severity: string, href: string}>
     */
    public function for(User $user): array
    {
        /*
         * ⓘ চার বোর্ডের সমস্যা কেন্দ্র একসাথে ভারী (কয়েক সেকেন্ড) — তাই [[CACHE_SECONDS]] মনে রাখা, মানুষ, কোম্পানি, দেখার শাখা আর
         *   ভাষা ধরে (শাখা বদলালে বা অন্য মানুষে অন্য উত্তর)। ⓘ "এটা ঠিক আছে" মনে রাখার পরে ছাঁকা হয় — চুপ করানো সাথে সাথে খাটে।
         */
        $out = \Illuminate\Support\Facades\Cache::remember(self::cacheKey($user), self::CACHE_SECONDS, function () use ($user): array {
            $found = [];

            foreach ($this->sources() as $module => [$permission, $read]) {
                if (! $user->can($permission)) {
                    continue;
                }

                try {
                    foreach ($read() as $finding) {
                        $found[] = $finding + ['module' => $module];
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            }

            return $found;
        });

        $quiet = AssistantDismissal::query()->where('until', '>=', Carbon::today()->toDateString())->pluck('finding')->all();
        $out = array_values(array_filter($out, fn (array $f): bool => ! in_array($f['id'], $quiet, true)));
        usort($out, fn (array $a, array $b): int => (self::ORDER[$a['severity']] ?? 9) <=> (self::ORDER[$b['severity']] ?? 9));

        return $out;
    }

    /**
     * উৎস → [চাবি, পড়ার কাজ]। ⓘ নতুন মডিউলের নজর এখানে এক সারি।
     *
     * @return array<string, array{0: string, 1: callable(): list<array<string, string>>}>
     */
    private function sources(): array
    {
        return [
            'accounts' => ['accounts.view', fn (): array => $this->boardProblems(app(AccountsBoard::class)->problems(), 'accounts', 'accounts::board.p.')],
            'sales' => ['sales.invoice.view', fn (): array => $this->boardProblems(app(SalesBoard::class)->problems(), 'sales', 'sales::board.p.')],
            'inventory' => ['inventory.stock.view', fn (): array => $this->stockProblems(app(InventoryBoard::class)->problems())],
            'finance' => ['accounts.view', fn (): array => $this->cashLow(app(FinanceBoard::class)->forecast())],
        ];
    }

    /**
     * হিসাব আর বিক্রয়ের সমস্যা কেন্দ্র — key, n, detail, note, sev, href।
     *
     * @param  list<array<string, string>>  $problems
     * @return list<array<string, string>>
     */
    private function boardProblems(array $problems, string $module, string $words): array
    {
        $out = [];

        foreach ($problems as $p) {
            $sev = (string) ($p['sev'] ?? 'ok');

            if (! isset(self::ORDER[$sev])) {
                continue;
            }

            $out[] = [
                'id' => $module.'.'.$p['key'],
                'title' => (string) __($words.$p['key']),
                'count' => (string) ($p['n'] ?? ''),
                'detail' => trim((string) ($p['detail'] ?? '').(($p['note'] ?? '') !== '' ? ' · '.$p['note'] : '')),
                'severity' => $sev,
                'href' => (string) ($p['href'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * মজুদের সমস্যা কেন্দ্র — label, count, value, text, severity, href।
     *
     * @param  list<array<string, string>>  $problems
     * @return list<array<string, string>>
     */
    private function stockProblems(array $problems): array
    {
        $out = [];

        foreach ($problems as $p) {
            $sev = (string) ($p['severity'] ?? 'ok');

            if (! isset(self::ORDER[$sev])) {
                continue;
            }

            $out[] = [
                'id' => 'inventory.'.$p['key'],
                'title' => (string) $p['label'],
                'count' => (string) ($p['count'] ?? ''),
                'detail' => trim((string) ($p['value'] ?? '').' · '.(string) ($p['text'] ?? ''), ' ·'),
                'severity' => $sev,
                'href' => (string) ($p['href'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * ⭐ ১৩ সপ্তাহের নগদ নিরাপদ সীমার নিচে নামবে — অর্থের পূর্বাভাস থেকে একটা নজর।
     *
     * @param  array{points: list<string>, weeks: list<array<string, mixed>>, floor: string, low: int}  $f
     * @return list<array<string, string>>
     */
    private function cashLow(array $f): array
    {
        $week = $f['weeks'][$f['low']] ?? null;

        if ($week === null || bccomp((string) $week['closing'], (string) $f['floor'], 4) >= 0) {
            return [];
        }

        return [[
            'id' => 'finance.cash_below_floor',
            'title' => (string) __('assistant::watch.cash_below_floor'),
            'count' => (string) __('assistant::watch.week_n', ['n' => $f['low'] + 1]),
            'detail' => (string) __('assistant::watch.cash_below_floor_detail', [
                'closing' => \App\Core\Support\Money::format((string) $week['closing'], 0),
                'floor' => \App\Core\Support\Money::format((string) $f['floor'], 0),
                'from' => Carbon::parse((string) $week['from'])->format('d/m'),
            ]),
            'severity' => $f['low'] <= 1 ? 'urgent' : ($f['low'] <= 4 ? 'today' : 'week'),
            'href' => \Illuminate\Support\Facades\Route::has('module.dashboard') ? route('module.dashboard', ['module' => 'finance']) : '',
        ]];
    }
}
