<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\SerialNumber;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\SerialNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ সিরিয়াল নম্বর, ফোনের নিজের পর্দায় — খোঁজা, পিস ঢোকানো, পিস বের করা (মালিক, ১৭ অক্টোবর ২০২৬; fe)।
 *
 *   GET  /api/v1/inventory/serials?serial=&page=   POST /api/v1/inventory/serials   POST /api/v1/inventory/serials/issue
 *
 * ⛔ ওয়েবের একই সেবা ([[SerialNumberService]] `receive`, `issue`) আর একই চাবি ([[SerialNumberController]]): দেখা
 * `inventory.serial.view`, ঢোকানো আর বের করা `inventory.serial.manage`। নকল নম্বর, অন্য পণ্যের পিস, গুদামে-নেই — সেবার পাহারায়।
 * ⓘ প্রতি সারিতে একটা নম্বর, স্ক্যানারের মতো; কেবল `public_id`; প্রতিটা লেখা একবারই ([[RemembersAPhoneWrite]])।
 */
final class SerialPhoneController extends Controller implements HasMiddleware
{
    public const PER_PAGE = 50;

    public function __construct(private readonly SerialNumberService $serials) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:inventory.serial.view', only: ['index']),
            new Middleware('can:inventory.serial.manage', only: ['store', 'issue']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['serial' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);

        $query = SerialNumber::query()->with(['product', 'warehouse', 'batch'])->orderByDesc('id');
        $serial = trim((string) ($data['serial'] ?? ''));

        if ($serial !== '') {
            $query->numbered($serial);
        }

        $page = $query->paginate(self::PER_PAGE);

        return response()->json([
            'rows' => $page->getCollection()->map(fn (SerialNumber $s) => [
                'serial_no' => (string) $s->serial_no,
                'product' => trim(($s->product?->code ?? '').' - '.($s->product?->name() ?? ''), ' -'),
                'warehouse' => $s->warehouse?->name(),
                'lot' => $s->batch?->batch_no,
                'status' => (string) $s->status,
                'status_label' => __('inventory::status.serial_'.$s->status),
                'received_on' => $s->received_on === null ? null : DateFormat::format($s->received_on),
                'issued_on' => $s->issued_on === null ? null : DateFormat::format($s->issued_on),
                'sold_to' => $s->sold_to,
                'warranty_to' => $s->warranty_to === null ? null : DateFormat::format($s->warranty_to),
            ])->values(),
            'count' => $page->total(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    /** পিস ঢোকানো — ওয়েবের [[SerialNumberController::store()]] */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product' => ['required', 'string', 'max:64'],
            'warehouse' => ['nullable', 'string', 'max:64'],
            'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'serials' => ['required', 'string', 'max:20000'],
        ]);

        $product = Product::query()->where('public_id', $data['product'])->where('company_id', CompanyContext::id())->first();
        $warehouse = filled($data['warehouse'] ?? null)
            ? Warehouse::query()->where('public_id', $data['warehouse'])->where('company_id', CompanyContext::id())->first()
            : null;

        if ($product === null || (filled($data['warehouse'] ?? null) && $warehouse === null)) {
            throw ValidationException::withMessages([$product === null ? 'product' : 'warehouse' => __('inventory::validation.phone_unknown_reference')]);
        }

        $made = $this->serials->receive(
            product: $product,
            serials: preg_split('/\r\n|\r|\n/', $data['serials']) ?: [],
            data: ['warehouse_id' => $warehouse?->id, 'received_on' => $data['received_on']],
        );

        return response()->json(['status' => 'done', 'count' => count($made),
            'notice' => trans_choice('inventory::message.serials_added', count($made), ['count' => count($made)])], 201);
    }

    /** পিস বের করা — ওয়েবের [[SerialNumberController::storeIssue()]] */
    public function issue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'serials' => ['required', 'string', 'max:20000'],
            'issued_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'sold_to' => ['nullable', 'string', 'max:160'],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:600'],
        ]);

        $gone = $this->serials->issue(
            serials: preg_split('/\r\n|\r|\n/', $data['serials']) ?: [],
            data: ['issued_on' => $data['issued_on'], 'sold_to' => $data['sold_to'] ?? null, 'warranty_months' => $data['warranty_months'] ?? 0],
        );

        return response()->json(['status' => 'done', 'count' => count($gone),
            'notice' => trans_choice('inventory::message.serials_issued', count($gone), ['count' => count($gone)])], 201);
    }
}
