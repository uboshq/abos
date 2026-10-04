<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * পথ ধরে বিক্রয় — NEXUS §২৮।
 *
 * ── কোন প্রশ্নের উত্তর ───────────────────────────────────────────────
 * *"কাউন্টারে কত, ডিলারের হাতে কত, অনলাইনে কত — আর কোন পথে ফেরত
 * বেশি?"* ⓘ ৩.৮২% মার্জিনে একটা পথের ছাড় বা ফেরত তার পুরো লাভ খেয়ে
 * ফেলতে পারে, আর গ্রাহক-ভিত্তিক তালিকায় সেটা ছড়িয়ে থাকে, চোখে পড়ে না।
 *
 * ── ⭐ পথটা কাগজের নিজের ঘর থেকে, গ্রাহকের ঘর থেকে নয় ─────────────────
 * গ্রাহকের পথ পরে বদলালে পুরনো মাসের সংখ্যা যেন না বদলায়
 * ([[CarriesTheSalesChannel]])।
 *
 * ── হিসাবের নিয়ম — [[SalesReports::byCustomer()]]-এর সাথে এক ─────────
 *   · খাতায় বসা বিল (`POSTED`) — খসড়া নয়, বাতিল নয়
 *   · বিক্রয় = মোট − ভ্যাট (ভ্যাট সরকারের টাকা, আমাদের আয় নয়)
 *   · ফেরত — খাতায় বসা ফেরত, **নিজের তারিখে** (যে মাসে মাল ফিরল সে মাসেই
 *     বিক্রি কমে), আর পথ ফেরতের নিজের ঘর থেকে (যেটা বিলের পথই বয়)
 *
 * ⓘ ফেরত আলাদা কলামে, বিক্রি থেকে কেটে নয় — "কত বেচলাম" আর "কত ফিরল"
 * দুইটা আলাদা প্রশ্ন; শেষ কলামে নিট।
 *
 * ⛔ পথহীন কাগজ বাদ পড়ে না — এক সারিতে জড়ো হয়। বাদ দিলে এই রিপোর্টের
 * যোগফল আর গ্রাহক-রিপোর্টের যোগফল আলাদা হত, আর মেলাতে গিয়ে কেউ ভাবত
 * হিসাব ভুল।
 */
final class SalesChannelReports
{
    public const KEY = 'sales.by_channel';

