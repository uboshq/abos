<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
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
            query: fn (array $f) => DB::table('pur_bills as b')
                ->join('suppliers as s', 's.id', '=', 'b.supplier_id')
                ->where('b.company_id', $f['company_id'])
                ->when($f['branch_id'], fn ($q, $br) => $q->where('b.branch_id', $br))
                ->whereBetween('b.trx_date', [$f['from'], $f['to']])
                ->whereNull('b.deleted_at')
                ->where('b.status', '<>', DocumentStatus::CANCELLED)
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

    /** পণ্যের নাম — কোড সহ, ব্যবহারকারীর ভাষায়। */
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
