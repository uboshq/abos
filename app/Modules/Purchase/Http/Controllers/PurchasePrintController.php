<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintableDocument;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Support\DateFormat;
use App\Http\Controllers\Controller;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Models\PurchaseReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;

/**
 * ক্রয়ের কাগজ — চারটা, বিক্রয়ের ছাঁচেই।
 *
 * ── কেন এটা দরকার ছিল ───────────────────────────────────────────────
 * §১২ প্রতিটা ডোমেইনে তিন মাপে ছাপা দাবি করে, আর Sales-এ সাতটা কাগজ
 * ছিল — Purchase-এ একটাও নয়। ফলে অর্ডার হাতে নিয়ে গুদামে যাওয়া, বা
 * সরবরাহকারীকে ফেরতের কাগজ ধরিয়ে দেওয়া — দুইটাই অসম্ভব ছিল।
 *
 * ── ক্রয়ের কাগজ বিক্রয়ের কাগজ থেকে যেভাবে আলাদা ─────────────────────
 * বিক্রয়ের কাগজ যায় **গ্রাহকের হাতে** — সে দাম দেখে, সই করে। ক্রয়ের
 * কাগজ থাকে **নিজেদের কাছে**, প্রমাণ হিসেবে: এই মাল এই দামে এসেছিল,
 * এই তারিখে, এই গাড়িতে। তাই সইয়ের ঘরও আলাদা — এখানে "বুঝে নিলেন কে"
 * আর "যাচাই করলেন কে", "গ্রাহকের সই" নয়।
 *
 * একটা ব্যতিক্রম: **ক্রয় ফেরতের কাগজ সরবরাহকারীর হাতে যায়**, কারণ মাল
 * ফেরত পাঠানোর সাথে ওই কাগজটাই যায় আর তার বিপরীতে ক্রেডিট নোট আসে।
 * ওখানে তাই সরবরাহকারীর প্রতিনিধির সই লাগে।
 */
class PurchasePrintController extends Controller implements HasMiddleware
{
    public function __construct(private readonly PrintEngine $print) {}

    public static function middleware(): array
    {
        /*
         * ডকুমেন্ট দেখার চাবিই ছাপার চাবি।
         *
         * ছাপা নতুন কোনো তথ্য দেয় না — একই তথ্য কাগজে দেয়। আলাদা চাবি
         * রাখলে গুদামের লোক মাল বুঝে নেওয়ার কাগজ ছাপতে পারতেন না, অথচ
         * ওটাই তাঁর কাজের কাগজ।
         */
        return [
            new Middleware('can:purchase.bill.view', only: ['bill']),
            new Middleware('can:purchase.order.view', only: ['order']),
            new Middleware('can:purchase.receipt.view', only: ['receipt']),
            new Middleware('can:purchase.return.view', only: ['creditNote']),
        ];
    }

    /** ক্রয় বিল — সরবরাহকারী যা চাইলেন, আমরা যা মানলাম। */
    public function bill(Request $request, PurchaseBill $bill): Response
    {
        $bill->load(['lines.product.unit', 'supplier', 'branch']);

        $doc = new PrintableDocument(
            title: __('purchase::doc.bill'),
            meta: [
                'core.print.document_no' => (string) $bill->document_no,
                'core.print.date' => DateFormat::format($bill->trx_date),
                'purchase::field.supplier' => $bill->supplier?->name() ?? '',
                'purchase::field.supplier_bill_no' => (string) ($bill->supplier_bill_no ?? ''),
            ],
            lines: $this->lines($bill->lines, 'qty'),
            totals: $this->totals($bill),
            signatures: ['core.print.prepared_by', 'purchase::print.checked_by'],
            narration: $bill->narration,
        );

        return $this->pdf($request, $doc, (string) $bill->total, (string) $bill->document_no);
    }

    /**
     * ক্রয় আদেশ — যা চাওয়া হয়েছিল।
     *
     * এটাই একমাত্র ক্রয়ের কাগজ যেটা **বাইরে যায়**: সরবরাহকারীকে পাঠানো
     * হয়। তাই এখানে অনুমোদনকারীর সই, নাহলে ওপারের লোক জানবেন না কাগজটা
     * কারও অনুমতি নিয়ে এসেছে কি না।
     */
    public function order(Request $request, PurchaseOrder $order): Response
    {
        $order->load(['lines.product.unit', 'supplier', 'branch']);

        $doc = new PrintableDocument(
            title: __('purchase::doc.order'),
            meta: [
                'core.print.document_no' => (string) $order->document_no,
                'core.print.date' => DateFormat::format($order->trx_date),
                'purchase::field.supplier' => $order->supplier?->name() ?? '',
            ],
            lines: $this->lines($order->lines, 'ordered_qty'),
            totals: $this->totals($order),
            signatures: ['core.print.prepared_by', 'core.print.approved_by'],
            narration: $order->narration,
        );

        return $this->pdf($request, $doc, (string) $order->total, (string) $order->document_no);
    }

