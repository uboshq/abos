<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintableDocument;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Support\DateFormat;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Services\IssuedLots;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\PrintJob;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\PrintQueue;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * বিক্রয়ের কাগজ — ছয়টা ডকুমেন্ট, তিনটা কাগজ।
 *
 * ── ছয়টা কেন, চারটা নয় ───────────────────────────────────────────────
 * ডকুমেন্ট চারটা, কিন্তু কাগজ ছয় রকম — কারণ একই ডকুমেন্ট থেকে ভিন্ন
 * মানুষের জন্য ভিন্ন কাগজ বেরোয়:
 *
 *     বিল          গ্রাহকের, দাম সহ
 *     খসড়া বিল     গ্রাহককে দেখানোর জন্য, কিন্তু "চূড়ান্ত নয়" লেখা
 *     চালান        গ্রাহকের সাথে যাওয়া কাগজ
 *     অর্ডার       গ্রাহকের নিশ্চিতকরণ
 *     ডেলিভারি অর্ডার  গুদামের লোকের — কী কী বের করতে হবে, **দাম ছাড়া**
 *     গেটপাস       দারোয়ানের — কী কী গেট দিয়ে যাচ্ছে, **দাম ছাড়া**
 *
 * শেষ দুইটায় দাম না থাকাটা মূল কথা। থাকলে গাড়ির চালক থেকে দারোয়ান
 * পর্যন্ত সবাই জেনে যেতেন কোন গ্রাহক কী দরে কেনেন, অথচ কারও ওটা জানার
 * দরকার নেই — আর ওই তথ্যটা ফাঁস হলে দর নিয়ে দরকষাকষি শুরু হয়।
 */
