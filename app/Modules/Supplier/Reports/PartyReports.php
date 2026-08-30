<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * সরবরাহকারীর বকেয়া তালিকা ও বয়সভিত্তিক বিশ্লেষণ।
 *
 * দুইটাই লেজার থেকে গোনা, সরবরাহকারীর টেবিলের কোনো কলাম থেকে নয় —
 * তাই প্ল্যানের শর্তটা ("Statement-এর ব্যালেন্স Accounts-এর ledger-এর
 * সাথে মিলছে") সংজ্ঞা অনুযায়ীই সত্যি, মিলিয়ে দেখার দরকার পড়ে না।
 */
final class PartyReports
{
    /**
     * বয়সের ধাপগুলো — দিনে।
     *
     * ০–৩০, ৩১–৬০, ৬১–৯০, ৯০+। বাংলাদেশের পরিবেশনে শর্ত সাধারণত ৭ থেকে
     * ৩০ দিন, তাই প্রথম ধাপটাই "সময়মতো", আর ৯০ পেরোনো মানে সম্পর্কে
     * সমস্যা — ওটা আলাদা করে দেখা দরকার।
     *
     * @var list<int>
     */
    public const BUCKETS = [30, 60, 90];

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::payableList());
        $engine->register(self::ageing());
    }

    /**
     * কাকে কত দিতে বাকি।
     *
     * শূন্য বকেয়ার সরবরাহকারীরা বাদ: তালিকাটার প্রশ্নই "কাকে দিতে
     * হবে", আর যাদের কিছু দিতে হবে না তাদের সারি শুধু ভিড় বাড়ায়।
     */
    public static function payableList(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'supplier.payable_list',
            title: 'supplier::menu.payable_list',
            filters: ['date_range', 'branch', 'party_type'],
            groupBy: 'party_id',
            query: fn (array $f) => DB::table('ledger_entries')
                ->join('suppliers', 'suppliers.id', '=', 'ledger_entries.party_id')
                ->where('ledger_entries.company_id', $f['company_id'])
                ->where('ledger_entries.party_type', Supplier::drillSourceType())
                ->when($f['branch_id'], fn ($q, $branch) => $q->where('ledger_entries.branch_id', $branch))
                /*
                 * পক্ষের ধরন ধরে ছাঁকা — "ট্রান্সপোর্টারদের কত দিতে হবে"।
                 *
                 * পরিকল্পনায় ডিপোর ছয়টা বিশেষ খতিয়ান চাওয়া হয়েছিল —
                 * ভাড়া গাড়ি, ট্রান্সপোর্ট ভেন্ডর, শ্রমিক ঠিকাদার, দালাল।
                 * ওরা সবাই **পক্ষ**, আর পক্ষের ধরন আগে থেকেই একটা খোলা
                 * তালিকা। ছয়টা আলাদা পর্দা বানালে সপ্তম ধরনটার দিন আবার
                 * কোড লিখতে হত; ছাঁকনি হলে কোম্পানি নিজে একটা ধরন যোগ
                 * করলেই তার খতিয়ান পেয়ে যায়।
                 */
                ->when($f['party_type_id'] ?? null,
                    fn ($q, $type) => $q->where('suppliers.party_type_id', $type))
                // শুরুর তারিখ ধরা হয় না: বকেয়া একটা মুহূর্তের অবস্থা,
                // পরিসরের নয় — "কত দিন থেকে বাকি" প্রশ্নটা ageing-এর
                ->where('ledger_entries.trx_date', '<=', $f['to'])
                ->groupBy('ledger_entries.party_id', 'suppliers.code', 'suppliers.name_en', 'suppliers.name_bn')
                ->havingRaw('SUM(ledger_entries.credit) - SUM(ledger_entries.debit) <> 0')
                ->orderByRaw('SUM(ledger_entries.credit) - SUM(ledger_entries.debit) DESC')
                ->select([
                    'ledger_entries.party_id',
                    self::supplierName(),
                    // ড্রিল-ডাউনের জন্য source_type — প্রতিটা সারিতে একই,
                    // তাই ধ্রুবক হিসেবেই select করা। engine সারিগুলোকে
                    // সাধারণ অ্যারে হিসেবে দেখে, তাই কলামটা থাকতেই হবে।
                    DB::raw("'".Supplier::drillSourceType()."' as party_type_literal"),
                    DB::raw('SUM(ledger_entries.credit) - SUM(ledger_entries.debit) as payable'),
                ]),
            columns: [
                [
                    'key' => 'supplier_name',
                    'label' => 'supplier::field.supplier',
                    'type' => ReportColumn::DOCUMENT,
                    // নামটা ক্লিকযোগ্য — নিয়ম ১
                    'source_type' => 'party_type_literal',
                    'source_id' => 'party_id',
                ],
                ['key' => 'payable', 'label' => 'supplier::field.payable', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * কত দিন ধরে বাকি।
     *
     * প্রতিটা এন্ট্রির বয়স তার নিজের তারিখ থেকে গোনা হয়, সরবরাহকারীর
     * শেষ লেনদেন থেকে নয়: একজনকে ছয় মাস ধরে বাকি রেখে গতকাল একটা
     * ছোট বিল দিলে পুরো বকেয়াটা "১ দিনের" হয়ে যেত।
     *
     * পরিশোধগুলো (ডেবিট) সবচেয়ে পুরনো ধাপ থেকে কাটা হয় — কাগজে-কলমেও
     * সেটাই নিয়ম, আর নাহলে পুরনো বকেয়া কখনো কমত না।
     */
    public static function ageing(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'supplier.ageing',
            title: 'supplier::menu.ageing',
            filters: ['date_range', 'branch', 'party_type'],
            groupBy: 'party_id',
            query: function (array $f) {
                $asOf = Carbon::parse($f['to']);

                $bucket = function (?int $from, ?int $to) use ($asOf) {
                    $conditions = [];

                    if ($to !== null) {
                        $conditions[] = 'ledger_entries.trx_date > '
                            .DB::getPdo()->quote($asOf->copy()->subDays($to)->toDateString());
                    }

                    if ($from !== null) {
                        $conditions[] = 'ledger_entries.trx_date <= '
                            .DB::getPdo()->quote($asOf->copy()->subDays($from)->toDateString());
                    }

                    $where = $conditions === [] ? '1=1' : implode(' AND ', $conditions);

                    return "SUM(CASE WHEN {$where} THEN ledger_entries.credit - ledger_entries.debit ELSE 0 END)";
                };

                [$b1, $b2, $b3] = self::BUCKETS;

                return DB::table('ledger_entries')
                    ->join('suppliers', 'suppliers.id', '=', 'ledger_entries.party_id')
                    ->where('ledger_entries.company_id', $f['company_id'])
                    ->where('ledger_entries.party_type', Supplier::drillSourceType())
                    ->when($f['branch_id'], fn ($q, $branch) => $q->where('ledger_entries.branch_id', $branch))
                    /* একই ছাঁকনি — বয়সের তালিকাতেও প্রশ্নটা একই */
                    ->when($f['party_type_id'] ?? null,
                        fn ($q, $type) => $q->where('suppliers.party_type_id', $type))
                    ->where('ledger_entries.trx_date', '<=', $f['to'])
                    ->groupBy('ledger_entries.party_id', 'suppliers.code', 'suppliers.name_en', 'suppliers.name_bn')
                    ->havingRaw('SUM(ledger_entries.credit) - SUM(ledger_entries.debit) <> 0')
                    ->orderByRaw('SUM(ledger_entries.credit) - SUM(ledger_entries.debit) DESC')
                    ->select([
                        'ledger_entries.party_id',
                        self::supplierName(),
                        DB::raw("'".Supplier::drillSourceType()."' as party_type_literal"),
                        DB::raw($bucket(null, $b1).' as bucket_current'),
                        DB::raw($bucket($b1, $b2).' as bucket_30'),
                        DB::raw($bucket($b2, $b3).' as bucket_60'),
                        DB::raw($bucket($b3, null).' as bucket_90'),
                        DB::raw('SUM(ledger_entries.credit) - SUM(ledger_entries.debit) as payable'),
                    ]);
            },
            columns: [
                [
                    'key' => 'supplier_name',
                    'label' => 'supplier::field.supplier',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'party_type_literal',
                    'source_id' => 'party_id',
                ],
                ['key' => 'bucket_current', 'label' => 'supplier::field.bucket_current', 'type' => ReportColumn::MONEY],
                ['key' => 'bucket_30', 'label' => 'supplier::field.bucket_30', 'type' => ReportColumn::MONEY],
                ['key' => 'bucket_60', 'label' => 'supplier::field.bucket_60', 'type' => ReportColumn::MONEY],
                ['key' => 'bucket_90', 'label' => 'supplier::field.bucket_90', 'type' => ReportColumn::MONEY],
                ['key' => 'payable', 'label' => 'supplier::field.payable', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * সরবরাহকারীর নাম — কোড সহ, ব্যবহারকারীর ভাষায়।
     *
     * SQL-এ ভাষা বাছা হয়, কারণ নামটা কোয়েরির অংশ; PHP-তে বাছলে প্রতিটা
     * সারির জন্য মডেল লাগত, আর engine সারিগুলোকে সাধারণ অ্যারে হিসেবেই
     * দেখে। CoreReports-এ খাতের নামের ক্ষেত্রেও একই সিদ্ধান্ত।
     */
    private static function supplierName(): Expression
    {
        $name = app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF(suppliers.name_bn, ''), suppliers.name_en)"
            : 'suppliers.name_en';

        return DB::raw("CONCAT(suppliers.code, ' — ', {$name}) as supplier_name");
    }
}
