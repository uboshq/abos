<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StorageLocation;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\BatchService;
use App\Modules\Inventory\Services\OpeningStockService;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Inventory\Services\StrandedStock;
use App\Modules\MasterData\Models\ReasonCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ মজুদের পাঁচ লেখা, ফোনের নিজের ফর্মে — মাল বসানো, গণনা-সমন্বয়, ইস্যু, খোলা মজুদ, লট (মালিক, ১৭ অক্টোবর ২০২৬; fe)।
 *
 *   GET  /api/v1/inventory/setup               গুদাম (জায়গাসহ), কারণ, আর এই মানুষ কোনটা পারেন
 *   GET  /api/v1/inventory/placement           বসানোর অপেক্ষার মাল — প্রতিটা সারির `ref`
 *   POST /api/v1/inventory/placement           `lines[{ref, qty, free_qty, place}]`
 *   POST /api/v1/inventory/adjust              গোনা → এক-সারির গণনা; মেনে নেওয়ার চাবি থাকলে মেনে নেওয়া
 *   GET  /api/v1/inventory/counts              মেনে নেওয়ার অপেক্ষার গণনা
 *   POST /api/v1/inventory/counts/{id}/approve মেনে নেওয়া, কারণসহ
 *   POST /api/v1/inventory/issue               বিক্রি ছাড়া মাল বের করা
 *   POST /api/v1/inventory/opening             খোলা মজুদ
 *   POST /api/v1/inventory/lot                 লট-ছাড়া মালকে লট দেওয়া
 *
 * ── ⛔ নতুন পোস্টিং-পথ নেই — ওয়েবের একই সেবা, একই ক্রমে ──────────────────────────────────────────────
 * প্রতিটা দরজা ওয়েবের পর্দার নিজের কাজটাই করে: [[StockPlacementController::placeLines()]], [[StockCountService::record()]] আর
 * `approve()`, `issue()`, [[OpeningStockService::bringIn()]], [[StrandedStock::giveItALot()]]। ঋণাত্মক মজুদ, বন্ধ মাস, লট, আধা-পিস
 * আর হিসাবের খাতা — সব ওই সেবার পাহারায়; এখানে কেবল ফোনের `public_id` থেকে মডেল খোঁজা।
 *
 * ── ⛔ দেয়াল আর চাবি ওয়েবের ──────────────────────────────────────────────────────────────────────
 * চাবি ওয়েবের পর্দার একই ([[middleware()]]); গুদাম খোঁজা হয় মডেলের নিজের স্কোপে (দেখার শাখা আর মানুষের গুদাম) — বাইরের গুদাম
 * "পাওয়া গেল না" (৪২২), অন্যের মাল বসে না। ⭐ সই (maker-checker) ওয়েবের একই: গণনা মেনে নেওয়ার চাবি না থাকলে কাগজ খসড়ায় থাকে,
 * আর ছক বসানো থাকলে সই চায় — ফোন পায় ২০২ আর কারণ ([[HeldForApproval]])। ইস্যুর শেষ সই কাজটা শেষ করে, ওয়েবের মতোই।
 *
 * ⭐ প্রতিটা লেখা একবারই — রুটে [[RemembersAPhoneWrite]] (`Idempotency-Key`)।
 */
