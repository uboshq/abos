<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * পুঁজির উপর ফেরত — পরিবেশক ডিপোর আসল সংখ্যা।
 *
 * ── কেন ৪% সংখ্যাটা যথেষ্ট নয় ───────────────────────────────────────
 * "৪% মার্জিন" বলে **বিক্রির উপর কত**। কিন্তু ডিপোর মালিকের আসল প্রশ্ন
 * আলাদা: *আমার টাকা খেটে বছরে কত আনছে?*
 *
 * ২০ লাখ টাকা মিলের কাছে বসে আছে, আরও কয়েক লাখ শেলফের মালে, আরও
 * কয়েক লাখ ডিলারের কাছে — সব মিলিয়ে হয়তো ৪০ লাখ আটকে। ওই ৪০ লাখে
 * বছরে ৬ লাখ এলে ফেরত ১৫%; ২ লাখ এলে ৫%, আর তখন ব্যাংকে রাখলেও
 * বেশি হত। **৪% সংখ্যাটা দুই ক্ষেত্রেই একই থাকে**, তাই ওটা দিয়ে
 * সিদ্ধান্ত নেওয়া যায় না।
 *
 * ── আটকে থাকা পুঁজি — পাঁচটা টুকরো ─────────────────────────────────
 *
 *   ১. মিলের কাছে অগ্রিম ও আটকানো মার্জিন
 *   ২. কমিশনের দাবি — যা আগে দেওয়া হয়েছে, ফেরত আসেনি
 *   ৩. ডিলারের বাকি
 *   ৪. শেলফে পড়ে থাকা মালের ক্রয়মূল্য
 *
 * প্রথম তিনটা টাকা, চার নম্বরটা মাল — কিন্তু চারটাই একই জিনিস: **আপনার
 * টাকা, যা এই মুহূর্তে অন্য কোথাও আটকে আছে।**
 *
 * ── কোনটা হুবহু, কোনটা ভাগ করে বসানো ────────────────────────────────
 * অগ্রিম, দাবি ও মজুদ — তিনটাই মিল ধরে **হুবহু** বের হয়। ডিলারের
 * বাকিটা নয়: একজন ডিলার কয়েক মিলের মাল একসাথে কেনেন, আর তাঁর বকেয়ার
 * কোন অংশ কোন মিলের সেটা কোনো খাতায় লেখা থাকে না।
 *
 * তাই ওটা ভাগ করে বসানো হয় — ওই পরিসরে প্রতিটা মিলের বিক্রীত মালের
 * ব্যয়ের অনুপাতে। **এটা একটা আন্দাজ, আর কলামের নামেই সেটা লেখা।**
 * আন্দাজটা লুকিয়ে রাখলে সংখ্যাটা যতটা নিশ্চিত দেখাত, ততটা নয়।
 */
