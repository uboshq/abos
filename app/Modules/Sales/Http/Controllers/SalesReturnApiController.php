<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Support\DocumentStatus;
use App\Core\Support\PhoneInput;
use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Http\Requests\SalesReturnRequest;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesPaperOverview;
use App\Modules\Sales\Services\SalesReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ ফোনে বিক্রি ফেরত — কাউন্টারের "ফেরত" বোতাম (মালিক, ৪ অক্টোবর ২০২৬; সমন্বয়ক: "ওয়েবের বিক্রি-ফেরতের সেবাই ডাকবে")।
 *
 * ⓘ নতুন নিয়ম একটাও নেই: যাচাই ওয়েবের ফর্মের ([[SalesReturnRequest]], হুবহু চালানো), তৈরি আর নিশ্চিত ওয়েবের সেবায়
 * ([[SalesReturnService::create()]], [[SalesReturnService::confirm()]]), নিশ্চিতের আগের সারাংশ ওয়েবের পপ-আপেরটাই
 * ([[SalesPaperOverview::salesReturn()]])। এখানে কেবল ফোনের public_id → ভেতরের id।
 * ⓘ চাবি ওয়েবের ফেরতের — `sales.return.create`; বিল আর ফেরত দেখার অধিকার তাদের নিজের নীতিতে (শাখা, নিজের ক্রেতা)।
 */
final class SalesReturnApiController extends Controller implements HasMiddleware
{
    public function __construct(private readonly SalesReturnService $returns) {}

    public static function middleware(): array
    {
        return [new Middleware('can:sales.return.create')];
    }

    /** `GET /returns/setup?customer=` — ফেরতের কারণ, আর ক্রেতার নিশ্চিত বিল (নতুন থেকে ৫০টা) */
    public function setup(Request $request): JsonResponse
    {
        /*
         * ⛔ দেখা শাখার গ্রাহকই — পুরো ERP অডিট, ৯ অক্টোবর ২০২৬। আগে শাখা ছাড়া খোঁজা হত, আর না মিললে ছাঁকনিই বসত না —
         * দোকান চেয়ে সব দোকানের বিল আসত। এখন অন্য শাখার বা অচেনা দোকান ৪০৪ ([[DirectSaleApiController]]-এর দামের দরজার একই দেয়াল)।
         */
        $customer = filled($request->query('customer'))
            ? Customer::query()->inViewedBranch()->where('public_id', PhoneInput::text($request, 'customer'))->firstOrFail()
            : null;

        $invoices = SalesInvoice::query()
            ->where('status', DocumentStatus::CONFIRMED)
            ->when($customer !== null, fn ($q) => $q->where('customer_id', $customer->id))
            ->with('customer.location.parent')
            ->orderByDesc('trx_date')->orderByDesc('id')
            ->limit(50)
            ->get()
            ->filter(fn (SalesInvoice $i) => $request->user()?->can('view', $i));

        return response()->json([
            'reasons' => ReasonCode::query()->active()->inContext(ReasonCode::SALES_RETURN)->orderBy('code')->get()
                ->map(fn (ReasonCode $r) => ['id' => (string) $r->public_id, 'label' => $r->name()])->values(),
            'invoices' => $invoices->map(fn (SalesInvoice $i) => [
                'id' => (string) $i->public_id,
                'no' => (string) $i->document_no,
                'date' => $i->trx_date?->toDateString(),
                'customer' => (string) ($i->customer?->name() ?? ''),
                // ⭐ পয়েন্ট — মালিক, ৭ অক্টোবর ২০২৬: "app e sob jaygay customer er pase obosoi point dibe" ([[Customer::pointName()]])
                'customer_point' => $i->customer?->pointName(),
                'total' => (string) $i->total,
            ])->values(),
        ]);
    }

    /** `GET /returns/invoice/{id}` — একটা বিলের সারি: পণ্য, লট, কত বেচা, কত দরে */
    public function invoice(string $id): JsonResponse
    {
        $invoice = $this->invoiceOf($id);
        $invoice->loadMissing(['customer', 'lines.product', 'lines.challanLine.batch']);

        return response()->json([
            'id' => (string) $invoice->public_id,
            'no' => (string) $invoice->document_no,
            'customer' => (string) ($invoice->customer?->public_id ?? ''),
            'customerName' => (string) ($invoice->customer?->name() ?? ''),
            'customerPoint' => $invoice->customer?->pointName(),
            'lines' => $invoice->lines->sortBy('line_no')->values()->map(fn (SalesInvoiceLine $l) => [
                'id' => (string) $l->public_id,
                'product' => (string) ($l->product?->public_id ?? ''),
                'name' => (string) ($l->product?->name() ?? ''),
                'lotNo' => $l->challanLine?->batch?->batch_no,
                'qty' => (string) $l->qty,
                'rate' => (string) $l->rate,
            ])->all(),
        ]);
    }

