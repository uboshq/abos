<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Core\Support\ViewedBranch;
use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ⭐ হিসাবের ছক আর একটা খাতের খাতা, ফোনের নিজের পর্দায় — মালিক, ১৭ অক্টোবর ২০২৬: *"mobile er ta mobile app style e zate bujazay"*
 * (coa আর খাতের পাতা ওয়েবে খুললে বোঝা কঠিন)।
 *
 * ⓘ হিসাব ওয়েবের পাতার একই সেবায় ([[AccountLedger]]) — একই শাখা ([[ViewedBranch::one()]]), একই চলমান জের, একই সর্বমোট।
 * ⓘ চাবি ওয়েবের হুবহু: ছক `viewAny`, খাত `view` ([[AccountPolicy]])। ⓘ খাত চেনা যায় public_id-তে (চুক্তি §৩ক), সংখ্যার id নয়।
 * ⓘ কেবল পড়া — খতিয়ানে কিছু লেখা হয় না।
 */
final class AccountLedgerApiController extends Controller
{
    public function __construct(private readonly AccountLedger $ledger) {}

    /** `GET /accounts/chart?q=` — চালু সব খাত, কোড ধরে, গাছের ধাপ আর জেরসহ; `q` দিলে কোড বা নামে মেলা খাতগুলো */
    public function chart(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        // ⚠️ গ্রুপের জের সন্তানদের যোগ — তাই জের গোনা হয় পুরো ছকে, ছাঁকা হয় তারপরে
        $accounts = Account::query()->active()->orderBy('code')->get();
        $balances = $this->ledger->balances($accounts, ViewedBranch::one());
        $byId = $accounts->keyBy('id');

        $term = mb_strtolower(trim((string) $request->query('q', '')));

        $rows = $accounts
            ->filter(fn (Account $a) => $term === ''
                || str_contains(mb_strtolower((string) $a->code), $term)
                || str_contains(mb_strtolower((string) $a->name_en), $term)
                || str_contains(mb_strtolower((string) $a->name_bn), $term))
            ->map(fn (Account $a) => [
                'id' => (string) $a->public_id,
                'code' => (string) $a->code,
                'name' => $a->name(),
                'is_group' => (bool) $a->is_group,
                'parent' => $a->parent_id !== null ? (string) ($byId[$a->parent_id]->public_id ?? '') : null,
                'depth' => $this->depth($a, $byId),
                'balance' => Money::drCr($this->drPositive($a, $balances[$a->id] ?? '0')),
            ])
            ->values();

        return response()->json(['accounts' => $rows, 'count' => $rows->count()]);
    }

    /** `GET /accounts/chart/{id}/ledger?page=` — খাতের সারি চলমান জেরসহ, আর সব পাতার ডেবিট, ক্রেডিট ও শেষ জের */
    public function ledger(Request $request, string $id): JsonResponse
    {
        $account = Account::query()->where('public_id', $id)->firstOrFail();
        $this->authorize('view', $account);

        $branch = ViewedBranch::one();
        $page = $this->ledger->page($account, $branch, (int) $request->query('page', 1));
        $entries = $page['entries'];

        return response()->json([
            'account' => [
                'id' => (string) $account->public_id,
                'code' => (string) $account->code,
                'name' => $account->name(),
                'is_group' => (bool) $account->is_group,
                // ⓘ মাথার জের ওয়েবের পাতার একই সংখ্যা ([[Account::balanceOn()]]), পাশ ধরে (Dr)/(Cr)
                'balance' => Money::drCr($this->drPositive($account, (string) $account->balanceOn(null, $branch))),
            ],
            'entries' => collect($entries->items())->map(fn (LedgerEntry $e) => [
                'date' => DateFormat::format($e->trx_date),
                'document_no' => (string) ($e->document_no ?? ''),
                'narration' => (string) ($e->narration ?? ''),
                'debit' => Money::isZero($e->debit) ? '' : Money::format($e->debit),
                'credit' => Money::isZero($e->credit) ? '' : Money::format($e->credit),
                'balance' => Money::drCr($e->running_balance),
            ])->values(),
            'totals' => [
                'debit' => Money::format($page['grand']['debit']),
                'credit' => Money::format($page['grand']['credit']),
                'closing' => Money::drCr($page['closing']),
            ],
            'count' => $entries->total(),
            'next_page' => $entries->hasMorePages() ? $entries->currentPage() + 1 : null,
        ])->header('Cache-Control', 'no-store');
    }

    /** খাতের নিজের দিকে ধনাত্মক জের → ডেবিট ধনাত্মক (Dr/Cr লেখার জন্য) */
    private function drPositive(Account $account, string $natural): string
    {
        return $account->nature === Account::CREDIT ? bcmul($natural, '-1', 4) : $natural;
    }

    /** @param  \Illuminate\Support\Collection<int, Account>  $byId */
    private function depth(Account $account, $byId): int
    {
        $depth = 0;
        $seen = [];

        while ($account->parent_id !== null && isset($byId[$account->parent_id]) && ! isset($seen[$account->parent_id]) && $depth < 10) {
            $seen[$account->parent_id] = true;
            $account = $byId[$account->parent_id];
            $depth++;
        }

        return $depth;
    }
}