    /**
     * মাল বুঝে নেওয়ার কাগজ — গুদামের কাজের কাগজ।
     *
     * দাম নেই, ইচ্ছাকৃতভাবে (`showMoney: false`)। গুদামের লোক গোনেন,
     * দাম নিয়ে তাঁর কিছু করার নেই — আর দামটা কাগজে থাকলে সেটা এমন
     * অনেকের চোখে পড়ে যাদের জানার কথা নয়।
     */
    public function receipt(Request $request, PurchaseReceipt $receipt): Response
    {
        $receipt->load(['lines.product.unit', 'supplier', 'warehouse', 'branch']);

        $doc = new PrintableDocument(
            title: __('purchase::doc.receipt'),
            meta: [
                'core.print.document_no' => (string) $receipt->document_no,
                'core.print.date' => DateFormat::format($receipt->trx_date),
                'purchase::field.supplier' => $receipt->supplier?->name() ?? '',
                'purchase::field.warehouse' => $receipt->warehouse?->name() ?? '',
            ],
            lines: $this->lines($receipt->lines, 'received_qty', money: false),
            totals: [],
            signatures: ['purchase::print.received_by', 'purchase::print.checked_by'],
            showMoney: false,
            narration: $receipt->narration,
        );

        return $this->pdf($request, $doc, '0', (string) $receipt->document_no);
    }

    /**
     * ক্রয় ফেরত — মালের সাথে যায়, ক্রেডিট নোট হয়ে ফেরে।
     *
     * এটাই একমাত্র ক্রয়ের কাগজ যেটা সরবরাহকারীর হাতে যায়, তাই ওপারের
     * সই লাগে। ওই সইটাই পরে প্রমাণ যে মাল সত্যিই ফেরত গেছে — নাহলে
     * "পাঠিয়েছি" বনাম "পাইনি" শুরু হয়, আর টাকাটা ঝুলে থাকে।
     */
    public function creditNote(Request $request, PurchaseReturn $return): Response
    {
        $return->load(['lines.product.unit', 'supplier', 'warehouse', 'branch']);

        $doc = new PrintableDocument(
            title: __('purchase::doc.return'),
            meta: [
                'core.print.document_no' => (string) $return->document_no,
                'core.print.date' => DateFormat::format($return->trx_date),
                'purchase::field.supplier' => $return->supplier?->name() ?? '',
                'purchase::field.warehouse' => $return->warehouse?->name() ?? '',
            ],
            lines: $this->lines($return->lines, 'qty'),
            totals: $this->totals($return),
            signatures: ['core.print.prepared_by', 'purchase::print.supplier_signature'],
            narration: $return->narration,
        );

        return $this->pdf($request, $doc, (string) $return->total, (string) $return->document_no);
    }

    /**
     * পণ্যের সারি — পরিমাণের ঘরটা ডকুমেন্টভেদে আলাদা নামে।
     *
     * অর্ডারে `ordered_qty`, বুঝে নেওয়ায় `received_qty`, বিলে `qty` —
     * তিনটাই একই জিনিস বলে, কিন্তু আলাদা নামে, কারণ একটা অর্ডারের
     * "চাওয়া" আর একটা চালানের "পাওয়া" এক সংখ্যা না-ও হতে পারে।
     *
     * @param  Collection<int, object>  $lines
     * @return list<array<string, string>>
     */
    private function lines($lines, string $qtyField, bool $money = true): array
    {
        return $lines->map(function ($line) use ($qtyField, $money) {
            $row = [
                'name' => trim(($line->product?->code ?? '').' '.($line->product?->name() ?? '')),
                'qty' => $this->qty($line->{$qtyField}),
                'unit' => $line->product?->unit?->name() ?? '',
            ];

            if ($money) {
                $row['rate'] = $this->money($line->rate);
                $row['amount'] = $this->money($line->amount);
            }

            return $row;
        })->values()->all();
    }

    /**
     * @return array<string, string>
     */
    private function totals(object $document): array
    {
        $rows = ['core.print.subtotal' => $this->money($document->subtotal)];

        // শূন্যের সারি কাগজে শুধু জায়গা নেয়, আর থার্মালে জায়গাটাই
        // সবচেয়ে দামি — Sales-এর কাগজেও ঠিক একই নিয়ম।
        if (bccomp((string) ($document->discount ?? '0'), '0', 4) > 0) {
            $rows['core.print.discount'] = $this->money($document->discount);
        }

        if (bccomp((string) ($document->tax ?? '0'), '0', 4) > 0) {
            $rows['core.print.tax'] = $this->money($document->tax);
        }

        $rows['core.print.total'] = $this->money($document->total);

        return $rows;
    }

    private function pdf(Request $request, PrintableDocument $doc, string $amount, string $documentNo): Response
    {
        $paper = $request->query('paper', PaperSize::A4);

        // অজানা মাপে ৪০৪ নয় — পুরনো বুকমার্কের জন্য কাগজ আটকে যাওয়ার
        // কোনো কারণ নেই।
        if (! in_array($paper, PaperSize::all(), true)) {
            $paper = PaperSize::A4;
        }

        $pdf = $this->print->render(
            template: 'print.document',
            data: [
                'doc' => $doc->withWordsFor($amount, app()->getLocale()),
                'title' => $doc->title.' '.$documentNo,
            ],
            paper: $paper,
        );

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$documentNo.'.pdf"',
        ]);
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2);
    }

    /** পরিমাণে ভগ্নাংশ থাকলে দেখাও, না থাকলে নয় — "১০.০০ পিস" কেউ লেখে না। */
    private function qty(mixed $value): string
    {
        $number = (float) $value;

        return fmod($number, 1.0) === 0.0
            ? number_format($number)
            : rtrim(rtrim(number_format($number, 4), '0'), '.');
    }
}
