<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * কোম্পানি ধরে নিষ্পত্তির হিসাব — সুপার ডিপোর মাসিক কাগজ।
 *
 * ── কোন প্রশ্নের উত্তর ───────────────────────────────────────────────
 * পরিবেশক ডিপো একটা কোম্পানির মাল কেনে ডিপো প্রাইসে, বেচে ডিলার
 * প্রাইসে, আর পার্থক্যটাই তার আয়। মাস শেষে একটাই প্রশ্ন থাকে:
 *
 *   *"এই কোম্পানির কত টাকার মাল এল, তার কতটা বিক্রি হলো, আমার মার্জিন
 *   কত দাঁড়াল, আর আমি ওদের কত দিলাম — এখনো কত দিতে বাকি?"*
 *
 * আজ ওই উত্তরটা পেতে চার-পাঁচটা রিপোর্ট মিলিয়ে হাতে গুনতে হয়, আর
 * ঠিক সেখানেই কোম্পানির লেজারের সাথে তর্ক বাধে।
 *
 * ── "কার মাল বিক্রি হলো" — অনুমান নয়, FIFO স্তর ধরে ───────────────
 * পণ্যের সাথে সরবরাহকারীর কোনো যোগ নেই, আর থাকা উচিতও নয়: একই পণ্য
 * দুই কোম্পানি থেকে আসতে পারে। তাই সম্পর্কটা টানা হয় **ব্যয়-স্তর**
 * ধরে — বিক্রয় → `inv_cost_layer_uses` → `inv_cost_layers` → যে
 * ক্রয়ে মালটা ঢুকেছিল → সরবরাহকারী।
 *
 * এটা আন্দাজ নয়: FIFO যে স্তরটা টেনেছে, সেই স্তরটাই বলে দেয় মালটা
 * কার চালানে এসেছিল আর কত দামে। পণ্য-মাস্টারে একটা `supplier_id`
 * বসিয়ে দিলে অঙ্কটা কাছাকাছি হত, সত্যি হত না।
 *
 * ── বিক্রয়মূল্য বের হয় বিলের লাইন থেকে ─────────────────────────────
 * স্তর বলে **খরচ**, দাম নয়। তাই স্তরের ব্যবহারটাকে বিলের লাইনের সাথে
 * মেলানো হয় বিল ও পণ্য ধরে, আর ওই লাইনের `amount` থেকে বিক্রয়মূল্য
 * আসে। এক বিলে একই পণ্য দুইবার থাকলে (দুই দরে) সারিদুটো একসাথে গোনা
 * হয় — যোগফল ঠিকই থাকে।
 */
