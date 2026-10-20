<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\SalesQuotation;
use App\Modules\Sales\Support\QuotationComparison;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ উদ্ধৃতির তুলনা আর সংস্করণ, ফোনের নিজের পর্দায় (মালিক, ১৭ অক্টোবর ২০২৬; fe)।
 *
 *   `GET /api/v1/sales/quotations/compare?ids[]=&family=` — পাশাপাশি দুই থেকে ছয়টা, ওয়েবের একই হিসাব ([[QuotationComparison::of()]])
 *   `GET /api/v1/sales/quotations/revisions?q=&page=` — যেসব উদ্ধৃতির সংস্করণ আছে, প্রতিটার সর্বশেষটা
 *
 * ⛔ দ্বিতীয় নিয়ম নেই — ওয়েবের [[SalesQuotationController::compare()]] আর `revisions()`-এর একই কোয়েরি, একই চাবি
 * (`viewAny` = `sales.quotation.view`), দেয়াল মডেলের নিজের স্কোপে (কোম্পানি, শাখা, বিক্রয়কর্মীর বাঁধা ডিলার)। ⓘ কেবল `public_id`।
 */
final class QuotationCompareApiController extends Controller implements HasMiddleware
{
    public const PER_PAGE = 30;

    public static function middleware(): array
    {
        return [new Middleware('can:viewAny,'.SalesQuotation::class)];
    }

    public function compare(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['nullable', 'array', 'max:'.QuotationComparison::MAX],
            'ids.*' => ['string', 'max:64'],
            'family' => ['nullable', 'string', 'max:64'],
        ]);

        if (filled($data['family'] ?? null)) {
            $anchor = SalesQuotation::query()->where('public_id', $data['family'])->firstOrFail();
            $quotations = $anchor->family()->with(['lines.product', 'lines.enteredUnit', 'customer.location.parent', 'customer.branch', 'paymentTerm'])->get();

            // ⛔ পরিবারের কোনো সংস্করণ দেয়ালের বাইরে হলে পুরোটা ৪০৪ — চুপচাপ বাদ দিয়ে আধা তুলনা নয়, `ids`-এর একই নিয়ম
            // (a5-এর পর্যালোচনা, ১৭ অক্টোবর ২০২৬); কোম্পানি নামে বলা, মোছাগুলো বাদ
            $whole = SalesQuotation::query()->withoutGlobalScopes()->where('company_id', $anchor->company_id)->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereKey($anchor->rootId())->orWhere('root_quotation_id', $anchor->rootId()))->count();
            abort_if($quotations->count() !== $whole, 404);
        } else {
            $ids = collect($data['ids'] ?? [])->unique()->values();
            $quotations = SalesQuotation::query()->whereIn('public_id', $ids)
                ->with(['lines.product', 'lines.enteredUnit', 'customer.location.parent', 'customer.branch', 'paymentTerm'])->orderBy('id')->get();

            // ⓘ কোনোটা দেয়ালের বাইরে বা নেই — ওয়েবের মতোই ৪০৪, কোনটা তা না বলে
            abort_if($quotations->count() !== $ids->count(), 404);
        }

        if ($quotations->count() > QuotationComparison::MAX) {
            throw ValidationException::withMessages(['ids' => __('sales::quotation.compare.too_many', ['max' => QuotationComparison::MAX])]);
        }

        return response()->json([
            'quotations' => $quotations->values()->map(fn (SalesQuotation $q) => $this->head($q)),
            // ⓘ দুটোর কম হলে তুলনা নেই — ফোন বাছাই চায়
            'comparison' => $quotations->count() >= 2 ? QuotationComparison::of($quotations) : null,
        ]);
    }

    public function revisions(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);

        $roots = SalesQuotation::query()
            ->whereNull('root_quotation_id')
            ->whereIn('id', SalesQuotation::query()->whereNotNull('root_quotation_id')->select('root_quotation_id'))
            ->search($data['q'] ?? null)
            ->with(['customer.location.parent', 'customer.branch'])
            ->latest('id')
            ->paginate(self::PER_PAGE);

        $families = SalesQuotation::query()->whereIn('root_quotation_id', $roots->getCollection()->pluck('id'))
            ->with(['customer.location.parent', 'customer.branch'])->get()
            ->groupBy('root_quotation_id');

        return response()->json([
            'rows' => $roots->getCollection()->map(function (SalesQuotation $root) use ($families): array {
                $family = $families->get($root->id) ?? collect();
                $latest = $family->sortByDesc('revision_no')->first() ?? $root;

                return $this->head($root) + [
                    'revisions' => $family->count(),
                    'latest' => $this->head($latest),
                ];
            })->values(),
            'count' => $roots->total(),
            'next_page' => $roots->hasMorePages() ? $roots->currentPage() + 1 : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function head(SalesQuotation $q): array
    {
        return [
            'id' => (string) $q->public_id,
            'no' => (string) $q->document_no,
            'revision_no' => (int) ($q->revision_no ?? 0),
            'customer' => $q->customer?->name(),
            // ⭐ পয়েন্ট আর শাখা — মালিক, ১৯ অক্টোবর ২০২৬ (স্থায়ী নিয়ম): "গ্রাহকের নামের পাশে পয়েন্ট দিতে হবে অবশ্যই, সব জায়গায়, আর শাখার নামও দিতে হবে" ([[Customer::pointName()]], [[Customer::branchName()]])
            'customer_point' => $q->customer?->pointName(),
            'customer_branch' => $q->customer?->branchName(),
            'status' => (string) $q->effectiveStatus(),
            'status_label' => __('sales::quotation.status.'.$q->effectiveStatus()),
            'total' => bcadd((string) ($q->total ?? '0'), '0', 2),
            'total_label' => Money::format((string) ($q->total ?? '0')),
        ];
    }
}
