<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintableDocument;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Engines\Print\PrintsInItsBranch;
use App\Core\Security\FieldSecurity;
use App\Core\Services\PaperTrail;
use App\Core\Services\SettingsService;
use App\Core\Support\AmountInWords;
use App\Core\Support\DateFormat;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Models\DocumentDelivery;
use App\Modules\Inventory\Models\Product;
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
    // ⭐ শাখার মাথা আর লোগো — বিক্রয়ের ছাপার মতো (পুনঃঅডিট ৯ অক্টোবর ২০২৬, ছাপা ১৮)
    use PrintsInItsBranch;

    public function __construct(
        private readonly PrintEngine $print,

        // কোন কাগজে ছাপা হবে — মালিকের বসানো মাপ
        private readonly SettingsService $settings,

        // ছাপা · নামানো · পাঠানো · খোলা — সব কাগজের এক হিসাব
        private readonly PaperTrail $trail,
    ) {}

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
        $price = $this->showsPurchasePrice();

        $doc = new PrintableDocument(
            title: __('purchase::doc.bill'),
            meta: [
                'core.print.document_no' => (string) $bill->document_no,
                'core.print.date' => DateFormat::format($bill->trx_date),
                'purchase::field.supplier' => $bill->supplier?->name() ?? '',
                'purchase::field.supplier_bill_no' => (string) ($bill->supplier_bill_no ?? ''),
            ],
            lines: $this->lines($bill->lines, 'qty', money: $price),
            totals: $price ? $this->totals($bill) : [],
            signatures: ['core.print.prepared_by', 'purchase::print.checked_by'],
            showMoney: $price,
            narration: $bill->narration,
        );

        /*
         * ⭐ নতুন কাগজ — মালিক, ১ অক্টোবর ২০২৬: *"ok template diye daw"*।
         * ⓘ A4/A5-এ; সরু থার্মাল রোলে আগের সাধারণ কাগজই (ওখানে আট কলাম ধরে না)।
         * ⓘ Control Panel-এ `purchase.print.design.bill` = `standard` দিলে আগের কাগজ।
         */
        $paper = PaperSize::chosen($request->query('paper'), $this->settings->get('purchase.print.paper.bill'));
        $modern = ! PaperSize::of($paper)->isThermal
            && ($this->settings->get('purchase.print.design.bill') ?? 'modern') === 'modern';

        return $this->pdf(
            $request, $doc, $price ? (string) $bill->total : '0', (string) $bill->document_no,
            document: $bill, kind: 'purchase_bill', paperSetting: 'purchase.print.paper.bill',
            template: $modern ? 'purchase::print.bill-modern' : 'print.document',
            extra: $modern ? ['bill' => $this->billFacts($bill, $price)] : [],
        );
    }

    /**
     * নতুন কাগজের তথ্য — [[purchase::print.bill-modern]]।
     *
     * ⓘ দাম দেখার চাবি না থাকলে দর, ছাড়, টাকা আর মোট কিছুই আসে না ([[showsPurchasePrice()]])।
     *
     * @return array<string, mixed>
     */
    private function billFacts(PurchaseBill $bill, bool $price): array
    {
        $bill->loadMissing(['warehouse', 'creator']);
        $blank = fn (mixed $v) => $v === null || bccomp((string) $v, '0', 4) === 0;

        $gross = '0';
        $lineDiscount = '0';
        $lineTotal = '0';
        $qtyTotal = '0';
        $freeTotal = '0';
        $lines = [];

        foreach ($bill->lines->sortBy('line_no')->values() as $line) {
            $qty = $line->packedQty('qty');
            $free = $this->freeOf($line);

            /*
             * ⓘ অফিসমেট থেকে আসা বিলে দামি+ফ্রি এক স্তূপে (মালিকের সিদ্ধান্ত, ৩০ সেপ্টেম্বর),
             * আর সারির বিবরণে "40 + 4 free" — কাগজে দুইটা আলাদা দেখানো হয়।
             */
            if ($free === '' && preg_match('/^([\d.]+) \+ ([\d.]+) free$/', (string) $line->narration, $m) === 1) {
                $qty = $m[1];
                $free = $this->qty($m[2]);
            }

            $qtyTotal = bcadd($qtyTotal, (string) $qty, 4);
            $freeTotal = bcadd($freeTotal, $free === '' ? '0' : str_replace(',', '', $free), 4);
            $gross = bcadd($gross, bcmul((string) $line->qty, (string) $line->rate, 4), 4);
            $lineDiscount = bcadd($lineDiscount, (string) $line->discount, 4);
            $lineTotal = bcadd($lineTotal, (string) $line->amount, 4);

            $lines[] = [
                'code' => (string) ($line->product?->code ?? ''),
                'name' => (string) ($line->product?->name() ?? ''),
                'lot' => (string) ($line->batch_no ?? ''),
                'qty' => $this->qty($qty),
                'unit' => $line->packedUnitName(),
                'free' => $free,

                /* ⭐ মালিক (১ অক্টোবর): পরিমাণ আর ফ্রি-র পরে মোট পরিমাণ */
                'total_qty' => $this->qty(bcadd((string) $qty, $free === '' ? '0' : str_replace(',', '', $free), 4)),
                'rate' => $price ? $this->money($line->packedRate('rate', 'qty')) : '',
                'discount' => $price && ! $blank($line->discount) ? $this->money($line->discount) : '',
                'amount' => $price ? $this->money($line->amount) : '',
            ];
        }

        $billDiscount = bcsub((string) $bill->discount, $lineDiscount, 4);
        $branch = $bill->branch;

        return [
            'no' => (string) $bill->document_no,
            'supplier_no' => (string) ($bill->supplier_bill_no ?? ''),
            'date' => DateFormat::format($bill->trx_date),
            'received' => DateFormat::format($bill->received_on ?? $bill->trx_date),
            'due' => $bill->due_on ? DateFormat::format($bill->due_on) : '',
            'status' => __('purchase::status.'.$bill->status) !== 'purchase::status.'.$bill->status
                ? (string) __('purchase::status.'.$bill->status) : (string) $bill->status,
            'vehicle' => (string) ($bill->vehicle_no ?? ''),
            'branch' => [
                'name' => (string) ($branch?->name() ?? ''),
                'address' => (string) (app()->getLocale() === 'bn' ? ($branch?->address_bn ?: $branch?->address_en) : $branch?->address_en),
                'phone' => (string) ($branch?->phone ?? ''),
            ],
            'supplier' => [
                'name' => (string) ($bill->supplier?->name() ?? ''),
                'address' => (string) (app()->getLocale() === 'bn' ? ($bill->supplier?->address_bn ?: $bill->supplier?->address_en) : $bill->supplier?->address_en),
                'phone' => (string) ($bill->supplier?->phone ?? ''),
            ],
            'warehouse' => (string) ($bill->warehouse?->name_en ?? ''),
            'lines' => $lines,
            'qty_total' => $this->qty($qtyTotal),
            'free_total' => $blank($freeTotal) ? '' : $this->qty($freeTotal),
            'all_total' => $this->qty(bcadd($qtyTotal, $freeTotal, 4)),
            'sums' => $price ? [
                'gross' => $this->money($gross),
                'line_discount' => $this->money($lineDiscount),
                'lines' => $this->money($lineTotal),
                'bill_discount' => bccomp($billDiscount, '0', 4) > 0 ? $this->money($billDiscount) : '',
                'tax' => $blank($bill->tax) ? '' : $this->money($bill->tax),
                'transport' => $blank($bill->transport_cost) ? '' : $this->money($bill->transport_cost),
                'total' => $this->money($bill->total),
                'paid' => $this->money($bill->paidAmount()),
                'due' => $this->money($bill->dueAmount()),
            ] : [],
            'words' => $price ? AmountInWords::of(Money::round($bill->total), app()->getLocale()) : '',
            'note' => trim((string) ($bill->narration ?? '')),
            'prepared_by' => (string) ($bill->creator?->name ?? ''),
        ];
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
        $price = $this->showsPurchasePrice();

        $doc = new PrintableDocument(
            title: __('purchase::doc.order'),
            meta: [
                'core.print.document_no' => (string) $order->document_no,
                'core.print.date' => DateFormat::format($order->trx_date),
                'purchase::field.supplier' => $order->supplier?->name() ?? '',
            ],
            lines: $this->lines($order->lines, 'ordered_qty', money: $price),
            totals: $price ? $this->totals($order) : [],
            signatures: ['core.print.prepared_by', 'core.print.approved_by'],
            showMoney: $price,
            narration: $order->narration,
        );

        return $this->pdf(
            $request, $doc, $price ? (string) $order->total : '0', (string) $order->document_no,
            document: $order, kind: 'purchase_order', paperSetting: 'purchase.print.paper.order',
        );
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

        return $this->pdf($request, $doc, '0', (string) $receipt->document_no,
            document: $receipt, kind: 'purchase_receipt', paperSetting: 'purchase.print.paper.receipt');
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
        $price = $this->showsPurchasePrice();

        $doc = new PrintableDocument(
            title: __('purchase::doc.return'),
            meta: [
                'core.print.document_no' => (string) $return->document_no,
                'core.print.date' => DateFormat::format($return->trx_date),
                'purchase::field.supplier' => $return->supplier?->name() ?? '',
                'purchase::field.warehouse' => $return->warehouse?->name() ?? '',
            ],
            lines: $this->lines($return->lines, 'qty', money: $price),
            totals: $price ? $this->totals($return) : [],
            signatures: ['core.print.prepared_by', 'purchase::print.supplier_signature'],
            showMoney: $price,
            narration: $return->narration,
        );

        return $this->pdf(
            $request, $doc, $price ? (string) $return->total : '0', (string) $return->document_no,
            document: $return, kind: 'purchase_return', paperSetting: 'purchase.print.paper.bill',
        );
    }

    /**
     * ⛔ ক্রয়ের কাগজে দাম কেবল যাঁর ক্রয়মূল্য দেখার চাবি আছে — ২৭ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ বিল, আদেশ আর ফেরতের প্রতিটা সারির দরই ক্রয়মূল্য। ⚠️ আগে কাগজটা
     * `purchase.bill.view` থাকলেই দাম ছাপত — পণ্যের পাতা যে দামটা ঢাকে
     * ([[FieldSecurity]]), ছাপা সেটাই খুলে দিত। ⭐ চাবি না থাকলে কাগজটা মাল
     * বুঝে নেওয়ার কাগজের মতো: পরিমাণ আছে, দর-অঙ্ক-মোট নেই।
     *
     * ⓘ প্রশ্নটা পণ্যের ঘোষণাকেই করা হয় — চাবির নাম এখানে হাতে লেখা নয়।
     */
    private function showsPurchasePrice(): bool
    {
        return FieldSecurity::visible(Product::class, 'purchase_price');
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
            // যে প্যাকে লেখা হয়েছিল সেটাই কাগজে — সরবরাহকারীর বিলের
            // সাথে মেলাতে গেলে "১০ বাক্স" খুঁজতে হয়, "১০০০ পিস" নয়
            $row = [
                'name' => trim(($line->product?->code ?? '').' '.($line->product?->name() ?? '')),
                'qty' => $this->qty($line->packedQty($qtyField)),
                'unit' => $line->packedUnitName(),

                /*
                 * ⭐ ফ্রি পরিমাণ — ১৮ সেপ্টেম্বর ২০২৬, মালিকের নির্দেশে।
                 *
                 * ⓘ ক্রয়ের কাগজে এটা সবচেয়ে দরকারি: সরবরাহকারীর
                 * বিলে *"৪৮ + ৪ ফ্রি"* লেখা থাকে, আর মিলাতে গেলে
                 * দুইটা সংখ্যাই আলাদা লাগে। ⚠️ এক সংখ্যায় মিশিয়ে
                 * দিলে ক্রয়দরের হিসাবই ভুল হয়।
                 *
                 * ⛔ `entered_free_qty` আগে: ফ্রি-র নিজের প্যাক থাকতে
                 * পারে (`free_unit_id`) — কেনা পিসে, ফ্রি কার্টনে।
                 */
                'free' => $this->freeOf($line),
            ];

            if ($money) {
                $row['rate'] = $this->money($line->packedRate('rate', $qtyField));
                $row['amount'] = $this->money($line->amount);
            }

            return $row;
        })->values()->all();
    }

    /**
     * এই সারিতে ফ্রি কতটা — যে প্যাকে লেখা হয়েছিল সেই প্যাকে।
     *
     * ⓘ খালি হলে [[print/document-body]] কলামটাই আঁকে না, তাই
     * ফ্রি না থাকলে কাগজ অবিকল আগের মতো।
     */
    private function freeOf(object $line): string
    {
        $free = $line->entered_free_qty ?: $line->free_qty;

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

    private function pdf(
        Request $request,
        PrintableDocument $doc,
        string $amount,
        string $documentNo,
        ?object $document = null,

        /*
         * ⛔ এই দুইটা আগে ছিল না, আর তাতেই দুইটা ভুল হচ্ছিল (২০ সেপ্টেম্বর ২০২৬)।
         *
         * ⚠️ এক: চারটা কাগজই `purchase_paper` নামে গোনা হত, তাই ৫ নম্বর বিল
         * আর ৫ নম্বর আদেশের হিসাব এক হয়ে যেত। ⓘ নামটা এখন কাগজভেদে আলাদা।
         *
         * ⚠️ দুই: মাপ সবসময় বিলের সেটিং থেকে আসত — মালিক আদেশের জন্য অন্য
         * মাপ বসালে সেটা কেউ মানত না, অথচ পর্দায় ঐ মাপটাই দাগানো থাকত।
         */
        string $kind = 'purchase_bill',
        string $paperSetting = 'purchase.print.paper.bill',

        /* ⓘ কোন ছাঁচ — ক্রয় বিলের নতুন কাগজ ছাড়া সবাই সাধারণটা */
        string $template = 'print.document',

        /** @var array<string, mixed> ছাঁচের বাড়তি তথ্য */
        array $extra = [],
    ): Response {
        /*
         * ⭐ কাগজের মাপ মালিকের বসানো, হাতে লেখা A4 নয় (২০ সেপ্টেম্বর ২০২৬)।
         * ⓘ ঠিকানায় চাওয়া মাপ আগে, তারপর সেটিং — কারণ [[PaperSize::chosen()]]-এ।
         */
        $paper = PaperSize::chosen($request->query('paper'), $this->settings->get($paperSetting));

        /*
         * বাতিল করা কাগজের গায়ে "বাতিল"।
         *
         * বিক্রয়ের কাগজে একই ব্যবস্থা, একই কারণে: বাতিল করা ক্রয় বিল
         * বা ফেরতের কাগজ ছাপলে হুবহু বৈধ একটা কাগজ বেরোত, আর সেটা
         * দেখিয়ে সরবরাহকারীর কাছে দাবি করা যেত।
         */
        $cancelled = ($document?->status ?? null) === DocumentStatus::CANCELLED;

        if ($cancelled) {
            $doc = $doc->withNotice(__('core.print.cancelled_notice'));
        }

        // ⛔ খসড়া ক্রয়ের কাগজেও "খসড়া" — পাকা কাগজের মতো ছাপা হত (পুনঃঅডিট ৯ অক্টোবর ২০২৬, ছাপা ১০)
        $draft = ($document?->status ?? null) === DocumentStatus::DRAFT;

        if ($draft) {
            $doc = $doc->withNotice(__('core.print.draft_paper_notice'));
        }

        $pdf = $this->print->render(
            template: $template,
            data: [
                ...$extra,
                'doc' => $doc->withWordsFor($amount, app()->getLocale()),
                'title' => $doc->title.' '.$documentNo,
            ],
            paper: $paper,

            /*
             * বাতিল ক্রয় বিলের গায়েও -- বাতিল কাগজ দেখিয়ে
             * সরবরাহকারীর কাছে দাবি করা যেত, আর উপরের বাক্সটা কেটে
             * ফেলা যায়।
             */
            watermark: $cancelled ? __('core.print.cancelled_watermark') : ($draft ? __('core.print.draft_watermark') : null),
        );

        /*
         * ⭐ কাগজটা বেরোল — ছাপা হয়ে, নাকি ফাইল হয়ে (২০ সেপ্টেম্বর ২০২৬)।
         *
         * ⓘ মালিকের চাওয়া: *"কয়টা কাগজ প্রিন্ট হল কয়টা শেয়ার হইল"*। ⚠️ দুইটা
         * আলাদা গোনা হয়, কারণ "ছেপে দিয়েছি" আর "ফাইল পাঠিয়েছি" এক কথা নয়।
         * ⛔ ফাইলটা আলাদা করে আঁকা হয় না — উপরের `$pdf`-ই নামে।
         */
        $asFile = $request->boolean('download');

        $this->trail->record(
            $kind, (int) ($document?->id ?? 0), $paper,
            $asFile ? DocumentDelivery::DOWNLOADED : DocumentDelivery::PRINTED,
            $documentNo,
        );

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($asFile ? 'attachment' : 'inline').'; filename="'.$documentNo.'.pdf"',
        ]);
    }

    private function money(mixed $value): string
    {
        return Money::format($value);
    }

    /**
     * পরিমাণে ভগ্নাংশ থাকলে দেখাও, না থাকলে নয় — "১০.০০ পিস" কেউ লেখে না।
     *
     * ভগ্নাংশ আছে কি না সেটাও স্ট্রিং ধরে দেখা: `fmod()`-এ যেতে হলে
     * সংখ্যাটা float হত, আর ০.১ কেজি জাতীয় পরিমাণে সেটা কখনো ঠিক
     * শূন্য দেয় না।
     */
    private function qty(mixed $value): string
    {
        $trimmed = rtrim(rtrim(Money::format($value, 4), '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }
}
