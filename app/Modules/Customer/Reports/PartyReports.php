<?php

declare(strict_types=1);

namespace App\Modules\Customer\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * গ্রাহকের বকেয়া তালিকা ও বয়সভিত্তিক বিশ্লেষণ।
 *
 * সরবরাহকারীর দুইটা রিপোর্টের আয়না, চিহ্ন উল্টো: এখানে ডেবিট − ক্রেডিট,
 * কারণ প্রাপ্য ডেবিট প্রকৃতির।
 *
 * কোড ভাগ করা হয়নি ইচ্ছাকৃতভাবে। একটা শেয়ার্ড "PartyReportBuilder"
 * বানালে প্রতিটা জায়গায় "গ্রাহক না সরবরাহকারী" শর্ত বসত — খাতের কোড,
 * চিহ্ন, টেবিলের নাম, ভাষার কী, সবখানে। দুইটা ফাইল পাশাপাশি পড়া অনেক
 * সহজ, আর তৃতীয় কোনো পক্ষ আসার কথা নেই (সেকশন ১৯.৮)।
 *
 * বিবরণী (Statement) আলাদা রিপোর্ট নয়: সেটাই গ্রাহকের নিজের পাতা, যেখানে
 * প্রতিটা সারি তার ডকুমেন্টে ক্লিকযোগ্য। আলাদা করে বানালে একই টেবিল দুই
 * জায়গায় থাকত, আর একটায় খোলা ব্যালেন্স দেখাত অন্যটায় নয়।
 */