final class SettlementReport
{
    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::definition());
    }

    public static function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'purchase.settlement',
            title: 'supplier::menu.settlement',
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
                ['key' => 'goods_in', 'label' => 'supplier::field.goods_in', 'type' => ReportColumn::MONEY],
                ['key' => 'sold', 'label' => 'supplier::field.sold', 'type' => ReportColumn::MONEY],
                ['key' => 'cost_of_sold', 'label' => 'supplier::field.cost_of_sold', 'type' => ReportColumn::MONEY],
                ['key' => 'margin', 'label' => 'supplier::field.margin', 'type' => ReportColumn::MONEY],
                ['key' => 'paid_to_them', 'label' => 'supplier::field.paid_to_them', 'type' => ReportColumn::MONEY],
                ['key' => 'still_owed', 'label' => 'supplier::field.still_owed', 'type' => ReportColumn::MONEY],
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

        return DB::table('suppliers')
            ->where('suppliers.company_id', $company)
            ->leftJoinSub(self::goodsIn($company, $from, $to), 'gi', 'gi.supplier_id', '=', 'suppliers.id')
            ->leftJoinSub(self::soldFromThem($company, $from, $to), 'sf', 'sf.supplier_id', '=', 'suppliers.id')
            ->leftJoinSub(self::money($company, $from, $to), 'mo', 'mo.party_id', '=', 'suppliers.id')
            ->leftJoinSub(self::balance($company, $to), 'ba', 'ba.party_id', '=', 'suppliers.id')

            /*
             * যে কোম্পানির সাথে এই পরিসরে কিছুই ঘটেনি, তার সারি আসে না।
             *
             * সব সরবরাহকারী দেখালে তালিকাটা শূন্যের সারিতে ভরে যেত, আর
             * যেটা দেখার জন্য পাতাটা খোলা — মাসের নিষ্পত্তি — সেটাই
             * খুঁজে পেতে হত।
             */
            ->where(function ($q) {
                $q->where('gi.goods_in', '<>', 0)
                    ->orWhere('sf.sold', '<>', 0)
                    ->orWhere('mo.paid_to_them', '<>', 0)
                    ->orWhere('ba.still_owed', '<>', 0);
            })
            ->orderByRaw('COALESCE(sf.sold, 0) DESC')
            ->select([
                DB::raw('suppliers.id as supplier_id'),
                self::supplierName(),
                DB::raw("'".Supplier::drillSourceType()."' as party_type_literal"),
                DB::raw('COALESCE(gi.goods_in, 0) as goods_in'),
                DB::raw('COALESCE(sf.sold, 0) as sold'),
                DB::raw('COALESCE(sf.cost_of_sold, 0) as cost_of_sold'),
                DB::raw('COALESCE(sf.sold, 0) - COALESCE(sf.cost_of_sold, 0) as margin'),
                DB::raw('COALESCE(mo.paid_to_them, 0) as paid_to_them'),
                DB::raw('COALESCE(ba.still_owed, 0) as still_owed'),
            ]);
    }

    /**
     * এই পরিসরে কত টাকার মাল এল — ডিপো প্রাইসে।
     *
     * চালান ধরে গোনা হয়, বিল ধরে নয়: মালটা গুদামে ঢোকে চালানে, আর
     * বিল আসে পরে — কখনো পরের মাসে। বিল ধরে গুনলে যে মাসে মাল এল সেই
     * মাসের সারিতে কিছুই দেখা যেত না।
     */
    private static function goodsIn(int $company, string $from, string $to): Builder
    {
        return DB::table('pur_receipts')
            ->where('company_id', $company)
            ->whereBetween('trx_date', [$from, $to])
            ->whereIn('status', ['confirmed', 'closed'])
            ->groupBy('supplier_id')
            ->select(['supplier_id', DB::raw('SUM(total) as goods_in')]);
    }

    /**
     * কার মাল কত টাকায় বিক্রি হলো, আর তার খরচ কত ছিল।
     *
     * ── স্তর থেকে সরবরাহকারী পর্যন্ত পথটা ───────────────────────────
     * `inv_cost_layer_uses` (কোন বিক্রয় কোন স্তর টানল)
     *   → `inv_cost_layers` (স্তরটা কোন ক্রয়ে জন্মেছিল)
     *     → `pur_receipts` বা `pur_bills` (সেই ক্রয়ের সরবরাহকারী)
     *
     * দুইটা উৎস, কারণ মাল দুই পথে ঢোকে: চালানে, আর চালান-ছাড়া বিলে।
     */
    private static function soldFromThem(int $company, string $from, string $to): Builder
    {
        return DB::table('inv_cost_layer_uses as u')
            ->join('inv_cost_layers as l', 'l.id', '=', 'u.cost_layer_id')
            ->join('sal_invoices as i', function ($join) {
                $join->on('i.id', '=', 'u.source_id')
                    ->where('u.source_type', '=', SalesInvoice::STOCK_SOURCE);
            })
            ->leftJoin('pur_receipts as r', function ($join) {
                $join->on('r.id', '=', 'l.source_id')
                    ->where('l.source_type', '=', PurchaseReceipt::STOCK_SOURCE);
            })
            ->leftJoin('pur_bills as b', function ($join) {
                $join->on('b.id', '=', 'l.source_id')
                    ->where('l.source_type', '=', PurchaseBill::STOCK_SOURCE);
            })

            /*
             * বিক্রয়মূল্যটা বিলের লাইন থেকে, স্তর থেকে নয় — স্তর কেবল
             * খরচ জানে। মিলানো হয় বিল ও পণ্য ধরে।
             */
            ->join('sal_invoice_lines as il', function ($join) {
                $join->on('il.sales_invoice_id', '=', 'i.id')
                    ->on('il.product_id', '=', 'u.product_id');
            })
            ->where('u.company_id', $company)
            ->whereBetween('i.trx_date', [$from, $to])
            ->whereIn('i.status', ['confirmed', 'closed'])
            ->whereNotNull(DB::raw('COALESCE(r.supplier_id, b.supplier_id)'))
            ->groupBy(DB::raw('COALESCE(r.supplier_id, b.supplier_id)'))
            ->select([
                DB::raw('COALESCE(r.supplier_id, b.supplier_id) as supplier_id'),

                /*
                 * বিক্রয়মূল্য = ওই লাইনের দর × স্তর থেকে টানা পরিমাণ।
                 *
                 * লাইনের পুরো `amount` নেওয়া যেত না: এক লাইনের মাল
                 * একাধিক স্তর থেকে আসতে পারে (পুরনো দরের কিছু, নতুন
                 * দরের কিছু), আর তখন প্রতিটা স্তরের সাথে পুরো অঙ্কটা
                 * গোনা হত — বিক্রয় দ্বিগুণ দেখাত।
                 */
                DB::raw('SUM(il.rate * u.qty) as sold'),
                DB::raw('SUM(u.amount) as cost_of_sold'),
            ]);
    }

    /**
     * এই পরিসরে কোম্পানিকে কত দেওয়া হলো।
     *
     * সরবরাহকারীর হিসাবের **ডেবিট** — অর্থাৎ দেনা কমেছে এমন সবকিছু।
     * এতে সরাসরি পরিশোধও আসে, আর ডিলার সরাসরি কোম্পানিকে দিলে যে তিন
     * কোণা সমন্বয় বসে সেটাও। দুইটাই একই কথা বলে: ওদের কাছে আমাদের
     * দেনা এতটা কমেছে।
     */
    private static function money(int $company, string $from, string $to): Builder
    {
        return DB::table('ledger_entries')
            ->where('company_id', $company)
            ->where('party_type', Supplier::drillSourceType())
            ->whereBetween('trx_date', [$from, $to])
            ->groupBy('party_id')
            ->select(['party_id', DB::raw('SUM(debit) as paid_to_them')]);
    }

    /**
     * তারিখ পর্যন্ত এখনো কত দিতে বাকি।
     *
     * শুরুর তারিখ ধরা হয় না: জের একটা মুহূর্তের অবস্থা, পরিসরের নয়।
     * ক্রেডিট − ডেবিট, কারণ সরবরাহকারী একটা দায় — ওদের পাওনা থাকলে
     * সংখ্যাটা ধনাত্মক।
     */
    private static function balance(int $company, string $to): Builder
    {
        return DB::table('ledger_entries')
            ->where('company_id', $company)
            ->where('party_type', Supplier::drillSourceType())
            ->where('trx_date', '<=', $to)
            ->groupBy('party_id')
            ->select(['party_id', DB::raw('SUM(credit) - SUM(debit) as still_owed')]);
    }

    /** সরবরাহকারীর নাম — কোডসহ, ব্যবহারকারীর ভাষায়। */
    private static function supplierName(): Expression
    {
        $column = app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF(suppliers.name_bn, ''), suppliers.name_en)"
            : 'suppliers.name_en';

        return DB::raw("CONCAT(suppliers.code, ' — ', {$column}) as supplier_name");
    }
}
