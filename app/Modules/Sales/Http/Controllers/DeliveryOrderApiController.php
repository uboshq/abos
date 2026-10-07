<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\PhoneInput;
use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Models\DeliveryOrderLine;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\DeliveryOrderService;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Str;

/**
 * ফোনের DO — `/api/v1/sales/delivery-orders` (মালিকের বিক্রয়-ধারা §২ক-খ, ২ অক্টোবর ২০২৬)।
 *
 * ⓘ SR আর তাঁর উপরের সবাই লেখেন (`sales.do.create`), দেখেন (`sales.do.view`); সুপারভাইজার এখান থেকেই মজুদ দেখে
 * পরিমাণ বদলান ([[DeliveryOrderService::setApprovedQuantities()]] — চাবি ছকের, এখানে নয়)।
 * ⓘ সব আইডি `public_id` — ফোন ক্রমিক id চেনে না। লেখার নিয়ম সব সার্ভিসে; এখানে কেবল অনুবাদ।
 * ⚠️ "কোন ডিলার কার" দেয়াল আসবে abos-bb-র [[DealerOwnership]] থেকে; ততক্ষণ কোম্পানি ও শাখার দেয়াল।
 */
class DeliveryOrderApiController extends Controller implements HasMiddleware
{
    public function __construct(private readonly DeliveryOrderService $orders) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.do.view', only: ['index', 'show']),
            new Middleware('can:sales.do.create', only: ['store', 'update', 'submit']),
        ];
    }

    /**
     * `GET /delivery-orders?customer=&status=&scope=&page=` — সর্বশেষ আগে, পাতায় ৫০; `next_page` না থাকলে শেষ।
     * ⓘ `scope=awaiting_me` — যেগুলো এখন আমার সইয়ের অপেক্ষায় (অনুমোদন-ইঞ্জিনের নিজের ইনবক্স ধরে, [[ApprovalEngine::pendingQueryFor()]])।
     */
    public function index(Request $request): JsonResponse
    {
        $rows = DeliveryOrder::query()
            ->with('customer')
            ->when(trim(PhoneInput::text($request, 'customer', '')), fn ($q, $c) => $q->where('customer_id', $this->customer($c)->id))
            ->when($request->query('scope') === 'awaiting_me', fn ($q) => $q->where('status', DeliveryOrderStatus::SUPERVISOR_PENDING)
                ->whereIn('id', app(ApprovalEngine::class)->pendingQueryFor($request->user())
                    ->where('approvable_type', (new DeliveryOrder)->getMorphClass())->select('approvable_id')))
            ->when(in_array($request->query('status'), [...DeliveryOrderStatus::FLOW, DeliveryOrderStatus::REJECTED, DeliveryOrderStatus::CANCELLED], true),
                fn ($q) => $q->where('status', PhoneInput::text($request, 'status')))
            ->latest('trx_date')->latest('id')->paginate(50);

        return response()->json([
            'orders' => collect($rows->items())->map(fn (DeliveryOrder $o) => $this->facts($o, false))->values(),
            'next_page' => $rows->hasMorePages() ? $rows->currentPage() + 1 : null,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->facts($this->order($id), true));
    }

    /** `POST /delivery-orders` — খসড়া; `submit: true` দিলে সাথে সাথে জমা */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $order = $this->orders->create([
            'customer_id' => $this->customer($data['customer'])->id,
            'sales_order_id' => isset($data['sales_order']) ? $this->salesOrderId($data['sales_order']) : null,
            'deliver_on' => $data['deliver_on'] ?? null,
            'narration' => $data['narration'] ?? null,
        ], $this->lines($data['lines']), $request->user());

        if ($request->boolean('submit')) {
            $order = $this->orders->submit($order, $request->user());
        }

        return response()->json($this->facts($order->fresh(), true), 201);
    }

    /** `PUT /delivery-orders/{id}` — কেবল খসড়া, কেবল লেখক */
    public function update(Request $request, string $id): JsonResponse
    {
        $data = $this->validated($request, creating: false);
        $order = $this->orders->update($this->order($id), [
            'deliver_on' => $data['deliver_on'] ?? null,
            'narration' => $data['narration'] ?? null,
        ], $this->lines($data['lines']), $request->user());

        return response()->json($this->facts($order, true));
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        return response()->json($this->facts($this->orders->submit($this->order($id), $request->user()), true));
    }

    /** `POST /delivery-orders/{id}/approved-quantities` {lines: {lineId: qty}} — সুপারভাইজার; চাবি ছকের */
    public function approvedQuantities(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lines' => ['required', 'array'], 'lines.*' => ['required', 'numeric', PhoneInput::DECIMAL, 'min:0']]);

        $order = $this->orders->setApprovedQuantities($this->order($id), array_map('strval', $data['lines']), $request->user());

        return response()->json($this->facts($order, true));
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'customer' => [$creating ? 'required' : 'nullable', 'string', 'max:64'],
            'sales_order' => ['nullable', 'string', 'max:64'],
            'deliver_on' => ['nullable', 'date'],
            'narration' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product' => ['required', 'string', 'max:64'],
            'lines.*.qty' => ['required', 'numeric', PhoneInput::DECIMAL, 'gt:0'],
            'lines.*.free_qty' => ['nullable', 'numeric', PhoneInput::DECIMAL, 'min:0'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /** @return list<array{product_id: int, qty: string, free_qty: string, note: ?string}> */
    private function lines(array $lines): array
    {
        return array_map(fn (array $l) => [
            'product_id' => (int) Product::query()
                ->when(Str::isUuid($l['product']), fn ($q) => $q->where('public_id', $l['product']), fn ($q) => $q->whereKey((int) $l['product']))
                ->value('id'),
            'qty' => (string) $l['qty'],
            'free_qty' => (string) ($l['free_qty'] ?? '0'),
            'note' => $l['note'] ?? null,
        ], array_values($lines));
    }

    private function customer(string $key): Customer
    {
        // ⓘ ফোনে বাছা শাখার গ্রাহকই ([[ViewedBranch]])
        return Customer::query()->inViewedBranch()
            ->when(Str::isUuid($key), fn ($q) => $q->where('public_id', $key), fn ($q) => $q->whereKey((int) $key))
            ->firstOrFail();
    }

    private function salesOrderId(string $key): ?int
    {
        return SalesOrder::query()
            ->when(Str::isUuid($key), fn ($q) => $q->where('public_id', $key), fn ($q) => $q->whereKey((int) $key))
            ->value('id');
    }

    private function order(string $id): DeliveryOrder
    {
        return DeliveryOrder::query()->where('public_id', $id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function facts(DeliveryOrder $o, bool $withLines): array
    {
        $o->loadMissing(['customer', 'lines.product']);
        $pending = $o->status === DeliveryOrderStatus::SUPERVISOR_PENDING
            ? app(ApprovalEngine::class)->latestFor($o, DeliveryOrderService::APPROVAL_ACTION) : null;
        $user = request()->user();

        return [
            'id' => (string) $o->public_id,
            'no' => (string) $o->document_no,
            'date' => $o->trx_date?->toDateString(),
            'customer' => ['id' => (string) $o->customer?->public_id, 'name' => $o->customer?->name()],
            'status' => (string) $o->status,
            'status_label' => DeliveryOrderStatus::label((string) $o->status),
            'total' => bcadd((string) $o->total, '0', 2),
            // ⓘ সুইচ চালু (কোম্পানি বিক্রয় আদেশে) হলে খসড়া DO আর বদলায় না ([[DeliveryOrderService::newOnesStopped()]]) — ফোনে বোতামও নয়
            'editable' => $o->isEditableByWriter() && (int) $o->created_by === (int) $user?->id && ! $this->orders->newOnesStopped(),
            'awaiting_me' => $awaitingMe = $pending !== null && $user !== null && app(ApprovalEngine::class)->canDecide($pending, $user),
            // ⓘ ফোন এটা দিয়েই সই দেয় — `/approvals/{id}/approve|reject`, অনুমোদন-বাক্সের একই দরজা
            'approval_id' => $awaitingMe ? (string) $pending->public_id : null,
            'lines' => $withLines ? $o->lines->map(fn (DeliveryOrderLine $l) => [
                'id' => (int) $l->id,
                'product' => ['id' => (string) $l->product?->public_id, 'name' => $l->product?->name()],
                'qty' => bcadd((string) $l->qty, '0', 4),
                'approved_qty' => $l->approved_qty === null ? null : bcadd((string) $l->approved_qty, '0', 4),
                'final_qty' => bcadd($l->finalQty(), '0', 4),
                'rate' => bcadd((string) $l->rate, '0', 2),
                'free_qty' => bcadd((string) $l->free_qty, '0', 4),
                'line_total' => bcadd((string) $l->line_total, '0', 2),
            ])->values()->all() : null,
        ];
    }
}
