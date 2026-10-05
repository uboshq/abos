<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Support\SalesOrderStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ ফোনের বিক্রয় আদেশ — `/api/v1/sales/orders` (DO বিক্রয় আদেশে মেশানো, নকশার ধাপ ১০; ৫ অক্টোবর ২০২৬)।
 *
 * ⓘ [[DeliveryOrderApiController]]-এর যমজ: একই দরজা (তালিকা `scope=awaiting_me`, লেখা, দেখা, বদল, জমা, সুপারভাইজারের
 * পরিমাণ), JSON-এর আকার DO-র হুবহু, কেবল `kind: "so"` যোগ — অ্যাপের পর্দা কম বদলায়।
 * ⓘ লেখার সব নিয়ম [[SalesOrderService]]-এ (জমা → বাকির যাচাই → সুপারভাইজার → অনুমোদিত); এখানে কেবল অনুবাদ আর
 * লেখকের দেয়াল। দাম পণ্যের ([[SalePriceBook]] যা বসায়) — ⛔ ফোন দাম বা ছাড় পাঠায় না, DO-র একই নিয়ম।
 * ⚠️ জমা কেবল কোম্পানির সুইচ চালু থাকলে ([[SalesOrderService::REPLACES_DO]]) — বন্ধে সেবা নিজেই ফেরায়।
 */
class SalesOrderApiController extends Controller implements HasMiddleware
{
    public function __construct(private readonly SalesOrderService $orders) {}

    public static function middleware(): array
    {
        return [
            // ⓘ সুপারভাইজারের পরিমাণও আদেশ দেখার চাবিতে — যিনি আদেশটা দেখতে পান না তিনি পরিমাণও বদলান না; তার উপরে
            // ছকের নিজের দেয়াল ([[SalesOrderService::setApprovedQuantities()]]: এখনকার স্তরের অনুমোদনকারী, নাহলে ৪০৩)
            new Middleware('can:sales.order.view', only: ['index', 'show', 'approvedQuantities']),
            new Middleware('can:sales.order.create', only: ['store', 'update', 'submit']),
        ];
    }

    /**
     * `GET /orders?customer=&status=&scope=&page=` — সর্বশেষ আগে, পাতায় ৫০; `next_page` না থাকলে শেষ।
     * ⓘ `scope=awaiting_me` — যেগুলো এখন আমার সইয়ের অপেক্ষায় (অনুমোদন-ইঞ্জিনের নিজের ইনবক্স, [[ApprovalEngine::pendingQueryFor()]])।
     */
    public function index(Request $request): JsonResponse
    {
        $rows = SalesOrder::query()
            ->with('customer')
            ->when(trim((string) $request->query('customer', '')), fn ($q, $c) => $q->where('customer_id', $this->customer($c)->id))
            ->when($request->query('scope') === 'awaiting_me', fn ($q) => $q->where('status', SalesOrderStatus::AWAITING_APPROVAL)
                ->whereIn('id', app(ApprovalEngine::class)->pendingQueryFor($request->user())
                    ->where('approvable_type', (new SalesOrder)->getMorphClass())->select('approvable_id')))
            ->when(in_array($request->query('status'), SalesOrderStatus::ALL, true),
                fn ($q) => $q->where('status', (string) $request->query('status')))
            ->latest('trx_date')->latest('id')->paginate(50);

        return response()->json([
            'orders' => collect($rows->items())->map(fn (SalesOrder $o) => $this->facts($o, false))->values(),
            'next_page' => $rows->hasMorePages() ? $rows->currentPage() + 1 : null,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->facts($this->order($id), true));
    }

    /** `POST /orders` — খসড়া; `submit: true` দিলে সাথে সাথে জমা */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        // ⛔ জমা চাইলে আগেই দেখা — সুইচ বন্ধে খসড়া বানিয়ে তারপর ফেরালে ফোনে একটা অনাথ খসড়া থেকে যেত
        if ($request->boolean('submit') && ! $this->orders->replacesDo()) {
            throw ValidationException::withMessages(['status' => __('sales::order_status.submit_needs_switch', ['no' => '—'])]);
        }

        $order = $this->orders->create([
            'customer_id' => $this->customer($data['customer'])->id,
            'deliver_on' => $data['deliver_on'] ?? null,
            'narration' => $data['narration'] ?? null,
            'source' => SalesOrderStatus::SOURCE_SR,
        ], $this->lines($data['lines']));

        if ($request->boolean('submit')) {
            $order = $this->orders->submit($order);
        }

        return response()->json($this->facts($order->fresh(), true), 201);
    }

    /** `PUT /orders/{id}` — কেবল খসড়া, কেবল লেখক */
    public function update(Request $request, string $id): JsonResponse
    {
        $order = $this->writersOwn($this->order($id), $request->user());
        $data = $this->validated($request, creating: false);
        $order = $this->orders->update($order, [
            'deliver_on' => $data['deliver_on'] ?? null,
            'narration' => $data['narration'] ?? null,
        ], $this->lines($data['lines']));

        return response()->json($this->facts($order, true));
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $order = $this->writersOwn($this->order($id), $request->user());

        return response()->json($this->facts($this->orders->submit($order), true));
    }

