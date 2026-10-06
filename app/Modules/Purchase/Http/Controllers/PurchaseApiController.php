<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Controllers;

use App\Core\Support\Money;
use App\Core\Support\PartyLedger;
use App\Core\Support\ViewedBranch;
use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseBillLine;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Models\PurchaseReceiptLine;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ⭐ ফোনে প্রিন্সিপালের তালিকা আর ক্রয়ের তালিকা — মালিক, ৬ অক্টোবর ২০২৬ (সমন্বয়কের মারফত)।
 *
 * ── প্রিন্সিপাল ([[principals()]], [[principal()]]) ─────────────────────────────
 * VENDOR ধরনের সরবরাহকারী ([[Supplier::scopeOnlySuppliers()]]), সংক্ষিপ্ত নামসহ; জের মাথায় বাছা শাখায় — ওয়েবের
 * সরবরাহকারীর পাতার একই হিসাব ([[Supplier::scopeWithPayableInView()]]): ধনাত্মক = "দিতে হবে", ঋণাত্মক = "পাব"; শেষ ক্রয়ের দিন;
 * চাপলে তার খাতা — ওয়েবের পাতার একই সারি আর চলমান জের ([[PartyLedger::page()]])। চাবি `supplier.view`।
 *
 * ── ক্রয় ([[purchases()]], [[purchase()]]) ─────────────────────────────────────
 * ক্রয় বিল (সরাসরি ক্রয়ও বিল — কাউন্টারে একসাথে লেখা) আর মাল গ্রহণ; তারিখ, প্রিন্সিপাল, মোট, আর বিলে পরিশোধিত ও বাকি
 * ([[PurchaseBill::paidAmount()]], [[PurchaseBill::dueAmount()]])। ছাঁকনি তারিখ আর প্রিন্সিপাল। চাবি বিলে `purchase.bill.view`,
 * গ্রহণে `purchase.receipt.view`। ⛔ কেনা দর আর লাইনের অঙ্ক কেবল `inventory.cost.view`-এ — না থাকলে ঘরটাই আসে না।
 *
 * ⓘ কেবল পড়া। শাখা — মডেলের নিজের দেয়াল ([[ScopedToUserBranch]]) আর মাথায় বাছা শাখা ([[ViewedBranch::narrow()]])।
 */
class PurchaseApiController extends Controller implements HasMiddleware
{
    private const PER_PAGE = 30;

    public static function middleware(): array
    {
        return [
            new Middleware('can:supplier.view', only: ['principals', 'principal']),
            // ⓘ বিস্তারিতে মাল গ্রহণ হলে তার নিজের চাবিও ([[purchase()]]) — তালিকাটাই বিলের চাবিতে খোলে
            new Middleware('can:purchase.bill.view', only: ['purchases', 'purchase']),
        ];
    }

