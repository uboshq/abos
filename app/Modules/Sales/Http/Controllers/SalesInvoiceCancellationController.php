<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceCancellation;
use App\Modules\Sales\Services\SalesInvoiceCancellationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * বাতিল-ইনভয়েসের দরজা — ইনভয়েসের পাতা থেকে চাওয়া, নিজের পাতা (মালিক, ৪ অক্টোবর ২০২৬; [[SalesInvoiceCancellationService]])।
 *
 * ⓘ চাওয়ার চাবি `sales.invoice.cancellation`; দেখার চাবি ইনভয়েসেরটাই (`sales.invoice.view`)। পাহারা সব সার্ভিসে — গেট পাস,
 * ফেরত, মাস, সই; ভুল হলে ইনভয়েসের পাতায় ফেরত, কারণসহ।
 */
final class SalesInvoiceCancellationController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly SalesInvoiceCancellationService $cancellations,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.invoice.cancellation', only: ['store']),
            new Middleware('can:sales.invoice.view', only: ['show']),
        ];
    }

    public function store(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => __('sales::cancellation.reason_required'),
        ]);

        $paper = $this->cancellations->request($invoice, $request->user(), $data['reason']);

        $done = $paper->status === DocumentStatus::CONFIRMED;

        return redirect()
            ->route('sales.cancellation.show', $paper)
            ->with('saved', __($done ? 'sales::cancellation.saved_confirmed' : 'sales::cancellation.saved_awaiting', [
                'cxl' => $paper->document_no,
                'no' => $invoice->document_no,
            ]));
    }

    public function show(Request $request, SalesInvoiceCancellation $cancellation): View
    {
        $cancellation->loadMissing(['invoice.lines.product.unit', 'customer', 'creator', 'confirmer']);

        return view('sales::cancellation.show', [
            'menu' => $this->menu->forUser($request->user()),
            'cancellation' => $cancellation,
            'invoice' => $cancellation->invoice,
        ]);
    }
}