    /** `POST /orders/{id}/approved-quantities` {lines: {lineId: qty}} — সুপারভাইজার; চাবি ছকের ([[SalesOrderService::setApprovedQuantities()]]) */
    public function approvedQuantities(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lines' => ['required', 'array'], 'lines.*' => ['required', 'numeric', 'min:0']]);

        $order = $this->orders->setApprovedQuantities($this->order($id), array_map('strval', $data['lines']), $request->user());

        return response()->json($this->facts($order, true));
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'customer' => [$creating ? 'required' : 'nullable', 'string', 'max:64'],
            'deliver_on' => ['nullable', 'date'],
            'narration' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product' => ['required', 'string', 'max:64'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /** @return list<array{product_id: int, ordered_qty: string, rate: string, discount: string, narration: ?string}> */
    private function lines(array $lines): array
    {
        return array_map(function (array $l): array {
            $product = Product::query()
                ->when(Str::isUuid($l['product']), fn ($q) => $q->where('public_id', $l['product']), fn ($q) => $q->whereKey((int) $l['product']))
                ->first();

            return [
                'product_id' => (int) $product?->id,
                'ordered_qty' => (string) $l['qty'],
                // ⓘ দাম পণ্যের — ফোন দাম পাঠায় না (DO-র একই নিয়ম, [[DeliveryOrderService::writeLines()]])
                'rate' => (string) ($product?->sale_price ?? '0'),
                'discount' => '0',
                'narration' => $l['note'] ?? null,
            ];
        }, array_values($lines));
    }

    /** ⛔ লেখকের দেয়াল — কেবল যিনি লিখেছেন, আর কেবল খসড়া (DO-র [[DeliveryOrderService::assertWriterMayEdit()]]-এর হুবহু) */
    private function writersOwn(SalesOrder $order, ?User $user): SalesOrder
    {
        if ($user === null || $order->created_by_customer_id !== null || (int) $order->created_by !== (int) $user->id) {
            abort(403);
        }

        if ($order->status !== SalesOrderStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('sales::order_status.only_draft_submits', ['no' => $order->document_no]),
            ]);
        }

        return $order;
    }

    private function customer(string $key): Customer
    {
        // ⓘ ফোনে বাছা শাখার গ্রাহকই ([[ViewedBranch]])
        return Customer::query()->inViewedBranch()
            ->when(Str::isUuid($key), fn ($q) => $q->where('public_id', $key), fn ($q) => $q->whereKey((int) $key))
            ->firstOrFail();
    }

    private function order(string $id): SalesOrder
    {
        return SalesOrder::query()->where('public_id', $id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function facts(SalesOrder $o, bool $withLines): array
    {
        $o->loadMissing(['customer', 'lines.product']);
        $pending = $o->status === SalesOrderStatus::AWAITING_APPROVAL
            ? app(ApprovalEngine::class)->latestFor($o, SalesOrderService::APPROVAL_ACTION) : null;
        $user = request()->user();

        return [
            'kind' => 'so',
            'id' => (string) $o->public_id,
            'no' => (string) $o->document_no,
            'date' => $o->trx_date?->toDateString(),
            'customer' => ['id' => (string) $o->customer?->public_id, 'name' => $o->customer?->name()],
            'status' => (string) $o->status,
            'status_label' => SalesOrderStatus::label((string) $o->status),
            'total' => bcadd((string) $o->total, '0', 2),
            // ⓘ সীমায় আটকে থাকলে কত কম — ফোনে লেখক দেখেন কেন থেমে আছে
            'credit_short' => $o->credit_short === null ? null : bcadd((string) $o->credit_short, '0', 2),
            'editable' => $o->status === SalesOrderStatus::DRAFT && $o->created_by_customer_id === null
                && (int) $o->created_by === (int) $user?->id,
            'awaiting_me' => $awaitingMe = $pending !== null && $user !== null && app(ApprovalEngine::class)->canDecide($pending, $user),
            // ⓘ ফোন এটা দিয়েই সই দেয় — `/approvals/{id}/approve|reject`, অনুমোদন-বাক্সের একই দরজা
            'approval_id' => $awaitingMe ? (string) $pending->public_id : null,
            'lines' => $withLines ? $o->lines->map(fn (SalesOrderLine $l) => [
                'id' => (int) $l->id,
                'product' => ['id' => (string) $l->product?->public_id, 'name' => $l->product?->name()],
                'qty' => bcadd((string) ($l->requested_qty ?? $l->ordered_qty), '0', 4),
                // ⓘ সুপারভাইজার বদলালে তবেই — চাওয়া আর চূড়ান্ত এক হলে null (DO-র একই অর্থ)
                'approved_qty' => $l->requested_qty !== null && bccomp((string) $l->requested_qty, (string) $l->ordered_qty, 4) !== 0
                    ? bcadd((string) $l->ordered_qty, '0', 4) : null,
                'final_qty' => bcadd((string) $l->ordered_qty, '0', 4),
                'rate' => bcadd((string) $l->rate, '0', 2),
                'free_qty' => bcadd((string) ($l->free_qty ?? '0'), '0', 4),
                'line_total' => bcadd((string) $l->amount, '0', 2),
            ])->values()->all() : null,
        ];
    }
}
