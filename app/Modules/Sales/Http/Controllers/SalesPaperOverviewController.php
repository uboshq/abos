<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesPaperOverview;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ⭐ রাখা চালান আর বিলের "নিশ্চিত করুন"-এর আগের সারাংশ — পপ-আপের ভিতরে যা আঁকা হয় (মালিক, ৪ অক্টোবর ২০২৬;
 * [[confirm-overview.js]], [[SalesPaperOverview]])। কিছুই লেখে না।
 *
 * ⓘ চাবি নিশ্চিতের দরজারই — চালানে `sales.challan.create`, বিলে `sales.invoice.create`, আদায়ে `sales.collection.create` ([[DeliveryChallanController]],
 * [[SalesInvoiceController]]-এর `confirm`); আর কাগজটা দেখার অধিকার (`view` নীতি — শাখা, নিজের ক্রেতা), যাতে
 * অন্য শাখার চালানের সারাংশ ঠিকানা বদলে খোলা না যায়।
 */
class SalesPaperOverviewController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.challan.create', only: ['challan']),
            new Middleware('can:sales.invoice.create', only: ['invoice']),
            new Middleware('can:sales.collection.create', only: ['collection']),
        ];
    }

    public function collection(Collection $collection, SalesPaperOverview $overview): View
    {
        $this->authorize('view', $collection);

        return view('ui.confirm-overview-body', ['overview' => $overview->collection($collection)->toArray()]);
    }

    public function challan(DeliveryChallan $challan, SalesPaperOverview $overview): View
    {
        $this->authorize('view', $challan);

        return view('ui.confirm-overview-body', ['overview' => $overview->challan($challan)->toArray()]);
    }

    public function invoice(SalesInvoice $invoice, SalesPaperOverview $overview): View
    {
        $this->authorize('view', $invoice);

        return view('ui.confirm-overview-body', ['overview' => $overview->invoice($invoice)->toArray()]);
    }
}