    /** `GET /principals?q=` */
    public function principals(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        // ⭐ ওয়েবের সরবরাহকারীর তালিকার একই ছাঁকনি — মাথায় বাছা শাখা ([[Supplier::scopeInViewedBranch()]])
        $page = Supplier::query()->inViewedBranch()->onlySuppliers()->withPayableInView()
            ->addSelect(['last_purchase_on' => PurchaseBill::query()->selectRaw('MAX(pur_bills.trx_date)')
                ->whereColumn('pur_bills.supplier_id', 'suppliers.id')
                ->where('pur_bills.status', '!=', 'cancelled')])
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('suppliers.short_name', 'like', "%{$term}%")
                ->orWhere('suppliers.name_en', 'like', "%{$term}%")
                ->orWhere('suppliers.name_bn', 'like', "%{$term}%")
                ->orWhere('suppliers.code', 'like', "%{$term}%")))
            ->orderByRaw("COALESCE(NULLIF(suppliers.short_name, ''), suppliers.name_en)")
            ->paginate(self::PER_PAGE);

        return response()->json([
            'rows' => collect($page->items())->map(fn (Supplier $s) => $this->principalRow($s))->values(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    /** `GET /principals/{public_id}?page=` — মাথা আর খাতা, নতুন আগে (ওয়েবের পাতার মতো) */
    public function principal(Request $request, string $id): JsonResponse
    {
        $supplier = Supplier::query()->inViewedBranch()->onlySuppliers()->withPayableInView()->where('suppliers.public_id', $id)->firstOrFail();

        $entries = PartyLedger::page(
            ViewedBranch::narrow(LedgerEntry::query(), 'ledger_entries.branch_id')
                ->forParty(Supplier::drillSourceType(), $supplier->id)->orderBy('trx_date')->orderBy('id'),
            $request,
        );

        return response()->json([
            ...$this->principalRow($supplier),
            'entries' => $entries->getCollection()->reverse()->values()->map(fn (LedgerEntry $e) => [
                'date' => $e->trx_date?->toDateString(),
                'no' => (string) ($e->document_no ?? ''),
                'narration' => (string) ($e->narration ?? ''),
                'debit' => Money::round((string) $e->debit, 4),
                'credit' => Money::round((string) $e->credit, 4),
                // ⓘ সরবরাহকারীর জের — ক্রেডিট − ডেবিট (ধনাত্মক = দিতে হবে), ওয়েবের পাতার `running_balance`-এর একই অর্থ
                'balance' => Money::round(bcmul((string) $e->net_balance, '-1', 4), 4),
            ]),
            'next_page' => $entries->hasMorePages() ? $entries->currentPage() + 1 : null,
        ]);
    }

    /** `GET /purchases?kind=bill|receipt&from=&to=&principal=&page=` */
    public function purchases(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['nullable', 'in:bill,receipt'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'principal' => ['nullable', 'uuid'],
        ]);
        $kind = $data['kind'] ?? 'bill';

        if ($kind === 'receipt') {
            abort_unless((bool) $request->user()?->can('purchase.receipt.view'), 403);
        }

        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? now()->toDateString();
        [$model, $table] = $kind === 'bill' ? [PurchaseBill::class, 'pur_bills'] : [PurchaseReceipt::class, 'pur_receipts'];

        $query = ViewedBranch::narrow($model::query(), "{$table}.branch_id")
            ->whereBetween("{$table}.trx_date", [$from, $to])
            ->when($data['principal'] ?? null, fn (Builder $q, string $p) => $q->whereHas('supplier', fn (Builder $s) => $s->where('public_id', $p)));

        /*
         * ⛔ দামের চাবি ছাড়া কোনো টাকার অঙ্ক নয় — মোট, পরিশোধ, বাকি কিছুই (পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, ক্রয় ⚠️১৫): আগে কেবল
         * সারির দর লুকানো ছিল, অথচ বিলের মোট সবসময় যেত — এক সারির বিলে মোট ÷ পরিমাণ = দর। ⓘ বিলের পরিশোধ এক কোয়েরিতে
         * ([[PurchaseBill::scopeWithPaid()]]) — আগে সারিপ্রতি ২–৪টা।
         */
        $cost = (bool) $request->user()?->can('inventory.cost.view');
        $page = (clone $query)->with('supplier')->when($kind === 'bill' && $cost, fn (Builder $q) => $q->withPaid())
            ->orderByDesc("{$table}.trx_date")->orderByDesc("{$table}.id")->paginate(self::PER_PAGE);

        return response()->json([
            'kind' => $kind,
            'from' => $from,
            'to' => $to,
            'count' => $page->total(),
            ...($cost ? ['total' => Money::round((string) (clone $query)->where("{$table}.status", '!=', 'cancelled')->sum("{$table}.total"), 4)] : []),
            'rows' => collect($page->items())->map(fn (PurchaseBill|PurchaseReceipt $p) => $this->purchaseRow($p, $cost))->values(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    /** `GET /purchases/{bill|receipt}/{public_id}` — লাইনসহ; কেনা দর কেবল খরচের চাবিতে */
    public function purchase(Request $request, string $kind, string $id): JsonResponse
    {
        abort_unless((bool) $request->user()?->can($kind === 'bill' ? 'purchase.bill.view' : 'purchase.receipt.view'), 403);

        $model = $kind === 'bill' ? PurchaseBill::class : PurchaseReceipt::class;
        $table = $kind === 'bill' ? 'pur_bills' : 'pur_receipts';
        $paper = ViewedBranch::narrow($model::query(), "{$table}.branch_id")
            ->where("{$table}.public_id", $id)->with(['supplier', 'lines.product'])->firstOrFail();
        $cost = (bool) $request->user()?->can('inventory.cost.view');

        return response()->json([
            ...$this->purchaseRow($paper, $cost),
            'supplier_no' => (string) ($kind === 'bill' ? ($paper->supplier_bill_no ?? '') : ($paper->supplier_challan_no ?? '')),
            'narration' => (string) ($paper->narration ?? ''),
            'lines' => $paper->lines->map(fn (PurchaseBillLine|PurchaseReceiptLine $l) => [
                'product' => (string) ($l->product?->name() ?? ''),
                'qty' => Money::round((string) ($l instanceof PurchaseBillLine ? $l->qty : $l->received_qty), 4),
                'free' => Money::round((string) ($l->free_qty ?? '0'), 4),
                'batch' => (string) ($l->batch_no ?? ''),
                // ⛔ কেনা দর আর অঙ্ক — খরচ দেখার চাবি ছাড়া ঘরটাই নেই (ফোনে না-থাকা মানে "দেখার অনুমতি নেই")
                ...($cost ? [
                    'rate' => Money::round((string) $l->rate, 4),
                    'amount' => Money::round((string) $l->amount, 4),
                ] : []),
            ])->values(),
        ]);
    }

    /** @return array<string, mixed> */
    private function principalRow(Supplier $s): array
    {
        return [
            'id' => (string) $s->public_id,
            'name' => (string) (filled($s->short_name) ? $s->short_name : $s->name()),
            'full_name' => $s->name(),
            'code' => (string) $s->code,
            'phone' => (string) ($s->phone ?? ''),
            'balance' => Money::round((string) ($s->payable_in_view ?? '0'), 4),
            'last_purchase_on' => $s->last_purchase_on === null ? null : substr((string) $s->last_purchase_on, 0, 10),
        ];
    }

    /** @return array<string, mixed> */
    private function purchaseRow(PurchaseBill|PurchaseReceipt $p, bool $cost): array
    {
        $bill = $p instanceof PurchaseBill;

        return [
            'kind' => $bill ? 'bill' : 'receipt',
            'id' => (string) $p->public_id,
            'no' => (string) $p->document_no,
            'date' => $p->trx_date?->toDateString(),
            'principal' => (string) ($p->supplier === null ? '' : (filled($p->supplier->short_name) ? $p->supplier->short_name : $p->supplier->name())),
            'status' => (string) $p->status,
            'status_label' => (string) __('core.status.'.$p->status),
            // ⛔ অঙ্ক কেবল দামের চাবিতে — উপরের [[purchases()]] দেখুন
            ...($cost ? ['total' => Money::round((string) $p->total, 4)] : []),
            ...($bill && $cost ? [
                'paid' => Money::round($p->paidAmount(), 4),
                'due' => Money::round($p->dueAmount(), 4),
            ] : []),
        ];
    }
}