final class ReturnOnCapitalReport
{
    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::byPrincipal());
    }

    public static function byPrincipal(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'supplier.return_on_capital',
            title: 'supplier::menu.return_on_capital',
            filters: ['date_range', 'branch'],
            groupBy: 'supplier_id',
            query: fn (array $f) => self::query($f),
            columns: [
                [
                    'key' => 'supplier_name',
                    'label' => 'supplier::field.name',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'party_type_literal',
                    'source_id' => 'supplier_id',
                ],
                ['key' => 'advance', 'label' => 'supplier::field.capital_advance', 'type' => ReportColumn::MONEY],
                ['key' => 'claims', 'label' => 'supplier::field.capital_claims', 'type' => ReportColumn::MONEY],
                ['key' => 'stock', 'label' => 'supplier::field.capital_stock', 'type' => ReportColumn::MONEY],
                ['key' => 'dealer_share', 'label' => 'supplier::field.capital_dealer', 'type' => ReportColumn::MONEY],
                ['key' => 'capital', 'label' => 'supplier::field.capital_total', 'type' => ReportColumn::MONEY],
                ['key' => 'margin', 'label' => 'supplier::field.margin', 'type' => ReportColumn::MONEY],
                ['key' => 'return_percent', 'label' => 'supplier::field.return_percent', 'type' => ReportColumn::PERCENT],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private static function query(array $f): Builder
    {
        $company = $f['company_id'];
        $from = $f['from'];
        $to = $f['to'];

        /*
         * বছরে ফেরত — পরিসরটা এক বছর না হলেও।
         *
         * তিন মাসের ফারাক দিয়ে ভাগ করলে সংখ্যাটা বছরের ফেরতের চার
         * ভাগের এক দেখাত, আর মালিক ভাবতেন ব্যবসাটা খারাপ চলছে। তাই
         * পরিসরের দিন গুনে বছরে টেনে নেওয়া হয়।
         */
        $days = max(1, Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1);
        $annualise = 365 / $days;

        // ডিলারের মোট বাকি — ভাগ করে বসানোর জন্য
        $dealerDue = (string) (DB::table('ledger_entries')
            ->where('company_id', $company)
            ->where('party_type', 'customer')
            ->where('trx_date', '<=', $to)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as due')
            ->value('due') ?? '0');

        $dealerDue = bccomp($dealerDue, '0', 4) > 0 ? $dealerDue : '0';

        return DB::table('suppliers')
            ->where('suppliers.company_id', $company)
            ->leftJoinSub(self::advance($company, $to), 'ad', 'ad.party_id', '=', 'suppliers.id')
            ->leftJoinSub(self::claims($company, $to), 'cl', 'cl.supplier_id', '=', 'suppliers.id')
            ->leftJoinSub(self::stock($company), 'st', 'st.supplier_id', '=', 'suppliers.id')
            ->leftJoinSub(self::traded($company, $from, $to), 'tr', 'tr.supplier_id', '=', 'suppliers.id')
            ->crossJoinSub(self::totalCost($company, $from, $to), 'tc')
            ->where(function ($q) {
                $q->where('ad.advance', '<>', 0)
                    ->orWhere('cl.claims', '<>', 0)
                    ->orWhere('st.stock', '<>', 0)
                    ->orWhere('tr.margin', '<>', 0);
            })
            ->orderByRaw('COALESCE(tr.margin, 0) DESC')
            ->select([
                DB::raw('suppliers.id as supplier_id'),
                self::supplierName(),
                DB::raw("'".Supplier::drillSourceType()."' as party_type_literal"),

                // মিলের কাছে আমাদের টাকা — ওদের হিসাবে ডেবিট জের
                DB::raw('GREATEST(COALESCE(ad.advance, 0), 0) as advance'),
                DB::raw('COALESCE(cl.claims, 0) as claims'),
                DB::raw('COALESCE(st.stock, 0) as stock'),

                /*
                 * ডিলারের বাকির ভাগ — এই মিলের বিক্রীত মালের ব্যয়ের
                 * অনুপাতে। মোট ব্যয় শূন্য হলে ভাগও শূন্য, নাহলে শূন্য
                 * দিয়ে ভাগ পড়ত।
                 */
                DB::raw('CASE WHEN tc.total_cost > 0
                    THEN '.(float) $dealerDue.' * COALESCE(tr.cost, 0) / tc.total_cost
                    ELSE 0 END as dealer_share'),

                DB::raw('GREATEST(COALESCE(ad.advance, 0), 0) + COALESCE(cl.claims, 0)
                    + COALESCE(st.stock, 0)
                    + CASE WHEN tc.total_cost > 0
                        THEN '.(float) $dealerDue.' * COALESCE(tr.cost, 0) / tc.total_cost
                        ELSE 0 END as capital'),

                DB::raw('COALESCE(tr.margin, 0) as margin'),

                /*
                 * ফেরত = বছরে টানা মার্জিন ÷ আটকে থাকা পুঁজি × ১০০।
                 *
                 * পুঁজি শূন্য হলে ফেরতও শূন্য দেখানো হয়, অসীম নয়:
                 * কিছু আটকে না রেখে আয় করা মানে সংখ্যাটার কোনো অর্থ
                 * নেই, আর "∞%" পড়ে কেউ ভুল সিদ্ধান্ত নেবেন।
                 */
                DB::raw('CASE WHEN (GREATEST(COALESCE(ad.advance, 0), 0) + COALESCE(cl.claims, 0)
                        + COALESCE(st.stock, 0)
                        + CASE WHEN tc.total_cost > 0
                            THEN '.(float) $dealerDue.' * COALESCE(tr.cost, 0) / tc.total_cost
                            ELSE 0 END) > 0
                    THEN ROUND(COALESCE(tr.margin, 0) * '.$annualise.' * 100
                        / (GREATEST(COALESCE(ad.advance, 0), 0) + COALESCE(cl.claims, 0)
                            + COALESCE(st.stock, 0)
                            + CASE WHEN tc.total_cost > 0
                                THEN '.(float) $dealerDue.' * COALESCE(tr.cost, 0) / tc.total_cost
                                ELSE 0 END), 2)
                    ELSE 0 END as return_percent'),
            ]);
    }

    /**
     * মিলের কাছে আমাদের টাকা — অগ্রিম ও আটকানো মার্জিন, একসাথে।
     *
     * ── কেন দুইটা আলাদা করে দেখানো হয় না ────────────────────────────
     * খাতায় দুইটাই একই জিনিস: সরবরাহকারীর হিসাবে ডেবিট জের। ২০ লাখ
     * অগ্রিম দিলে জের ডেবিট, আর মিল আমাদের মার্জিন আটকে রাখলেও ডেবিট।
     * আলাদা করতে গেলে অনুমান করতে হত কোন টাকাটা কোনটা — আর সেটা
     * সংখ্যাটাকে আরও নিশ্চিত দেখাত, সত্যি করত না।
     */
    private static function advance(int $company, string $to): Builder
    {
        return DB::table('ledger_entries')
            ->where('company_id', $company)
            ->where('party_type', Supplier::drillSourceType())
            ->where('trx_date', '<=', $to)
            ->groupBy('party_id')
            ->select(['party_id', DB::raw('SUM(debit) - SUM(credit) as advance')]);
    }

    /** কমিশনের দাবি, যা এখনো মেটেনি। */
    private static function claims(int $company, string $to): Builder
    {
        return DB::table('sal_commission_claims')
            ->where('company_id', $company)
            ->whereNull('deleted_at')
            ->where('status', 'pending')
            ->where('trx_date', '<=', $to)
            ->groupBy('supplier_id')
            ->select(['supplier_id', DB::raw('SUM(amount) as claims')]);
    }

    /**
     * শেলফে পড়ে থাকা মালের ক্রয়মূল্য — মিল ধরে।
     *
     * খোলা ব্যয়-স্তর থেকে, তাই "কোন মিলের মাল" প্রশ্নটা আন্দাজ নয়:
     * স্তরটাই বলে মালটা কার চালানে এসেছিল আর কত দামে।
     */
    private static function stock(int $company): Builder
    {
        return DB::table('inv_cost_layers as l')
            ->leftJoin('pur_receipts as r', function ($join) {
                $join->on('r.id', '=', 'l.source_id')
                    ->where('l.source_type', '=', PurchaseReceipt::STOCK_SOURCE);
            })
            ->leftJoin('pur_bills as b', function ($join) {
                $join->on('b.id', '=', 'l.source_id')
                    ->where('l.source_type', '=', PurchaseBill::STOCK_SOURCE);
            })
            ->where('l.company_id', $company)
            ->where('l.qty_remaining', '>', 0)
            ->whereNotNull(DB::raw('COALESCE(r.supplier_id, b.supplier_id)'))
            ->groupBy(DB::raw('COALESCE(r.supplier_id, b.supplier_id)'))
            ->select([
                DB::raw('COALESCE(r.supplier_id, b.supplier_id) as supplier_id'),
                DB::raw('SUM(l.qty_remaining * l.unit_cost) as stock'),
            ]);
    }

    /**
     * এই পরিসরে কার মাল কত বিক্রি হলো, আর মার্জিন কত।
     *
     * নিষ্পত্তির রিপোর্টের সাথে একই পথ ধরে গোনা — দুই পাতায় দুই রকম
     * সংখ্যা দেখালে কোনটা সত্যি সেটাই প্রশ্ন হয়ে দাঁড়াত।
     */
    private static function traded(int $company, string $from, string $to): Builder
    {
        return DB::table('inv_cost_layer_uses as u')
            ->join('inv_cost_layers as l', 'l.id', '=', 'u.cost_layer_id')
            ->join('sal_invoices as i', function ($join) {
                $join->on('i.id', '=', 'u.source_id')
                    ->where('u.source_type', '=', SalesInvoice::STOCK_SOURCE);
            })
            ->join('sal_invoice_lines as il', function ($join) {
                $join->on('il.sales_invoice_id', '=', 'i.id')
                    ->on('il.product_id', '=', 'u.product_id');
            })
            ->leftJoin('pur_receipts as r', function ($join) {
                $join->on('r.id', '=', 'l.source_id')
                    ->where('l.source_type', '=', PurchaseReceipt::STOCK_SOURCE);
            })
            ->leftJoin('pur_bills as b', function ($join) {
                $join->on('b.id', '=', 'l.source_id')
                    ->where('l.source_type', '=', PurchaseBill::STOCK_SOURCE);
            })
            ->where('u.company_id', $company)
            ->whereBetween('i.trx_date', [$from, $to])
            ->whereIn('i.status', ['confirmed', 'closed'])
            ->whereNotNull(DB::raw('COALESCE(r.supplier_id, b.supplier_id)'))
            ->groupBy(DB::raw('COALESCE(r.supplier_id, b.supplier_id)'))
            ->select([
                DB::raw('COALESCE(r.supplier_id, b.supplier_id) as supplier_id'),
                DB::raw('SUM(u.amount) as cost'),
                DB::raw('SUM(il.rate * u.qty) - SUM(u.amount) as margin'),
            ]);
    }

    /** সব মিল মিলিয়ে বিক্রীত মালের মোট ব্যয় — ভাগ করার হর। */
    private static function totalCost(int $company, string $from, string $to): Builder
    {
        return DB::table('inv_cost_layer_uses as u')
            ->join('sal_invoices as i', function ($join) {
                $join->on('i.id', '=', 'u.source_id')
                    ->where('u.source_type', '=', SalesInvoice::STOCK_SOURCE);
            })
            ->where('u.company_id', $company)
            ->whereBetween('i.trx_date', [$from, $to])
            ->whereIn('i.status', ['confirmed', 'closed'])
            ->select([DB::raw('COALESCE(SUM(u.amount), 0) as total_cost')]);
    }

    private static function supplierName(): Expression
    {
        $column = app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF(suppliers.name_bn, ''), suppliers.name_en)"
            : 'suppliers.name_en';

        return DB::raw("CONCAT(suppliers.code, ' — ', {$column}) as supplier_name");
    }
}
