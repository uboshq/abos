<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Support\PhoneInput;
use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Http\Requests\SalesQuotationRequest;
use App\Modules\Sales\Models\SalesQuotation;
use App\Modules\Sales\Models\SalesQuotationLine;
use App\Modules\Sales\Services\SalesQuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ ফোনে উদ্ধৃতি (কোটেশন) — মাঠ থেকেই দাম দেওয়া, জমা, পাঠানো, দোকানির উত্তর আর আদেশে রূপান্তর
 * (সমন্বয়কের ক্রম "ঘ", ৫ অক্টোবর ২০২৬)।
 *
 * ⓘ ওয়েবের [[SalesQuotationController]]-এর একই সেবা ([[SalesQuotationService]]) আর একই যাচাই ([[SalesQuotationRequest]],
 * `createFrom()->replace()->validateResolved()`): দর শূন্য নয়, গ্রাহক আর পণ্য এই কোম্পানির। ফোনের কাজ কেবল `public_id`
 * থেকে ভেতরের id আর দিনের হিসাব (আজ থেকে কোম্পানির নিজের মেয়াদ, [[SalesQuotationService::defaultValidDays()]])।
 * ⓘ চাবি ওয়েবের নীতির ([[SalesQuotationPolicy]]): দেখা `view`, লেখা `create`, ধাপ এগোনো `update`, রূপান্তর `convert` আর
 * আদেশ লেখার চাবি দুটোই। অবস্থার শর্ত সেবায় — বার্তা বলে কেন হল না।
 * ⓘ দর না পাঠালে পণ্যের বিক্রয়-দাম; ফোনে ছাড়ের ঘর নেই — দর নিজেই দামাদামির জায়গা, আর সইয়ের ছক সেবার।
 */
