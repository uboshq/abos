<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\QualityInspection;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\QualityInspectionService;
use App\Modules\MasterData\Models\ReasonCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ মান যাচাই (QC), ফোনের নিজের পর্দায় — তালিকা, পাতা, নতুন যাচাই, রায় আর বাতিল মাল সরানো (মালিক, ১৭ অক্টোবর ২০২৬; fe)।
 *
 *   GET  /api/v1/inventory/quality?from=&to=&status=&page=     GET /api/v1/inventory/quality/{id}
 *   POST /api/v1/inventory/quality                              POST /quality/{id}/decide · POST /quality/{id}/dispose
 *
 * ⛔ ওয়েবের একই সেবা ([[QualityInspectionService]] `open`, `decide`, `dispose`) আর একই নীতি ([[QualityInspectionPolicy]]):
 * দেখা `inventory.qc.view`, লেখা `inventory.qc.create`, রায় আর সরানো `inventory.qc.decide` — রায় কেবল বাকি যাচাইয়ে, সরানো কেবল
 * বাতিল মাল থাকলে। ⓘ গুদাম মডেলের নিজের দেয়ালে খোঁজা; কেবল `public_id`; প্রতিটা লেখা একবারই ([[RemembersAPhoneWrite]])।
 * ⓘ রায়ের কাগজের ছবি ওয়েবেই — ফোন থেকে ফাইল পরে।
 */
final class QualityPhoneController extends Controller implements HasMiddleware
{
    public const PER_PAGE = 30;

