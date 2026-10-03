<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintableDocument;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Engines\Print\PrintProfile;
use App\Core\Services\PaperTrail;
use App\Core\Services\BranchSettings;
use App\Core\Services\SettingsService;
use App\Core\Support\DateFormat;
use App\Core\Support\AmountInWords;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Models\DocumentDelivery;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Inventory\Services\IssuedLots;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Models\PrintJob;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Sales\Services\PrintQueue;
use App\Modules\Sales\Support\ChallanPaperFacts;
use App\Modules\Sales\Support\InvoiceDesigns;
use App\Modules\Sales\Support\OrderPaperFacts;
use App\Modules\Sales\Support\PaperDesigns;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Route;
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

        // ⭐ কাগজের শাখার মাপ, নকশা, মাথার তথ্য, সই, পাদটীকা, লোগো — না বসালে কোম্পানির
        private readonly BranchSettings $branch,
    ) {}

    /**
     * ⭐ প্রতিটা কাগজ তার নিজের শাখার সেটিংয়ে আঁকা — মালিক, ৩০ সেপ্টেম্বর ২০২৬:
     * *"protiti branch er jonno alada alada hobe … karon alada alada branch e alada type business hote pare"*।
     *
     * ⓘ এক জায়গায়, প্রতিটা কাজের আগে: রুটে বাঁধা ডকুমেন্টের শাখা ধরে [[BranchSettings::during()]]।
     * ⚠️ কেবল `pdf()` মোড়ালে চলত না — নকশা আর মাপ তার **আগেই** পড়া হয় (invoice(), paperDesign())।
     * ডকুমেন্টে শাখা না থাকলে কোম্পানির সেটিং, আগের মতোই।
     *
     * @param  array<string, mixed>  $parameters
     */
    public function callAction($method, $parameters): mixed
    {
        $document = collect($parameters)->first(
            fn ($value) => $value instanceof \Illuminate\Database\Eloquent\Model && $value->getAttribute('branch_id') !== null,
        );

        return $this->branch->during(
            $document === null ? null : (int) $document->getAttribute('branch_id'),
            fn () => $this->{$method}(...array_values($parameters)),
        );
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.invoice.view', only: ['invoice', 'draft']),
            new Middleware('can:sales.challan.view', only: ['challan', 'gatepass']),
            new Middleware('can:sales.gate_pass.view', only: ['gatePassDocument']),
            new Middleware('can:sales.shipment.view', only: ['loadingSheet']),
            new Middleware('can:sales.order.view', only: ['order', 'deliveryOrder']),
            new Middleware('can:sales.collection.view', only: ['receipt']),
        ];
    }

    public function invoice(Request $request, SalesInvoice $invoice): Response
    {
        /*
         * ⛔ খসড়া বিল ছাপা হয় না — মালিকের নিয়ম।
         *
         * ১৯ সেপ্টেম্বর: *"কোনো print option আসবে না যতক্ষণ approve হচ্ছে।"*
         * ২৫ সেপ্টেম্বর, আরও সোজা করে: *"খসড়া print hobe na"*।
         *
         * ── ⚠️ শর্তটা বদলেছে, আর কারণটা জরুরি ──────────────────────
         * আগে এখানে [[SalesInvoice::isHeldAtCounter()]] ছিল, আর সে
         * **দুইটা** শর্ত মেলাত: খসড়া *আর* সইয়ের অপেক্ষায় একটা জমা।
         * ⛔ কিন্তু খসড়া হওয়ার এখন দুইটা পথ, আর "খসড়া রাখুন" বোতামে
         * বানানো কাগজে কোনো জমাই থাকে না — তাই ওটা পাহারা পেরিয়ে
         * যেত। ⓘ পাহারাটা ঘটনা ধরে লেখা ছিল, অবস্থা ধরে নয়।
         *
         * ⓘ বোতামটা পাতায় লুকানো; এটা ঠিকানা টাইপ করে আসার পাহারা।
         */
        if ($invoice->isNotFinalYet()) {
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
            // ⭐ পণ্যের কোড বিলের সুইচে (মালিক, ৩ অক্টোবর ২০২৬) — [[withoutCodeUnlessShown()]]
            lines: $this->withoutCodeUnlessShown(fn (string $what) => app(\App\Modules\Sales\Support\InvoicePrintLook::class)->shows($what), $this->productLines(
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
            )),

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
                    $this->branch->get('sales.print.paper.invoice'),
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
        /*
         * ⭐ ক্লাসিক টেবিল ইনভয়েস — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ একই `$doc`, একই সারিতে ওঠা, একই DUPLICATE ও "বাতিল" — কেবল
         * ছাঁচটা আলাদা। ⛔ আলাদা পথ বানালে একদিন নতুন নকশার বিলে
         * DUPLICATE বসত না, আর সেটা ধরা পড়ত কেবল দুইবার টাকা চাওয়ার দিনে।
         *
         * ⚠️ থার্মালে সবসময় চলতি রসিদ: তিন কলামের মাথা ৮০মিমিতে ধরে না।
         */
        /*
         * ⭐ ছাঁচ আসে কাগজ-মাপের নিজের বাছাই থেকে ([[PaperDesigns]]) — মালিক, ৩০ সেপ্টেম্বর ২০২৬:
         * A4 · A5 · থার্মাল তিনটারই নিজের নকশা। `standard` বা অচেনা → চলতি কাগজ।
         */
        $chosenPaper = PaperSize::chosen($request->query('paper'), $this->branch->get('sales.print.paper.invoice'));
        $designSize = PaperDesigns::sizeOf($chosenPaper, PaperSize::of($chosenPaper)->isThermal);
        $designTemplate = PaperDesigns::template(
            'invoice', $designSize,
            (string) $this->branch->get(PaperDesigns::key('invoice', $designSize)),
        );
        $classic = $designTemplate !== null;

        return $this->pdf(
            $request, $doc, (string) $invoice->total, $invoice->document_no,
            type: PrintJob::INVOICE, id: $invoice->id, document: $invoice,
            paperSetting: 'sales.print.paper.invoice',
            template: $classic ? $designTemplate : 'print.document',
            extra: $classic ? ['facts' => $this->classicFacts($invoice)] : [],
        );
    }

    /**
     * ⭐ চালানের বাছা নকশা — মালিক, ৩০ সেপ্টেম্বর ২০২৬ (abos-3c-র ছাঁচ, [[ChallanPaperFacts]])।
     *
     * ⓘ `standard` বা অচেনা হলে কিছুই নয় — তখন চলতি চালান (`print.document`), আগের মতোই।
     *
     * @return array{template?: string, extra?: array<string, mixed>}
     */
    private function challanDesign(Request $request, DeliveryChallan $challan, ?array $money = null): array
    {
        return $this->paperDesign($request, 'challan', 'sales.print.paper.challan',
            fn () => [...ChallanPaperFacts::of($challan), ...($money ?? [])]);
    }

    /**
     * ⭐ টাকাসহ চালান = ইনভয়েসের হুবহু হিসাব — মালিক, ২ অক্টোবর ২০২৬: *"Amount print hole Invoice er same same hisab thakbe"*।
     *
     * ⛔ চালানের নিজের `total` কেবল পরিমাণ × দর — ছাড়, ভ্যাট, রাউন্ডিং ধরে না; তাই সেটা ইনভয়েসের মোটের সাথে মেলে না।
     * ⓘ চালানের ইনভয়েস থাকলে তার নিজের হিসাব, বিলের কাগজের একই কোডে ([[totals()]]): সারির যোগ (চালানের), তারপর
     * ছাড়/ভ্যাট/রাউন্ডিংয়ের সারি, আর শেষে মোট ও কথায় = ইনভয়েসের মোট। ইনভয়েস না থাকলে (অফিসের পুরনো চালান) চালানেরটাই।
     *
     * @return array{lines_total: string, money_rows: array<string, string>, total: string, words: string, words_bn: string}|array{}
     */
    private function challanMoney(DeliveryChallan $challan): array
    {
        $invoice = SalesInvoice::query()
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->whereHas('lines.challanLine', fn ($q) => $q->where('delivery_challan_id', $challan->id))
            ->first();

        if ($invoice === null) {
            return [];
        }

        // ⓘ উপমোট থেকে রাউন্ডিং পর্যন্ত ইনভয়েসের সারি; মোটটা আলাদা ঘরে (নকশার মোটের বাক্স)
        $rows = $this->totals($invoice);
        unset($rows['core.print.total']);

        return [
            'lines_total' => $this->money($challan->total),
            'money_rows' => $rows,
            'total' => $this->money($invoice->total),
            'words' => AmountInWords::of((string) $invoice->total, 'en'),
            'words_bn' => AmountInWords::of((string) $invoice->total, 'bn'),
        ];
    }

    /**
     * একটা কাগজের বাছা নকশা — চালান, অর্ডার, আদায় রসিদ ([[PaperDesigns]])।
     *
     * ⓘ facts কেবল নকশা বাছা থাকলে গোনা হয় (closure): চলতি কাগজে ঐ হিসাবের দরকারই নেই।
     *
     * @return array{template?: string, extra?: array<string, mixed>}
     */
    private function paperDesign(Request $request, string $kind, string $paperSetting, \Closure $facts): array
    {
        $paper = PaperSize::chosen($request->query('paper'), $this->branch->get($paperSetting));
        $size = PaperDesigns::sizeOf($paper, PaperSize::of($paper)->isThermal);
        $template = PaperDesigns::template($kind, $size, (string) $this->branch->get(PaperDesigns::key($kind, $size)));

        return $template === null ? [] : ['template' => $template, 'extra' => ['facts' => $facts()]];
    }

    /**
     * ক্লাসিক বিলের মাথার তিন কলাম — কাকে · কোন গাড়িতে · কোন বিল।
     *
     * ── ⚠️ না-জানা ঘর খালি থাকে, আন্দাজে ভরে না ─────────────────────────
     * ⓘ কাউন্টারের নগদ বিক্রিতে চালানই নেই — তখন পরিবহনের ঘরগুলো খালি।
     * ⛔ "নগদ/বাকি" ঘরটাও কেবল চালানে লেখা শর্ত থেকে; পুরনো চালানে শর্ত
     * লেখা নেই, আর তখন বকেয়া দেখে "বাকি" লিখে দিলে পরে-শোধ-করা বাকির
     * বিল "নগদ" ছাপত — কাগজ একটা মিথ্যা বলত।
     *
     * @return array{bill_to: array<string, string>, transport: array<string, string>, bill: array<string, string>, total_items: string, total_delivery: string}
     */
    private function classicFacts(SalesInvoice $invoice): array
    {
        $invoice->loadMissing([
            'customer.location.parent',
            'creator',
            'lines.challanLine.challan.vehicle.vehicleType',
            'lines.challanLine.challan.order',
        ]);

        $customer = $invoice->customer;
        $challan = $invoice->lines->first()?->challanLine?->challan;

        /*
         * ⓘ পয়েন্ট — গ্রাহক পয়েন্টে বসলে সেটাই, রুটে বসলে তার উপরেরটা।
         * ⚠️ [[Customer::ladderNode()]] ডাকা হয়নি: সে গাছ বেয়ে যত উপরে
         * দরকার ওঠে, আর এই রিপোতে lazy loading বন্ধ — এক ধাপ বেশি উঠলেই
         * ছাপার পাতা ৫০০।
         */
        $node = $customer?->location;
        $point = match (true) {
            $node?->level === Location::POINT => $node,
            $node?->level === Location::ROUTE && $node->parent?->level === Location::POINT => $node->parent,
            default => null,
        };

        $vehicle = $challan === null ? '' : trim(implode(' ', array_filter([
            (string) ($challan->vehicle?->vehicleType?->name() ?? ''),
            (string) $challan->vehiclePlate(),
        ])));

        /*
         * ⓘ CASH / CREDIT — চালানে লেখা শর্ত থেকে; শর্ত না থাকলে বিলের নিজের মেয়াদ থেকে
         * (মেয়াদ বিলের তারিখের পরে → CREDIT, নাহলে CASH)। ⚠️ দুইটাই কাগজের নিজের কথা —
         * বকেয়া দেখে আন্দাজ নয়: পরে শোধ হওয়া বাকির বিল তখনো CREDIT থাকে।
         */
        $term = (string) ($challan?->payment_term ?? '');
        $credit = match (true) {
            in_array($term, ['cash', 'cod'], true) => false,
            $term !== '' => true,
            default => $invoice->due_on !== null && $invoice->trx_date !== null
                && $invoice->due_on->greaterThan($invoice->trx_date),
        };
        $type = __('sales::print.classic.'.($credit ? 'credit' : 'cash'), [], 'en');

        /*
         * ⭐ টাকার সারি — মালিকের নমুনার ক্রমে, সবসময় একই সারি (২৯ সেপ্টেম্বর ২০২৬)।
         * ⓘ কাঁচা অঙ্ক থেকে, `$doc->totals`-এর লেখা থেকে নয় — নমুনায় "Discount" আর
         * "Rounding" শূন্য হলেও থাকে, আর চলতি নকশা শূন্য সারি বাদ দেয়। ⚠️ আগের বকেয়া আর
         * পরিশোধ একই হিসাবের ([[earlierDue()]], `collectedAmount()`) — দুই নকশা এক কথা বলে।
         */
        $paid = $invoice->collectedAmount();
        $due = $invoice->dueAmount();
        $earlier = $this->earlierDue($invoice, $due);

        $sums = [
            'grand_total' => (string) $invoice->subtotal,
            'discount' => bcadd((string) ($invoice->discount ?? '0'), (string) ($invoice->bill_discount ?? '0'), 4),
            'vat' => (string) ($invoice->tax ?? '0'),
            'rounding' => (string) ($invoice->rounding_amount ?? '0'),
            'net_payable' => (string) $invoice->total,
            'paid' => $paid,
            'invoice_due' => $due,
            'previous_due' => $earlier,
            /* ⓘ গ্রাহকের আসল জের — অগ্রিম থাকলে ঋণাত্মক; আগের + এই বিলের বাকি = এটাই ([[earlierDue()]]) */
            'outstanding' => $invoice->customer !== null ? (string) $invoice->customer->outstanding() : bcadd($earlier, $due, 4),
        ];

        /*
         * ⭐ বকেয়া না অগ্রিম — মালিক, ৩ অক্টোবর ২০২৬: *"Outstanding due hole Outstanding (Due), r advance thakle
         * Outstanding (Advance)"*। ⓘ অঙ্কটা নিজের চিহ্নেই ছাপে (অগ্রিম ঋণাত্মক), নামটাও বলে কোন দিকে। নকশাগুলো একই চাবি
         * (`total_due`) পড়ে, তাই অগ্রিমের বেলায় এই কাগজের জন্যই নামটা বদলানো — ৫০টা নকশা ছুঁতে হয় না।
         */
        // ⓘ চিহ্ন যেমন আছে তেমন — মালিক, ৩ অক্টোবর ২০২৬: "na renatok hole renatok ei hobe" (অগ্রিম −৮,৭৮৯.০০)
        $advance = bccomp($sums['outstanding'], '0', 4) < 0;
        $earlierAdvance = bccomp($earlier, '0', 4) < 0;

        /* ⚠️ প্রতিবার বসানো, দুই দিকেই — এক প্রসেসে (কিউ, পরীক্ষা) পরের বিলে আগের "অগ্রিম" থেকে না যায় */
        foreach (['en', 'bn'] as $locale) {
            app('translator')->addLines(
                [
                    'print.classic.total_due' => (string) __('sales::print.classic.'.($advance ? 'total_advance' : 'total_owed'), [], $locale),
                    /* ⭐ আগের সারিও — মালিক, ৩ অক্টোবর ২০২৬: "Previous Due na ese Previous Advance aste hobe" */
                    'print.classic.previous_due' => (string) __('sales::print.classic.'.($earlierAdvance ? 'previous_advance' : 'previous_owed'), [], $locale),
                ],
                $locale, 'sales',
            );
        }

        return [
            'bill_to' => [
                'name' => (string) ($customer?->name('en') ?? ''),
                'point' => (string) ($point?->name('en') ?? ''),
                'phone' => (string) ($customer?->phone ?? ''),
                'address' => (string) ($customer?->address('en') ?? ''),
            ],
            'transport' => [
                'carrier' => (string) ($challan?->carrier_name ?? ''),
                'driver_phone' => (string) ($challan?->driver_phone ?? ''),
                'vehicle' => $vehicle,
                'delivery_date' => $challan === null
                    ? ''
                    : DateFormat::format($challan->ship_date ?? $challan->trx_date),
            ],
            'bill' => [
                'bill_date' => DateFormat::format($invoice->trx_date),
                'bill_no' => (string) $invoice->document_no,
                'order_no' => (string) ($challan?->order?->document_no ?? ''),
                'type' => $type,
                'created_by' => (string) ($invoice->creator?->name ?? ''),
            ],
            'total_items' => (string) $invoice->lines->count(),
            'total_delivery' => $this->qty($invoice->lines->reduce(
                fn (string $sum, $line) => bcadd($sum, (string) $line->packedQty('qty'), 4),
                '0',
            )),
            'items' => $this->classicItems($invoice),
            'sums' => array_map(fn (string $v) => $this->money($v), $sums),
            'words' => $this->sampleWords((string) $invoice->total),

            /*
             * ⭐ কাগজের QR — মালিক, ৩০ সেপ্টেম্বর ২০২৬: স্ক্যান করে কর্মী ডেলিভারির ধাপ দেন, ডিলার
             * নিজের হিসাব দেখে মাল পাওয়া নিশ্চিত করেন ([[DeliveryScanController]])। ⓘ লিংকে কেবল
             * চালানের `public_id` — দাম নেই, টোকেন নেই, আর খুলতে লগইন লাগে। কাউন্টারের বিলে চালান
             * নেই, তখন খালি, আর ছাঁচ QR আঁকে না।
             */
            // ⭐ সই-করা টোকেন (১ অক্টোবর ২০২৬) — [[PaperToken]]
            'scan_url' => $challan?->public_id !== null && Route::has('sales.qr')
                ? route('sales.qr', app(\App\Modules\Sales\Services\PaperToken::class)->for($challan))
                : '',
        ];
    }

    /**
     * ⭐ নমুনার পণ্যের সারি — মালিকের দাগানো ক্রমে: দর · পরিমাণ · ফ্রি · মোট পরিমাণ · টাকা।
     *
     * ⓘ চলতি নকশার সারি ([[productLines()]]) কোড আর লট জুড়ে দেয় — নমুনায় ওগুলো নেই
     * (মালিক: "100% same")। ⚠️ একক লেখা হয় এককের কোড থেকে (CTN → Ctn), নমুনার মতো;
     * কোড না থাকলে ইংরেজি নাম।
     *
     * ── ⚠️ ফ্রি কোন এককে ─────────────────────────────────────────────
     * বিক্রির পরিমাণ লেখা থাকে প্যাকে ("10 Ctn"), কিন্তু ফ্রি জমা থাকে ভিত্তি এককে
     * (পিস)। ⛔ দুইটা সরাসরি যোগ করলে "10 Ctn + 24 পিস = 34" — মিথ্যা অঙ্ক। ⭐ তাই ফ্রি-কে
     * সারির নিজের অনুপাতে প্যাকে নামানো হয় (প্রবেশের পরিমাণ ÷ ভিত্তি পরিমাণ), তারপর যোগ।
     *
     * @return array{rows: list<array{name: string, rate: string, qty: string, free: string, total_qty: string, amount: string}>, totals: array{qty: string, free: string, total_qty: string, amount: string}}
     */
    private function classicItems(SalesInvoice $invoice): array
    {
        $invoice->loadMissing(['lines.product.unit', 'lines.enteredUnit', 'lines.challanLine']);

        $rows = [];
        $sum = ['qty' => [], 'free' => [], 'total_qty' => []];
        $amount = '0';

        foreach ($invoice->lines->values() as $line) {
            $unit = $line->wasEnteredInAPack() ? $line->enteredUnit : $line->product?->unit;
            $short = filled($unit?->code) ? ucfirst(strtolower((string) $unit->code)) : (string) ($unit?->name('en') ?? '');

            $qty = $line->packedQty('qty');

            $freeBase = (string) ($line->free_qty ?? $line->challanLine?->free_qty ?? '0');
            $free = $line->wasEnteredInAPack() && bccomp((string) $line->qty, '0', 6) !== 0
                ? bcdiv(bcmul($freeBase, (string) $line->entered_qty, 8), (string) $line->qty, 4)
                : $freeBase;

            $total = bcadd($qty, $free, 4);

            foreach (['qty' => $qty, 'free' => $free, 'total_qty' => $total] as $key => $value) {
                $sum[$key][$short] = bcadd($sum[$key][$short] ?? '0', $value, 4);
            }

            /*
             * ⓘ সারির টাকা = পরিমাণ × দর, ছাড়ের আগে — নমুনার মতো ছাড় বসে নিচের টাকার সারিতে।
             * ⚠️ তাই নিচের "Grand Total" সারি আর ডানের "Grand Total" (`subtotal`) একই অঙ্ক বলে।
             */
            $gross = bcmul((string) $line->qty, (string) $line->rate, 4);
            $amount = bcadd($amount, $gross, 4);

            $rows[] = [
                'name' => (string) ($line->product?->name('en') ?? ''),
                'rate' => $this->money($line->packedRate('rate', 'qty')),
                'qty' => trim($this->qty($qty).' '.$short),
                'free' => bccomp($free, '0', 4) > 0 ? trim($this->qty($free).' '.$short) : '',
                'total_qty' => trim($this->qty($total).' '.$short),
                'amount' => $this->money($gross),
            ];
        }

        /*
         * ⓘ নিচের "Grand Total" সারি — এককভেদে আলাদা যোগ ("12 Ctn, 7 Pcs")।
         * ⚠️ ভিত্তি এককে নামিয়ে এক অঙ্ক বানানো যেত, কিন্তু তখন কাগজে "১৪৪ পিস" লেখা
         * থাকত যেখানে উপরের সারিতে "১২ Ctn" — চোখে মেলানো যেত না।
         */
        $joined = fn (array $byUnit) => implode(', ', array_map(
            fn (string $unit, string $value) => trim($this->qty($value).' '.$unit),
            array_keys(array_filter($byUnit, fn (string $v) => bccomp($v, '0', 4) > 0)),
            array_values(array_filter($byUnit, fn (string $v) => bccomp($v, '0', 4) > 0)),
        ));

        return [
            'rows' => $rows,
            'totals' => [
                'qty' => $joined($sum['qty']),
                'free' => $joined($sum['free']),
                'total_qty' => $joined($sum['total_qty']),
                'amount' => $this->money($amount),
            ],
        ];
    }

    /**
     * ⭐ কথায় অঙ্ক — নমুনার ধাঁচে: "One Lac Fifty Two Thousand … (BDT)"।
     *
     * ⓘ সংখ্যা থেকে শব্দ একটাই জায়গায় ([[AmountInWords]], লাখ-কোটি); এখানে কেবল চেহারা
     * বদলায় — প্রতিটা শব্দ বড় হাতে, "lakh" → "Lac", হাইফেন নয়, "taka … only" নয়, শেষে
     * "(BDT)"। ⛔ দ্বিতীয় একটা সংখ্যা-থেকে-শব্দ লিখলে একদিন দুই কাগজে একই অঙ্কের দুই
     * রকম কথা ছাপা হত।
     */
    private function sampleWords(string $amount): string
    {
        $words = \App\Core\Support\AmountInWords::of($amount, 'en');
        $words = (string) preg_replace('/\s+only$/i', '', trim($words));
        $words = (string) preg_replace('/\btaka\b\s*/i', '', $words);
        $words = str_replace('-', ' ', $words);
        $words = ucwords(strtolower(trim((string) preg_replace('/\s+/', ' ', $words))));
        $words = (string) preg_replace('/\bLakh\b/', 'Lac', $words);

        return $words.' (BDT)';
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
         * ⛔ খসড়া বিল ছাপা হয় না — মালিকের নিয়ম, ২৫ সেপ্টেম্বর ২০২৬:
         * *"খসড়া print hobe na"*।
         *
         * ── ⚠️ এই দরজাটা জলছাপ দেওয়া, তবু বন্ধ ─────────────────────
         * ⓘ এখানকার কাগজে *"চূড়ান্ত নয়"* লেখা থাকে। ⛔ কিন্তু কাউন্টারে
         * ছাপা কাগজটা গ্রাহকের হাতে যায়, আর কেউ জলছাপ পড়ে না — কাগজ
         * হাতে পেলে মানুষ ধরে নেন কাজটা হয়ে গেছে।
         *
         * ⚠️ শর্তটা আগে [[isHeldAtCounter()]] ছিল, আর সে সইয়ের অপেক্ষায়
         * থাকা জমা খুঁজত — "খসড়া রাখুন" বোতামের কাগজে যেটা নেই।
         */
        if ($invoice->isNotFinalYet()) {
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
            lines: $this->withoutCodeUnlessShown(fn (string $what) => app(\App\Modules\Sales\Support\InvoicePrintLook::class)->shows($what),
                $this->productLines($invoice->lines, 'qty', $this->lotsForInvoice($invoice))),
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
        $this->assertNotAnUnfinishedCounterSale($challan);

        $challan->load(['lines.product.unit', 'customer', 'warehouse']);

        /*
         * ⭐ টাকাসহ না টাকা ছাড়া — মালিক, ২ অক্টোবর ২০২৬: *"Challan Print er age Amount soho print hobe na amount chara"*।
         * ⓘ ছাপার বোতাম (`?prices=1|0`) বললে সেটাই; না বললে শাখার চালানের সুইচ ([[InvoicePrintLook::challanShows()]])।
         * ⓘ কোনটা ছাপা হলো, ছাপার খাতায় লেখা থাকে ([[PaperTrail::record()]] — `variant`)।
         */
        $look = app(\App\Modules\Sales\Support\InvoicePrintLook::class);
        $chosen = $request->has('prices') ? $request->boolean('prices') : null;
        $withMoney = $chosen ?? $look->challanShows('prices');
        $money = $withMoney ? $this->challanMoney($challan) : [];
        $request->attributes->set('print_variant', $withMoney ? 'with_amounts' : 'without_amounts');

        $lines = $this->productLines(
            $challan->lines,
            'delivered_qty',
            // ⓘ লট ও মেয়াদ — চালানের সুইচে (ডিফল্ট চালু, লট সবসময়)
            $look->challanShows('lot') ? $this->lots->forDocument(DeliveryChallan::STOCK_SOURCE, $challan->id) : [],
        );

        // ⭐ পণ্যের কোড — সুইচ বন্ধ থাকলে কাগজের কোথাও নয় (মালিক, ৩ অক্টোবর ২০২৬: *"product id dewar dorkar nai"*)
        $lines = $this->withoutCodeUnlessShown(fn (string $what) => $look->challanShows($what), $lines);

        $doc = new PrintableDocument(
            title: __('sales::doc.challan'),
            meta: $this->challanMeta($challan),
            lines: $lines,
            totals: $money === []
                ? ['core.print.total' => $this->money($challan->total)]
                : [...$money['money_rows'], 'core.print.total' => $money['total']],
            signatures: ['core.print.delivered_by', 'core.print.driver', 'core.print.received_by'],
            showMoney: $withMoney,
            narration: $challan->narration,
            pricesChosen: $chosen,
        );

        $design = $this->challanDesign($request, $challan, [
            ...$money,
            // ⓘ চালানের সুইচগুলো নকশার কাছে — কী আঁকবে, কী নয়
            'shows' => array_combine(
                \App\Modules\Sales\Support\InvoicePrintLook::CHALLAN_SHOWS,
                array_map(fn (string $what) => $look->challanShows($what), \App\Modules\Sales\Support\InvoicePrintLook::CHALLAN_SHOWS),
            ),
        ]);

        // চালানও — একই কারণে: দুইটা একরকম চালান মানে দুইবার মাল দাবি
        return $this->pdf(
            $request, $doc, (string) $challan->total, $challan->document_no,
            type: PrintJob::CHALLAN, id: $challan->id, document: $challan,
            paperSetting: 'sales.print.paper.challan', target: 'challan',
            template: $design['template'] ?? 'print.document',
            extra: $design['extra'] ?? [],
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
        $this->assertNotAnUnfinishedCounterSale($challan);

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

    /**
     * ⭐ লোডিং শিট — ট্রিপ ধরে গাড়িতে যা উঠবে, প্রতিটা চালানের প্রতিটা সারি ([[LoadingSheetController]])।
     * ⓘ দাম নেই — মাল তোলার লোক গোনেন, দাম তাঁর কাজ নয়।
     */
    public function loadingSheet(Request $request, Shipment $shipment): Response
    {
        $shipment->load(['lines.challan.customer', 'lines.challan.lines.product.unit']);

        $lines = $shipment->lines
            ->flatMap(fn ($tripLine) => $tripLine->challan?->lines ?? collect())
            ->values();

        $doc = new PrintableDocument(
            title: __('sales::loading.title'),
            meta: [
                'core.print.document_no' => $shipment->document_no,
                'core.print.date' => DateFormat::format($shipment->trx_date),
                'sales::field.vehicle_no' => (string) $shipment->vehicle_no,
                'sales::field.driver_name' => (string) $shipment->driver_name,
                'sales::loading.challan_list' => $shipment->lines
                    ->map(fn ($l) => trim(($l->challan?->document_no ?? '').' '.($l->challan?->customer?->name() ?? '')))
                    ->implode(' · '),
            ],
            lines: $this->productLines($lines, 'delivered_qty', []),
            signatures: ['core.print.storekeeper', 'core.print.driver'],
            showMoney: false,
            notice: __('core.print.no_price_notice'),
        );

        return $this->pdf($request, $doc, '0', (string) $shipment->document_no, document: $shipment,
            paperSetting: 'sales.print.paper.challan', target: 'challan');
    }

    /**
     * ⭐ গেট পাস — নিজের কাগজ, রওনার মুহূর্তে তৈরি ([[GatePassService]]), আধা পাতায় (A5)।
     *
     * ⓘ দাম নেই — দারোয়ান মেলান গাড়িতে যা আছে কাগজে তা-ই কি না। পণ্য, পরিমাণ আর ফ্রি চালানের
     * সারি থেকে; গাড়ি, চালক আর কে কখন দিলেন — গেট পাসের নিজের ছবি থেকে। বাতিল হলে পাতায়
     * "বাতিল" লেখা পড়ে ([[pdf()]])।
     */
    public function gatePassDocument(Request $request, GatePass $gatePass): Response
    {
        $challan = $gatePass->challan()->with(['lines.product.unit', 'customer', 'warehouse', 'vehicle.vehicleType'])->firstOrFail();
        $gatePass->loadMissing('issuer');

        /*
         * ⭐ পরিবহনের পুরো তথ্য — মালিক, ২ অক্টোবর ২০২৬: *"Gate Pass e transport driver details nai"*।
         * ⓘ চালানের "মাল কীভাবে যাবে" থেকে, হুবহু ([[DeliveryChallan::transportFacts()]]) — A5, A4, থার্মাল সব নকশা
         * এই একই মেটা আঁকে। ক্রেতা নিজে নিলে লেখা থাকে "গ্রাহক নিজে নিয়েছেন" আর যিনি নিলেন।
         */
        $t = $challan->transportFacts();
        $self = $t['mode'] === 'customer_self';
        $transport = array_filter([
            'sales::field.transport_mode' => $t['mode'] === null ? null : __('sales::field.transport_mode_'.$t['mode']),
            'sales::field.carrier' => $t['carrier'] ?: null,
            'sales::field.vehicle_no' => $t['vehicle_no'] ?: (string) $gatePass->vehicle_no,
            'sales::field.vehicle_type' => $t['vehicle_type'],
            ($self ? 'sales::field.collected_by' : 'sales::field.driver_name') => $t['driver_name'] ?: $gatePass->driver_name,
            'sales::field.driver_phone' => $t['driver_phone'] ?: $gatePass->driver_phone,
            'sales::field.transport_cost' => $t['cost'] === null ? null : \App\Core\Support\Money::format($t['cost']),
        ], fn ($v) => filled($v));

        $doc = new PrintableDocument(
            title: __('sales::doc.gate_pass'),
            meta: [
                'core.print.document_no' => $gatePass->document_no,
                'sales::gate_pass.column.challan' => $challan->document_no,
                'sales::field.customer' => $challan->customer?->name() ?? '',
                ...$transport,
                'sales::gate_pass.column.issued_by' => trim(($gatePass->issuer?->name ?? '').' · '.DateFormat::format($gatePass->issued_at), ' ·'),
            ],
            lines: $this->productLines(
                $challan->lines,
                'delivered_qty',
                $this->lots->forDocument(DeliveryChallan::STOCK_SOURCE, $challan->id),
            ),
            signatures: ['core.print.storekeeper', 'core.print.driver', 'core.print.gate_officer'],
            showMoney: false,
            notice: __('core.print.no_price_notice'),
            // ⭐ গেটম্যান এটাই স্ক্যান করেন — চালানের সই-করা টোকেন ([[PaperToken]], [[QrScanController::gateOut()]])
            qrUrl: $challan->public_id !== null && Route::has('sales.qr')
                ? route('sales.qr', app(\App\Modules\Sales\Services\PaperToken::class)->for($challan))
                : null,
        );

        return $this->pdf($request, $doc, '0', $gatePass->document_no, document: $gatePass,
            paperSetting: 'sales.print.paper.gate_pass', target: 'challan');
    }

    /**
     * ⛔ কাউন্টারের অসমাপ্ত বিক্রির চালান ছাপা হয় না — মালিকের নিয়ম,
     * ২৬ সেপ্টেম্বর ২০২৬: *"sudu challan inv print hobe na"*।
     *
     * ⓘ বিলের দুই দরজায় পাহারা ছিল ([[invoice()]], [[draft()]]), চালান আর
     * গেটপাসে ছিল না — খসড়া চালানের গেটপাস হাতে পেলে মাল গেট পেরোত,
     * অথচ মজুদে কিছুই নামেনি। ⚠️ পাহারাটা সরু: কেবল যে খসড়া চালানে একটা
     * খসড়া বিল বাঁধা (কাউন্টারের রাখা বা সইয়ের অপেক্ষার বিক্রি)। অফিসের
     * সাধারণ খসড়া চালান আগের মতোই ছাপা হয়।
     */
    private function assertNotAnUnfinishedCounterSale(DeliveryChallan $challan): void
    {
        if ($challan->status !== DocumentStatus::DRAFT) {
            return;
        }

        $invoice = SalesInvoice::query()
            ->where('status', DocumentStatus::DRAFT)
            ->whereHas('lines.challanLine', fn ($q) => $q->where('delivery_challan_id', $challan->id))
            ->first(['id', 'document_no']);

        if ($invoice !== null) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.held_no_print', ['no' => $invoice->document_no]),
            ]);
        }
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

        $design = $this->paperDesign($request, 'order', 'sales.print.paper.order', fn () => OrderPaperFacts::order($order));

        return $this->pdf($request, $doc, (string) $order->total, $order->document_no, document: $order,
            paperSetting: 'sales.print.paper.order', target: 'order',
            template: $design['template'] ?? 'print.document',
            extra: $design['extra'] ?? []);
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

        $design = $this->paperDesign($request, 'receipt', 'sales.print.paper.receipt', fn () => OrderPaperFacts::receipt($collection));

        return $this->pdf($request, $doc, (string) $collection->amount, $collection->document_no, document: $collection,
            paperSetting: 'sales.print.paper.receipt', target: 'receipt',
            template: $design['template'] ?? 'print.document',
            extra: $design['extra'] ?? []);
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
    /**
     * ⭐ জমার সারির "কোন পথে" — টাকাটা যে খাতে ঢুকল তার নাম ("নগদ", "বিকাশ", "ব্র্যাক ব্যাংক")।
     *
     * ── ⛔ লাইভে ঘরটা ফাঁকা ছিল, ২৯ সেপ্টেম্বর ২০২৬ ─────────────────────
     * ⓘ মালিকের পাঠানো ছবি: RCV-0006, কাউন্টারের নগদ ৫০,০০০, "Payment Method" খালি। আগে
     * কেবল `money_account_id` পড়া হত, আর নকশা অনুযায়ীই ঘরটা বসে কেবল ব্যাংক-রেফারেন্সওয়ালা
     * ভাউচারে (TrxID-এর অনন্যতার জন্য, [[VoucherService]])। ⛔ কাউন্টারের ভাউচারে ওটা NULL,
     * তাই প্রতিটা কাউন্টারের জমা ফাঁকা ছাপত।
     *
     * ⭐ তাই তিন ধাপ: `money_account_id` থাকলে সেই খাত · নাহলে রসিদের debit দিকের টাকার খাত
     * (ভাউচারের নিজের সারি) · তাও না পেলে [[Voucher::wayInWords()]] — কাঁচা কোড (`BKASH`)
     * কখনো নয়। ⚠️ সারি আর খাত আগেই তোলা থাকে ([[paymentsAgainst()]]-এ `with`), নাহলে
     * এখানে প্রতিটা জমায় একটা করে কোয়েরি যেত।
     */
    private function methodOf(Voucher $voucher): string
    {
        $named = $this->accountName((int) ($voucher->money_account_id ?? 0));

        if ($named !== '') {
            return $named;
        }

        $money = $voucher->lines
            ->filter(fn ($line) => bccomp((string) $line->debit, '0', 4) > 0)
            ->map(fn ($line) => $line->account)
            ->first(fn ($account) => $account instanceof Account && $account->isMoney());

        if ($money !== null) {
            return (string) $money->name();
        }

        return (string) ($voucher->wayInWords() ?? '');
    }

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

        foreach ($invoice->receiptVouchers()->with('lines.account')->get() as $voucher) {
            $rows[] = [
                'ref' => (string) $voucher->document_no,
                'date' => $voucher->trx_date,

                /*
                 * ⓘ "কোন পথে" — খাতার নাম, কারণ ওটাই মানুষ চেনে
                 * ("নগদ", "ব্র্যাক ব্যাংক")। ⚠️ যন্ত্রের নাম
                 * (`money_kind`) ছাপলে গ্রাহকের কাছে ওটা কিছুই বলত না।
                 */
                'method' => $this->methodOf($voucher),
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
     * ⓘ তাই বিয়োগ করা হয়; অগ্রিম থাকলে ফল ঋণাত্মকই থাকে (৩ অক্টোবর ২০২৬, [[earlierDue()]])।
     *
     * @return array<string, string>
     */
    /**
     * ⓘ আগের বকেয়া — গ্রাহকের মোট পাওনা থেকে এই বিলের বকেয়া বাদ, চিহ্নসহ (অগ্রিম ঋণাত্মক)।
     *
     * ⭐ এক জায়গায়, কারণ দুই নকশাই ([[invoiceTotals()]] আর [[classicFacts()]]) এটা ছাপে;
     * ⛔ দুইবার লিখলে একদিন দুই কাগজে একই গ্রাহকের দুই রকম "আগের বকেয়া" ছাপা হত।
     */
    private function earlierDue(SalesInvoice $invoice, string $due): string
    {
        $customer = $invoice->customer;

        if ($customer === null) {
            return '0';
        }

        /*
         * ⭐ চিহ্নসহ — মালিক, ৩ অক্টোবর ২০২৬: *"Previous Due aseni keno"*। গ্রাহকের আগাম টাকা থাকলে আগে শূন্যে থামত,
         * অথচ "Outstanding" আসল জের ছাপে — তাই আগের সারি ০, শেষ সারি অন্য অঙ্ক, যোগ মিলত না।
         * ⓘ এখন অগ্রিম ঋণাত্মক ("na renatok hole renatok ei hobe"), আর আগের + এই বিলের বাকি = মোট, সবসময়।
         */
        return bcsub($customer->outstanding(), $due, 4);
    }

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
            $earlier = $this->earlierDue($invoice, $due);

            if (bccomp($earlier, '0', 4) !== 0) {
                // ⓘ অগ্রিম হলে নাম "আগের অগ্রিম", অঙ্ক চিহ্নসহ (৩ অক্টোবর ২০২৬)
                $rows[bccomp($earlier, '0', 4) < 0 ? 'sales::print.previous_advance' : 'sales::print.previous_due'] = $this->money($earlier);
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
            // ⭐ নিজস্ব পরিবহন নয়তো বাহক — পাতার সাথে একই উত্তর ([[DeliveryChallan::transportLabel()]])
            'sales::field.carrier' => $challan->transportLabel(),
            'sales::field.vehicle_no' => $challan->vehiclePlate(),
            'sales::field.driver_name' => $challan->driver_name ?? '',
            'sales::field.driver_phone' => $challan->driver_phone ?? '',
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
        // ⭐ লট ও মেয়াদ — বিলের সুইচে (ডিফল্ট চালু, লট সবসময়; মালিক, ৩ অক্টোবর ২০২৬)
        if (! app(\App\Modules\Sales\Support\InvoicePrintLook::class)->shows('lot')) {
            return [];
        }

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
    /**
     * ⭐ পণ্যের কোড — সুইচ বন্ধ থাকলে সারির কোডের ঘর খালি, তাই কোনো নকশায় কোথাও ছাপা হয় না
     * (মালিক, ৩ অক্টোবর ২০২৬: *"print e product id dewar dorkar nai"*)।
     *
     * @param  \Closure(string): bool  $shows
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withoutCodeUnlessShown(\Closure $shows, array $rows): array
    {
        return $shows('product_code') ? $rows : array_map(fn (array $row) => [...$row, 'code' => ''], $rows);
    }

    /**
     * ফ্রি পরিমাণ প্যাকের এককে — বিলের [[classicItems()]]-এর হুবহু নিয়ম: প্যাকে লেখা সারিতে ফ্রি × লেখা ÷ ভিত্তি।
     * ⚠️ `packedQty('free_qty')` চলে না — প্যাকে লেখা সারিতে সেটা ঘর না দেখে লেখা পরিমাণই ফেরত দেয়।
     */
    private function freeInPacks($line, string $qtyField): string
    {
        $free = (string) ($line->free_qty ?? '0');
        $base = (string) ($line->{$qtyField} ?? '0');

        return method_exists($line, 'wasEnteredInAPack') && $line->wasEnteredInAPack() && bccomp($base, '0', 6) !== 0
            ? bcdiv(bcmul($free, (string) $line->entered_qty, 8), $base, 4)
            : $free;
    }

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

            /*
             * ⭐ মোট পরিমাণ = পরিমাণ + ফ্রি, প্যাকের এককেই — মালিক, ২ অক্টোবর ২০২৬: *"Challan e total qty nai"*।
             * ⓘ ফ্রি ঘরটা ভিত্তি-এককে লেখা ([[freeOf()]]), তাই যোগের জন্য ফ্রিও প্যাকে আনা — বিলের মোট পরিমাণের একই নিয়ম।
             */
            'total_qty' => $this->qty(bcadd((string) $line->packedQty($qtyField), $this->freeInPacks($line, $qtyField), 4)),

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

        /*
         * ⭐ পয়সা মেলানোর সারি — মালিকের নির্দেশ, ২৩ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ *"রাউন্ডিং শুধু পজ এ"* — আর কাগজে ঠিক সেটাই ঘটে, কোনো
         * বাড়তি সুইচ ছাড়াই: অঙ্কটা **কেবল কাউন্টারে** বসে, তাই শূন্য
         * নয় এমন সারিটা কেবল কাউন্টারের রসিদেই ওঠে।
         *
         * ⚠️ আলাদা সুইচ বানানো হয়নি ইচ্ছাকৃতভাবে: তাতে দুইটা সুইচ হত
         * (একটা এন্ট্রির, একটা ছাপার), আর একটা চালু অন্যটা বন্ধ থাকলে
         * মেলানো অঙ্কটা খাতায় বসত অথচ কাগজে দেখা যেত না — ⛔ গ্রাহকের
         * হাতের কাগজ আর খাতা তখন দুই কথা বলত।
         *
         * ⓘ মোটের **আগে**, কারণ ওটা মোটকে বদলায়।
         */
        /*
         * ⭐ বিলের ছাড় — ২৭ সেপ্টেম্বর ২০২৬। ⓘ মোট এটা বাদ দিয়েই গোনা
         * ([[SalesInvoiceService::replaceLines()]]); ⚠️ সারিটা না থাকলে
         * কাগজের যোগ-বিয়োগ মিলত না, আর ক্রেতা ভাবতেন মোটটা ভুল।
         */
        $billDiscount = (string) ($document->bill_discount ?? '0');

        if (bccomp($billDiscount, '0', 4) > 0) {
            $rows['sales::print.bill_discount'] = $this->money($billDiscount);
        }

        $rounding = (string) ($document->rounding_amount ?? '0');

        if (bccomp($rounding, '0', 4) !== 0) {
            $rows['sales::field.rounding'] = $this->money($rounding);
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
        $paper = PaperSize::chosen($request->query('paper'), $this->branch->get($paperSetting));

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

        /* ⓘ কোন ছাঁচ — বিলের ক্লাসিক নকশা ছাড়া সবাই চলতিটা */
        string $template = 'print.document',

        /** @var array<string, mixed> ছাঁচের বাড়তি তথ্য */
        array $extra = [],
    ): Response {
        /*
         * ⭐ কাগজের মাপ: ঠিকানায় যা চাওয়া হয়েছে, নয়তো মালিকের বসানো মাপ।
         * ⓘ কারণটা [[PaperSize::chosen()]]-এ — আগে এখানে হাতে লেখা A4 ছিল।
         */
        $paper = PaperSize::chosen($request->query('paper'), $this->branch->get($paperSetting));

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
            // ⭐ কততম ছাপা — আগে যতবার + এইবার (মালিক, ৩০ সেপ্টেম্বর ২০২৬)
            $doc = $doc->withNotice(__('core.print.duplicate_notice', ['n' => $job->printed_count + 1]));
        }

        $locale = app()->getLocale();

        $pdf = $this->print->render(
            template: $template,
            data: [
                ...$extra,
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
