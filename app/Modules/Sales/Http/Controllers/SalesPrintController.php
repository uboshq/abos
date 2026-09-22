<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintableDocument;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Engines\Print\PrintProfile;
use App\Core\Services\PaperTrail;
use App\Core\Services\SettingsService;
use App\Core\Support\DateFormat;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Models\DocumentDelivery;
use App\Modules\Accounts\Models\Account;
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
use Illuminate\Validation\ValidationException;

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

        // কোন কাগজে ছাপা হবে, সেটা মালিক বসিয়ে দেন
        private readonly SettingsService $settings,

        // ছাপা · নামানো · পাঠানো · খোলা — সব কাগজের এক হিসাব
        private readonly PaperTrail $trail,
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
        /*
         * ⛔ কাউন্টারে আটকে থাকা বিক্রয়ের বিল ছাপা হয় না — মালিকের নিয়ম
         * (১৯ সেপ্টেম্বর): *"কোনো print option আসবে না যতক্ষণ approve হচ্ছে।"*
         * ⓘ বোতামটা পাতায় লুকানো; এটা ঠিকানা টাইপ করে আসার পাহারা।
         */
        if ($invoice->isHeldAtCounter()) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.held_no_print', ['no' => $invoice->document_no]),
            ]);
        }

        /*
         * ⚠️ `lines.challanLine` — নইলে ছাপার পাতা ৫০০ দেয়।
         *
         * ── কী ঘটেছিল (মাপা, ৩ সেপ্টেম্বর ২০২৬) ─────────────────────
         * `lotsForInvoice()` প্রতিটা লাইনের চালান-লাইন ধরে লট খোঁজে।
         * সম্পর্কটা এখানে তোলা হত না, আর এই রিপোতে lazy loading **বন্ধ**
         * (`Model::preventLazyLoading`) — তাই সরাসরি বিক্রয় নিশ্চিত করার
         * পর ছাপার পাতায় গিয়ে `LazyLoadingViolationException`।
         *
         * ⭐ ধরা পড়েছে সত্যিকারের একটা বিক্রয় করে, কারণ পাহারাটা কেবল
         * **চালান-সহ** বিলে জাগে — আর সেটাই কাউন্টারের একমাত্র পথ।
         */
        /* ⓘ `brandRow`-ও সাথে — নাহলে প্রতিটা সারিতে একটা করে কোয়েরি যেত */
        $invoice->load(['lines.product.unit', 'lines.product.brandRow', 'lines.challanLine', 'customer', 'branch']);

        $doc = new PrintableDocument(
            title: __('sales::doc.invoice'),
            meta: $this->invoiceMeta($invoice),
            /*
             * ⭐ ব্র্যান্ড ধরে ভাগ — মালিকের নির্দেশ, ২২ সেপ্টেম্বর ২০২৬।
             *
             * ⚠️ সুইচটা এখনো এখানে বাঁধা (`band: true`) — ছাপার সব সুইচ
             * এক জায়গায় আনার কাজ চলছে (abos-8b, `PrintProfile`), আর
             * সেটা এলে এই লাইনটা ওখান থেকে উত্তর নেবে।
             *
             * ⓘ ততদিন বিলে ভাগটা চালু, আর সেটাই মালিকের চাওয়া — তিনি
             * বিলের কাগজ দেখিয়েই বলেছেন।
             */
            lines: $this->productLines(
                $invoice->lines,
                'qty',
                $this->lotsForInvoice($invoice),
                /*
                 * ⛔ এখানে হাতে লেখা `band: true` ছিল — ২২ সেপ্টেম্বর ২০২৬-এ সরানো।
                 *
                 * ⚠️ তাতে সুইচ দাঁড়াত **দুইটা**: এখানে একটা, আর
                 * [[PrintProfile]]-এ মালিকের একটা। ⓘ মালিক তাঁরটা চালু
                 * করলে কিছুই হত না, কারণ এখানকারটা বন্ধ — আর উল্টোটাও।
                 * ⛔ কোনো ভুল দেখা যেত না, কেবল সুইচটা কাজ করত না, আর
                 * কারণটা দুই ফাইল দূরে।
                 */
                band: $this->profileFor($request, 'sales.print.paper.invoice')->shows('band'),
            ),

            /*
             * ⭐ বিলের নিচে টাকার পুরো গল্প — মালিকের নমুনা, ২২ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ আগে কেবল উপ-মোট, ছাড়, ভ্যাট আর মোট যেত। ⛔ গ্রাহক বিল
             * হাতে নিয়ে সবার আগে যে প্রশ্নটা করেন — *"আমার মোট কত
             * পাওনা?"* — তার উত্তর কাগজে ছিলই না।
             */
            totals: $this->invoiceTotals(
                $invoice,
                roll: PaperSize::of(PaperSize::chosen(
                    $request->query('paper'),
                    $this->settings->get('sales.print.paper.invoice'),
                ))->isThermal,
            ),

            signatures: ['core.print.prepared_by', 'core.print.received_by'],
            narration: $invoice->narration,

            /*
             * ⭐ আদায়ের ছক — মালিকের নমুনা, ২২ সেপ্টেম্বর ২০২৬।
             *
             * ⚠️ সুইচটা এখনো এখানে বাঁধা নয়: [[PrintProfile]]-এর কাজ
             * চলছে (abos-8b), আর ওটা এলে ছকটাও ওখান থেকে উত্তর নেবে।
             * ⓘ ততদিন খালি হলে ছকটা এমনিতেই আঁকা হয় না, তাই যে বিলে
             * একটাও জমা নেই সেখানে কাগজ আগের মতোই থাকে।
             */
            payments: $this->paymentsAgainst($invoice),
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
            paperSetting: 'sales.print.paper.invoice',
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
        /*
         * ⛔ কাউন্টারে আটকে থাকা বিক্রয়ের বিল ছাপা হয় না — মালিকের নিয়ম
         * (১৯ সেপ্টেম্বর): *"কোনো print option আসবে না যতক্ষণ approve হচ্ছে।"*
         * ⓘ বোতামটা পাতায় লুকানো; এটা ঠিকানা টাইপ করে আসার পাহারা।
         */
        if ($invoice->isHeldAtCounter()) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.held_no_print', ['no' => $invoice->document_no]),
            ]);
        }

        /*
         * ⚠️ `lines.challanLine` — নইলে ছাপার পাতা ৫০০ দেয়।
         *
         * ── কী ঘটেছিল (মাপা, ৩ সেপ্টেম্বর ২০২৬) ─────────────────────
         * `lotsForInvoice()` প্রতিটা লাইনের চালান-লাইন ধরে লট খোঁজে।
         * সম্পর্কটা এখানে তোলা হত না, আর এই রিপোতে lazy loading **বন্ধ**
         * (`Model::preventLazyLoading`) — তাই সরাসরি বিক্রয় নিশ্চিত করার
         * পর ছাপার পাতায় গিয়ে `LazyLoadingViolationException`।
         *
         * ⭐ ধরা পড়েছে সত্যিকারের একটা বিক্রয় করে, কারণ পাহারাটা কেবল
         * **চালান-সহ** বিলে জাগে — আর সেটাই কাউন্টারের একমাত্র পথ।
         */
        $invoice->load(['lines.product.unit', 'lines.challanLine', 'customer', 'branch']);

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
            paperSetting: 'sales.print.paper.challan', target: 'challan',
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

        return $this->pdf($request, $doc, '0', $challan->document_no, document: $challan,
            paperSetting: 'sales.print.paper.challan', target: 'challan');
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

        return $this->pdf($request, $doc, (string) $order->total, $order->document_no, document: $order,
            paperSetting: 'sales.print.paper.order', target: 'order');
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

        return $this->pdf($request, $doc, '0', $order->document_no, document: $order,
            paperSetting: 'sales.print.paper.order', target: 'order');
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

        return $this->pdf($request, $doc, (string) $collection->amount, $collection->document_no, document: $collection,
            paperSetting: 'sales.print.paper.receipt', target: 'receipt');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    private function invoiceMeta(SalesInvoice $invoice): array
    {
        $meta = [
            'core.print.document_no' => $invoice->document_no,
            'core.print.date' => DateFormat::format($invoice->trx_date),
            'sales::field.customer' => $invoice->customer?->name() ?? '',
            'sales::field.due_on' => DateFormat::format($invoice->due_on),

            /*
             * ⭐ কয়টা পণ্য আর মোট কত মাল — মালিকের নমুনা, ২২ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ তাঁর পাঠানো বিলে উপরে লেখা থাকে *"Total Item: 15"* আর
             * *"Delivery Qty. 656"*। ⚠️ ডিলারের কাছে মাল নামানোর সময়
             * ওটাই প্রথম মিলিয়ে দেখা হয় — কয়টা আইটেম, মোট কত কার্টন।
             *
             * ⛔ না থাকলে গুদামের লোককে সারি গুনে যোগ করতে হত, আর
             * ত্রিশ সারির বিলে সেটা রোজ ভুল হত।
             *
             * ⓘ সংখ্যা দুইটা **মোটের ঘরে নয়, মাথায়** — ওগুলো টাকা নয়,
             * আর টাকার ঘরে বসালে কাগজে `১৫.০০` ছাপা হত।
             */
            'sales::print.total_item' => (string) $invoice->lines->count(),
            'sales::print.delivery_qty' => $this->qty(
                $invoice->lines->reduce(
                    fn (string $sum, $line) => bcadd($sum, (string) $line->qty, 4),
                    '0',
                )
            ),
        ];

        /*
         * ⭐ পরিবহনের ঘর — মালিকের নমুনা, ২২ সেপ্টেম্বর ২০২৬।
         *
         * ── ⓘ কেন বিলে, অথচ তথ্যটা চালানের ─────────────────────────
         * মাল যায় চালানে, আর গাড়ি-চালকের নামও ওখানেই লেখা। ⚠️ কিন্তু
         * গ্রাহকের হাতে যায় **বিল**, আর তিনি মাল বুঝে নেওয়ার সময়
         * মেলাতে চান কোন গাড়িতে এসেছে।
         *
         * ⛔ তাই ঘরগুলো **যোগ হয় কেবল যদি সত্যিই জানা থাকে** — খালি
         * ঘর ছাপা মানে কাগজে একটা প্রশ্ন, উত্তর নয়। ⓘ কাউন্টারের
         * নগদ বিক্রিতে কোনো চালানই নেই, আর সেখানে "গাড়ি: —" লেখা
         * থাকলে মানুষ খুঁজতেন কোথায় ভুল হলো।
         */
        $challan = $invoice->lines->first()?->challanLine?->challan;

        if ($challan !== null) {
            $plate = $challan->vehiclePlate();
            $driver = (string) ($challan->driver_name ?? '');

            if ($plate !== '' && $plate !== null) {
                $meta['sales::field.vehicle_no'] = $plate;
            }

            if ($driver !== '') {
                $meta['sales::field.driver_name'] = $driver;
            }
        }

        return $meta;
    }

    /**
     * ⓘ টাকার খাতার নাম — একবার তুলে মনে রাখা।
     *
     * ⚠️ ভাউচারে খাতার সম্পর্ক নেই, কেবল `money_account_id` ঘরটা আছে।
     * ⛔ সারি ধরে ধরে কোয়েরি করলে দশটা রসিদে দশটা কোয়েরি লাগত, আর
     * কাগজ ছাপা এমনিতেই ধীর।
     *
     * @var array<int, string>
     */
    private array $accountNames = [];

    private function accountName(int $id): string
    {
        if ($id === 0) {
            return '';
        }

        return $this->accountNames[$id] ??= (string) (
            Account::query()->whereKey($id)->first()?->name() ?? ''
        );
    }

    /**
     * ⭐ এই বিলের বিপরীতে যে টাকাগুলো এসেছে — কাগজেই, সারি ধরে।
     *
     * ── ⓘ মালিকের নমুনা (২২ সেপ্টেম্বর ২০২৬) ─────────────────────────
     * বিলের বাঁ-নিচে একটা ছোট ছক: ক্রম · লেনদেন নম্বর · তারিখ · কোন পথে ·
     * বিবরণ · টাকা। ⚠️ উদ্দেশ্য একটাই — গ্রাহক যেন ফোন করে জিজ্ঞেস না
     * করেন *"আমার ঐ জমাটা বসেছে কি না"*।
     *
     * ── ⛔ সবচেয়ে বড় ফাঁদ, আর সেটা এড়ানোর একমাত্র উপায় ───────────────
     * ⚠️ গ্রাহকের **সব** জমা ছাপলে ছকের যোগফল আর উপরের "পরিশোধ" লাইনটা
     * দুই কথা বলত — আর পাঠক ভাবতেন কোথাও টাকা দুইবার গোনা হয়েছে।
     *
     * ⭐ তাই ছকটা হুবহু সেই দুইটা উৎস থেকেই আসে যেগুলো দিয়ে
     * [[SalesInvoice::collectedAmount()]] অঙ্কটা বানায়:
     *
     *   ১. এই বিলে কাটা আদায়ের সারি (`collectionLines`, খাতায় বসা)
     *   ২. এই বিলের বিপরীতে রসিদ ভাউচার (`receiptVouchers`, খাতায় বসা)
     *
     * ⓘ অর্থাৎ **যোগফল মেলাটা গঠনগত**, কাকতালীয় নয় — আর পাহারাটা ঠিক
     * সেটাই মাপে।
     *
     * @return list<array{no: int, ref: string, date: string, method: string, narration: string, amount: string}>
     */
    private function paymentsAgainst(SalesInvoice $invoice): array
    {
        $rows = [];

        foreach ($invoice->collectionLines()->with('collection.account')->get() as $line) {
            $collection = $line->collection;

            // ⚠️ খসড়া আদায় টাকা নয় — শর্তটা যোগফলেরও হুবহু।
            /*
             * ⛔ [[DocumentStatus::POSTED]] একটা **তালিকা** (`confirmed` +
             * `closed`), একটা মান নয়।
             *
             * ⚠️ প্রথম খসড়ায় `!==` দিয়ে মেলানো হয়েছিল, আর তুলনাটা সবসময়
             * সত্যি হত — অর্থাৎ প্রতিটা সারি বাদ পড়ত আর **ছকটা কাগজে
             * চিরকাল খালি আসত**। ⓘ পাতা ২০০ দিত, দেখতেও ঠিক লাগত; ধরা
             * পড়েছে কেবল *"দুইটা জমা বসালাম, ছকে দুইটা আছে তো?"* প্রশ্নে।
             */
            if ($collection === null || ! in_array($collection->status, DocumentStatus::POSTED, true)) {
                continue;
            }

            $rows[] = [
                'ref' => (string) $collection->document_no,
                'date' => $collection->trx_date,
                'method' => (string) ($collection->account?->name() ?? ''),
                'narration' => (string) ($collection->narration ?? ''),
                'amount' => (string) $line->amount,
            ];
        }

        foreach ($invoice->receiptVouchers()->get() as $voucher) {
            $rows[] = [
                'ref' => (string) $voucher->document_no,
                'date' => $voucher->trx_date,

                /*
                 * ⓘ "কোন পথে" — খাতার নাম, কারণ ওটাই মানুষ চেনে
                 * ("নগদ", "ব্র্যাক ব্যাংক")। ⚠️ যন্ত্রের নাম
                 * (`money_kind`) ছাপলে গ্রাহকের কাছে ওটা কিছুই বলত না।
                 */
                'method' => $this->accountName((int) $voucher->money_account_id),
                'narration' => (string) ($voucher->narration ?? ''),
                'amount' => (string) $voucher->amount,
            ];
        }

        // ⓘ তারিখের ক্রমে — গ্রাহক কাগজটা উপর থেকে নিচে পড়েন।
        usort($rows, fn (array $a, array $b) => [$a['date'], $a['ref']] <=> [$b['date'], $b['ref']]);

        $out = [];

        foreach ($rows as $i => $row) {
            $out[] = [
                'no' => $i + 1,
                'ref' => $row['ref'],
                'date' => DateFormat::format($row['date']),
                'method' => $row['method'],
                'narration' => $row['narration'],
                'amount' => $this->money($row['amount']),
            ];
        }

        return $out;
    }

    /**
     * বিলের নিচের টাকার সারিগুলো — মালিকের নমুনার ক্রমেই।
     *
     * ⓘ উপ-মোট → ছাড় → ভ্যাট → মোট (উপরের [[totals()]] থেকে), তারপর
     * পরিশোধ → এই বিলের বকেয়া → আগের বকেয়া → সব মিলিয়ে পাওনা।
     *
     * ── ⚠️ "আগের বকেয়া" বের করার ফাঁদ ──────────────────────────────
     * গ্রাহকের মোট পাওনার (`outstanding()`) ভিতরে **এই বিলটাও আছে**।
     * ⛔ সরাসরি ছাপলে আজকের বিলটা দুইবার গোনা হত, আর "সব মিলিয়ে"
     * সারিটা সবসময় বেশি দেখাত — নীরবে, কারণ প্রতিটা সংখ্যা আলাদা
     * করে ঠিক।
     *
     * ⓘ তাই বিয়োগ করা হয়, আর ফলটা ঋণাত্মক হলে শূন্য ধরা হয়: গ্রাহক
     * আগাম টাকা দিয়ে রাখলে "আগের বকেয়া −৫,০০০" লেখা কাগজে বিভ্রান্তি
     * ছাড়া কিছু দিত না।
     *
     * @return array<string, string>
     */
    private function invoiceTotals(SalesInvoice $invoice, bool $roll = false): array
    {
        $rows = $this->totals($invoice);

        $paid = $invoice->collectedAmount();
        $due = $invoice->dueAmount();

        if (bccomp($paid, '0', 4) > 0) {
            $rows['sales::print.paid'] = $this->money($paid);
        }

        $rows['sales::print.invoice_due'] = $this->money($due);

        /*
         * ⛔ খতিয়ানের সারি দুইটা সরু রোলে যায় না — ২২ সেপ্টেম্বর ২০২৬।
         *
         * ── ⚠️ কীভাবে ধরা পড়ল ───────────────────────────────────────
         * [[NoPrintedFigureOverflowsItsColumnTest]] লাল হলো: ৮০মিমি
         * রসিদে `12,31,87,500` বসেছে একটা **১৫মিমি** ঘরে। ⓘ সংখ্যাটা
         * এই বিলের নয় — ঐ গ্রাহকের **মোট পাওনা**, যা আজকের বিলের
         * চেয়ে হাজার গুণ বড় হতে পারে।
         *
         * ⛔ আর ভুলটা নীরব: mPDF অভিযোগ করে না, সংখ্যাটা চুপচাপ পাশের
         * ঘরে উঠে যায় আর কাগজটা বেরিয়ে যায়।
         *
         * ⓘ এটা সুইচ নয়, নিয়ম — আর সেটাই ঠিক: থার্মালে কোনো প্রস্থই
         * যথেষ্ট নয়, কারণ বকেয়ার কোনো সীমা নেই। ⚠️ সুইচ বানালে কেউ
         * একদিন চালু করতেন, আর কাগজটা আবার নীরবে ভাঙত।
         *
         * ⭐ কাউন্টারের রসিদে সারি দুইটার দরকারও নেই: ওখানে এখনই
         * মিটিয়ে দেওয়া হয়, আর পুরো খতিয়ান বিলের কাগজের জিনিস।
         */
        $customer = $roll ? null : $invoice->customer;

        if ($customer !== null) {
            $earlier = bcsub($customer->outstanding(), $due, 4);

            if (bccomp($earlier, '0', 4) < 0) {
                $earlier = '0';
            }

            if (bccomp($earlier, '0', 4) > 0) {
                $rows['sales::print.previous_due'] = $this->money($earlier);
            }

            $rows['sales::print.outstanding'] = $this->money(bcadd($earlier, $due, 4));
        }

        return $rows;
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
    /**
     * ⭐ ব্র্যান্ড ধরে ভাগ আর উপ-মোট — মালিকের নির্দেশ, ২২ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ তিনি Univer-এর একটা বিল পাঠিয়ে বললেন: *"এই রকম একটি ফরমেট রাখ
     * যাতে ব্যান্ড ওয়াইজ দেখা যায়"*। ⚠️ ওখানে সারিগুলো ব্র্যান্ড ধরে দল
     * বাঁধা, আর প্রতিটা দলের শেষে একটা করে উপ-মোট।
     *
     * ── ⚠️ উপ-মোট এখানে গোনা হয়, পর্দায় নয় ─────────────────────────
     * ব্লেডে গুনতে হলে **ছাপার জন্য সাজানো লেখা** যোগ করতে হত
     * (`১২,৩৪৫.৬৭`), আর সেটা সংখ্যা নয়। ⓘ এখানে কাঁচা `amount`
     * হাতের কাছেই, তাই যোগটা সঠিক আর একবারই হয়।
     *
     * ⭐ দলের **শেষ সারিতে** সংখ্যাটা বসে, তাই পর্দাকে কোনো হিসাব করতে
     * হয় না — সে কেবল দেখে ঘরটা ভরা কি না।
     *
     * ── ⓘ ব্র্যান্ড না থাকলে ────────────────────────────────────────
     * নাম খালি রাখা হয়, আর তখন ঐ সারিগুলো নিজেরাই একটা দল — ⚠️ সবাইকে
     * "অন্যান্য" নামে ঢোকালে কাগজে এমন একটা শব্দ ছাপা হত যা মালিকের
     * ব্র্যান্ডের তালিকায় নেই।
     */
    private function productLines($lines, string $qtyField, array $lots = [], bool $band = false): array
    {
        /*
         * যে প্যাকে লেখা হয়েছিল সেটাই কাগজে — "২ বাক্স", "২০০ পিস" নয়।
         *
         * গুদামের লোক বাক্স গোনেন, আর ক্রেতা যা চেয়েছিলেন কাগজে সেটাই
         * দেখতে চান। ভেতরের হিসাব পিসেই চলে; এই দুইটা ঘর কেবল চোখের।
         */
        $rows = $lines->map(fn ($line) => [
            /*
             * ⭐ কোড আর নাম আলাদা ঘরে — ২২ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ মালিকের নমুনায় কোডের নিজের কলাম আছে ("Company/Code")।
             * ⚠️ আগে দুইটা এক তারে জোড়া ছিল (`CODE - নাম`), তাই কোডের
             * জন্য আলাদা কলাম বসানোর কোনো উপায়ই ছিল না।
             *
             * ⛔ চলতি কাগজ একটুও বদলায়নি: কোডের কলাম বন্ধ থাকলে
             * [[print/document-body]] কোডটা নামের সাথেই জুড়ে দেয় —
             * নাহলে গুদামের কাগজ থেকে কোডটা নীরবে উধাও হত, আর মাল
             * মেলানো হয় কোড ধরে, নাম ধরে নয়।
             */
            'code' => (string) ($line->product?->code ?? ''),
            'name' => (string) ($line->product?->name() ?? ''),
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

            /*
             * ⭐ ফ্রি পরিমাণ — ১৮ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ খালি হলে [[print/document-body]] কলামটাই আঁকে না,
             * তাই যে ব্যবসায় ফ্রি দেওয়া হয় না তার কাগজ অবিকল
             * আগের মতো।
             */
            'free' => $this->freeOf($line),

            /* ⓘ দলের নাম — খালি হলে পর্দা ভাগটাই আঁকে না */
            'group' => $band ? $this->brandOf($line) : '',
        ])->values()->all();

        return $band ? $this->closeEachBand($rows, $lines) : $rows;
    }

    /**
     * এই সারির ব্র্যান্ডের নাম।
     *
     * ⚠️ `brandRow`, `brand` নয় — পণ্যে দুইটাই আছে, আর পুরনোটা মুক্ত
     * লেখা ([[Product]]-এর মন্তব্যে কারণ)। ⓘ দল বাঁধতে হলে **একই নামের
     * একই বানান** লাগে, আর সেটা কেবল সম্পর্কটাই দেয়।
     */
    private function brandOf($line): string
    {
        return (string) ($line->product?->brandRow?->name() ?? '');
    }

    /**
     * প্রতিটা দলের শেষ সারিতে তার উপ-মোট বসানো।
     *
     * ⚠️ ক্রম বদলানো হয় না — সারিগুলো যেভাবে সাজানো ছিল সেভাবেই থাকে।
     * ⛔ ব্র্যান্ড ধরে সাজিয়ে দিলে বিলের সারির ক্রম বদলে যেত, আর
     * গুদামের লোক যে ক্রমে মাল তুলেছেন কাগজ আর সেই ক্রমে থাকত না।
     *
     * ⓘ তাই দল মানে **পাশাপাশি বসা একই ব্র্যান্ডের সারি**, আর একই
     * ব্র্যান্ড দুই জায়গায় ছড়ালে দুইটা উপ-মোট হবে — যা সত্যি কথাই বলে।
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function closeEachBand(array $rows, $lines): array
    {
        $amounts = $lines->values()->pluck('amount')->all();
        $running = '0';

        foreach ($rows as $i => $row) {
            $running = bcadd($running, (string) ($amounts[$i] ?? '0'), 4);

            $lastOfBand = ! isset($rows[$i + 1]) || $rows[$i + 1]['group'] !== $row['group'];

            if (! $lastOfBand) {
                continue;
            }

            $rows[$i]['band_total'] = $this->money($running);
            $running = '0';
        }

        return $rows;
    }

    /**
     * এই সারিতে ফ্রি কতটা — কাগজে দেখানোর জন্য।
     *
     * ── ⭐ কেন তিন জায়গায় খোঁজা হয়, ১৮ সেপ্টেম্বর ২০২৬ ──────────────
     * মালিকের নির্দেশ: *"ইনভয়েস প্রিন্টিংয়ে ফ্রি আলাদা দেখাতে হবে।"*
     *
     * ⚠️ কিন্তু ফ্রি পরিমাণটা সব সারিতে থাকে না। ⓘ `sal_challan_lines`-এ
     * `free_qty` কলামটা আছে; **`sal_invoice_lines`-এ নেই** — চালানের
     * বিলে ফ্রি-টা তার চালানের সারি থেকেই আসে, আর ছাপার কন্ট্রোলার
     * `lines.challanLine` এমনিতেই সাথে তোলে।
     *
     * ⛔ `method_exists()` পরীক্ষাটা বাদ দেওয়া যায় না: ক্রয়াদেশের
     * সারিতে ঐ সম্পর্কটা নেই, আর না দেখে ডাকলে `BadMethodCallException`
     * হয়ে গোটা ছাপার পাতা ৫০০ দিত।
     *
     * ⓘ শূন্য মানে খালি লেখা, "0" নয় — কাগজে শূন্যের কলাম কেবল জায়গা
     * নেয়, আর সরু রোলে জায়গাটাই সবচেয়ে দামি।
     */
    private function freeOf(object $line): string
    {
        $free = $line->free_qty
            ?? (method_exists($line, 'challanLine') ? $line->challanLine?->free_qty : null);

        if ($free === null || bccomp((string) $free, '0', 4) <= 0) {
            return '';
        }

        return $this->qty($free);
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
     * এই অনুরোধে কোন কাগজের সুইচগুলো মানা হবে।
     *
     * ── ⭐ কেন থার্মাল হলে "পস" — মালিকের নির্দেশ, ২২ সেপ্টেম্বর ২০২৬ ──
     * *"ইনভয়েজে কি লোগো দেবে পস প্রিন্টারে কি লোগো দেবে"*।
     *
     * ⓘ বিল আর কাউন্টারের রসিদ একই রুট দিয়ে বেরোয় — পস পর্দা
     * `sales.print.invoice`-এ `paper=80mm` দিয়ে পাঠায়। ⚠️ তাই "কোন
     * কাগজ" প্রশ্নের উত্তর রুটে নেই, **কাগজের মাপে আছে**।
     *
     * ⛔ একটাই প্রোফাইল দিলে লোগো নিয়ে সিদ্ধান্তটা অসম্ভব হত: A4-তে
     * লোগো চাই, ৫৮মিমি রোলে ওটা একটা ধূসর দাগ।
     */
    private function profileFor(Request $request, string $paperSetting, string $target = 'invoice'): PrintProfile
    {
        $paper = PaperSize::chosen($request->query('paper'), $this->settings->get($paperSetting));

        if ($target === 'invoice' && PaperSize::of($paper)->isThermal) {
            $target = 'pos';
        }

        return PrintProfile::for($target, $this->settings);
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
        string $paperSetting = 'sales.print.paper.invoice',

        /* ⓘ কোন কাগজের সুইচ — [[profileFor()]] থার্মাল হলে নিজেই "পস" বানায় */
        string $target = 'invoice',
    ): Response {
        /*
         * ⭐ কাগজের মাপ: ঠিকানায় যা চাওয়া হয়েছে, নয়তো মালিকের বসানো মাপ।
         * ⓘ কারণটা [[PaperSize::chosen()]]-এ — আগে এখানে হাতে লেখা A4 ছিল।
         */
        $paper = PaperSize::chosen($request->query('paper'), $this->settings->get($paperSetting));

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
            profile: $this->profileFor($request, $paperSetting, $target)->target,

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

        /*
         * ⭐ কাগজটা কোথায় গেল — ছাপা নাকি ফাইল হয়ে নামানো (২০ সেপ্টেম্বর ২০২৬)।
         *
         * ⓘ মালিকের চাওয়া: *"কয়টা কাগজ প্রিন্ট হল কয়টা শেয়ার হইল এটা যাতে
         * একটা হিসাব থাকে"*, আর *"sathe pdf o zate dwa zay"*। ⚠️ দুইটা আলাদা
         * গোনা হয়, কারণ "ছেপে দিয়েছি" আর "ফাইল পাঠিয়েছি" এক কথা নয়।
         *
         * ⛔ একই আঁকা, দুইটা পথ নয়: উপরের `$pdf` যা, নামানো ফাইলও তা-ই।
         * গ্রাহকের কপি আর আমাদের কপি আলাদা হওয়ার পথটাই বন্ধ।
         */
        $asFile = $request->boolean('download');

        if ($type !== null && $id !== null) {
            $this->trail->record(
                $type, $id, $paper,
                $asFile ? DocumentDelivery::DOWNLOADED : DocumentDelivery::PRINTED,
                $documentNo,
            );
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($asFile ? 'attachment' : 'inline').'; filename="'.$documentNo.'.pdf"',
        ]);
    }
}
