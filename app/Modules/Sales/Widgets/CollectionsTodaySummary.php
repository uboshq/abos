<?php

declare(strict_types=1);

namespace App\Modules\Sales\Widgets;

use App\Core\Services\DealerScope;
use App\Core\Widgets\WidgetSummary;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherLine;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Models\Collection;
use Illuminate\Support\Carbon;

/**
 * ⭐ আজকের আদায় — টাকা কোথায় ঢুকল (নগদ · ব্যাংক · MFS) ভাগ করে (ফোনের হোম-স্ক্রিন উইজেট; মালিকের আদেশ, ২০ অক্টোবর ২০২৬)।
 *
 * ⭐ মোট নিজে গোনা নয় — ওয়েবের একই উৎস [[SalesMetrics::collectionTotal()]] (মালিকের পাতার [[Figures::COLLECTIONS]], ফোনের
 * আজকের পাতা, বিক্রয়ের ড্যাশবোর্ড — সবাই এটাই ডাকে)। ভাগগুলো সেই একই দুই উৎসের সারি — খাতায় বসা আদায়ের কাগজ আর গ্রাহকের রসিদ
 * ভাউচার — একই দেয়ালে (শাখা: মডেলের দেখার-শাখা স্কোপ; ডিলার: আদায়ে মডেলের স্কোপ, রসিদে [[DealerScope::restrict()]], হুবহু
 * collectionTotal-এর মতো)।
 *
 * ⭐ খাতের নিয়ম — টাকা যে খাতে পড়ল তার `money_kind` ([[Account::MONEY_KINDS]], বাবার খাত থেকে বসে):
 *  - আদায়ের কাগজ: `account_id` (কাগজ নিজেই বলে টাকা কোথায় ঢুকল);
 *  - রসিদ ভাউচার: মাথার `money_account_id`, না থাকলে সারির প্রথম টাকার খাত — ভাউচারের পাতা আর সইয়ের পাতার একই নিয়ম
 *    ([[VoucherApiController::moneySide()]], [[Voucher::signingSheet()]]); ওয়েবের "টাকা কোন খাতে" ছাঁকনিও তাই ([[paidInto()]])।
 * কোনো খাতে না মিললে `other` — কেবল তখনই উত্তরে আসে; ⛔ চুপচাপ নগদে ঠেলে দেওয়া নয়।
 *
 * ⛔ cash + bank + mfs (+ other) == total — bcmath, চার ঘর।
 */
final class CollectionsTodaySummary implements WidgetSummary
{
    public function key(): string
    {
        return 'collections_today';
    }

    public function module(): string
    {
        return 'sales';
    }

    /** ওয়েবের আদায়ের সংখ্যা আর তালিকার চাবি ([[Figures::COLLECTIONS]], ফোনের আজকের পাতা) */
    public function permission(): ?string
    {
        return 'sales.collection.view';
    }

    public function data(User $user): array
    {
        $today = Carbon::today()->toDateString();

        $collections = Collection::query()
            ->posted()
            ->whereBetween('trx_date', [$today, $today])
            ->get();

        $vouchers = Voucher::query()
            ->where('type', Voucher::RECEIPT)
            ->where('party_type', 'customer')
            ->tap(fn ($q) => app(DealerScope::class)->restrict($q, 'party_id'))
            ->posted()
            ->whereBetween('trx_date', [$today, $today])
            ->with('lines')
            ->get();

        // ⓘ সারিগুলো আগেই দেয়ালে ছাঁকা — তাই খাতের ধরন খোঁজায় খাতের নিজের দেয়াল লাগে না (অন্য শাখার টিল হলেও ধরন একই)
        $ids = $collections->pluck('account_id')
            ->merge($vouchers->pluck('money_account_id'))
            ->merge($vouchers->flatMap(fn (Voucher $v) => $v->lines->pluck('account_id')))
            ->filter()->unique()->values()->all();
        $kinds = $ids === [] ? [] : Account::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->where('is_group', false)
            ->whereIn('money_kind', Account::MONEY_KINDS)
            ->pluck('money_kind', 'id')
            ->all();

        $parts = [Account::CASH => '0.0000', Account::BANK => '0.0000', Account::MFS => '0.0000', 'other' => '0.0000'];
        $add = function (?string $kind, mixed $amount) use (&$parts): void {
            $kind ??= 'other';
            $parts[$kind] = bcadd($parts[$kind], (string) ($amount ?: '0'), 4);
        };

        foreach ($collections as $c) {
            $add($kinds[(int) $c->account_id] ?? null, $c->amount);
        }
        foreach ($vouchers as $v) {
            $add($this->landedIn($v, $kinds), $v->amount);
        }

        // ⓘ সময়ের ছাপ নেই — দরজা নিজেই `asOf` পাঠায়; ভেতরে ঘড়ি থাকলে ETag কখনো মিলত না, আর 304 আসত না
        $out = [
            'total' => bcadd(SalesMetrics::collectionTotal($today, $today), '0', 4),
            'cash' => $parts[Account::CASH],
            'bank' => $parts[Account::BANK],
            'mfs' => $parts[Account::MFS],
        ];
        if (bccomp($parts['other'], '0', 4) !== 0) {
            $out['other'] = $parts['other'];
        }
        $out['link'] = route('sales.collection.index', ['from' => $today, 'to' => $today], false);

        return $out;
    }

    /**
     * রসিদের টাকা কোন ধরনের খাতে — মাথার খাত, না থাকলে সারির প্রথম টাকার খাত ([[Voucher::signingSheet()]]-এর একই নিয়ম)।
     *
     * @param  array<int, string>  $kinds
     */
    private function landedIn(Voucher $v, array $kinds): ?string
    {
        if ($v->money_account_id !== null) {
            return $kinds[(int) $v->money_account_id] ?? null;
        }

        $line = $v->lines->first(fn (VoucherLine $l) => isset($kinds[(int) $l->account_id]));

        return $line === null ? null : $kinds[(int) $line->account_id];
    }
}
