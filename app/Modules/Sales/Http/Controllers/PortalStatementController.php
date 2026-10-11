<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Services\PaperTrail;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Models\DocumentDelivery;
use App\Models\LedgerEntry;
use App\Modules\Sales\Models\Collection as SalesCollection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CustomerPapers;
use App\Modules\Sales\Support\StatementPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⭐ গ্রাহকের বিবরণী — একটা সারির কাগজ, আর পুরো বিবরণীর PDF (গ্রাহক পোর্টাল, ধাপ ১, ১১ অক্টোবর ২০২৬)।
 *
 * ⓘ পর্দার বিবরণী নিজে [[PortalController::ledger()]]; এখানে কেবল তার দুই সঙ্গী।
 * ⛔ "কার" প্রশ্নের উত্তর সবসময় [[CustomerPapers]] থেকে — অন্যের সারির public id বসালে ৪০৪।
 */
class PortalStatementController extends Controller
{
    /** ⓘ `doc_deliveries`-এ বিবরণীর চিহ্ন — নথির আইডি গ্রাহকের নিজের id, কারণ বিবরণীটা গ্রাহকেরই খাতা */
    public const PAPER = 'portal_statement';

    public function __construct(private readonly CustomerPapers $papers) {}

    /**
     * বিবরণীর একটা সারি খুললে — বিলের সারি বিলের ঘরে যায়, বাকিগুলো (আদায়, ফেরত, ভাউচার, খোলার জের) এখানে।
     *
     * ⓘ এখানে কেবল এই গ্রাহকের খাতায় বসা অংশ — একই কাগজের অন্য পায়ের সারি (ব্যাংক, আয়, অন্য পার্টি) গ্রাহকের নয়।
     * ⓘ আদায় হলে কোন কোন বিল শোধ হলো, সেটাও — প্রতিটা বিল বিলের ঘরে খোলে।
     */
    public function entry(string $id): View|RedirectResponse
    {
        $entry = $this->papers->statementEntry($id);

        if ($entry->source_type === SalesInvoice::drillSourceType()) {
            $bill = $this->papers->billLinks([$entry->source_id])[(int) $entry->source_id] ?? null;

            if ($bill !== null) {
                return redirect()->route('sales.portal.bill.show', $bill);
            }
        }

        $paid = $entry->source_type === SalesCollection::drillSourceType()
            ? $this->papers->collectionBills((int) $entry->source_id)
            : collect();

        return view('sales::portal.statement-entry', [
            'customer' => $this->papers->customer(),
            'entry' => $entry,
            'lines' => $this->papers->entriesOfSameSource($entry),
            'paid' => $paid,
            'billLinks' => $this->papers->billLinks($paid->pluck('sales_invoice_id')),
        ]);
    }

    /**
     * পুরো বিবরণীর PDF — পর্দার সেই একই সময়, সেই একই সারি, খোলার জের আর শেষ জেরসহ।
     *
     * ⓘ কাগজটা রিপোর্টের সেই একই ছাঁচে ([[PrintEngine]], `print.report`) — দ্বিতীয় কোনো নকশা নয়।
     * ⓘ খাতায় ওঠে গ্রাহকের নামে — কে, কোন সময়ের বিবরণী, কখন ([[PaperTrail::record()]])।
     */
    public function pdf(Request $request): Response
    {
        $customer = $this->papers->customer();
        $period = StatementPeriod::of($request, $this->papers->yearStart());

        $opening = $this->papers->openingBefore($period->from);
        $balance = $opening;
        $rows = [['—', __('sales::portal.opening'), '', '', Money::drCr($opening)]];

        foreach ($this->papers->ledgerBetween($period->from, $period->to) as $row) {
            /** @var LedgerEntry $row */
            $balance = bcsub(bcadd($balance, (string) $row->debit, 4), (string) $row->credit, 4);
            $rows[] = [
                DateFormat::format($row->trx_date),
                (string) ($row->document_no ?: $row->narration),
                bccomp((string) $row->debit, '0', 4) > 0 ? Money::format($row->debit) : '',
                bccomp((string) $row->credit, '0', 4) > 0 ? Money::format($row->credit) : '',
                Money::drCr($balance),
            ];
        }

        $range = DateFormat::format($period->from).' — '.DateFormat::format($period->to);

        $pdf = app(PrintEngine::class)->render('print.report', [
            'title' => __('sales::portal_statement.title').' · '.$customer->name().' ('.$customer->code.')',
            'range' => $range,
            'columns' => [
                ['label' => __('sales::portal.date'), 'numeric' => false],
                ['label' => __('sales::portal.particulars'), 'numeric' => false],
                ['label' => __('sales::portal.debit'), 'numeric' => true],
                ['label' => __('sales::portal.credit'), 'numeric' => true],
                ['label' => __('sales::portal.balance'), 'numeric' => true],
            ],
            'rows' => $rows,
            'footer' => ['', __('sales::portal_statement.closing'), '', '', Money::drCr($balance)],
        ], PaperSize::A4);

        $asFile = $request->boolean('download');
        $name = 'statement-'.$customer->code.'-'.$period->from.'-'.$period->to;

        app(PaperTrail::class)->record(
            self::PAPER, (int) $customer->id, PaperSize::A4,
            $asFile ? DocumentDelivery::DOWNLOADED : DocumentDelivery::PRINTED,
            mb_substr($period->from.' — '.$period->to, 0, 60),
        );

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($asFile ? 'attachment' : 'inline').'; filename="'.$name.'.pdf"',
        ]);
    }
}