class SalesQuotationApiController extends Controller implements HasMiddleware
{
    public function __construct(private readonly SalesQuotationService $service) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.quotation.view', only: ['index', 'show']),
            new Middleware('can:sales.quotation.create', only: ['store']),
            new Middleware('can:sales.quotation.update', only: ['submit', 'send', 'accept', 'reject']),
            new Middleware('can:sales.quotation.convert', only: ['convert']),
        ];
    }

    /** `GET /quotations?status=&customer=&page=` — নতুনটা আগে, পাতায় ৫০ */
    public function index(Request $request): JsonResponse
    {
        $rows = SalesQuotation::query()
            // ⓘ ক্রেতা আর আদেশ পাতায় একবার — আগে প্রতিটা দরপত্রে আদেশের আলাদা ডাক (অডিট ফোন ⚠️১৭)
            ->with(['customer.location.parent', 'order'])
            ->when(trim(PhoneInput::text($request, 'customer', '')), fn ($q, $c) => $q->where('customer_id', $this->customer($c)->id))
            ->when(in_array($request->query('status'), SalesQuotation::STATES, true),
                fn ($q) => $q->where('status', PhoneInput::text($request, 'status')))
            ->latest('trx_date')->latest('id')
            ->paginate(50);

        return response()->json([
            'quotations' => collect($rows->items())->map(fn (SalesQuotation $q) => $this->facts($q, false))->values(),
            'next_page' => $rows->hasMorePages() ? $rows->currentPage() + 1 : null,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->facts($this->quotation($id), true));
    }

    /** `POST /quotations` — খসড়া; `submit: true` দিলে সাথে সাথে জমা (ছক থাকলে সইয়ের অপেক্ষা, নাহলে অনুমোদিত) */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer' => ['required', 'string', 'max:64'],
            'narration' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product' => ['required', 'string', 'max:64'],
            'lines.*.qty' => ['required', 'numeric', PhoneInput::DECIMAL, 'gt:0'],
            'lines.*.rate' => ['nullable', 'numeric', PhoneInput::DECIMAL],
        ]);

        // ⚠️ আগেই পড়া — `createFrom()` JSON-এর থলেটা ভাগ করে, তাই `replace()`-এর পরে `$request`-এ `submit` আর থাকে না
        $submit = $request->boolean('submit');
        $today = now();
        $customer = $this->customer($data['customer']);
        $form = SalesQuotationRequest::createFrom($request)->replace([
            'customer_id' => $customer->id,
            'trx_date' => $today->toDateString(),
            'valid_until' => $today->copy()->addDays($this->service->defaultValidDays())->toDateString(),
            'narration' => $data['narration'] ?? null,
            'lines' => array_map(function (array $l) use ($customer): array {
                $product = $this->product($l['product']);

                /*
                 * ⛔ দর সার্ভারের — ফোনের পাঠানো দর নয় (পুরো ERP অডিট, ৯ অক্টোবর ২০২৬; অফলাইন আদেশের একই নিয়ম,
                 * [[SalesOrderSync]]): এই ডিলারের দর তালিকার দাম, নাহলে পণ্যের ([[SalesPrice]])। আগে ফোনের দর দামের সহনসীমার
                 * ভেতরে থাকলেই বসত, আর রূপান্তরে আদেশে যেত। ছাড় ফোন থেকে আসেই না; দেন মালিক, ওয়েবে। ⓘ শূন্য দাম — ফেরত
                 * (মালিকের "sales price chara entry nibe na")।
                 */
                $rate = $product === null ? '0' : (string) app(\App\Modules\Sales\Services\SalesPrice::class)->for($customer, $product)->price;
                if ($product !== null && bccomp($rate, '0', 4) <= 0) {
                    throw ValidationException::withMessages(['lines' => __('sales::sync.order_line_has_no_price', ['product' => (string) ($product->name_bn ?: $product->name_en)])]);
                }

                return [
                    'product_id' => $product?->id,
                    'qty' => (string) $l['qty'],
                    'rate' => $rate,
                ];
            }, array_values($data['lines'])),
        ]);
        $form->setContainer(app())->setRedirector(app('redirect'));
        $form->validateResolved();

        $quotation = $this->service->create($form->documentData(), $form->lineData());

        if ($submit) {
            $quotation = $this->service->submit($quotation);
        }

        return response()->json($this->facts($quotation->fresh(), true), 201);
    }

    public function submit(string $id): JsonResponse
    {
        return response()->json($this->facts($this->service->submit($this->quotation($id)), true));
    }

    /** দোকানিকে পাঠানো হলো — অনুমোদিত থেকে "পাঠানো" */
    public function send(string $id): JsonResponse
    {
        return response()->json($this->facts($this->service->markSent($this->quotation($id)), true));
    }

    /** দোকানি রাজি */
    public function accept(Request $request, string $id): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return response()->json($this->facts($this->service->accept($this->quotation($id), $note), true));
    }

    /** দোকানি রাজি নন — কারণ লাগে (ওয়েবের একই নিয়ম) */
    public function reject(Request $request, string $id): JsonResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'max:500']])['note'];

        return response()->json($this->facts($this->service->reject($this->quotation($id), $note), true));
    }

    /** গৃহীত উদ্ধৃতি থেকে বিক্রয় আদেশ — ⛔ আদেশ লেখার চাবিও লাগে ([[SalesQuotationPolicy::convert()]]) */
    public function convert(string $id): JsonResponse
    {
        $quotation = $this->quotation($id);
        $this->authorize('convert', $quotation);
        $order = $this->service->convert($quotation);

        return response()->json($this->facts($quotation->fresh(), true) + [
            'order' => ['id' => (string) $order->public_id, 'no' => (string) $order->document_no],
        ]);
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function quotation(string $id): SalesQuotation
    {
        return SalesQuotation::query()->where('public_id', $id)->firstOrFail();
    }

    private function customer(string $key): Customer
    {
        // ⓘ ফোনে বাছা শাখার গ্রাহকই ([[ViewedBranch]])
        return Customer::query()->inViewedBranch()
            ->when(Str::isUuid($key), fn ($q) => $q->where('public_id', $key), fn ($q) => $q->whereKey((int) $key))
            ->firstOrFail();
    }

    private function product(string $key): ?Product
    {
        return Product::query()
            ->when(Str::isUuid($key), fn ($q) => $q->where('public_id', $key), fn ($q) => $q->whereKey((int) $key))
            ->first();
    }

    /** @return array<string, mixed> */
    private function facts(SalesQuotation $q, bool $withLines): array
    {
        $q->loadMissing($withLines ? ['customer.location.parent', 'lines.product', 'order'] : ['customer.location.parent', 'order']);
        $user = request()->user();

        return [
            'id' => (string) $q->public_id,
            'no' => (string) $q->document_no,
            'date' => $q->trx_date?->toDateString(),
            'valid_until' => $q->valid_until?->toDateString(),
            'customer' => ['id' => (string) $q->customer?->public_id, 'name' => $q->customer?->name(), 'point' => $q->customer?->pointName()],
            'status' => (string) $q->status,
            'status_label' => __('sales::quotation.status.'.$q->status),
            'total' => bcadd((string) $q->total, '0', 2),
            'narration' => $q->narration,
            // ⓘ কোন বোতাম দেখাবে — ফোন নিজে নিয়ম গোনে না; চাবি আর অবস্থা দুটোই এখানে
            'can' => [
                'submit' => $q->status === SalesQuotation::DRAFT && (bool) $user?->can('sales.quotation.update'),
                'send' => $q->status === SalesQuotation::APPROVED && (bool) $user?->can('sales.quotation.update'),
                'answer' => $q->status === SalesQuotation::SENT && (bool) $user?->can('sales.quotation.update'),
                'convert' => $q->status === SalesQuotation::ACCEPTED && (bool) $user?->can('convert', $q),
            ],
            'order' => $q->order ? ['id' => (string) $q->order->public_id, 'no' => (string) $q->order->document_no] : null,
            'lines' => $withLines ? $q->lines->map(fn (SalesQuotationLine $l) => [
                'product' => ['id' => (string) $l->product?->public_id, 'name' => $l->product?->name()],
                'qty' => bcadd((string) $l->qty, '0', 4),
                'rate' => bcadd((string) $l->rate, '0', 2),
                'amount' => bcadd((string) $l->amount, '0', 2),
            ])->values()->all() : null,
        ];
    }
}