    public function __construct(private readonly QualityInspectionService $inspections) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,'.QualityInspection::class, only: ['index', 'show']),
            new Middleware('can:create,'.QualityInspection::class, only: ['store']),
            // ⓘ দরজার চাবি; কাগজের অবস্থার নীতি (বাকি রায়, বাতিল মাল আছে) পদ্ধতির ভেতরে ([[QualityInspectionPolicy]])
            new Middleware('can:inventory.qc.decide', only: ['decide', 'dispose']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::in([QualityInspection::PENDING, ...QualityInspection::RESULTS])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $to = $data['to'] ?? Carbon::today()->toDateString();
        $from = $data['from'] ?? Carbon::parse($to)->subMonths(3)->toDateString();

        // ⓘ বাকি রায় আগে, ওয়েবের তালিকার ডিফল্ট সাজানোর মতো
        $page = QualityInspection::query()->with(['product.unit', 'warehouse', 'inspector'])
            ->whereBetween('inspected_on', [min($from, $to), max($from, $to)])
            ->when($data['status'] ?? null, fn ($q, string $s) => $q->where('status', $s))
            ->orderByRaw("CASE WHEN status = '".QualityInspection::PENDING."' THEN 0 ELSE 1 END")
            ->orderByDesc('inspected_on')->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'from' => min($from, $to),
            'to' => max($from, $to),
            'statuses' => collect([QualityInspection::PENDING, ...QualityInspection::RESULTS])
                ->map(fn (string $s) => ['code' => $s, 'label' => __('inventory::status.qc_'.$s)])->values(),
            'rows' => $page->getCollection()->map(fn (QualityInspection $i) => $this->row($i))->values(),
            'count' => $page->total(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $inspection = $this->inspection($id);
        $this->authorize('view', $inspection);
        $inspection->load(['product.unit', 'batch', 'warehouse', 'inspector']);
        $user = $request->user();

        return response()->json($this->row($inspection) + [
            'batch' => $inspection->batch?->batch_no,
            'criteria' => $inspection->criteria,
            'remarks' => $inspection->remarks,
            'accepted_qty' => Money::quantity((string) ($inspection->accepted_qty ?? '0')),
            'rejected_qty' => Money::quantity((string) ($inspection->rejected_qty ?? '0')),
            'disposed_qty' => Money::quantity((string) ($inspection->disposed_qty ?? '0')),
            'can_decide' => (bool) $user?->can('decide', $inspection),
            'can_dispose' => (bool) $user?->can('dispose', $inspection),
            'results' => collect(QualityInspection::RESULTS)->map(fn (string $s) => ['code' => $s, 'label' => __('inventory::status.qc_'.$s)])->values(),
            // ⓘ সরানোর কারণ — মজুদ সমন্বয়ের কারণ, ওয়েবের পাতার একই তালিকা
            'write_off_reasons' => ReasonCode::query()->where('context', ReasonCode::STOCK_ADJUSTMENT)->orderBy('code')->get()
                ->map(fn (ReasonCode $r) => ['id' => (string) $r->public_id, 'name' => $r->name()])->values(),
        ]);
    }

    /** নতুন যাচাই — ওয়েবের [[QualityInspectionController::store()]] */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product' => ['required', 'string', 'max:64'],
            'warehouse' => ['nullable', 'string', 'max:64'],
            'inspected_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'inspected_qty' => ['required', 'numeric', 'gt:0'],
            'batch_no' => ['nullable', 'string', 'max:60'],
            'expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'criteria' => ['nullable', 'string', 'max:2000'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $product = Product::query()->where('public_id', $data['product'])->where('company_id', CompanyContext::id())->first();
        $warehouse = filled($data['warehouse'] ?? null)
            ? Warehouse::query()->where('public_id', $data['warehouse'])->where('company_id', CompanyContext::id())->first()
            : null;

        if ($product === null || (filled($data['warehouse'] ?? null) && $warehouse === null)) {
            throw ValidationException::withMessages([$product === null ? 'product' : 'warehouse' => __('inventory::validation.phone_unknown_reference')]);
        }

        $inspection = $this->inspections->open([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse?->id,
            'inspected_on' => $data['inspected_on'],
            'inspected_qty' => $data['inspected_qty'],
            'batch_no' => $data['batch_no'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'criteria' => $data['criteria'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ]);

        return response()->json(['status' => 'done', 'id' => (string) $inspection->public_id, 'no' => (string) $inspection->document_no,
            'notice' => __('inventory::message.qc_opened')], 201);
    }

    /** রায় — ওয়েবের [[QualityInspectionController::decide()]]; নীতি `decide` */
    public function decide(Request $request, string $id): JsonResponse
    {
        $inspection = $this->inspection($id);
        $this->authorize('decide', $inspection);

        $data = $request->validate([
            'result' => ['required', 'string', Rule::in(QualityInspection::RESULTS)],
            'accepted_qty' => ['required', 'numeric', 'min:0'],
            'rejected_qty' => ['required', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->inspections->decide(
            inspection: $inspection,
            result: $data['result'],
            acceptedQty: (string) $data['accepted_qty'],
            rejectedQty: (string) $data['rejected_qty'],
            remarks: $data['remarks'] ?? null,
        );

        return response()->json(['status' => 'done', 'notice' => __('inventory::message.qc_decided')], 201);
    }

    /** বাতিল মাল সরানো — ওয়েবের [[QualityInspectionController::dispose()]]; নীতি `dispose` */
    public function dispose(Request $request, string $id): JsonResponse
    {
        $inspection = $this->inspection($id);
        $this->authorize('dispose', $inspection);

        $data = $request->validate([
            'qty' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:64'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        $reason = ReasonCode::query()->where('public_id', $data['reason'])->where('company_id', CompanyContext::id())->first();

        if ($reason === null) {
            throw ValidationException::withMessages(['reason' => __('inventory::validation.phone_unknown_reference')]);
        }

        $this->inspections->dispose(inspection: $inspection, qty: (string) $data['qty'], writeOff: $reason, narration: $data['narration'] ?? null);

        return response()->json(['status' => 'done', 'notice' => __('inventory::message.qc_disposed')], 201);
    }

    private function inspection(string $id): QualityInspection
    {
        abort_unless(\Illuminate\Support\Str::isUuid($id), 404);

        return QualityInspection::query()->where('public_id', $id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function row(QualityInspection $i): array
    {
        $unit = $i->product?->unit?->name();

        return [
            'id' => (string) $i->public_id,
            'no' => (string) $i->document_no,
            'date' => $i->inspected_on?->toDateString(),
            'date_label' => $i->inspected_on === null ? null : DateFormat::format($i->inspected_on),
            'product' => trim(($i->product?->code ?? '').' - '.($i->product?->name() ?? ''), ' -'),
            'warehouse' => $i->warehouse?->name(),
            'qty' => trim(Money::quantity((string) $i->inspected_qty).' '.($unit ?? '')),
            'status' => (string) $i->status,
            'status_label' => __('inventory::status.qc_'.$i->status),
            'inspector' => $i->inspector?->name,
        ];
    }
}