final class PartyReports
{
    /**
     * বয়সের ধাপ — দিনে। ০–৩০, ৩১–৬০, ৬১–৯০, ৯০+।
     *
     * @var list<int>
     */
    public const BUCKETS = [30, 60, 90];

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::dueList());
        $engine->register(self::ageing());
        $engine->register(self::noCreditLimit());
        $engine->register(self::collection());
    }

    /**
     * কে কত দিল — একটা সময়ের ভেতরে, গ্রাহকভিত্তিক।
     *
     * ── কেন বকেয়ার তালিকা দিয়ে এই প্রশ্নের উত্তর হয় না ────────────────
     * বকেয়ার তালিকা বলে **কত পাওনা**, আর সেটা একটা মুহূর্তের অবস্থা।
     * কিন্তু মাস শেষে মালিকের প্রশ্নটা আলাদা: "এ মাসে কে কত দিল"।
     * দুইটা এক নয় — যে গ্রাহক মাসজুড়ে নিয়মিত দিয়েছেন অথচ নতুন বিলও
     * নিয়েছেন, তাঁর বকেয়া অপরিবর্তিত থাকতে পারে যদিও তিনি সবচেয়ে ভালো
     * পরিশোধকারী।
     *
     * আদায় মানে গ্রাহকের খাতে ক্রেডিট: প্রাপ্য ডেবিট প্রকৃতির, তাই টাকা
     * এলে সেটা কমে। বিক্রয় ঠিক উল্টো — ডেবিট।
     *
     * শুরুর তারিখের আগের কিছু ধরা হয় না, কারণ প্রশ্নটাই "এই সময়ে কত"।
     * আগের বকেয়া জানতে বকেয়ার তালিকা আছে।
     */
    public static function collection(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'customer.collection',
            title: 'customer::menu.collection',
            filters: ['date_range', 'branch', 'party_type'],
            groupBy: 'party_id',
            query: fn (array $f) => DB::table('ledger_entries')
                ->join('customers', 'customers.id', '=', 'ledger_entries.party_id')
                ->tap(fn ($q) => self::joinLadder($q))
                ->where('ledger_entries.company_id', $f['company_id'])
                ->where('ledger_entries.party_type', Customer::drillSourceType())
                ->when($f['branch_id'], fn ($q, $branch) => $q->where('ledger_entries.branch_id', $branch))
                /*
                 * পক্ষের ধরন ধরে ছাঁকা — "ডিলারদের কত বকেয়া, পাইকারদের কত"।
                 *
                 * ধরনটা খোলা তালিকা, তাই কোম্পানি নতুন একটা ধরন যোগ
                 * করলেই তার খতিয়ান পেয়ে যায় — কোড ছোঁয়া ছাড়াই।
                 */
                ->when($f['party_type_id'] ?? null,
                    fn ($q, $type) => $q->where('customers.party_type_id', $type))
                ->whereBetween('ledger_entries.trx_date', [$f['from'], $f['to']])
                ->groupBy('ledger_entries.party_id', 'customers.id', 'l0.id', 'l1.id', 'l2.id')
                // যে গ্রাহকের এই সময়ে কিছুই হয়নি তাঁর সারি শুধু ভিড় বাড়ায়
                ->havingRaw('SUM(ledger_entries.credit) > 0 OR SUM(ledger_entries.debit) > 0')
                ->orderByRaw('SUM(ledger_entries.credit) DESC')
                ->select([
                    'ledger_entries.party_id',
                    ...self::identity(),
                    DB::raw("'".Customer::drillSourceType()."' as party_type_literal"),
                    DB::raw('SUM(ledger_entries.debit) as billed'),
                    DB::raw('SUM(ledger_entries.credit) as collected'),
                    DB::raw('SUM(ledger_entries.debit) - SUM(ledger_entries.credit) as movement'),
                ]),
            columns: [
                ...self::identityColumns('party_id', mobile: true),
                ['key' => 'billed', 'label' => 'customer::field.billed_in_period', 'type' => ReportColumn::MONEY],
                ['key' => 'collected', 'label' => 'customer::field.collected_in_period', 'type' => ReportColumn::MONEY],

                /*
                 * নিট পরিবর্তন — ধনাত্মক মানে বকেয়া বেড়েছে।
                 *
                 * এই একটা কলামই বলে দেয় গ্রাহকটা এ মাসে এগিয়েছেন না
                 * পিছিয়েছেন, দুইটা সংখ্যা মাথায় বিয়োগ না করেই।
                 */
                ['key' => 'movement', 'label' => 'customer::field.net_change', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * কাদের ধারের সীমা বসানো নেই।
     *
     * ── কেন এই তালিকাটা সুইচের আগে দরকার ────────────────────────────
     * `customer.zero_limit_blocks` চালু করলে **শূন্য মানে শূন্য** হয়ে
     * যায় — বাকিতে কিছুই যাবে না। কিন্তু যাঁদের লিমিট কেউ কোনোদিন
     * বসায়নি তাঁদেরও লিমিট শূন্য, আর তাঁদের মধ্যে বড় ডিলারও থাকেন
     * যাঁদের সত্যিই বাকি দিতে হয়।
     *
     * সুইচটা টেপার আগে এই তালিকা দেখে লিমিট বসিয়ে নিতে হয়, নাহলে
     * পরদিন সকালেই ভালো খদ্দেরও আটকে যাবেন। **এটাই সুইচটার সাথে
     * জোড়া কাগজ।**
     *
     * বকেয়ার কলামটা সাথেই, কারণ যাঁর কাছে এখনই টাকা আটকে আছে তাঁর
     * লিমিটটা আগে বসানো দরকার।
     */
    public static function noCreditLimit(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'customer.no_limit',
            title: 'customer::menu.no_limit',
            filters: ['branch'],
            groupBy: 'id',
            query: fn (array $f) => DB::table('customers')
                ->leftJoinSub(
                    DB::table('ledger_entries')
                        ->where('company_id', $f['company_id'])
                        ->where('party_type', Customer::drillSourceType())
                        ->groupBy('party_id')
                        ->select(['party_id', DB::raw('SUM(debit) - SUM(credit) as due')]),
                    'l', 'l.party_id', '=', 'customers.id',
                )
                ->tap(fn ($q) => self::joinLadder($q))
                ->where('customers.company_id', $f['company_id'])
                ->whereNull('customers.deleted_at')
                ->where('customers.is_active', true)
                ->where(fn ($q) => $q->whereNull('customers.credit_limit')
                    ->orWhere('customers.credit_limit', '<=', 0))

                // যাঁর কাছে সবচেয়ে বেশি টাকা আটকে, তাঁর সারি আগে
                ->orderByRaw('COALESCE(l.due, 0) DESC')
                ->select([
                    'customers.id',
                    ...self::identity(grouped: false),
                    DB::raw("'".Customer::drillSourceType()."' as party_type_literal"),
                    'customers.credit_days',
                    DB::raw('COALESCE(l.due, 0) as outstanding'),
                ]),
            columns: [
                ...self::identityColumns('id', mobile: true),
                ['key' => 'credit_days', 'label' => 'customer::field.credit_days', 'type' => ReportColumn::TEXT],
                ['key' => 'outstanding', 'label' => 'customer::field.outstanding', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * কার কাছে কত পাওনা।
     *
     * শূন্য বকেয়ার গ্রাহকরা বাদ: তালিকাটার প্রশ্নই "কার কাছে টাকা আটকে
     * আছে", আর যাদের কিছু বাকি নেই তাদের সারি শুধু ভিড় বাড়ায়।
     */
    public static function dueList(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'customer.due_list',
            title: 'customer::menu.due_list',
            filters: ['date_range', 'branch', 'party_type'],
            groupBy: 'party_id',
            query: fn (array $f) => DB::table('ledger_entries')
                ->join('customers', 'customers.id', '=', 'ledger_entries.party_id')
                ->tap(fn ($q) => self::joinLadder($q))
                ->where('ledger_entries.company_id', $f['company_id'])
                ->where('ledger_entries.party_type', Customer::drillSourceType())
                ->when($f['branch_id'], fn ($q, $branch) => $q->where('ledger_entries.branch_id', $branch))
                /*
                 * পক্ষের ধরন ধরে ছাঁকা — "ডিলারদের কত বকেয়া, পাইকারদের কত"।
                 *
                 * ধরনটা খোলা তালিকা, তাই কোম্পানি নতুন একটা ধরন যোগ
                 * করলেই তার খতিয়ান পেয়ে যায় — কোড ছোঁয়া ছাড়াই।
                 */
                ->when($f['party_type_id'] ?? null,
                    fn ($q, $type) => $q->where('customers.party_type_id', $type))
                // শুরুর তারিখ ধরা হয় না: বকেয়া একটা মুহূর্তের অবস্থা,
                // পরিসরের নয় — "কত দিন থেকে বাকি" প্রশ্নটা ageing-এর
                ->where('ledger_entries.trx_date', '<=', $f['to'])
                ->groupBy('ledger_entries.party_id', 'customers.id', 'l0.id', 'l1.id', 'l2.id')
                ->havingRaw('SUM(ledger_entries.debit) - SUM(ledger_entries.credit) <> 0')
                ->orderByRaw('SUM(ledger_entries.debit) - SUM(ledger_entries.credit) DESC')
                ->select([
                    'ledger_entries.party_id',
                    ...self::identity(),
                    // ড্রিল-ডাউনের জন্য source_type — প্রতিটা সারিতে একই,
                    // তাই ধ্রুবক হিসেবেই select করা। engine সারিগুলোকে
                    // সাধারণ অ্যারে হিসেবে দেখে, তাই কলামটা থাকতেই হবে।
                    DB::raw("'".Customer::drillSourceType()."' as party_type_literal"),
                    DB::raw('SUM(ledger_entries.debit) - SUM(ledger_entries.credit) as outstanding'),
                ]),
            // ⭐ গ্রাহক তালিকার কলামগুলোই, একই ক্রমে — মালিকের চাওয়া, ১৯ সেপ্টেম্বর ২০২৬
            columns: [
                ...self::identityColumns('party_id', address: true, owner: true, mobile: true),
                ['key' => 'outstanding', 'label' => 'customer::field.outstanding', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * কত দিন ধরে বাকি।
     *
     * প্রতিটা এন্ট্রির বয়স তার নিজের তারিখ থেকে গোনা হয়, গ্রাহকের শেষ
     * লেনদেন থেকে নয়: ছয় মাসের পুরনো বকেয়া রেখে গতকাল একটা ছোট বিল
     * করলে পুরোটা "১ দিনের" হয়ে যেত — আর ঠিক ওই গ্রাহকের কাছেই টাকা
     * সবচেয়ে বেশি আটকে আছে।
     *
     * আদায়গুলো (ক্রেডিট) সবচেয়ে পুরনো ধাপ থেকে কাটা হয় — কাগজে-কলমেও
     * সেটাই নিয়ম, আর নাহলে পুরনো বকেয়া কখনো কমত না।
     */
    public static function ageing(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'customer.ageing',
            title: 'customer::menu.ageing',
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

                    return "SUM(CASE WHEN {$where} THEN ledger_entries.debit - ledger_entries.credit ELSE 0 END)";
                };

                [$b1, $b2, $b3] = self::BUCKETS;

                return DB::table('ledger_entries')
                    ->join('customers', 'customers.id', '=', 'ledger_entries.party_id')
                    ->tap(fn ($q) => self::joinLadder($q))
                    ->where('ledger_entries.company_id', $f['company_id'])
                    ->where('ledger_entries.party_type', Customer::drillSourceType())
                    ->when($f['branch_id'], fn ($q, $branch) => $q->where('ledger_entries.branch_id', $branch))
                    /* একই ছাঁকনি — প্রশ্নটা এখানেও একই */
                    ->when($f['party_type_id'] ?? null,
                        fn ($q, $type) => $q->where('customers.party_type_id', $type))
                    ->where('ledger_entries.trx_date', '<=', $f['to'])
                    ->groupBy('ledger_entries.party_id', 'customers.id', 'l0.id', 'l1.id', 'l2.id')
                    ->havingRaw('SUM(ledger_entries.debit) - SUM(ledger_entries.credit) <> 0')
                    ->orderByRaw('SUM(ledger_entries.debit) - SUM(ledger_entries.credit) DESC')
                    ->select([
                        'ledger_entries.party_id',
                        ...self::identity(),
                        DB::raw("'".Customer::drillSourceType()."' as party_type_literal"),
                        DB::raw($bucket(null, $b1).' as bucket_current'),
                        DB::raw($bucket($b1, $b2).' as bucket_30'),
                        DB::raw($bucket($b2, $b3).' as bucket_60'),
                        DB::raw($bucket($b3, null).' as bucket_90'),
                        DB::raw('SUM(ledger_entries.debit) - SUM(ledger_entries.credit) as outstanding'),
                        DB::raw('MAX(CASE WHEN ledger_entries.debit > 0 THEN ledger_entries.trx_date END) as last_billed'),
                        DB::raw('MAX(CASE WHEN ledger_entries.credit > 0 THEN ledger_entries.trx_date END) as last_collected'),
                        // ⓘ গ্রুপের বাইরের ঘর — কারণটা [[identity()]]-এ
                        DB::raw('MIN(customers.credit_limit) as credit_limit'),
                    ]);
            },
            columns: [
                ...self::identityColumns('party_id', mobile: true),
                ['key' => 'bucket_current', 'label' => 'customer::field.bucket_current', 'type' => ReportColumn::MONEY],
                ['key' => 'bucket_30', 'label' => 'customer::field.bucket_30', 'type' => ReportColumn::MONEY],
                ['key' => 'bucket_60', 'label' => 'customer::field.bucket_60', 'type' => ReportColumn::MONEY],
                ['key' => 'bucket_90', 'label' => 'customer::field.bucket_90', 'type' => ReportColumn::MONEY],
                ['key' => 'outstanding', 'label' => 'customer::field.outstanding', 'type' => ReportColumn::MONEY],

                /*
                 * ⭐ মালিক জানতে চেয়েছেন আর কী দেওয়া যায় — ১৯ সেপ্টেম্বর ২০২৬।
                 * ⓘ তিনটা প্রশ্ন, যা বয়সের বালতি একা বলে না: শেষ কবে বিল হলো
                 * (কেনা থেমেছে কি না), শেষ কবে টাকা এল (দেওয়া থেমেছে কি না),
                 * আর সীমা কত (বকেয়া সীমার কত কাছে)।
                 */
                ['key' => 'last_billed', 'label' => 'customer::field.last_billed_on', 'type' => ReportColumn::DATE],
                ['key' => 'last_collected', 'label' => 'customer::field.last_collected_on', 'type' => ReportColumn::DATE],
                ['key' => 'credit_limit', 'label' => 'customer::field.credit_limit', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * গ্রাহকের নাম — কোড সহ, ব্যবহারকারীর ভাষায়।
     *
     * SQL-এ ভাষা বাছা হয়, কারণ নামটা কোয়েরির অংশ; PHP-তে বাছলে প্রতিটা
     * সারির জন্য মডেল লাগত, আর engine সারিগুলোকে সাধারণ অ্যারে হিসেবেই
     * দেখে।
     */
    /**
     * গ্রাহকের পরিচয় — চারটা রিপোর্টে একই কলাম, গ্রাহক তালিকার মতোই।
     *
     * ── ⭐ মালিকের চাওয়া, ১৯ সেপ্টেম্বর ২০২৬ ─────────────────────────────
     * তালিকায় যা দেখেন, রিপোর্টেও তা-ই: কোড আলাদা কলামে, তারপর নাম,
     * পয়েন্ট, এরিয়া, মোবাইল (বকেয়ার তালিকায় ঠিকানা আর মালিকও)। ⓘ আগে
     * কেবল একটা ঘর ছিল — "কোড — নাম" — আর কোন এলাকার দোকান, তা জানতে
     * প্রতিটা সারি খুলে দেখতে হত।
     *
     * ⚠️ "এরিয়া" এখন `territory` চাবি (06e0d8cd-এ কেবল নাম বদলেছে), আর
     * শিরোনাম আসে `master_data::level.*` থেকে — তালিকার সাথে একই।
     *
     * @return list<Expression|string>
     */
    private static function identity(bool $grouped = true): array
    {
        $address = app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF(customers.address_bn, ''), customers.address_en)"
            : "COALESCE(NULLIF(customers.address_en, ''), customers.address_bn)";

        /*
         * ⛔ প্রতিটা ঘর `MIN()`-এ মোড়া — আর এটা সাজসজ্জা নয়, ২০ সেপ্টেম্বর
         * ২০২৬-এ লাইভে তিনটা রিপোর্ট ৫০০ দিয়েছে ঠিক এই কারণে:
         *
         *     SQLSTATE[42000]: 1055 'customers.code' isn't in GROUP BY
         *
         * ── কেন লোকালে ধরা পড়েনি ────────────────────────────────────
         * ⚠️ লাইভের সার্ভার `ONLY_FULL_GROUP_BY` নিয়ে চলে, আর আমাদের
         * লোকাল MySQL চলে না — একই প্রশ্ন ওখানে ভুল, এখানে নীরবে ঠিক।
         * ⓘ আর যে ইঞ্জিন কার্যকরী নির্ভরতা বোঝে (`customers.id` গ্রুপে
         * থাকলে `customers.code` এমনিতেই একটাই) সে ছেড়ে দিত; যে বোঝে না
         * সে থামায়। তাই ভরসা না করে **স্পষ্ট করে বলা**।
         *
         * ⓘ গ্রুপ প্রতি সারি একটাই গ্রাহক, তাই `MIN()` মানে "ঐ একটাই মান" —
         * সংখ্যাটা বদলায় না, কেবল প্রশ্নটা সব ইঞ্জিনে বৈধ হয়।
         * ⚠️ যে প্রশ্নে `GROUP BY` নেই (যেমন "কাদের লিমিট নেই") সেখানে
         * মোড়ানো চলবে না — একটা সমষ্টি পুরো তালিকাকে এক সারিতে নামিয়ে
         * আনত। তাই ঘরটা ডাকার জায়গা থেকেই বলে দেয়।
         */
        $wrap = fn (string $sql, string $alias) => DB::raw($grouped ? "MIN({$sql}) as {$alias}" : "{$sql} as {$alias}");

        return [
            $wrap('customers.code', 'customer_code'),
            $wrap(self::localised('customers'), 'customer_name'),
            $wrap(self::ladderName(Location::POINT), 'point_name'),
            $wrap(self::ladderName(Location::TERRITORY), 'area_name'),
            $wrap($address, 'full_address'),
            $wrap('customers.owner_name', 'owner_name'),
            $wrap('customers.phone', 'phone'),
        ];
    }

    /**
     * মইয়ের তিন ধাপ — গ্রাহকের নিজের জায়গা আর উপরের দুইটা।
     *
     * ⓘ তিনটাই যথেষ্ট: দোকান বসে পয়েন্টে (কখনো রুটে), আর এরিয়া তার ঠিক
     * উপরে বা আরেক ধাপ উপরে। ⚠️ `leftJoin` — মই না থাকলেও সারিটা থাকে।
     */
    private static function joinLadder($query): void
    {
        $query->leftJoin('mdm_locations as l0', 'l0.id', '=', 'customers.location_id')
            ->leftJoin('mdm_locations as l1', 'l1.id', '=', 'l0.parent_id')
            ->leftJoin('mdm_locations as l2', 'l2.id', '=', 'l1.parent_id');
    }

    /** তিন ধাপের যেটা এই স্তরের, তার নাম */
    private static function ladderName(string $level): string
    {
        $quoted = DB::getPdo()->quote($level);

        return 'CASE'
            ." WHEN l0.level = {$quoted} THEN ".self::localised('l0')
            ." WHEN l1.level = {$quoted} THEN ".self::localised('l1')
            ." WHEN l2.level = {$quoted} THEN ".self::localised('l2')
            .' END';
    }

    private static function localised(string $table): string
    {
        return app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF({$table}.name_bn, ''), {$table}.name_en)"
            : "COALESCE(NULLIF({$table}.name_en, ''), {$table}.name_bn)";
    }

    /**
     * পরিচয়ের কলামগুলো — নামটা ক্লিকযোগ্য (নিয়ম ১), বাকিগুলো লেখা।
     *
     * @return list<array<string, string>>
     */
    private static function identityColumns(string $idKey, bool $address = false, bool $owner = false, bool $mobile = false): array
    {
        return array_values(array_filter([
            ['key' => 'customer_code', 'label' => 'customer::field.code', 'type' => ReportColumn::TEXT],
            [
                'key' => 'customer_name',
                'label' => 'customer::field.name',
                'type' => ReportColumn::DOCUMENT,
                'source_type' => 'party_type_literal',
                'source_id' => $idKey,
            ],
            ['key' => 'point_name', 'label' => 'master_data::level.point', 'type' => ReportColumn::TEXT],
            ['key' => 'area_name', 'label' => 'master_data::level.territory', 'type' => ReportColumn::TEXT],
            $address ? ['key' => 'full_address', 'label' => 'customer::field.full_address', 'type' => ReportColumn::TEXT] : null,
            $owner ? ['key' => 'owner_name', 'label' => 'customer::field.owner_name', 'type' => ReportColumn::TEXT] : null,
            $mobile ? ['key' => 'phone', 'label' => 'customer::field.phone', 'type' => ReportColumn::TEXT] : null,
        ]));
    }
}
