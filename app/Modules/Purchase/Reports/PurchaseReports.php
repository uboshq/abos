<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseReceipt;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * ক্রয়ের তিনটা রিপোর্ট।
 *
 * তিনটাই ডকুমেন্টের লাইন থেকে গোনা, কোনো সারাংশ কলাম থেকে নয় — তাই পর্দার
 * সংখ্যা আর রিপোর্টের সংখ্যা আলাদা হতে পারে না।
 */
final class PurchaseReports
{
    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::pendingOrders());
        $engine->register(self::uninvoiced());
        $engine->register(self::bySupplier());
        $engine->register(self::matchExceptions());
        $engine->register(self::priceHistory());
        $engine->register(self::supplierPerformance());
    }

    /**
     * যে আদেশগুলোর মাল এখনো পুরো আসেনি।
     *
     * "কী কী আসার কথা" প্রশ্নের উত্তর। বাকিটা গোনা হয় আদেশ ও চালানের
     * লাইন মিলিয়ে — আদেশে কোনো "কত এসেছে" কলাম রাখা হয়নি, কারণ ওটা
     * একদিন গোনার সাথে মিলত না।
     */
    public static function pendingOrders(): ReportDefinition
    {
        /*
         * সাব-কোয়েরিটা কাঁচা SQL, আর তাতে কোনো প্লেসহোল্ডার নেই — ইচ্ছাকৃত।
         *
         * আগে এটা কোয়েরি বিল্ডার দিয়ে বানিয়ে toSql() বসানো হয়েছিল, কিন্তু
         * তাতে ভেতরের `?`-গুলো SELECT অংশে চলে যেত অথচ তাদের মানগুলো
         * যেত না। ফলে বাকি সব বাইন্ডিং এক ঘর করে সরে গিয়ে ভয়ানক SQL
         * তৈরি হত: `status <> 51`, `company_id = 2026-08-05`। ভুলটা
         * চোখে পড়ে না, কারণ কোডটা পড়তে ঠিকই দেখায়।
         *
         * অবস্থার মানটা একটা ধ্রুবক, ব্যবহারকারীর ইনপুট নয় — তাই সরাসরি
         * বসানো নিরাপদ, আর এতে বাইন্ডিং গোনার প্রশ্নই ওঠে না।
         */
        $cancelled = DocumentStatus::CANCELLED;

        /*
         * "এসেছে" মানে দুইটা পথের যোগফল।
         *
         * ── কেন দুইটা ─────────────────────────────────────────────────
         * আগে কেবল মাল গ্রহণের কাগজ (GRN) গোনা হত। কিন্তু আদেশ থেকে
         * সরাসরি বিলও করা যায় — যে ডিপো GRN লেখে না তার একমাত্র পথ
         * ওটাই, আর তখন মাল বিল নিশ্চিত করার সময়েই গুদামে ঢোকে।
         *
         * শুধু GRN গুনলে ওই আদেশগুলো বিল হয়ে যাওয়ার পরেও "অপেক্ষমাণ"
         * তালিকায় বসে থাকত, আর কেউ বুঝত না মালটা এসে গেছে কি না। ভুল
         * সংখ্যা, অথচ পর্দা ঠিক দেখায় — সবচেয়ে খারাপ ধরনের ভুল।
         *
         * বাতিল দুই দিকেই বাদ: বাতিল কাগজের মাল আর আসবে না।
         */
        $received = "((select COALESCE(SUM(rl2.received_qty), 0)
                from pur_receipt_lines rl2
                join pur_receipts r2 on r2.id = rl2.purchase_receipt_id
                where rl2.purchase_order_line_id = ol.id
                  and r2.status <> '{$cancelled}')
            + (select COALESCE(SUM(bl2.qty), 0)
                from pur_bill_lines bl2
                join pur_bills b2 on b2.id = bl2.purchase_bill_id
                where bl2.purchase_order_line_id = ol.id
                  and b2.status <> '{$cancelled}'))";

        return new ReportDefinition(
            key: 'purchase.pending_orders',
            title: 'purchase::menu.pending_orders',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => DB::table('pur_order_lines as ol')
                ->join('pur_orders as o', 'o.id', '=', 'ol.purchase_order_id')
                ->join('inv_products as p', 'p.id', '=', 'ol.product_id')
                ->join('suppliers as s', 's.id', '=', 'o.supplier_id')
                ->where('o.company_id', $f['company_id'])
                ->when($f['branch_id'], fn ($q, $b) => $q->where('o.branch_id', $b))
                ->whereBetween('o.trx_date', [$f['from'], $f['to']])
                ->whereNull('o.deleted_at')
                ->where('o.status', DocumentStatus::CONFIRMED)
                // পুরো এসে গেলে সারিটা আর দেখানোর কিছু নেই
                ->whereRaw("ol.ordered_qty > {$received}")
                ->orderBy('o.trx_date')
                ->orderBy('o.document_no')
                ->select([
                    'o.trx_date',
                    'o.document_no',
                    DB::raw("'".PurchaseOrder::drillSourceType()."' as source_type_literal"),
                    'o.id as order_id',
                    self::supplierName(),
                    self::productName(),
                    'ol.ordered_qty',
                    DB::raw("{$received} as received_qty"),
                    DB::raw("ol.ordered_qty - {$received} as pending_qty"),
                ]),
            columns: [
                ['key' => 'trx_date', 'label' => 'core.print.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                [
                    'key' => 'document_no',
                    'label' => 'core.table.document',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type_literal',
                    'source_id' => 'order_id',
                ],
                ['key' => 'supplier_name', 'label' => 'purchase::field.supplier'],
                ['key' => 'product_name', 'label' => 'purchase::field.product'],
                ['key' => 'ordered_qty', 'label' => 'purchase::field.ordered', 'type' => ReportColumn::MONEY],
                ['key' => 'received_qty', 'label' => 'purchase::field.received', 'type' => ReportColumn::MONEY],
                ['key' => 'pending_qty', 'label' => 'purchase::field.pending', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * মাল এসেছে, বিল আসেনি।
     *
     * এই রিপোর্টটাই ২১৬০ খাতের ব্যাখ্যা। খাতটা শূন্য না হলে এখানে দেখা যায়
     * কোন চালানগুলো ঝুলে আছে — হয় সরবরাহকারী বিল পাঠাননি, নয় কেউ বিল
     * ছাড়াই মাল নামিয়েছেন। দুইটাই জানা দরকার, আর দুইটার ব্যবস্থা আলাদা।
     */
    public static function uninvoiced(): ReportDefinition
    {
        // কাঁচা SQL, প্লেসহোল্ডার ছাড়া — কারণটা pendingOrders()-এ লেখা
        $cancelled = DocumentStatus::CANCELLED;

        $billed = "(select COALESCE(SUM(bl.qty), 0)
                from pur_bill_lines bl
                join pur_bills b on b.id = bl.purchase_bill_id
                where bl.purchase_receipt_line_id = rl.id
                  and b.status <> '{$cancelled}')";

        return new ReportDefinition(
            key: 'purchase.uninvoiced',
            title: 'purchase::menu.uninvoiced',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => DB::table('pur_receipt_lines as rl')
                ->join('pur_receipts as r', 'r.id', '=', 'rl.purchase_receipt_id')
                ->join('inv_products as p', 'p.id', '=', 'rl.product_id')
                ->join('suppliers as s', 's.id', '=', 'r.supplier_id')
                ->where('r.company_id', $f['company_id'])
                ->when($f['branch_id'], fn ($q, $b) => $q->where('r.branch_id', $b))
                ->whereBetween('r.trx_date', [$f['from'], $f['to']])
                ->whereNull('r.deleted_at')
                ->where('r.status', DocumentStatus::CONFIRMED)
                ->whereRaw("rl.received_qty > {$billed}")
                ->orderBy('r.trx_date')
                ->orderBy('r.document_no')
                ->select([
                    'r.trx_date',
                    'r.document_no',
                    DB::raw("'".PurchaseReceipt::drillSourceType()."' as source_type_literal"),
                    'r.id as receipt_id',
                    self::supplierName(),
                    self::productName(),
                    'rl.received_qty',
                    DB::raw("rl.received_qty - {$billed} as unbilled_qty"),
                    // চালানের দরেই — ২১৬০ খাতে ঠিক এই টাকাটাই বসে আছে
                    DB::raw("(rl.received_qty - {$billed}) * rl.rate as unbilled_value"),
                ]),
            columns: [
                ['key' => 'trx_date', 'label' => 'core.print.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                [
                    'key' => 'document_no',
                    'label' => 'core.table.document',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type_literal',
                    'source_id' => 'receipt_id',
                ],
                ['key' => 'supplier_name', 'label' => 'purchase::field.supplier'],
                ['key' => 'product_name', 'label' => 'purchase::field.product'],
                ['key' => 'received_qty', 'label' => 'purchase::field.received', 'type' => ReportColumn::MONEY],
                ['key' => 'unbilled_qty', 'label' => 'purchase::field.unbilled', 'type' => ReportColumn::MONEY],
                ['key' => 'unbilled_value', 'label' => 'purchase::field.unbilled_value', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /** কার কাছ থেকে কত কিনেছি — বিলের ভিত্তিতে। */
    public static function bySupplier(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'purchase.by_supplier',
            title: 'purchase::menu.by_supplier',
            filters: ['date_range', 'branch'],
            groupBy: 'supplier_id',

            // এক সরবরাহকারীর উপর কতটা নির্ভরতা — তিনি থামলে কী থামে
            rankBy: 'total',
            query: fn (array $f) => DB::table('pur_bills as b')
                ->join('suppliers as s', 's.id', '=', 'b.supplier_id')
                ->where('b.company_id', $f['company_id'])
                ->when($f['branch_id'], fn ($q, $br) => $q->where('b.branch_id', $br))
                ->whereBetween('b.trx_date', [$f['from'], $f['to']])
                ->whereNull('b.deleted_at')
                /*
                 * খাতায় বসা বিল — খসড়া নয়।
                 *
                 * বিক্রয়ের একই রিপোর্টে ঠিক এই ভুলটাই ছিল: "বাতিল ছাড়া
                 * সব" মানে খসড়াও, আর তখন এখনো নিশ্চিত না-হওয়া একটা বিল
                 * সরবরাহকারীর নামে যোগ হয়ে বসে থাকত। কেউ দরকষাকষিতে
                 * বসতেন এমন একটা সংখ্যা নিয়ে যা খাতায় নেই।
                 */
                ->whereIn('b.status', DocumentStatus::POSTED)
                ->groupBy('b.supplier_id', 's.code', 's.name_en', 's.name_bn')
                ->orderByRaw('SUM(b.total) desc')
                ->select([
                    'b.supplier_id',
                    DB::raw("'supplier' as source_type_literal"),
                    self::supplierName(),
                    DB::raw('COUNT(*) as bill_count'),
                    DB::raw('SUM(b.subtotal) as subtotal'),
                    DB::raw('SUM(b.discount) as discount'),
                    DB::raw('SUM(b.tax) as tax'),
                    DB::raw('SUM(b.total) as total'),
                ]),
            columns: [
                [
                    'key' => 'supplier_name',
                    'label' => 'purchase::field.supplier',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type_literal',
                    'source_id' => 'supplier_id',
                ],
                ['key' => 'bill_count', 'label' => 'purchase::field.bill_count'],
                ['key' => 'subtotal', 'label' => 'purchase::field.subtotal', 'type' => ReportColumn::MONEY],
                ['key' => 'discount', 'label' => 'purchase::field.discount', 'type' => ReportColumn::MONEY],
                ['key' => 'tax', 'label' => 'purchase::field.tax', 'type' => ReportColumn::MONEY],
                ['key' => 'total', 'label' => 'purchase::field.total', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * ⭐ যে বিলগুলো মেলেনি — ২৪ সেপ্টেম্বর ২০২৬।
     *
     * ── ⭐ মালিকের স্পেক ──────────────────────────────────────────────
     * *"3-Way Matching mandatory architecture হবে"*, আর ফলটা হবে
     * `MATCHED` · `PARTIAL_MATCH` · `MISMATCH` · `EXCEPTION`।
     *
     * ── ⛔ এর আগে যা হত ──────────────────────────────────────────────
     * তিনটা সুইচ আগে থেকেই কাজ করত, কিন্তু ফলটা কোথাও থাকত না। ⚠️ সুইচ
     * বন্ধ থাকলে বিলটা চুপচাপ পাশ হয়ে যেত আর পার্থক্যটা কেবল
     * মূল্য-পার্থক্য খাতে বসত। ⓘ ফল: *"কোন বিলগুলো মেলেনি"* প্রশ্নের
     * উত্তর বের করতে হিসাবের খাত ধরে উল্টোদিকে হাঁটতে হত।
     *
     * ── ⚠️ মিলে যাওয়া বিল এই তালিকায় নেই, ইচ্ছাকৃতভাবে ────────────────
     * ⛔ ওগুলো দেখালে এটা গোটা বিলের তালিকা হয়ে যেত, আর যে তিনটা
     * সত্যিই দেখার দরকার সেগুলো একশোটার ভিড়ে হারাত।
     */
    public static function matchExceptions(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'purchase.match_exceptions',
            title: 'purchase::menu.match_exceptions',
            filters: ['date_range', 'branch'],

            /*
             * ⛔ `rankBy` নেই, ইচ্ছাকৃতভাবে।
             *
             * ⓘ ওটা প্রতিটা সারিতে *"মোটের কত অংশ"* কলাম বসায়, আর
             * ⚠️ *"এই বিলটা সব ফাঁকের ৪০%"* বাক্যটা কাউকে কিছুই বলে
             * না। ⛔ তার উপর ফাঁক ঋণাত্মকও হতে পারে (সরবরাহকারী কম
             * দরে বিল পাঠালে), আর তখন শতাংশটা অর্থহীন।
             *
             * ⭐ বড় ফাঁক আগে — সেটা `orderByRaw` করেই হয়, নিচে।
             */
            query: fn (array $f) => DB::table('pur_bills as b')
                ->join('suppliers as s', 's.id', '=', 'b.supplier_id')
                ->where('b.company_id', $f['company_id'])
                ->when($f['branch_id'], fn ($q, $br) => $q->where('b.branch_id', $br))
                ->whereBetween('b.trx_date', [$f['from'], $f['to']])
                ->whereNull('b.deleted_at')

                /*
                 * ⚠️ খাতায় বসা বিল — খসড়া নয়। ⓘ খসড়ায় মিলকরণের
                 * প্রশ্নই ওঠে না, আর `match_state` তখন খালিই থাকে।
                 */
                ->whereIn('b.status', DocumentStatus::POSTED)
                ->whereIn('b.match_state', PurchaseBill::MATCH_NEEDS_ATTENTION)
                ->orderByRaw('ABS(COALESCE(b.match_difference, 0)) desc')
                ->select([
                    'b.id as bill_id',
                    'b.document_no',
                    'b.trx_date',
                    DB::raw("'supplier' as source_type_literal"),
                    'b.supplier_id',
                    self::supplierName(),
                    'b.match_state',
                    'b.total',
                    'b.match_difference as gap',
                ]),
            columns: [
                ['key' => 'trx_date', 'label' => 'purchase::field.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'document_no', 'label' => 'core.print.document_no', 'width' => '11rem'],
                [
                    'key' => 'supplier_name',
                    'label' => 'purchase::field.supplier',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type_literal',
                    'source_id' => 'supplier_id',
                ],
                ['key' => 'match_state', 'label' => 'purchase::field.match_state', 'width' => '9rem'],
                ['key' => 'total', 'label' => 'purchase::field.total', 'type' => ReportColumn::MONEY],

                /*
                 * ⓘ ফাঁকটা আলাদা কলামে, কারণ *"মেলেনি"* কথাটা একাই কিছু
                 * বলে না — ⚠️ দুই টাকার অমিল আর দুই লাখ টাকার অমিল এক
                 * জিনিস নয়, আর কোনটা আগে দেখতে হবে সেটা এই সংখ্যাটাই বলে।
                 */
                ['key' => 'gap', 'label' => 'purchase::field.match_gap', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * ⭐ ক্রয়ের দরের ইতিহাস — ২৪ সেপ্টেম্বর ২০২৬।
     *
     * ── ⭐ মালিকের স্পেক ──────────────────────────────────────────────
     * *"Purchase Price History"* — পণ্য · সরবরাহকারী · তারিখ · পরিমাণ ·
     * আগের দর · এখনকার দর · পার্থক্য।
     *
     * ── ⛔ এর আগে যতটুকু ছিল ─────────────────────────────────────────
     * [[LastPaidRate]] কেবল **শেষ** দরটা বলত, আর সেটা সরাসরি ক্রয়ের
     * পর্দায় দরাদরির জন্য। ⚠️ কিন্তু *"এই মালের দর গত ছয় মাসে কীভাবে
     * উঠল"* প্রশ্নের কোনো উত্তর ছিল না — আর দর বাড়ার ধরনটাই বলে দেয়
     * কোন সরবরাহকারী সুযোগ নিচ্ছেন।
     *
     * ── ⚠️ আগের দরটা একই সরবরাহকারীর, যে কারো নয় ─────────────────────
     * ⛔ `LAG()` কেবল পণ্য ধরে নিলে অন্য সরবরাহকারীর দর আগের সারিতে
     * বসত, আর পার্থক্যের কলামটা তখন মিথ্যা বলত: দুই দোকানের দুই দর
     * দেখে মনে হত একজন দাম বাড়িয়েছেন।
     *
     * ⓘ সেজন্য ভাগটা **সরবরাহকারী + পণ্য** ধরে, আর ক্রমটা তারিখ ধরে।
     */
    public static function priceHistory(): ReportDefinition
    {
        $cancelled = DocumentStatus::CANCELLED;

        /*
         * ⚠️ উইন্ডো ফাংশন — MySQL 8 ও MariaDB 10.2 দুইটাতেই আছে, আর
         * লাইভ দুইটার একটাতেই চলে। ⓘ হাতে জোড়া লাগালে (self-join)
         * একই ফল পেতে প্রতিটা সারিতে একটা সাব-কোয়েরি লাগত, আর হাজার
         * সারির রিপোর্টে সেটা মিনিট নিত।
         */
        $previous = 'LAG(l.rate) OVER (PARTITION BY b.supplier_id, l.product_id ORDER BY b.trx_date, b.id)';

        return new ReportDefinition(
            key: 'purchase.price_history',
            title: 'purchase::menu.price_history',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => DB::table('pur_bill_lines as l')
                ->join('pur_bills as b', 'b.id', '=', 'l.purchase_bill_id')
                ->join('suppliers as s', 's.id', '=', 'b.supplier_id')
                ->join('inv_products as p', 'p.id', '=', 'l.product_id')
                ->where('b.company_id', $f['company_id'])
                ->when($f['branch_id'], fn ($q, $br) => $q->where('b.branch_id', $br))
                ->whereBetween('b.trx_date', [$f['from'], $f['to']])
                ->whereNull('b.deleted_at')

                /*
                 * ⚠️ বাতিল বিল বাদ। ⓘ বাতিল মানে ঘটনাটা ঘটেনি — ওই দর
                 * দেখিয়ে দরাদরি করতে গেলে সরবরাহকারী বলতেন *"ওটা তো
                 * ফেরত গেছে"*, আর কথাটা তাঁরই ঠিক হত।
                 */
                ->where('b.status', '<>', $cancelled)
                ->orderBy('p.code')
                ->orderBy('s.code')
                ->orderBy('b.trx_date')
                ->select([
                    'b.trx_date',
                    'b.document_no',
                    DB::raw("'supplier' as source_type_literal"),
                    'b.supplier_id',
                    self::supplierName(),
                    'l.product_id',
                    self::productName(),
                    'l.qty',
                    'l.rate',
                    DB::raw($previous.' as previous_rate'),
                    DB::raw('l.rate - '.$previous.' as rate_change'),
                ]),
            columns: [
                ['key' => 'trx_date', 'label' => 'purchase::field.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'product_name', 'label' => 'purchase::field.product'],
                [
                    'key' => 'supplier_name',
                    'label' => 'purchase::field.supplier',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type_literal',
                    'source_id' => 'supplier_id',
                ],
                ['key' => 'document_no', 'label' => 'core.print.document_no', 'width' => '11rem'],
                ['key' => 'qty', 'label' => 'purchase::field.quantity', 'type' => ReportColumn::QUANTITY],
                ['key' => 'previous_rate', 'label' => 'purchase::field.previous_rate', 'type' => ReportColumn::MONEY],
                ['key' => 'rate', 'label' => 'purchase::field.rate', 'type' => ReportColumn::MONEY],

                /*
                 * ⓘ পার্থক্যটাই আসল কলাম — ⚠️ দুইটা দর পাশাপাশি থাকলেও
                 * মানুষ মাথায় বিয়োগ করে না, আর তখন যে সারিতে দর লাফ
                 * দিয়েছে সেটা চোখেই পড়ে না।
                 */
                ['key' => 'rate_change', 'label' => 'purchase::field.rate_change', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /** পণ্যের নাম — কোড সহ, ব্যবহারকারীর ভাষায়। */
    /**
     * ⭐ সরবরাহকারীর কার্যক্ষমতা — ২৪ সেপ্টেম্বর ২০২৬।
     *
     * ── ⭐ মালিকের স্পেক ──────────────────────────────────────────────
     * *"On-time Delivery · Fill Rate · Quality Rejection · Return Rate ·
     * Price Variance · Response Time · Purchase Volume"*।
     *
     * ── ⓘ সাতটার মধ্যে যে চারটা আজ সত্যিই মাপা যায় ───────────────────
     * ⚠️ বাকি তিনটা মাপতে হলে এমন তথ্য লাগত যা ABOS আজ রাখে না
     * (দরপত্রের জবাবের সময়, চুক্তির দর)। ⛔ ওগুলোর জন্য একটা শূন্য
     * কলাম বসালে রিপোর্টটা মিথ্যা বলত — *"শূন্য"* আর *"জানা নেই"* এক
     * জিনিস নয়, আর প্রথমটা দেখে কেউ সরবরাহকারী বদলে ফেলতেন।
     *
     * ⓘ তাই আজ চারটা, আর বাকিগুলো যেদিন তথ্যটা আসবে সেদিন।
     *
     * ── ⚠️ "সময়মতো" মাপা হয় আদেশের প্রতিশ্রুত দিন ধরে ────────────────
     * ⛔ `expected_on` খালি থাকলে সেই আদেশটা গোনাই হয় না: ⓘ কোনো দিন
     * বলা না থাকলে দেরি বলে কিছু নেই, আর ধরে-নেওয়া একটা দিন বসালে
     * সরবরাহকারীকে এমন প্রতিশ্রুতির দায়ে ফেলা হত যা তিনি দেননি।
     */
    public static function supplierPerformance(): ReportDefinition
    {
        $cancelled = DocumentStatus::CANCELLED;

        return new ReportDefinition(
            key: 'purchase.supplier_performance',
            title: 'purchase::menu.supplier_performance',
            filters: ['date_range', 'branch'],
            groupBy: 'supplier_id',

            /* ⓘ যাঁর কাছ থেকে সবচেয়ে বেশি কেনা হয়, তাঁর দেরিটাই সবচেয়ে দামি */
            rankBy: 'receipts',
            query: fn (array $f) => DB::table('pur_receipts as r')
                ->join('suppliers as s', 's.id', '=', 'r.supplier_id')
                ->leftJoin('pur_orders as o', 'o.id', '=', 'r.purchase_order_id')
                ->where('r.company_id', $f['company_id'])
                ->when($f['branch_id'], fn ($q, $br) => $q->where('r.branch_id', $br))
                ->whereBetween('r.trx_date', [$f['from'], $f['to']])
                ->whereNull('r.deleted_at')
                ->where('r.status', '<>', $cancelled)
                ->groupBy('r.supplier_id', 's.code', 's.name_en', 's.name_bn')
                ->orderByRaw('COUNT(*) desc')
                ->select([
                    'r.supplier_id',
                    DB::raw("'supplier' as source_type_literal"),
                    self::supplierName(),
                    DB::raw('COUNT(*) as receipts'),

                    /*
                     * ⓘ যে চালানগুলোর পিছনে একটা প্রতিশ্রুত দিন আছে —
                     * কেবল ওগুলোই সময়ের হিসাবে ধরা হয়।
                     */
                    DB::raw('SUM(CASE WHEN o.expected_on IS NOT NULL THEN 1 ELSE 0 END) as promised'),
                    DB::raw('SUM(CASE WHEN o.expected_on IS NOT NULL
                                       AND r.trx_date <= o.expected_on THEN 1 ELSE 0 END) as on_time'),

                    /*
                     * ⚠️ গড় দেরি — কেবল যেগুলো সত্যিই দেরি হয়েছে।
                     * ⛔ আগে-আসা চালানগুলো ঋণাত্মক দিন দিত, আর তাতে গড়
                     * নেমে গিয়ে দেরিটা লুকিয়ে যেত।
                     */
                    DB::raw('AVG(CASE WHEN o.expected_on IS NOT NULL
                                       AND r.trx_date > o.expected_on
                                      THEN DATEDIFF(r.trx_date, o.expected_on) END) as late_days'),
                ]),
            columns: [
                [
                    'key' => 'supplier_name',
                    'label' => 'purchase::field.supplier',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type_literal',
                    'source_id' => 'supplier_id',
                ],
                ['key' => 'receipts', 'label' => 'purchase::field.receipts'],
                ['key' => 'promised', 'label' => 'purchase::field.promised'],
                ['key' => 'on_time', 'label' => 'purchase::field.on_time'],
                ['key' => 'late_days', 'label' => 'purchase::field.late_days'],
            ],
        );
    }

    private static function productName(): Expression
    {
        $name = app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF(p.name_bn, ''), p.name_en)"
            : 'p.name_en';

        return DB::raw("CONCAT(p.code, ' - ', {$name}) as product_name");
    }

    private static function supplierName(): Expression
    {
        $name = app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF(s.name_bn, ''), s.name_en)"
            : 's.name_en';

        return DB::raw("{$name} as supplier_name");
    }
}
