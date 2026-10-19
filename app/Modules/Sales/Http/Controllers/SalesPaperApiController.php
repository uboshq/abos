<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryChallanLine;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Services\ChallanBills;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * ⭐ ফোনে বিক্রয় বিল আর চালানের তালিকা ও পাতা — মালিক, ১৩ অক্টোবর ২০২৬: *"Mobile App e kora invoice, challan, voucher — kono
 * kichuri list show korche na"*। কাউন্টারে বিক্রি করে ফোনে বিলটা আর কোথাও দেখা যেত না।
 *
 *   `GET /api/v1/sales/invoices?from=&to=&q=&status=&page=` · `GET /api/v1/sales/invoices/{public_id}`
 *   `GET /api/v1/sales/challans?from=&to=&q=&status=&page=` · `GET /api/v1/sales/challans/{public_id}`
 *
 * ⛔ দ্বিতীয় কোনো তালিকার নিয়ম নেই — ওয়েবের তালিকার নিজের কোয়েরি ([[SalesInvoiceController::listed()]],
 * [[DeliveryChallanController::listed()]]: খোঁজা, কাউন্টারের খোলা খসড়া বাদ, বাতিল লুকানো), আর দেয়াল মডেলের নিজের স্কোপে —
 * কোম্পানি, দেখার শাখা, বিক্রয়কর্মীর বাঁধা ডিলার। আদায় আর বাকি বিলের নিজের হিসাবে ([[SalesInvoice::withCollected()]],
 * [[SalesInvoice::dueAmount()]]) — ওয়েবের বিলের পাতার একই অঙ্ক। চাবি ওয়েবের নীতির একই: `sales.invoice.view`, `sales.challan.view`।
 * ⓘ তারিখ না দিলে এই মাস। ⛔ কেবল public_id।
 */
final class SalesPaperApiController extends Controller
{
    public const PER_PAGE = 30;