    /**
     * `POST /returns` — খসড়া ফেরত। ফোন পাঠায় বিল, কারণ আর সারি (বিলের সারি + পরিমাণ); বাকি সব ঘর বিল থেকেই — ক্রেতা,
     * গুদাম, দর, লট — যাতে ফোন বিলের বাইরের কিছু ফেরত দিতে না পারে। যাচাই ওয়েবের ফর্মের হুবহু।
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'invoice' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.line' => ['required', 'string', 'max:64'],
            'lines.*.qty' => ['required', 'numeric', PhoneInput::DECIMAL, 'min:0'],
            'lines.*.to_hold' => ['nullable', 'boolean'],
        ]);

        $invoice = $this->invoiceOf((string) $request->input('invoice'));
        $invoice->loadMissing('lines.challanLine');
        $reason = ReasonCode::query()->active()->inContext(ReasonCode::SALES_RETURN)
            ->where('public_id', (string) $request->input('reason'))->value('id');

        if ($reason === null) {
            throw ValidationException::withMessages(['reason' => __('sales::sync.unknown_reference')]);
        }

        $byPublic = $invoice->lines->keyBy(fn (SalesInvoiceLine $l) => (string) $l->public_id);
        $lines = [];

        foreach ((array) $request->input('lines') as $row) {
            $line = $byPublic->get((string) ($row['line'] ?? ''));

            if ($line === null) {
                throw ValidationException::withMessages(['lines' => __('sales::sync.unknown_reference')]);
            }

            $lines[] = [
                'product_id' => $line->product_id,
                'sales_invoice_line_id' => $line->id,
                'qty' => (string) $row['qty'],
                'rate' => (string) $line->rate,
                'batch_id' => $line->challanLine?->batch_id,
                'to_hold' => (bool) ($row['to_hold'] ?? false),
            ];
        }

        $form = SalesReturnRequest::createFrom($request)->replace([
            'customer_id' => $invoice->customer_id,
            'warehouse_id' => $invoice->warehouse_id,
            'sales_invoice_id' => $invoice->id,
            'reason_code_id' => (int) $reason,
            'reason_note' => $request->input('reason_note'),
            'trx_date' => now()->toDateString(),
            'narration' => $request->input('note'),
            'lines' => $lines,
        ]);
        $form->setContainer(app())->setRedirector(app('redirect'));
        $form->validateResolved();

        $document = $this->returns->create($form->documentData(), $form->lineData());

        return response()->json(['id' => (string) $document->public_id, 'no' => (string) $document->document_no], 201);
    }

    /** `GET /returns/{id}/overview` — নিশ্চিতের আগের সারাংশ, ওয়েবের পপ-আপের একই ([[SalesPaperOverview::salesReturn()]]) */
    public function overview(string $id): JsonResponse
    {
        return response()->json(app(SalesPaperOverview::class)->salesReturn($this->returnOf($id))->toArray());
    }

    /** `POST /returns/{id}/confirm` — নিশ্চিত; সই লাগলে খসড়া থাকে (২০২) */
    public function confirm(string $id): JsonResponse
    {
        $return = $this->returnOf($id);

        try {
            $this->returns->confirm($return);
        } catch (HeldForApproval $held) {
            return response()->json(['status' => 'held', 'notice' => (string) collect($held->errors())->flatten()->first()], 202);
        }

        return response()->json(['status' => 'done', 'no' => (string) $return->document_no, 'notice' => __('sales::message.return_confirmed')]);
    }

    private function invoiceOf(string $publicId): SalesInvoice
    {
        $invoice = SalesInvoice::query()->where('public_id', $publicId)->where('status', DocumentStatus::CONFIRMED)->first();

        abort_if($invoice === null, 404);
        $this->authorize('view', $invoice);

        return $invoice;
    }

    private function returnOf(string $publicId): SalesReturn
    {
        $return = SalesReturn::query()->where('public_id', $publicId)->first();

        abort_if($return === null, 404);
        $this->authorize('view', $return);

        return $return;
    }
}
