<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Support\DocumentStatus;
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
        $engine->register(self::paymentSchedule());
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
    /**
     * পরিশোধের সময়সূচি — **"এই সপ্তাহে কার টাকা দিতে হবে"**।
     *
     * ── কেন এটা প্রদেয়ের তালিকা থেকে আলাদা ───────────────────────────
     * [[payableList]] বলে **কার কাছে কত** — পক্ষ ধরে, খতিয়ান থেকে। ⓘ
     * সেটা মাস শেষের প্রশ্ন।
     *
     * ⭐ রোজকার প্রশ্নটা অন্য: *"আজ-কাল কার টাকা দিতে হবে"* — আর তার
     * উত্তর **বিল ধরে, তারিখ ধরে**। মেয়াদের তারিখ খতিয়ানে থাকেই না,
     * থাকে বিলে (`pur_bills.due_on`) — তাই এই রিপোর্টটা বিল থেকে আসে।
     *
     * ── ⚠️ বকেয়ার সংজ্ঞা দুই জায়গায় — আর সেটা পাহারায় বাঁধা ──────────
     * আসল সংজ্ঞা [[PurchaseBill::dueAmount]]: **মোট বাদ শোধ, ঋণাত্মক
     * হলে শূন্য** (অতিরিক্ত শোধ বিলের বাকি নয়, সরবরাহকারীর অগ্রিম)।
     *
     * রিপোর্ট-ইঞ্জিন কলামে কেবল **চাবি** পড়ে, কোনো মেথড ডাকে না — তাই
     * সংখ্যাটা SQL-এ গুনতেই হয়। ⛔ দুইটা সংজ্ঞা রাখা যায় না, তাই
     * [[ThePaymentScheduleAgreesWithTheBillTest]] প্রতিটা বিলে দুইটা
     * মিলিয়ে দেখে — **আলাদা হলে লাল**।
     *
     * ⓘ শোধ গোনার সময় কেবল `DocumentStatus::POSTED` অবস্থার পরিশোধ —
     * হুবহু `Payment::posted()`-এর নিয়ম। বাতিল পরিশোধ বকেয়া কমায় না।
     *
     * ── ⚠️ তারিখহীন বিল লুকানো হয় না ────────────────────────────────
     * `due_on` খালি থাকতে পারে (শর্ত বসানো হয়নি এমন বিল)। নীরবে বাদ
     * দিলে **যোগফল প্রদেয়ের তালিকার সাথে মিলত না**, আর কেউ বুঝত না
     * কেন — সবচেয়ে খারাপ ধরনের অমিল।
     *
     * তাই ওগুলো তালিকায় থাকে, তারিখের ঘর খালি নিয়ে, আর **সবার শেষে**:
     * যার তারিখ নেই সে আজকের কাজ নয়, কিন্তু টাকাটা তো বাকিই।
     */
    public static function paymentSchedule(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'supplier.payment_schedule',
            title: 'supplier::menu.payment_schedule',
            filters: ['date_range', 'branch', 'party_type'],
            query: function (array $f) {
                /*
                 * বিলপ্রতি শোধ — আলাদা সাব-কোয়েরিতে, যাতে সংখ্যাটা
                 * ছাঁকনি ও কলাম **দুই জায়গাতেই** একই উৎস থেকে আসে।
                 */
                $paid = DB::table('pur_payment_lines as pl')
                    ->join('pur_payments as p', 'p.id', '=', 'pl.payment_id')

                    /*
                     * ⚠️ কোম্পানির ছাঁকনি এখানেও, যদিও বাইরের কোয়েরি
                     * বিলগুলোকে এই কোম্পানিতেই আটকে রেখেছে।
                     *
                     * ⓘ join-এর উপর ভরসা করে বিচ্ছিন্নতা ছেড়ে দেওয়া
                     * ভঙ্গুর: কেউ একদিন join-টা বদলালে ছাঁকনিটা নীরবে
                     * চলে যেত। **বহু-টেন্যান্টে বিচ্ছিন্নতা সুবিধা নয়,
                     * আইনি বাধ্যবাধকতা** — তাই প্রতিটা কাঁচা কোয়েরি
                     * নিজেই বলে সে কোন কোম্পানির।
                     */
                    ->where('pl.company_id', $f['company_id'])
                    ->where('p.company_id', $f['company_id'])
                    ->whereIn('p.status', DocumentStatus::POSTED)
                    ->whereNull('p.deleted_at')
                    ->groupBy('pl.purchase_bill_id')
                    ->selectRaw('pl.purchase_bill_id, COALESCE(SUM(pl.amount), 0) as paid');

                // GREATEST — [[PurchaseBill::dueAmount]]-এর ঋণাত্মক-ক্ল্যাম্প
                $due = 'GREATEST(pur_bills.total - COALESCE(paid.paid, 0), 0)';

                return DB::table('pur_bills')
                    ->join('suppliers', 'suppliers.id', '=', 'pur_bills.supplier_id')
                    ->leftJoinSub($paid, 'paid', 'paid.purchase_bill_id', '=', 'pur_bills.id')
                    ->where('pur_bills.company_id', $f['company_id'])
                    ->where('pur_bills.status', DocumentStatus::CONFIRMED)
                    ->whereNull('pur_bills.deleted_at')
                    ->when($f['branch_id'], fn ($q, $b) => $q->where('pur_bills.branch_id', $b))
                    ->when(
                        $f['party_type_id'] ?? null,
                        fn ($q, $type) => $q->where('suppliers.party_type_id', $type)
                    )

                    /*
                     * ⚠️ শুরুর তারিখ ধরা হয় না — বকেয়া একটা **মুহূর্তের
                     * অবস্থা**, পরিসরের নয় ([[payableList]]-এ একই যুক্তি)।
                     */
                    ->where('pur_bills.trx_date', '<=', $f['to'])
                    ->whereRaw($due.' > 0')

                    /*
                     * তারিখ আগে, তারিখহীনরা শেষে — MySQL-এ `NULL` ডিফল্টে
                     * আগে আসে, তাই আলাদা করে পিছনে পাঠানো।
                     */
                    ->orderByRaw('pur_bills.due_on IS NULL')
                    ->orderBy('pur_bills.due_on')
                    ->orderBy('pur_bills.id')
                    ->select([
                        'pur_bills.due_on',
                        'pur_bills.document_no',
                        self::supplierName(),
                        DB::raw("'".Supplier::drillSourceType()."' as party_type_literal"),
                        'pur_bills.supplier_id as party_id',
                        DB::raw($due.' as due_amount'),
                    ]);
            },
            columns: [
                [
                    'key' => 'due_on',
                    'label' => 'purchase::field.due_on',
                    'type' => ReportColumn::DATE,
                    'width' => '8rem',
                ],
                [
                    'key' => 'document_no',
                    'label' => 'core.print.document_no',
                    'type' => ReportColumn::DOCUMENT,
                    'width' => '10rem',
                    'source_type' => 'party_type_literal',
                    'source_id' => 'party_id',
                ],
                ['key' => 'supplier_name', 'label' => 'supplier::field.supplier'],
                [
                    'key' => 'due_amount',
                    'label' => 'supplier::field.payable',
                    'type' => ReportColumn::MONEY,
                ],
            ],
        );
    }

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
