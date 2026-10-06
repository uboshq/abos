<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Core\Support\ViewedBranch;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\CollectionLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ⭐ ফোনে "টাকা আদায়" — মালিক, ৬ অক্টোবর ২০২৬ (সমন্বয়কের মারফত: "অ্যাপে payment received … দরকার")।
 *
 * ⓘ কেবল পড়া: আদায়ের তালিকা — তারিখের পরিসর, গ্রাহক বা দোকান খুঁজে, পদ্ধতি (নগদ · ব্যাংক · MFS · চেক), মোট — আর একটার
 * বিস্তারিত। ⛔ লেখা নেই; আদায় লেখা ওয়েবের আর ফোনের নিজের পথে।
 *
 * ── ⛔ দেয়াল ───────────────────────────────────────────────────────────────
 *   · চাবি ওয়েবের আদায়ের তালিকার — `sales.collection.view`
 *   · শাখা — মডেলের নিজের ([[ScopedToUserBranch]]), তার উপরে মাথায় বাছা শাখা ([[ViewedBranch::narrow()]]), ওয়েবের তালিকার মতো
 *   · SR — নিজের ডিলারের আদায়ই ([[ScopedToUserDealers]], মডেলের নিজের; দেয়াল চালু থাকলে)
 *
 * ⓘ পদ্ধতি আলাদা কোনো ঘরে লেখা থাকে না: টাকা কোন খাতে এল (নগদ · ব্যাংক · MFS), আর কাগজের নাম চেক হলে চেক।
 */
class CollectionApiController extends Controller implements HasMiddleware
{
    public const METHODS = ['cash', 'bank', 'mfs', 'cheque'];

    private const PER_PAGE = 30;

    public static function middleware(): array
    {
        return [new Middleware('can:sales.collection.view')];
    }

    /** `GET /collections?from=&to=&q=&method=&page=` */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
            'method' => ['nullable', 'in:'.implode(',', self::METHODS)],
        ]);

        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? now()->toDateString();

        $query = ViewedBranch::narrow(Collection::query(), 'sal_collections.branch_id')
            ->whereIn('sal_collections.status', DocumentStatus::POSTED)
            ->whereBetween('sal_collections.trx_date', [$from, $to])
            ->search($data['q'] ?? null)
            ->tap(fn (Builder $q) => self::method($q, $data['method'] ?? null));

        $total = (string) (clone $query)->sum('sal_collections.amount');
        $page = (clone $query)->with(['customer', 'account'])
            ->orderByDesc('sal_collections.trx_date')->orderByDesc('sal_collections.id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'from' => $from,
            'to' => $to,
            'count' => $page->total(),
            'total' => Money::round($total, 4),
            'rows' => collect($page->items())->map(fn (Collection $c) => $this->row($c))->values(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    /** `GET /collections/{public_id}` — একটা আদায়, কোন বিলে কত বসল */
    public function show(string $id): JsonResponse
    {
        $collection = ViewedBranch::narrow(Collection::query(), 'sal_collections.branch_id')
            ->where('public_id', $id)->with(['customer', 'account', 'lines.invoice', 'creator'])->firstOrFail();

        return response()->json([
            ...$this->row($collection),
            'instrument' => (string) ($collection->instrument ?? ''),
            'instrument_no' => (string) ($collection->instrument_no ?? ''),
            'instrument_date' => $collection->instrument_date?->toDateString(),
            'narration' => (string) ($collection->narration ?? ''),
            'by' => (string) ($collection->creator?->name ?? ''),
            'lines' => $collection->lines->map(fn (CollectionLine $l) => [
                'invoice' => (string) ($l->invoice?->document_no ?? ''),
                'amount' => Money::round((string) $l->amount, 4),
            ])->values(),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(Collection $c): array
    {
        return [
            'id' => (string) $c->public_id,
            'no' => (string) $c->document_no,
            'date' => $c->trx_date?->toDateString(),
            'customer' => (string) ($c->customer?->name() ?? ''),
            'account' => (string) ($c->account?->name() ?? ''),
            'method' => self::methodOf($c),
            'amount' => Money::round((string) $c->amount, 4),
        ];
    }

    /** পদ্ধতি — চেক কাগজের নামে, নাহলে টাকা যে খাতে এল তার ধরন */
    public static function methodOf(Collection $c): string
    {
        if (self::isCheque((string) $c->instrument)) {
            return 'cheque';
        }

        return match ($c->account?->money_kind) {
            Account::CASH => 'cash',
            Account::BANK => 'bank',
            Account::MFS => 'mfs',
            default => 'other',
        };
    }

    private static function isCheque(string $instrument): bool
    {
        $word = mb_strtolower(trim($instrument));

        return $word !== '' && (str_contains($word, 'cheque') || str_contains($word, 'check') || str_contains($word, 'চেক'));
    }

    private static function method(Builder $query, ?string $method): void
    {
        $cheque = fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('sal_collections.instrument', 'like', '%cheque%')
            ->orWhere('sal_collections.instrument', 'like', '%check%')
            ->orWhere('sal_collections.instrument', 'like', '%চেক%'));

        match ($method) {
            'cheque' => $cheque($query),
            // ⚠️ কাগজের নাম ফাঁকা (NULL) সারি "চেক নয়" — NOT LIKE-এ NULL পড়ে যেত, আর নগদের আদায় হারাত
            'cash', 'bank', 'mfs' => $query
                ->where(fn (Builder $w) => $w->whereNull('sal_collections.instrument')->orWhereNot(fn (Builder $q) => $cheque($q)))
                ->whereHas('account', fn (Builder $a) => $a->where('money_kind', match ($method) {
                    'cash' => Account::CASH, 'bank' => Account::BANK, default => Account::MFS,
                })),
            default => null,
        };
    }
}