final class StockPhoneController extends Controller implements HasMiddleware
{
    public function __construct(private readonly StockCountService $counts) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:inventory.stock.view', only: ['setup']),
            new Middleware('can:inventory.stock.place', only: ['placement', 'place']),
            new Middleware('can:inventory.stock.adjust', only: ['adjust', 'issue']),
            new Middleware('can:inventory.count.approve', only: ['counts', 'approveCount']),
            new Middleware('can:inventory.stock.opening', only: ['opening']),
            new Middleware('can:inventory.stock.lot', only: ['lot']),
        ];
    }

    /** গুদাম (তাদের জায়গাসহ), দুই রকম কারণ, আর এই মানুষ কোন লেখা পারেন */
    public function setup(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $warehouses = Warehouse::query()->active()->orderBy('code')->get();
        $places = StorageLocation::query()->active()->whereIn('warehouse_id', $warehouses->pluck('id'))->inWalkingOrder()->get()
            ->groupBy('warehouse_id');

        $reasons = fn (string $context) => ReasonCode::query()->inContext($context)->active()->orderBy('code')->get()
            ->map(fn (ReasonCode $r) => ['id' => (string) $r->public_id, 'name' => $r->name()])->values();

        return response()->json([
            'warehouses' => $warehouses->map(fn (Warehouse $w) => [
                'id' => (string) $w->public_id,
                'name' => $w->name(),
                'places' => ($places->get($w->id) ?? collect())->map(fn (StorageLocation $p) => [
                    'id' => (string) $p->public_id, 'name' => $p->name(), 'depth' => (int) $p->depth,
                ])->values(),
            ])->values(),
            'reasons' => [
                'adjust' => $reasons(ReasonCode::STOCK_ADJUSTMENT),
                'issue' => $reasons(ReasonCode::STOCK_ISSUE),
            ],
            'can' => [
                'place' => $user->can('inventory.stock.place'),
                'adjust' => $user->can('inventory.stock.adjust'),
                'approve_count' => $user->can('inventory.count.approve'),
                'opening' => $user->can('inventory.stock.opening'),
                'lot' => $user->can('inventory.stock.lot'),
            ],
        ]);
    }

    /** বসানোর অপেক্ষার মাল — ওয়েবের তালিকাই ([[StockPlacementController::waiting()]]); প্রতিটা সারির `ref` */
    public function placement(): JsonResponse
    {
        $papers = app(StockPlacementController::class)->waiting();
        // ⓘ গুদামের public_id এক কোয়েরিতে — সারিপ্রতি নয়
        $warehouses = Warehouse::query()->whereIn('id', collect($papers)->flatMap(fn (array $p) => array_column($p['lines'], 'warehouse_id'))->unique())
            ->pluck('public_id', 'id');

        return response()->json([
            'papers' => collect($papers)->map(fn (array $paper) => [
                'no' => (string) ($paper['document_no'] ?? ''),
                'date' => $paper['trx_date'] ?? null,
                'lines' => collect($paper['lines'])->map(fn (array $line) => [
                    'ref' => self::refOf($line),
                    'product' => trim(($line['product_code'] ?? '').' - '.($line['product_name'] ?? ''), ' -'),
                    'warehouse' => $line['warehouse_name'] ?? null,
                    'warehouse_id' => (string) ($warehouses[$line['warehouse_id']] ?? ''),
                    'lot' => $line['batch_no'] ?? null,
                    'waiting' => (string) $line['waiting'],
                    'waiting_free' => (string) $line['waiting_free'],
                ])->values(),
            ])->values(),
        ]);
    }

    /** সারিগুলো জায়গায় — ওয়েবের একই পথ ([[StockPlacementController::placeLines()]]) */
    public function place(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.ref' => ['required', 'string', 'max:64'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.free_qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.place' => ['nullable', 'string', 'max:64'],
        ]);

        $waiting = [];

        foreach (app(StockPlacementController::class)->waiting() as $paper) {
            foreach ($paper['lines'] as $line) {
                $waiting[self::refOf($line)] = $line;
            }
        }

        $lines = [];

        foreach ($data['lines'] as $i => $asked) {
            $line = $waiting[$asked['ref']] ?? null;

            if ($line === null) {
                throw ValidationException::withMessages(["lines.{$i}.ref" => __('inventory::validation.phone_unknown_reference')]);
            }

            $place = null;

            if (filled($asked['place'] ?? null)) {
                $place = StorageLocation::query()->where('public_id', $asked['place'])->value('id');

                if ($place === null) {
                    throw ValidationException::withMessages(["lines.{$i}.place" => __('inventory::validation.phone_unknown_reference')]);
                }
            }

            $lines[] = [
                'product_id' => (int) $line['product_id'],
                'warehouse_id' => (int) $line['warehouse_id'],
                'batch_id' => $line['batch_id'] ?? null,
                'source_type' => (string) $line['source_type'],
                'free_source_type' => $line['free_source_type'] ?? null,
                'source_id' => (int) $line['source_id'],
                'qty' => $asked['qty'] ?? '0',
                'free_qty' => $asked['free_qty'] ?? '0',
                'storage_location_id' => $place,
            ];
        }

        $placed = app(StockPlacementController::class)->placeLines($lines);

        return response()->json(['status' => 'done', 'placed' => $placed, 'notice' => __('inventory::message.placed', ['count' => $placed])], 201);
    }

    /**
     * গোনা — ওয়েবের সমন্বয়ের পর্দার একই ক্রম ([[StockController::storeAdjustment()]]): এক-সারির গণনা লেখা, তারপর মেনে নেওয়ার
     * চাবি থাকলে মেনে নেওয়া; না থাকলে খসড়া, যিনি পারেন তিনি মেনে নেন।
     */
    public function adjust(Request $request): JsonResponse
    {
        [$product, $warehouse, $reason] = $this->movement($request, ReasonCode::STOCK_ADJUSTMENT, 'counted');
        $request->validate(['pool' => ['nullable', Rule::in(['paid', 'free'])]]);
        $free = $request->input('pool') === 'free';

        $count = $this->counts->record([
            'kind' => $free ? StockCount::KIND_FREE : StockCount::KIND_COUNT,
            'warehouse_id' => $warehouse->id,
            'count_date' => $request->input('trx_date'),
            'narration' => $request->input('narration'),
        ], [[
            'product_id' => $product->id,
            'counted_qty' => (string) $request->input('counted'),
            'batch_no' => $request->input('batch_no'),
            'expiry_date' => $request->input('expiry_date'),
            'unit_cost' => $free ? null : $request->input('unit_cost'),
        ]]);

        /** @var User $user */
        $user = $request->user();

        if (! $user->can('approve', $count)) {
            return $this->waiting($count, __('inventory::message.adjust_waiting_for_approver', ['document' => $count->document_no]));
        }

        return $this->settleCount($count, $reason);
    }

    /** মেনে নেওয়ার অপেক্ষার গণনা — ফোনে মেনে নেওয়ার জন্য */
    public function counts(): JsonResponse
    {
        $counts = StockCount::query()->where('status', \App\Core\Support\DocumentStatus::DRAFT)
            ->whereIn('kind', [StockCount::KIND_COUNT, StockCount::KIND_FREE])
            ->with(['warehouse', 'lines.product'])->orderByDesc('id')->limit(100)->get();

        return response()->json(['rows' => $counts->map(fn (StockCount $c) => [
            'id' => (string) $c->public_id,
            'no' => (string) $c->document_no,
            'date' => $c->count_date?->toDateString(),
            'warehouse' => $c->warehouse?->name(),
            'lines' => $c->lines->map(fn ($l) => [
                'product' => $l->product?->name(),
                'book' => Money::quantity((string) $l->book_qty),
                'counted' => Money::quantity((string) $l->counted_qty),
                'difference' => Money::quantity((string) $l->difference),
            ])->values(),
        ])->values()]);
    }

    /** গণনা মেনে নেওয়া — ওয়েবের [[StockCountController::approve()]]; নীতির চাবি ([[StockCountPolicy::approve()]]) */
    public function approveCount(Request $request, string $id): JsonResponse
    {
        $count = StockCount::query()->where('public_id', $id)->firstOrFail();
        $this->authorize('approve', $count);

        $data = $request->validate(['reason' => ['required', 'string', 'max:64']]);

        return $this->settleCount($count, $this->reason($data['reason'], ReasonCode::STOCK_ADJUSTMENT, 'reason'));
    }

    /** বিক্রি ছাড়া মাল বের করা — ওয়েবের [[StockController::storeIssue()]]; সই লাগলে খসড়া, শেষ সই কাজটা শেষ করে */
    public function issue(Request $request): JsonResponse
    {
        [$product, $warehouse, $reason] = $this->movement($request, ReasonCode::STOCK_ISSUE, 'qty');

        [$paper, $held] = $this->counts->issue(
            product: $product,
            warehouse: $warehouse,
            qty: (string) $request->input('qty'),
            reason: $reason,
            date: $request->input('trx_date'),
            narration: $request->input('narration'),
        );

        if ($held) {
            return $this->held($paper, __('inventory::message.issue_waiting_for_signature', ['document' => $paper->document_no]));
        }

        return response()->json(['status' => 'done', 'id' => (string) $paper->public_id, 'no' => (string) $paper->document_no,
            'notice' => __('inventory::message.issued', [
                'qty' => Money::quantity((string) $request->input('qty')),
                'reason' => $reason->name(),
                'account' => $reason->account?->label() ?? __('inventory::message.issue_no_account'),
            ])], 201);
    }

    /**
     * খোলা মজুদ — ওয়েবের [[OpeningStockController::store()]]; পণ্য এই শাখার বিক্রির তালিকায় থাকতে হবে।
     * ⏳ মালিক সই চেয়েছেন (১৭ অক্টোবর ২০২৬): ২১ অক্টোবরের পরে এটা ec-র খোলা মজুদের কাগজ আর সইয়ের পথে যাবে; আজ ওয়েবের আজকের নিয়মে।
     */
    public function opening(Request $request, OpeningStockService $opening): JsonResponse
    {
        $data = $request->validate([
            'product' => ['required', 'string', 'max:64'],
            'warehouse' => ['required', 'string', 'max:64'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['required', 'numeric', 'gt:0'],
            'trx_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'narration' => ['nullable', 'string', 'max:500'],
            'batch_no' => ['nullable', 'string', 'max:60'],
            'expiry_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $product = $this->find(Product::query()->soldInViewedBranch(), $data['product'], 'product');
        $warehouse = $this->find(Warehouse::query(), $data['warehouse'], 'warehouse');

        $opening->bringIn(
            product: $product,
            warehouse: $warehouse,
            qty: (string) $data['qty'],
            unitCost: (string) $data['unit_cost'],
            date: $data['trx_date'] ?? null,
            narration: $data['narration'] ?? null,
            batch: $product->track_batch
                ? app(BatchService::class)->receive(product: $product, batchNo: (string) ($data['batch_no'] ?? ''), expiry: $data['expiry_date'] ?? null)
                : null,
        );

        return response()->json(['status' => 'done', 'notice' => __('inventory::message.opening_saved', [
            'product' => $product->name(),
            'qty' => Money::quantity((string) $data['qty']),
            'value' => Money::format(bcmul((string) $data['qty'], (string) $data['unit_cost'], 4)),
        ])], 201);
    }

    /** লট-ছাড়া মালকে লট — ওয়েবের [[StrandedStockController::store()]] */
    public function lot(Request $request, StrandedStock $stranded): JsonResponse
    {
        $data = $request->validate([
            'product' => ['required', 'string', 'max:64'],
            'warehouse' => ['required', 'string', 'max:64'],
            'batch_no' => ['required', 'string', 'max:60'],
            'expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'manufactured_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'qty' => ['required', 'numeric', 'gte:0'],
            'free_qty' => ['nullable', 'numeric', 'gte:0'],
            'trx_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        $product = $this->find(Product::query(), $data['product'], 'product');
        $warehouse = $this->find(Warehouse::query(), $data['warehouse'], 'warehouse');

        // ⛔ দেয়াল ছাড়া — অন্য শাখার একই লট আড়ালে থাকলে নতুন সারি বসাতে গিয়ে অনন্য-সূচকে ৫০০ হত (ওয়েবের একই কারণ)
        $batch = Batch::acrossWarehouses()->firstOrCreate(
            ['product_id' => $product->id, 'batch_no' => $data['batch_no']],
            ['expiry_date' => $data['expiry_date'] ?? null, 'manufactured_on' => $data['manufactured_on'] ?? null],
        );

        $qty = (string) $data['qty'];
        $free = (string) ($data['free_qty'] ?? '0');

        $stranded->giveItALot(product: $product, warehouse: $warehouse, batch: $batch, qty: $qty, freeQty: $free,
            date: $data['trx_date'] ?? null, narration: $data['narration'] ?? null);

        return response()->json(['status' => 'done', 'notice' => __('inventory::message.lot_assigned', [
            'product' => $product->name(),
            'qty' => Money::format(bcadd($qty, $free, 4)),
            'lot' => $batch->batch_no,
        ])], 201);
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────────────────────────────────

    /**
     * পণ্য, গুদাম আর কারণ — ওয়েবের [[StockController::validatedMovement()]]-এর একই যাচাই, `public_id` দিয়ে।
     *
     * @return array{0: Product, 1: Warehouse, 2: ReasonCode}
     */
    private function movement(Request $request, string $context, string $qtyField): array
    {
        $data = $request->validate([
            'product' => ['required', 'string', 'max:64'],
            'warehouse' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'max:64'],
            $qtyField => ['required', 'numeric'],
            'trx_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'narration' => ['nullable', 'string', 'max:500'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'batch_no' => ['nullable', 'string', 'max:60'],
            'expiry_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return [
            $this->find(Product::query(), $data['product'], 'product'),
            $this->find(Warehouse::query(), $data['warehouse'], 'warehouse'),
            $this->reason($data['reason'], $context, 'reason'),
        ];
    }

    private function reason(string $publicId, string $context, string $field): ReasonCode
    {
        $reason = $this->find(ReasonCode::query(), $publicId, $field);

        if ($reason->context !== $context) {
            throw ValidationException::withMessages([$field => __('inventory::validation.wrong_reason_context')]);
        }

        return $reason;
    }

    /**
     * ⛔ মডেলের নিজের দেয়ালে খোঁজা — অন্য কোম্পানির, অন্য শাখার গুদাম বা নাগালের বাইরের হলে "পাওয়া গেল না", কারও মাল নয়।
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return T
     */
    private function find(Builder $query, string $publicId, string $field): Model
    {
        $model = $query->where($query->getModel()->getTable().'.public_id', $publicId)
            ->where($query->getModel()->getTable().'.company_id', CompanyContext::id())->first();

        if ($model === null) {
            throw ValidationException::withMessages([$field => __('inventory::validation.phone_unknown_reference')]);
        }

        return $model;
    }

    /** মেনে নেওয়া — সই লাগলে ২০২, কারণসহ ([[HeldForApproval]]) */
    private function settleCount(StockCount $count, ReasonCode $reason): JsonResponse
    {
        try {
            $this->counts->approve($count, $reason);
        } catch (HeldForApproval $held) {
            return $this->held($count, (string) collect($held->errors())->flatten()->first());
        }

        $difference = (string) ($count->fresh(['lines'])->lines->first()?->difference ?? '0');

        return response()->json(['status' => 'done', 'id' => (string) $count->public_id, 'no' => (string) $count->document_no,
            'notice' => bccomp($difference, '0', 4) === 0
                ? __('inventory::message.adjust_matched')
                : __('inventory::message.adjusted', ['difference' => Money::quantity($difference)])], 201);
    }

    private function held(StockCount $paper, string $notice): JsonResponse
    {
        return response()->json(['status' => 'held', 'id' => (string) $paper->public_id, 'no' => (string) $paper->document_no, 'notice' => $notice], 202);
    }

    private function waiting(StockCount $paper, string $notice): JsonResponse
    {
        return response()->json(['status' => 'waiting', 'id' => (string) $paper->public_id, 'no' => (string) $paper->document_no, 'notice' => $notice], 202);
    }

    /** বসানোর অপেক্ষার সারির চিহ্ন — ক্রমিক id ফোনে যায় না; সার্ভার তালিকাটা আবার বানিয়ে চিহ্ন মেলায় */
    private static function refOf(array $line): string
    {
        return substr(hash('sha256', implode('|', [
            CompanyContext::id(), $line['source_type'] ?? '', $line['free_source_type'] ?? '', $line['source_id'] ?? '',
            $line['product_id'] ?? '', $line['warehouse_id'] ?? '', $line['batch_id'] ?? '',
        ])), 0, 32);
    }
}