    /** অনুমতি — বিক্রয়ের বাকি রিপোর্টগুলোর একই চাবি। */
    public const PERMISSION = 'sales.report';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::byChannel());
    }

    public static function byChannel(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            title: 'sales::channel.report_title',
            filters: ['date_range', 'branch'],
            groupBy: 'channel_id',
            rankBy: 'net_sales',
            permission: self::PERMISSION,
            query: fn (array $f) => DB::query()
                ->fromSub(self::movements($f), 'm')
                ->leftJoin('mdm_sales_channels as ch', 'ch.id', '=', 'm.channel_id')
                // ⚠️ লাইভ ONLY_FULL_GROUP_BY — নির্বাচিত প্রতিটা অ-যোগফল ঘর এখানে
                ->groupBy('m.channel_id', 'ch.name_en', 'ch.name_bn')
                ->orderByRaw('SUM(m.net_sales) desc')
                ->select([
                    'm.channel_id',
                    self::channelName(),
                    DB::raw('SUM(m.invoice_count) as invoice_count'),
                    DB::raw('SUM(m.qty) as qty'),
                    DB::raw('SUM(m.gross) as gross'),
                    DB::raw('SUM(m.discount) as discount'),
                    DB::raw('SUM(m.net_sales) as net_sales'),
                    DB::raw('SUM(m.returned_qty) as returned_qty'),
                    DB::raw('SUM(m.return_value) as returns_value'),
                    DB::raw('SUM(m.net_sales) - SUM(m.return_value) as net_after_returns'),
                ]),
            columns: [
                ['key' => 'channel_name', 'label' => 'sales::channel.channel'],
                ['key' => 'invoice_count', 'label' => 'sales::channel.invoice_count'],
                ['key' => 'qty', 'label' => 'sales::channel.qty', 'type' => ReportColumn::QUANTITY],
                ['key' => 'gross', 'label' => 'sales::channel.gross', 'type' => ReportColumn::MONEY],
                ['key' => 'discount', 'label' => 'sales::channel.discount', 'type' => ReportColumn::MONEY],
                ['key' => 'net_sales', 'label' => 'sales::channel.net_sales', 'type' => ReportColumn::MONEY],
                ['key' => 'returned_qty', 'label' => 'sales::channel.returned_qty', 'type' => ReportColumn::QUANTITY],
                ['key' => 'returns_value', 'label' => 'sales::channel.returns', 'type' => ReportColumn::MONEY],
                ['key' => 'net_after_returns', 'label' => 'sales::channel.net_after_returns', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * বিল আর ফেরত — একই আকারের সারিতে, পাশাপাশি।
     *
     * ⓘ দুই অংশেই কোম্পানি, শাখা, তারিখ আর অবস্থার ছাঁকনি আলাদা করে বসে —
     * ⛔ বাইরের কোয়েরিতে বসালে ফেরতের সারি বিলের তারিখে ছাঁকা হত।
     *
     * @param  array<string, mixed>  $f
     */
    private static function movements(array $f): Builder
    {
        // ⓘ সাব-কোয়েরিতে প্লেসহোল্ডার নেই — SalesReports-এর মাথায় কারণ লেখা।
        // লাইনের টেবিলে company_id নেই; মাথার সারিটা নিচে কোম্পানি ধরে বাছা।
        $invoiceQty = '(select COALESCE(SUM(il.qty), 0) from sal_invoice_lines il
                where il.sales_invoice_id = i.id)';

        $returnQty = '(select COALESCE(SUM(rl.qty), 0) from sal_return_lines rl
                where rl.sales_return_id = r.id)';

        $invoices = DB::table('sal_invoices as i')
            ->where('i.company_id', $f['company_id'])
            ->tap(ReportEngine::branchWall($f, 'i.branch_id'))
            ->whereBetween('i.trx_date', [$f['from'], $f['to']])
            ->whereNull('i.deleted_at')
            ->whereIn('i.status', DocumentStatus::POSTED)
            ->select([
                'i.channel_id',
                DB::raw('1 as invoice_count'),
                DB::raw("{$invoiceQty} as qty"),
                DB::raw('i.subtotal as gross'),
                DB::raw('i.discount as discount'),
                DB::raw('i.total - i.tax as net_sales'),
                DB::raw('0 as returned_qty'),
                DB::raw('0 as return_value'),
            ]);

        $returns = DB::table('sal_returns as r')
            ->where('r.company_id', $f['company_id'])
            ->tap(ReportEngine::branchWall($f, 'r.branch_id'))
            ->whereBetween('r.trx_date', [$f['from'], $f['to']])
            ->whereNull('r.deleted_at')
            ->whereIn('r.status', DocumentStatus::POSTED)
            ->select([
                'r.channel_id',
                DB::raw('0 as invoice_count'),
                DB::raw('0 as qty'),
                DB::raw('0 as gross'),
                DB::raw('0 as discount'),
                DB::raw('0 as net_sales'),
                DB::raw("{$returnQty} as returned_qty"),
                DB::raw('r.total - r.tax as return_value'),
            ]);

        return $invoices->unionAll($returns);
    }

    /** পথের নাম — পথ না থাকলে "পথ বসানো হয়নি"। */
    private static function channelName(): Expression
    {
        $name = app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF(ch.name_bn, ''), ch.name_en)"
            : 'ch.name_en';

        // ড্রাইভারকে দিয়ে উদ্ধৃতি — SELECT-এ `?` বাকি বাইন্ডিং এক ঘর সরিয়ে দেয়
        $none = DB::getPdo()->quote(__('sales::channel.none'));

        return DB::raw("COALESCE({$name}, {$none}) as channel_name");
    }
}