    public function invoices(Request $request): JsonResponse
    {
        [$from, $to, $status] = $this->filters($request);

        $query = SalesInvoiceController::listed($request)
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->whereBetween('trx_date', [$from, $to]);

        $total = (string) ((clone $query)->reorder()->sum('total') ?: '0');
        $page = (clone $query)->withCollected()->orderByDesc('trx_date')->orderByDesc('sal_invoices.id')->paginate(self::PER_PAGE);

        return response()->json([
            'from' => $from,
            'to' => $to,
            'rows' => $page->getCollection()->map(fn (SalesInvoice $i) => [
                'id' => (string) $i->public_id,
                'no' => (string) $i->document_no,
                'sale_no' => $i->sale_no,
                'date' => $i->trx_date?->toDateString(),
                'customer' => (string) ($i->customer?->name() ?? ''),
                'point' => $i->customer?->pointName(),
                'status' => (string) $i->status,
                'status_label' => __('core.status.'.$i->status),
                'total' => bcadd((string) $i->total, '0', 2),
                'collected' => bcadd($i->collectedAmount(), '0', 2),
                'due' => bcadd($i->dueAmount(), '0', 2),
            ])->values(),
            'count' => $page->total(),
            'sum' => bcadd($total, '0', 2),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    public function invoice(string $id): JsonResponse
    {
        $invoice = SalesInvoice::query()->where('public_id', $id)
            ->with(['lines.product.unit', 'lines.challanLine.challan', 'customer', 'warehouse', 'creator'])->firstOrFail();

        $challans = $invoice->lines->map(fn (SalesInvoiceLine $l) => $l->challanLine?->challan)->filter()->unique('id')->values();

        return response()->json([
            'id' => (string) $invoice->public_id,
            'no' => (string) $invoice->document_no,
            'sale_no' => $invoice->sale_no,
            'date' => $invoice->trx_date?->toDateString(),
            'due_on' => $invoice->due_on?->toDateString(),
            'status' => (string) $invoice->status,
            'status_label' => __('core.status.'.$invoice->status),
            'customer' => (string) ($invoice->customer?->name() ?? ''),
            'customer_code' => $invoice->customer?->code,
            'point' => $invoice->customer?->pointName(),
            'warehouse' => $this->nameOf($invoice->warehouse),
            'narration' => (string) ($invoice->narration ?? ''),
            'created_by' => $invoice->creator?->name,
            'lines' => $invoice->lines->sortBy('line_no')->values()->map(fn (SalesInvoiceLine $l) => [
                'name' => (string) ($l->product?->name() ?? ''),
                'qty' => (string) $l->qty,
                'unit' => $this->nameOf($l->product?->unit),
                'rate' => bcadd((string) $l->rate, '0', 2),
                'discount' => bcadd((string) ($l->discount ?? '0'), '0', 2),
                'amount' => bcadd((string) $l->amount, '0', 2),
            ]),
            'subtotal' => bcadd((string) ($invoice->subtotal ?? '0'), '0', 2),
            'discount' => bcadd((string) ($invoice->discount ?? '0'), '0', 2),
            'tax' => bcadd((string) ($invoice->tax ?? '0'), '0', 2),
            'total' => bcadd((string) $invoice->total, '0', 2),
            // ⓘ ওয়েবের বিলের পাতার একই দুই অঙ্ক — খাতায় বসা আদায় আর রসিদ ভাউচার; ফেরত আর কমিশন দাবি বাকি কমায়
            'collected' => bcadd($invoice->collectedAmount(), '0', 2),
            'due' => bcadd($invoice->dueAmount(), '0', 2),
            'challans' => $challans->map(fn (DeliveryChallan $c) => ['id' => (string) $c->public_id, 'no' => (string) $c->document_no]),
        ]);
    }

    public function challans(Request $request): JsonResponse
    {
        [$from, $to, $status] = $this->filters($request);

        $query = DeliveryChallanController::listed($request)
            ->when($status === null, fn (Builder $q) => $q->where('status', '<>', DocumentStatus::CANCELLED))
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->whereBetween('trx_date', [$from, $to]);

        $total = (string) ((clone $query)->reorder()->sum('total') ?: '0');
        $page = (clone $query)->with('customer.location.parent')->orderByDesc('trx_date')->orderByDesc('sal_challans.id')->paginate(self::PER_PAGE);

        return response()->json([
            'from' => $from,
            'to' => $to,
            'rows' => $page->getCollection()->map(fn (DeliveryChallan $c) => [
                'id' => (string) $c->public_id,
                'no' => (string) $c->document_no,
                'sale_no' => $c->sale_no,
                'date' => $c->trx_date?->toDateString(),
                'customer' => (string) ($c->customer?->name() ?? ''),
                'point' => $c->customer?->pointName(),
                'status' => (string) $c->status,
                'status_label' => __('core.status.'.$c->status),
                'total' => bcadd((string) ($c->total ?? '0'), '0', 2),
            ])->values(),
            'count' => $page->total(),
            'sum' => bcadd($total, '0', 2),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    public function challan(string $id): JsonResponse
    {
        $challan = DeliveryChallan::query()->where('public_id', $id)
            ->with(['lines.product.unit', 'customer', 'warehouse', 'creator'])->firstOrFail();

        return response()->json([
            'id' => (string) $challan->public_id,
            'no' => (string) $challan->document_no,
            'sale_no' => $challan->sale_no,
            'date' => $challan->trx_date?->toDateString(),
            'status' => (string) $challan->status,
            'status_label' => __('core.status.'.$challan->status),
            'customer' => (string) ($challan->customer?->name() ?? ''),
            'customer_code' => $challan->customer?->code,
            'point' => $challan->customer?->pointName(),
            'warehouse' => $this->nameOf($challan->warehouse),
            'vehicle_no' => $challan->vehicle_no,
            'driver_name' => $challan->driver_name,
            'narration' => (string) ($challan->narration ?? ''),
            'created_by' => $challan->creator?->name,
            'lines' => $challan->lines->sortBy('line_no')->values()->map(fn (DeliveryChallanLine $l) => [
                'name' => (string) ($l->product?->name() ?? ''),
                'qty' => (string) $l->delivered_qty,
                'free_qty' => (string) ($l->free_qty ?? '0'),
                'unit' => $this->nameOf($l->product?->unit),
                'rate' => bcadd((string) ($l->rate ?? '0'), '0', 2),
                'amount' => bcadd((string) ($l->amount ?? '0'), '0', 2),
            ]),
            'discount' => bcadd((string) ($challan->discount_amount ?? '0'), '0', 2),
            'total' => bcadd((string) ($challan->total ?? '0'), '0', 2),
            // ⭐ এই চালানের বিল — ওয়েবের চালানের পাতার একই উত্তর ([[ChallanBills::of()]])
            'bills' => app(ChallanBills::class)->of($challan)->map(fn (SalesInvoice $i) => ['id' => (string) $i->public_id, 'no' => (string) $i->document_no])->values(),
        ]);
    }

    /** @return array{0: string, 1: string, 2: ?string} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(DocumentStatus::ALL)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $to = $data['to'] ?? Carbon::today()->toDateString();
        $from = $data['from'] ?? Carbon::parse($to)->startOfMonth()->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to, $data['status'] ?? null];
    }

    /** ইউনিট বা গুদামের নাম — বাংলায় থাকলে বাংলা */
    private function nameOf(?Model $row): ?string
    {
        if ($row === null) {
            return null;
        }

        $bn = (string) ($row->getAttribute('name_bn') ?? '');
        $en = (string) ($row->getAttribute('name_en') ?? '');
        $name = app()->getLocale() === 'bn' ? ($bn ?: $en) : ($en ?: $bn);

        return $name !== '' ? $name : (string) ($row->getAttribute('code') ?? '');
    }
}
