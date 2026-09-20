<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\InstitutionAccount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * ব্যাংক চার্জ — কোন ব্যাংক/MFS কত কাটল (মানচিত্র §৯)।
 *
 * ── কোথা থেকে পড়ে ────────────────────────────────────────────────────
 * খতিয়ান থেকে, আর কোথাও না: ৫২১০ (ব্যাংক চার্জ) আর ৫২১১ (MFS চার্জ),
 * আর তাদের নিচে কেউ খাত খুলে থাকলে সেগুলোও। ⓘ চার্জ বসে তিনভাবে — রসিদের
 * "চার্জ" ঘরে ([[VoucherService::withCharge]]), মূলধনের পোস্টিংয়ে, আর
 * হাতে লেখা খরচ/পরিশোধ ভাউচারে। তিনটাই শেষে এই দুই খাতে নামে, তাই এখান
 * থেকে পড়লে কোনোটা বাদ পড়ে না।
 *
 * ── "কোন ব্যাংক" কীভাবে জানা যায় ────────────────────────────────────
 * চার্জের দাখিলার নিজের সারিতে ব্যাংকের নাম নেই; আছে **একই কাগজের**
 * আরেকটা সারিতে — ব্যাংক বা MFS-এর খাত। ⓘ সেটাই একটা উপ-প্রশ্ন হয়ে
 * প্রতিটা সারির সাথে আসে ([[BANK_OF]])। ⚠️ এক কাগজে দুইটা ব্যাংক থাকলে
 * (ব্যাংক থেকে ব্যাংকে কন্ট্রা) ছোট আইডিরটা ধরা হয় — চার্জ দুইবার গোনা
 * হয় না, এটাই জরুরি। ব্যাংকের সারি না পেলে "অজানা" — লুকানো হয় না।
 *
 * ── ⚠️ তালিকা আর যোগফল আলাদা দুইটা প্রশ্ন ───────────────────────────
 * সারিগুলো পাতা ভাগ করে আসে, আর উপরের "ব্যাংক ধরে" যোগফল আসে পুরো
 * সময়ের উপর আলাদা সমষ্টি-প্রশ্নে। ⓘ একই তালিকা থেকে গুনলে দ্বিতীয়
 * পাতায় গিয়ে "মোট" বদলে যেত, অথচ লেবেল বলত মাসের মোট
 * ([[Tests\Feature\Architecture\EveryListScreenPaginatesTest]])।
 *
 * ⓘ অঙ্ক = ডেবিট − ক্রেডিট: চার্জ ফেরত এলে (ক্রেডিট) কমে।
 */
final class BankCharges
{
    /** একই কাগজের ব্যাংক/MFS খাত — না পেলে `null` */
    private const BANK_OF = '(select min(s.account_id) from ledger_entries s
        where s.source_type = ledger_entries.source_type
          and s.source_id = ledger_entries.source_id
          and s.account_id in (select id from accounts where money_kind in (?, ?) and is_group = 0))';

    /** @return LengthAwarePaginator<int, LedgerEntry> */
    public function rows(string $from, string $to, int $perPage = 100): LengthAwarePaginator
    {
        return $this->base($from, $to)
            ->selectRaw('ledger_entries.*, '.self::BANK_OF.' as bank_id', [Account::BANK, Account::MFS])
            ->orderByDesc('trx_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * ব্যাংক ধরে — কতবার আর কত, পুরো সময়ের উপর।
     *
     * @return Collection<int, array{bank: ?Account, institution: ?string, count: int, amount: string}>
     */
    public function byBank(string $from, string $to): Collection
    {
        $rows = $this->base($from, $to)
            ->toBase()
            ->selectRaw(self::BANK_OF.' as bank_id, COUNT(*) as n, COALESCE(SUM(debit - credit), 0) as amount',
                [Account::BANK, Account::MFS])
            ->groupBy('bank_id')
            ->get();

        $banks = Account::query()->whereIn('id', $rows->pluck('bank_id')->filter())->get()->keyBy('id');

        $institutions = InstitutionAccount::query()
            ->with('institution')
            ->whereIn('account_id', $banks->keys())
            ->get()
            ->mapWithKeys(fn (InstitutionAccount $l) => [$l->account_id => $l->institution?->label()]);

        return $rows
            ->map(fn ($r) => [
                'bank' => $banks[$r->bank_id] ?? null,
                'institution' => $institutions[$r->bank_id] ?? null,
                'count' => (int) $r->n,
                'amount' => (string) $r->amount,
            ])
            ->sortByDesc(fn ($b) => (float) $b['amount'])
            ->values();
    }

    public function total(string $from, string $to): string
    {
        return (string) ($this->base($from, $to)
            ->toBase()
            ->selectRaw('COALESCE(SUM(debit - credit), 0) as t')
            ->value('t') ?? '0');
    }

    /** @return Builder<LedgerEntry> */
    private function base(string $from, string $to): Builder
    {
        $ids = $this->chargeAccountIds();

        return LedgerEntry::query()
            ->whereIn('account_id', $ids === [] ? [0] : $ids)
            ->whereBetween('trx_date', [$from, $to]);
    }

    /** @return list<int> ৫২১০, ৫২১১ আর তাদের নিচের সব খাত */
    private function chargeAccountIds(): array
    {
        return Account::query()
            ->whereIn('code', [StandardChart::BANK_CHARGES, StandardChart::MFS_CHARGES])
            ->get()
            ->flatMap(fn (Account $a) => $a->selfAndDescendants()->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