class SalesPrintController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly PrintEngine $print,

        // কোন লাইনে কোন লট গেছে — চলাচলের সারি থেকে, এক কোয়েরিতে
        private readonly IssuedLots $lots,

        // কাগজটা বেরোল কি না, আর কতবার — DUPLICATE-এর ভিত্তি
        private readonly PrintQueue $queue,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.invoice.view', only: ['invoice', 'draft']),
            new Middleware('can:sales.challan.view', only: ['challan', 'gatepass']),
            new Middleware('can:sales.order.view', only: ['order', 'deliveryOrder']),
            new Middleware('can:sales.collection.view', only: ['receipt']),
        ];
    }

    public function invoice(Request $request, SalesInvoice $invoice): Response
    {
        $invoice->load(['lines.product.unit', 'customer', 'branch']);

        $doc = new PrintableDocument(
            title: __('sales::doc.invoice'),
            meta: $this->invoiceMeta($invoice),
            lines: $this->productLines($invoice->lines, 'qty', $this->lotsForInvoice($invoice)),
            totals: $this->totals($invoice),
            signatures: ['core.print.prepared_by', 'core.print.received_by'],
            narration: $invoice->narration,
        );

        /*
         * বিলটা সারিতে ওঠে, আর দ্বিতীয়বার থেকে DUPLICATE বসে।
         *
         * খসড়ায় নয় (নিচের draft): ওটা এমনিতেই "চূড়ান্ত নয়" লেখা নিয়ে
         * বেরোয়, আর খসড়া কতবার ছাপা হলো তা কারও জানার দরকার নেই।
         */
        return $this->pdf(
            $request, $doc, (string) $invoice->total, $invoice->document_no,
            type: PrintJob::INVOICE, id: $invoice->id, document: $invoice,
        );
    }

    /**
     * খসড়া বিল — দাম আছে, কিন্তু কাগজে বড় করে লেখা "চূড়ান্ত নয়"।
     *
     * গ্রাহক দাম দেখে সিদ্ধান্ত নেন, অথচ কেউ যেন এটা নিয়ে টাকা চাইতে না
     * যায়। লেখাটা না থাকলে খসড়া আর আসল বিল দেখতে হুবহু এক হত।
     */
    public function draft(Request $request, SalesInvoice $invoice): Response
    {
        $invoice->load(['lines.product.unit', 'customer', 'branch']);

        $doc = new PrintableDocument(
            title: __('sales::doc.invoice'),
            meta: $this->invoiceMeta($invoice),
            lines: $this->productLines($invoice->lines, 'qty', $this->lotsForInvoice($invoice)),
            totals: $this->totals($invoice),
            signatures: [],
            narration: $invoice->narration,
            notice: __('core.print.draft_notice'),
        );

        return $this->pdf(
            $request, $doc, (string) $invoice->total, $invoice->document_no,
            document: $invoice,
        );
    }

    public function challan(Request $request, DeliveryChallan $challan): Response
    {
        $challan->load(['lines.product.unit', 'customer', 'warehouse']);

        $doc = new PrintableDocument(
            title: __('sales::doc.challan'),
            meta: $this->challanMeta($challan),
            lines: $this->productLines(
                $challan->lines,
                'delivered_qty',
                $this->lots->forDocument(DeliveryChallan::STOCK_SOURCE, $challan->id),
            ),
            totals: ['core.print.total' => $this->money($challan->total)],
            signatures: ['core.print.delivered_by', 'core.print.driver', 'core.print.received_by'],
            narration: $challan->narration,
        );

        // চালানও — একই কারণে: দুইটা একরকম চালান মানে দুইবার মাল দাবি
        return $this->pdf(
            $request, $doc, (string) $challan->total, $challan->document_no,
            type: PrintJob::CHALLAN, id: $challan->id, document: $challan,
        );
    }

    /**
     * গেটপাস — একই চালান, দাম ছাড়া।
     *
     * দারোয়ান মিলিয়ে দেখেন গাড়িতে যা আছে কাগজে তা-ই লেখা কি না। সেই
     * কাজে দামের কোনো ভূমিকা নেই।
     */
    public function gatepass(Request $request, DeliveryChallan $challan): Response
    {
        $challan->load(['lines.product.unit', 'customer', 'warehouse']);

        $doc = new PrintableDocument(
            title: __('sales::doc.gatepass'),
            meta: $this->challanMeta($challan),
            lines: $this->productLines(
                $challan->lines,
                'delivered_qty',
                $this->lots->forDocument(DeliveryChallan::STOCK_SOURCE, $challan->id),
            ),
            signatures: ['core.print.storekeeper', 'core.print.driver', 'core.print.gate_officer'],
            showMoney: false,
            notice: __('core.print.no_price_notice'),
        );

        return $this->pdf($request, $doc, '0', $challan->document_no, document: $challan);
    }

    public function order(Request $request, SalesOrder $order): Response
    {
        $order->load(['lines.product.unit', 'customer', 'warehouse']);

        $doc = new PrintableDocument(
            title: __('sales::doc.order'),
            meta: [
                'core.print.document_no' => $order->document_no,
                'core.print.date' => DateFormat::format($order->trx_date),
                'sales::field.customer' => $order->customer?->name() ?? '',
                'sales::field.deliver_on' => DateFormat::format($order->deliver_on),
            ],
            lines: $this->productLines($order->lines, 'ordered_qty'),
            totals: $this->totals($order),
            signatures: ['core.print.prepared_by', 'core.print.approved_by'],
            narration: $order->narration,
        );

        return $this->pdf($request, $doc, (string) $order->total, $order->document_no, document: $order);
    }

    /**
     * ডেলিভারি অর্ডার — গুদামের লোকের কাগজ, দাম ছাড়া।
     *
     * শুধু যেটুকু এখনো বের করা বাকি, সেটুকুই ছাপা হয়। পুরো অর্ডার ছাপলে
     * আগের চালানে যা গেছে সেটাও আবার বের করে ফেলার ঝুঁকি থাকত।
     */
    public function deliveryOrder(Request $request, SalesOrder $order): Response
    {
        $order->load(['lines.product.unit', 'customer', 'warehouse']);

        $pending = $order->lines
            ->filter(fn ($line) => bccomp($line->pendingQty(), '0', 4) > 0)
            ->map(fn ($line) => [
                'name' => $this->productName($line),
                'qty' => $this->qty($line->pendingQty()),
                'unit' => $line->product?->unit?->name() ?? '',
                'rate' => '',
                'amount' => '',
            ])
            ->values()
            ->all();

        $doc = new PrintableDocument(
            title: __('sales::doc.delivery_order'),
            meta: [
                'core.print.document_no' => $order->document_no,
                'core.print.date' => DateFormat::format(now()),
                'sales::field.customer' => $order->customer?->name() ?? '',
                'sales::field.warehouse' => $order->warehouse?->name() ?? '',
            ],
            lines: $pending,
            signatures: ['core.print.storekeeper', 'core.print.delivered_by'],
            showMoney: false,
            notice: __('core.print.no_price_notice'),
        );

        return $this->pdf($request, $doc, '0', $order->document_no, document: $order);
    }

    /** টাকার রসিদ — আদায়ের কাগজ। */
    public function receipt(Request $request, Collection $collection): Response
    {
        $collection->load(['lines.invoice', 'customer', 'account']);

        $doc = new PrintableDocument(
            title: __('sales::doc.collection'),
            meta: [
                'core.print.document_no' => $collection->document_no,
                'core.print.date' => DateFormat::format($collection->trx_date),
                'sales::field.customer' => $collection->customer?->name() ?? '',
                'sales::field.account' => $collection->account?->name() ?? '',
                'sales::field.instrument_no' => $collection->instrument_no ?? '',
            ],
            lines: $collection->lines->map(fn ($line) => [
                'name' => $line->invoice?->document_no ?? '',
                'qty' => '',
                'unit' => '',
                'rate' => '',
                'amount' => $this->money($line->amount),
            ])->all(),
            totals: ['core.print.total' => $this->money($collection->amount)],
            signatures: ['core.print.received_by'],
            narration: $collection->narration,
        );

        return $this->pdf($request, $doc, (string) $collection->amount, $collection->document_no, document: $collection);
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    private function invoiceMeta(SalesInvoice $invoice): array
    {
        return [
            'core.print.document_no' => $invoice->document_no,
            'core.print.date' => DateFormat::format($invoice->trx_date),
            'sales::field.customer' => $invoice->customer?->name() ?? '',
            'sales::field.due_on' => DateFormat::format($invoice->due_on),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function challanMeta(DeliveryChallan $challan): array
    {
        return [
            'core.print.document_no' => $challan->document_no,
            'core.print.date' => DateFormat::format($challan->trx_date),
            'sales::field.customer' => $challan->customer?->name() ?? '',
            'sales::field.warehouse' => $challan->warehouse?->name() ?? '',
            // বহরের গাড়ি হলে মাস্টারের নম্বরপ্লেট, নাহলে লেখা নম্বরটা
            'sales::field.vehicle_no' => $challan->vehiclePlate(),
            'sales::field.driver_name' => $challan->driver_name ?? '',
        ];
    }

    /**
     * বিলের লটগুলো — চালান থেকে, বিল থেকে নয়।
     *
     * ── কেন এই ঘুরপথ ────────────────────────────────────────────────
     * মাল বেরোয় চালানে, বিলে নয়। তাই লটের সিদ্ধান্তটাও ওখানেই লেখা।
     * বিলের লাইন তার চালানের লাইনকে চেনে, আর ওই সুতো ধরেই লটে পৌঁছানো
     * যায়।
     *
     * একটা বিলে একাধিক চালান থাকতে পারে (কয়েক দিনের মাল একসাথে বিল),
     * তাই সবগুলো মিলিয়ে নেওয়া হয়।
     *
     * চালান ছাড়া বিল হলে (Control Panel-এ ছাড় দেওয়া থাকলে) কোনো লট
     * নেই — মালটা কখন কোন লট থেকে গেল সেই প্রশ্নেরই উত্তর নেই।
     *
     * @return array<int, string>
     */
    private function lotsForInvoice(SalesInvoice $invoice): array
    {
        $challanIds = $invoice->lines
            ->map(fn ($line) => $line->challanLine?->delivery_challan_id)
            ->filter()
            ->unique();

        $lots = [];

        foreach ($challanIds as $challanId) {
            foreach ($this->lots->forDocument(DeliveryChallan::STOCK_SOURCE, (int) $challanId) as $productId => $label) {
                $lots[$productId] = isset($lots[$productId])
                    ? $lots[$productId].', '.$label
                    : $label;
            }
        }

        return $lots;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $lines
     * @param  array<int, string>  $lots  পণ্যের আইডি → ব্যাচের লেখা
     * @return list<array{name: string, qty: string, unit: string, rate: string, amount: string, note: string}>
     */
    private function productLines($lines, string $qtyField, array $lots = []): array
    {
        /*
         * যে প্যাকে লেখা হয়েছিল সেটাই কাগজে — "২ বাক্স", "২০০ পিস" নয়।
         *
         * গুদামের লোক বাক্স গোনেন, আর ক্রেতা যা চেয়েছিলেন কাগজে সেটাই
         * দেখতে চান। ভেতরের হিসাব পিসেই চলে; এই দুইটা ঘর কেবল চোখের।
         */
        return $lines->map(fn ($line) => [
            'name' => $this->productName($line),
            'qty' => $this->qty($line->packedQty($qtyField)),
            'unit' => $line->packedUnitName(),
            'rate' => $this->money($line->packedRate('rate', $qtyField)),
            'amount' => $this->money($line->amount),

            /*
             * ব্যাচ ও মেয়াদ — নামের নিচে, ছোট করে।
             *
             * ওষুধে এটা সাজসজ্জা নয়: রিকল হলে ক্রেতার হাতের কাগজই বলে
             * দেয় তার পাতাটা ওই লটের কি না। লট ধরা না থাকলে ঘরটা খালি,
             * আর কাগজ অবিকল আগের মতো।
             */
            'note' => $lots[$line->product_id] ?? '',
        ])->values()->all();
    }

    /**
     * @return array<string, string>
     */
    private function totals(object $document): array
    {
        $rows = [];

        // ছাড় বা ভ্যাট শূন্য হলে সারিটাই থাকে না — শূন্যের সারি কাগজে
        // শুধু জায়গা নেয়, আর থার্মালে জায়গাটাই সবচেয়ে দামি
        $rows['core.print.subtotal'] = $this->money($document->subtotal);

        if (bccomp((string) $document->discount, '0', 4) > 0) {
            $rows['core.print.discount'] = $this->money($document->discount);
        }

        if (bccomp((string) $document->tax, '0', 4) > 0) {
            $rows['core.print.tax'] = $this->money($document->tax);
        }

        $rows['core.print.total'] = $this->money($document->total);

        return $rows;
    }

    private function money(mixed $value): string
    {
        return Money::format($value);
    }

    /** পরিমাণে পিছনের শূন্য বাদ — "১০.০০০০ বস্তা" কেউ লেখে না। */
    private function qty(mixed $value): string
    {
        $formatted = rtrim(rtrim(Money::format($value, 4), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private function productName(object $line): string
    {
        $product = $line->product;

        return $product === null
            ? ''
            : $product->code.' - '.$product->name();
    }

    /**
     * PDF হিসেবে ফেরত।
     *
     * ব্রাউজারে খোলে, নামানো হয় না (`inline`): বেশিরভাগ সময় কাগজটা দেখে
     * তারপর ছাপা হয়, আর প্রতিবার ফাইল নামলে Downloads ফোল্ডার ভরে যেত।
     */
    private function pdf(
        Request $request,
        PrintableDocument $doc,
        string $amount,
        string $documentNo,
        ?string $type = null,
        ?int $id = null,
        ?object $document = null,
    ): Response {
        $paper = $request->query('paper', PaperSize::A4);

        // অজানা মাপ এলে ৪০৪ নয়, A4 — পুরনো বুকমার্ক বা হাতে বদলানো URL
        // দিয়ে কাগজটা ছাপা না হওয়ার কোনো কারণ নেই
        if (! in_array($paper, PaperSize::all(), true)) {
            $paper = PaperSize::A4;
        }

        /*
         * বাতিল করা কাগজের গায়ে "বাতিল" — সবার আগে।
         *
         * ── কেন এখানে, প্রতিটা পদ্ধতিতে নয় ─────────────────────────
         * ছয়টা কাগজ, আর সপ্তমটা লেখার দিনে কেউ ভুলত। ভুলটা কোনো ভুল
         * দেখাত না: বাতিল করা চালান ছাপলে **হুবহু বৈধ একটা কাগজ**
         * বেরোত, আর সেটা দেখিয়ে গেট থেকে মাল বের করে নেওয়া যেত।
         *
         * স্থানান্তরের কাগজে যুক্তিটা আগে থেকেই লেখা ছিল, ভাউচারের
         * কন্ট্রোলারেও ছিল — বিক্রয় ও ক্রয়ের দশটা কাগজে ছিল না। HP-র
         * পরীক্ষক ভাউচারেরটা ধরেন (১৪ আগস্ট); খুঁজতে গিয়ে দেখা গেল
         * বাকিগুলোও একই অবস্থায়।
         *
         * DUPLICATE-এর আগে, কারণ বাতিল বেশি জরুরি: দ্বিতীয় কপি নিয়ে
         * বড়জোর দুইবার দাবি করা যায়, বাতিল কাগজ নিয়ে মাল নেওয়া যায়।
         * খসড়ার সাথে সংঘর্ষ নেই — খসড়া আর বাতিল একসাথে হয় না।
         */
        $cancelled = ($document?->status ?? null) === DocumentStatus::CANCELLED;

        if ($cancelled) {
            $doc = $doc->withNotice(__('core.print.cancelled_notice'));
        }

        /*
         * দ্বিতীয়বার ছাপা কাগজে DUPLICATE।
         *
         * ── কেন এটা দরকার ───────────────────────────────────────────
         * একই বিলের দুইটা একরকম কাগজ ঘুরলে কোনটা আসল তা বলার উপায়
         * থাকে না — আর ক্রেতা দুইটা নিয়ে দুইবার ফেরতের দাবি করতে
         * পারেন, বা কর্মী একটা দেখিয়ে দ্বিতীয়বার টাকা নিতে পারেন।
         *
         * গোনাটা বাড়ে PDF সত্যিই তৈরি হওয়ার পর, আগে নয়: ব্যর্থ চেষ্টায়
         * কোনো কাগজ বেরোয় না, আর সেটা গুনলে প্রথম সত্যিকারের কাগজেই
         * DUPLICATE বসত।
         */
        $job = $type === null ? null : $this->queue->queue($type, (int) $id, $paper, $documentNo);

        /*
         * সীমা পেরোলে এখানেই থামে — PDF তৈরির আগে।
         *
         * পরে বসালে কাগজটা তৈরি হয়ে যেত, শুধু ফেরত দেওয়া হত না — আর
         * তখন গোনাটা বেড়ে যেত এমন একটা কাগজের জন্য যেটা কেউ পায়নি।
         */
        if ($job !== null) {
            $this->queue->assertMayPrint($job);
        }

        if ($job?->isReprint()) {
            $doc = $doc->withNotice(__('core.print.duplicate_notice'));
        }

        $locale = app()->getLocale();

        $pdf = $this->print->render(
            template: 'print.document',
            data: [
                'doc' => $doc->withWordsFor($amount, $locale),
                'title' => $doc->title.' '.$documentNo,
            ],
            paper: $paper,

            /*
             * বাতিল বিলের গায়ে কোনাকুনি জলছাপ -- উপরের বাক্সের সাথে,
             * তার বদলে নয়। কারণটা [[PrintEngine::toPdf()]]-এ লেখা:
             * বাক্স কেটে ফেলা যায়, জলছাপ যায় না।
             */
            watermark: $cancelled ? __('core.print.cancelled_watermark') : null,
        );

        if ($job !== null) {
            $this->queue->printed($job);
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$documentNo.'.pdf"',
        ]);
    }
}
