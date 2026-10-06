<?php

declare(strict_types=1);

namespace App\Modules\Finance\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\Money;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\HandLoanMovement;
use App\Modules\MasterData\Models\Person;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ হাতধারের রিপোর্ট ৩–৭ — অর্থ-মডিউলের পরিকল্পনা (৫ অক্টোবর ২০২৬, মালিকের অনুমোদিত ক্রম, সমন্বয়কের মারফত):
 *
 *   ৩ ব্যক্তির খাতা বনাম হাতধার খাত — সব ব্যক্তির যোগফল = ১১৭০ খাতের জের? না মিললে কোথায় ফাঁক ([[RECONCILE]])
 *   ৪ পাওনা তালিকা (তিনি দেবেন) · ৫ দেনা তালিকা (আমাদের দিতে হবে) ([[RECEIVABLE]], [[PAYABLE]])
 *   ৬ দুই দিকের বয়স — ০–৩০, ৩১–৬০, ৬১–৯০, ৯০+ দিন ([[AGE_RECEIVABLE]], [[AGE_PAYABLE]])
 *   ৭ কে কত দিল বা নিল — তারিখের মধ্যে ([[ACTIVITY]])
 *
 * ── ⭐ একটাই উৎস ─────────────────────────────────────────────────────────────
 * প্রতিটা সংখ্যা [[rows()]] থেকে — হাতধারের চলাচল (খাতায় বসা, [[HandLoanMovement::countedSql()]]) আর হাতধার খাতে ব্যক্তির
 * নামে সাধারণ সারি ([[LoanLedgerReports::looseRows()]])। হাতধারের তালিকা ([[HandLoanService::people()]]) আর একজনের খাতা
 * ([[LoanLedgerReports::HAND_LOAN]]) ঠিক এই দুইটাই পড়ে, তাই কোনো রিপোর্ট আলাদা হিসাব রাখে না, আর দুই পাতা কখনো দুই
 * সংখ্যা বলে না (পরিকল্পনার শেষ নিয়ম)।
 *
 * ⓘ চিহ্ন: দেওয়া (টাকা গেল) = ডেবিট, ধনাত্মক জের মানে তিনি দেবেন; নেওয়া = ক্রেডিট, ঋণাত্মক মানে আমরা দেব।
 */
final class HandLoanReports
{
    public const RECONCILE = 'finance.hand_loan_reconcile';

    public const RECEIVABLE = 'finance.hand_loan_receivable';

    public const PAYABLE = 'finance.hand_loan_payable';

    public const AGE_RECEIVABLE = 'finance.hand_loan_age_receivable';

    public const AGE_PAYABLE = 'finance.hand_loan_age_payable';

    public const ACTIVITY = 'finance.hand_loan_activity';

    public const SCHEDULE = 'finance.hand_loan_schedule';

    /** সময়সূচির অবস্থা — `state` ছাঁকনি ([[schedule()]]) */
    public const OVERDUE = 'overdue';

    public const DUE_TODAY = 'today';

    public const UPCOMING = 'upcoming';

    public const UNDATED = 'undated';

    /** @var list<string> */
    public const STATES = [self::OVERDUE, self::DUE_TODAY, self::UPCOMING, self::UNDATED];

    /** বয়সের ধাপ — দিন, পরিকল্পনার হুবহু (০–৩০, ৩১–৬০, ৬১–৯০, ৯০+) */
    public const BUCKETS = [30, 60, 90];

    // ⛔ হাতধারের পাতা যে চাবি দেখে, সেটাই — সব কয়টার
    private const KEY = 'finance.hand_loan.view';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::reconcile());
        $engine->register(self::standing(self::RECEIVABLE, 1));
        $engine->register(self::standing(self::PAYABLE, -1));
        $engine->register(self::ageing(self::AGE_RECEIVABLE, 1));
        $engine->register(self::ageing(self::AGE_PAYABLE, -1));
        $engine->register(self::activity());
        $engine->register(self::schedule());
    }

    /**
     * হাতধারের সব সারি — জন প্রতি, তারিখ ধরে: person_id, trx_date, debit, credit, id, return_on (কবে ফেরতের কথা)।
     *
     * ⓘ `$wall` — দেখার শাখার দেয়াল ([[ReportEngine::branchWall()]]); কেবল জের-চিঠি ([[HandLoanLetterController]]) এক
     * মানুষের পুরো জের পড়ে, শাখা ছাড়া।
     *
     * @param  array<string, mixed>  $f
     */
    public static function rows(array $f, bool $wall = true): Builder
    {
        $out = HandLoanMovement::OUT;
        $in = HandLoanMovement::IN;

        $moves = DB::table('fin_hand_loan_movements as m')
            ->join('fin_hand_loan_accounts as a', 'a.id', '=', 'm.account_id')
            ->leftJoin('vouchers as v', 'v.id', '=', 'm.voucher_id')
            ->where('m.company_id', $f['company_id'])
            ->whereNotNull('a.person_id')
            ->whereRaw(HandLoanMovement::countedSql('m', 'v'))
            ->when($wall, fn ($q) => $q->tap(ReportEngine::branchWall($f, 'a.branch_id')))
            ->selectRaw("a.person_id as person_id, m.moved_on as trx_date, CASE WHEN m.direction = '{$out}' THEN m.amount ELSE 0 END as debit, "
                ."CASE WHEN m.direction = '{$in}' THEN m.amount ELSE 0 END as credit, m.id as id, "
                // ⓘ কবে ফেরতের কথা — চলাচলের নিজের তারিখ, না থাকলে হিসাবের (পরিকল্পনা ১.৮)
                .'COALESCE(m.return_on, a.next_due_on, a.due_on) as return_on');

        $loose = LoanLedgerReports::looseRows((int) $f['company_id'], null)
            ->when($wall, fn ($q) => $q->tap(ReportEngine::branchWall($f, 'le.branch_id')))
            ->selectRaw('le.party_id as person_id, le.trx_date as trx_date, le.debit as debit, le.credit as credit, le.id + 1000000000 as id, '
                .'NULL as return_on');

        return DB::query()->fromSub($moves->unionAll($loose), 'u');
    }

    /**
     * ⭐ ৩ — ব্যক্তির খাতা বনাম হাতধার খাত (১১৭০ আর নিচের সব)।
     *
     * প্রতিটা ব্যক্তি: হাতধারের তালিকার বাকি, খাতে তাঁর নামের জের, আর ফাঁক। সাথে একটা সারি — খাতে কারও নাম ছাড়া (বা অন্য
     * পক্ষের নামে) বসা টাকা। ⓘ "খাতে" কলামের যোগফল = ১১৭০ খাতের জের, হুবহু (খাতের সব সারি কোনো না কোনো সারিতে পড়ে);
     * "ফাঁক"-এর যোগফল = তালিকার যোগফল − খাতের জের।
     *
     * ── ফাঁক কেন হয় ──────────────────────────────────────────────────────────
     *   · খাতায় না-বসা পুরনো চলাচল (খোলার আমদানি, ভাউচার নেই) — তালিকায় আছে, খাতে নেই → ঐ ব্যক্তির সারিতে
     *   · নাম ছাড়া বসা পুরনো চলাচলের ভাউচার (৮c৬b২১৯৫-এর আগের; `abos:hand-loan-party` ঠিক করে) → ব্যক্তির সারিতে + নামহীন সারিতে
     *   · জাবেদায় হাতধার খাতে কারও নাম না দিয়ে টাকা → নামহীন সারিতে
     *
     * ⓘ শাখার দেয়াল দুই দিকেই ([[ReportEngine::branchWall()]]): তালিকা হিসাবের শাখা ধরে, খাত দাখিলার শাখা ধরে। "সব শাখা"-য়
     * খাতের যোগফল = ১১৭০-এর গোটা জের; এক শাখা বাছলে সেই শাখার দুই দিক পাশাপাশি — হিসাব এক শাখার আর ভাউচার আরেক
     * শাখার হলে সেটাও ফাঁক হয়ে দেখায়, আর সেটাই সত্যি।
     */
    private static function reconcile(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::RECONCILE,
            permission: self::KEY,
            title: 'finance::hand_loan_report.reconcile_title',
            filters: ['date_range', 'branch'],
            asOfDate: true,
            query: function (array $f): Builder {
                $head = StandardChart::find(StandardChart::HAND_LOAN);
                $heads = $head === null ? [0] : ($head->selfAndDescendants()->modelKeys() ?: [0]);

                $list = self::rows($f)
                    ->where('u.trx_date', '<=', $f['to'])
                    ->selectRaw('u.person_id as person_id, u.debit - u.credit as list_amount, 0 as books_amount');

                $books = DB::table('ledger_entries as le')
                    ->where('le.company_id', $f['company_id'])
                    ->whereIn('le.account_id', $heads)
                    ->where('le.trx_date', '<=', $f['to'])
                    ->tap(ReportEngine::branchWall($f, 'le.branch_id'))
                    ->selectRaw("CASE WHEN le.party_type = 'person' THEN le.party_id ELSE NULL END as person_id, "
                        .'0 as list_amount, le.debit - le.credit as books_amount');

                $nameless = DB::getPdo()->quote((string) __('finance::hand_loan_report.nameless'));

                return DB::query()
                    ->fromSub($list->unionAll($books), 'x')
                    ->leftJoin('mdm_people as p', 'p.id', '=', 'x.person_id')
                    ->groupBy('x.person_id')
                    ->havingRaw('SUM(x.list_amount) <> 0 OR SUM(x.books_amount) <> 0')
                    ->selectRaw('x.person_id as person_id, '
                        .'COALESCE(MIN('.self::nameSql().'), '.$nameless.') as person_name, '
                        .'SUM(x.list_amount) as list_balance, SUM(x.books_amount) as books_balance, '
                        .'SUM(x.list_amount) - SUM(x.books_amount) as gap')
                    // ⓘ নামহীন সারি শেষে, তারপর ফাঁক বড় থেকে ছোট
                    ->orderByRaw('x.person_id IS NULL, ABS(SUM(x.list_amount) - SUM(x.books_amount)) DESC, MIN('.self::nameSql().')');
            },
            summary: function (array $totals): array {
                $list = bcadd((string) ($totals['list_balance'] ?? '0'), '0', 4);
                $books = bcadd((string) ($totals['books_balance'] ?? '0'), '0', 4);

                return [
                    'label' => __('finance::hand_loan_report.reconcile_summary'),
                    'value' => bcsub($list, $books, 4),
                    'text' => __('finance::hand_loan_report.reconcile_text', [
                        'list' => Money::drCr($list), 'books' => Money::drCr($books), 'gap' => Money::format(bcsub($list, $books, 4)),
                    ]),
                    'good' => bccomp($list, $books, 4) === 0,
                ];
            },
            columns: [
                ['key' => 'person_name', 'label' => 'finance::field.person_name'],
                ['key' => 'list_balance', 'label' => 'finance::hand_loan_report.list_balance', 'type' => ReportColumn::MONEY],
                ['key' => 'books_balance', 'label' => 'finance::hand_loan_report.books_balance', 'type' => ReportColumn::MONEY],
                ['key' => 'gap', 'label' => 'finance::hand_loan_report.gap', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * ⭐ ৪ ও ৫ — পাওনা (তিনি দেবেন, `$side` = ১) আর দেনা (আমরা দেব, `$side` = −১), একটা তারিখ পর্যন্ত, বড় থেকে ছোট।
     */
    private static function standing(string $key, int $side): ReportDefinition
    {
        return new ReportDefinition(
            key: $key,
            permission: self::KEY,
            title: $side > 0 ? 'finance::hand_loan_report.receivable_title' : 'finance::hand_loan_report.payable_title',
            filters: ['date_range', 'branch'],
            asOfDate: true,
            rankBy: 'balance',
            query: function (array $f) use ($side): Builder {
                $net = "({$side}) * (SUM(u.debit) - SUM(u.credit))";

                return self::rows($f)
                    ->join('mdm_people as p', 'p.id', '=', 'u.person_id')
                    ->where('u.trx_date', '<=', $f['to'])
                    ->groupBy('u.person_id')
                    ->havingRaw("{$net} > 0")
                    ->selectRaw('u.person_id as person_id, MIN('.self::nameSql().') as person_name, MIN(p.mobile) as mobile, '
                        .'MIN('.self::kindSql().') as kind_label, '
                        ."{$net} as balance, "
                        .'MAX(CASE WHEN u.debit > 0 THEN u.trx_date END) as last_given, '
                        .'MAX(CASE WHEN u.credit > 0 THEN u.trx_date END) as last_taken')
                    ->orderByRaw("{$net} DESC");
            },
            columns: [
                ['key' => 'person_name', 'label' => 'finance::field.person_name'],
                ['key' => 'mobile', 'label' => 'finance::hand_loan_report.mobile', 'width' => '9rem'],
                ['key' => 'kind_label', 'label' => 'finance::hand_loan_report.kind', 'width' => '7rem'],
                ['key' => 'balance', 'label' => $side > 0 ? 'finance::hand_loan_report.they_owe' : 'finance::hand_loan_report.we_owe',
                    'type' => ReportColumn::MONEY],
                ['key' => 'last_given', 'label' => 'finance::hand_loan_report.last_given', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'last_taken', 'label' => 'finance::hand_loan_report.last_taken', 'type' => ReportColumn::DATE, 'width' => '7rem'],
            ],
        );
    }

    /**
     * ⭐ ৬ — বয়স: বাকি টাকার কোন অংশ কত দিনের।
     *
     * ── ⭐ পুরনোটা আগে শোধ (FIFO) ─────────────────────────────────────────────
     * ফেরত সবসময় সবচেয়ে পুরনো দেওয়া থেকে কাটে, তাই যা বাকি সেটা **সবচেয়ে নতুন** দেওয়াগুলো: নতুন থেকে পুরনোর দিকে
     * হেঁটে বাকির সমান হওয়া পর্যন্ত ([[openParts()]])। ⛔ "সারির তারিখ ধরে ডেবিট − ক্রেডিট" নয় — তাতে ছয় মাস আগের
     * পুরো ধার ফেরত এলেও ৯০+ ঘরে টাকা দেখাত, আর নতুন ঘরে ঋণাত্মক।
     *
     * ⓘ বয়স গোনা হয় রিপোর্টের তারিখ থেকে (আজ নয়), তাই পেছনের তারিখের ছবিও ঠিক।
     */
    private static function ageing(string $key, int $side): ReportDefinition
    {
        [$b1, $b2, $b3] = self::BUCKETS;

        return new ReportDefinition(
            key: $key,
            permission: self::KEY,
            title: $side > 0 ? 'finance::hand_loan_report.age_receivable_title' : 'finance::hand_loan_report.age_payable_title',
            filters: ['date_range', 'branch'],
            asOfDate: true,
            rankBy: 'balance',
            query: function (array $f) use ($side, $b1, $b2, $b3): Builder {
                $age = 'DATEDIFF('.DB::getPdo()->quote((string) $f['to']).', o.trx_date)';
                $part = fn (string $when) => "SUM(CASE WHEN {$when} THEN o.open_amount ELSE 0 END)";

                return DB::query()
                    ->fromSub(self::openParts($f, $side), 'o')
                    ->join('mdm_people as p', 'p.id', '=', 'o.person_id')
                    ->groupBy('o.person_id')
                    ->havingRaw('SUM(o.open_amount) > 0')
                    ->selectRaw('o.person_id as person_id, MIN('.self::nameSql().') as person_name, MIN(p.mobile) as mobile, '
                        .'MIN('.self::kindSql().') as kind_label, '
                        .$part("{$age} <= {$b1}").' as bucket_0, '
                        .$part("{$age} > {$b1} AND {$age} <= {$b2}").' as bucket_30, '
                        .$part("{$age} > {$b2} AND {$age} <= {$b3}").' as bucket_60, '
                        .$part("{$age} > {$b3}").' as bucket_90, '
                        .'SUM(o.open_amount) as balance, '
                        .'MIN(CASE WHEN o.open_amount > 0 THEN o.trx_date END) as oldest')
                    ->orderByRaw('SUM(o.open_amount) DESC');
            },
            columns: [
                ['key' => 'person_name', 'label' => 'finance::field.person_name'],
                ['key' => 'mobile', 'label' => 'finance::hand_loan_report.mobile', 'width' => '9rem'],
                ['key' => 'kind_label', 'label' => 'finance::hand_loan_report.kind', 'width' => '7rem'],
                ['key' => 'bucket_0', 'label' => 'finance::hand_loan_report.bucket_0', 'type' => ReportColumn::MONEY],
                ['key' => 'bucket_30', 'label' => 'finance::hand_loan_report.bucket_30', 'type' => ReportColumn::MONEY],
                ['key' => 'bucket_60', 'label' => 'finance::hand_loan_report.bucket_60', 'type' => ReportColumn::MONEY],
                ['key' => 'bucket_90', 'label' => 'finance::hand_loan_report.bucket_90', 'type' => ReportColumn::MONEY],
                ['key' => 'balance', 'label' => $side > 0 ? 'finance::hand_loan_report.they_owe' : 'finance::hand_loan_report.we_owe',
                    'type' => ReportColumn::MONEY],
                ['key' => 'oldest', 'label' => 'finance::hand_loan_report.oldest', 'type' => ReportColumn::DATE, 'width' => '7rem'],
            ],
        );
    }

    /**
     * বাকি টাকার টুকরোগুলো — যে দেওয়া (বা নেওয়া) এখনো শোধ হয়নি, তার কতটা: person_id, trx_date, id, return_on, open_amount।
     *
     * ⓘ জন প্রতি বাকি B (দিক `$side` ধরে); বাকি তৈরি করা সারিগুলো (পাওনায় দেওয়া, দেনায় নেওয়া) নতুন থেকে পুরনোয় সাজিয়ে
     * প্রতিটার আগের (নতুনতর) সারিগুলোর যোগ N; খোলা অংশ = min(অঙ্ক, max(B − N, ০))। যোগফল সবসময় হুবহু B।
     *
     * ⓘ পরিশোধের সময়সূচিও এই টুকরোগুলোই পড়ে (কোন দেওয়ার কতটা বাকি, কবে ফেরতের কথা — [[schedule()]])।
     *
     * @param  array<string, mixed>  $f
     */
    public static function openParts(array $f, int $side): Builder
    {
        $amount = $side > 0 ? 'u.debit' : 'u.credit';

        $balances = self::rows($f)
            ->where('u.trx_date', '<=', $f['to'])
            ->groupBy('u.person_id')
            ->havingRaw("({$side}) * (SUM(u.debit) - SUM(u.credit)) > 0")
            ->selectRaw("u.person_id as person_id, ({$side}) * (SUM(u.debit) - SUM(u.credit)) as owed");

        $made = self::rows($f)
            ->where('u.trx_date', '<=', $f['to'])
            ->where($amount, '>', 0)
            ->selectRaw("u.person_id as person_id, u.trx_date as trx_date, u.id as id, {$amount} as amount, u.return_on as return_on");

        $walked = DB::query()
            ->fromSub($made, 'c')
            ->joinSub($balances, 'b', 'b.person_id', '=', 'c.person_id')
            ->selectRaw('c.person_id, c.trx_date, c.id, c.amount, c.return_on, b.owed, '
                .'COALESCE(SUM(c.amount) OVER (PARTITION BY c.person_id ORDER BY c.trx_date DESC, c.id DESC '
                .'ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING), 0) as newer');

        return DB::query()
            ->fromSub($walked, 'w')
            ->selectRaw('w.person_id as person_id, w.trx_date as trx_date, w.id as id, w.return_on as return_on, '
                .'LEAST(w.amount, GREATEST(w.owed - w.newer, 0)) as open_amount');
    }

    /**
     * ⭐ ৭ — কে কত দিল বা নিল, তারিখের মধ্যে: খোলা জের, দেওয়া, নেওয়া, শেষ জের।
     */
    private static function activity(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::ACTIVITY,
            permission: self::KEY,
            title: 'finance::hand_loan_report.activity_title',
            filters: ['date_range', 'branch'],
            query: function (array $f): Builder {
                $pdo = DB::getPdo();
                $from = $pdo->quote((string) $f['from']);
                $before = "CASE WHEN u.trx_date < {$from} THEN u.debit - u.credit ELSE 0 END";
                $given = "CASE WHEN u.trx_date >= {$from} THEN u.debit ELSE 0 END";
                $taken = "CASE WHEN u.trx_date >= {$from} THEN u.credit ELSE 0 END";

                return self::rows($f)
                    ->join('mdm_people as p', 'p.id', '=', 'u.person_id')
                    ->where('u.trx_date', '<=', $f['to'])
                    ->groupBy('u.person_id')
                    // ⓘ সময়ের ভিতরে কিছু না ঘটলেও খোলা জের থাকলে সারিটা থাকে — "কার কাছে কত ছিল, কত হলো"
                    ->havingRaw("SUM({$before}) <> 0 OR SUM({$given}) <> 0 OR SUM({$taken}) <> 0")
                    ->selectRaw('u.person_id as person_id, MIN('.self::nameSql().') as person_name, '
                        ."SUM({$before}) as opening, SUM({$given}) as given, SUM({$taken}) as taken, "
                        ."SUM({$before}) + SUM({$given}) - SUM({$taken}) as closing")
                    ->orderByRaw('MIN('.self::nameSql().')');
            },
            columns: [
                ['key' => 'person_name', 'label' => 'finance::field.person_name'],
                ['key' => 'opening', 'label' => 'finance::loan_ledger.opening', 'type' => ReportColumn::DR_CR, 'width' => '10rem'],
                ['key' => 'given', 'label' => 'finance::loan_ledger.given', 'type' => ReportColumn::MONEY],
                ['key' => 'taken', 'label' => 'finance::loan_ledger.taken', 'type' => ReportColumn::MONEY],
                ['key' => 'closing', 'label' => 'finance::loan_ledger.closing', 'type' => ReportColumn::DR_CR, 'width' => '10rem'],
            ],
        );
    }

    /**
     * ⭐ ৮ — পরিশোধের সময়সূচি: কোন দেওয়া (বা নেওয়া) এখনো কতটা বাকি, কবে ফেরতের কথা, আজ কার দিন, কার দিন পার।
     *
     * ⓘ সারি = বাকি টাকার এক টুকরো ([[openParts()]], পুরনোটা আগে শোধ) — তাই "৫ হাজার দিলাম ৩০ তারিখে ফেরত, ২ হাজার এল"
     * হলে সারিতে ৩ হাজার, ৩০ তারিখ। তারিখ চলাচলের নিজের ([[HandLoanMovement]] `return_on`), না থাকলে হিসাবের
     * (`next_due_on`/`due_on`), তাও না থাকলে "তারিখ নেই"। দুই দিক দুই কলামে, তাই যোগফল দুইটা আলাদা অর্থ রাখে।
     * ⓘ `state` ছাঁকনি — দিন পার / আজ / সামনে / তারিখ নেই; ড্যাশবোর্ডের সতর্কতা "দিন পার"-টাই পড়ে।
     */
    private static function schedule(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::SCHEDULE,
            permission: self::KEY,
            title: 'finance::hand_loan_report.schedule_title',
            filters: ['date_range', 'branch', 'state'],
            asOfDate: true,
            query: function (array $f): Builder {
                $pdo = DB::getPdo();
                $to = $pdo->quote((string) $f['to']);
                $label = fn (string $key) => $pdo->quote((string) __('finance::hand_loan_report.'.$key));
                $state = "CASE WHEN s.return_on IS NULL THEN '".self::UNDATED."' WHEN s.return_on < {$to} THEN '".self::OVERDUE
                    ."' WHEN s.return_on = {$to} THEN '".self::DUE_TODAY."' ELSE '".self::UPCOMING."' END";

                $both = DB::query()->fromSub(
                    DB::query()->fromSub(self::openParts($f, 1), 'r')->where('r.open_amount', '>', 0)->selectRaw('r.*, 1 as side')
                        ->unionAll(DB::query()->fromSub(self::openParts($f, -1), 'p')->where('p.open_amount', '>', 0)->selectRaw('p.*, -1 as side')),
                    's');

                return $both
                    ->join('mdm_people as p', 'p.id', '=', 's.person_id')
                    ->when(in_array($f['state'] ?? null, self::STATES, true), fn ($q) => $q->whereRaw("{$state} = ?", [$f['state']]))
                    ->selectRaw('s.person_id as person_id, '.self::nameSql().' as person_name, p.mobile as mobile, '
                        .'s.trx_date as made_on, s.return_on as return_on, '
                        .'CASE WHEN s.side = 1 THEN s.open_amount ELSE 0 END as they_owe, '
                        .'CASE WHEN s.side = -1 THEN s.open_amount ELSE 0 END as we_owe, '
                        ."CASE WHEN s.return_on IS NULL THEN NULL ELSE DATEDIFF(s.return_on, {$to}) END as days_left, "
                        ."{$state} as state, "
                        .'CASE '.$state.' WHEN '.$pdo->quote(self::OVERDUE).' THEN '.$label('state_overdue')
                        .' WHEN '.$pdo->quote(self::DUE_TODAY).' THEN '.$label('state_today')
                        .' WHEN '.$pdo->quote(self::UPCOMING).' THEN '.$label('state_upcoming')
                        .' ELSE '.$label('state_undated').' END as state_label')
                    ->orderByRaw('s.return_on IS NULL, s.return_on, '.self::nameSql());
            },
            columns: [
                ['key' => 'person_name', 'label' => 'finance::field.person_name'],
                ['key' => 'mobile', 'label' => 'finance::hand_loan_report.mobile', 'width' => '9rem'],
                ['key' => 'made_on', 'label' => 'finance::hand_loan_report.made_on', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'return_on', 'label' => 'finance::field.return_on', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'state_label', 'label' => 'finance::hand_loan_report.state', 'width' => '8rem'],
                ['key' => 'days_left', 'label' => 'finance::hand_loan_report.days_left', 'width' => '6rem'],
                ['key' => 'they_owe', 'label' => 'finance::hand_loan_report.they_owe', 'type' => ReportColumn::MONEY],
                ['key' => 'we_owe', 'label' => 'finance::hand_loan_report.we_owe', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * ব্যক্তির ধরন, পড়ার মতো — কর্মী, আত্মীয়, ব্যবসায়ী, অন্যান্য ([[Person::KINDS]]); IAS 24: আত্মীয়ের সাথে লেনদেন আলাদা চেনা যায়।
     * ⓘ বলা না থাকলে খালি।
     */
    private static function kindSql(): string
    {
        $pdo = DB::getPdo();
        $sql = 'CASE p.kind';

        foreach (Person::KINDS as $kind) {
            $sql .= ' WHEN '.$pdo->quote($kind).' THEN '.$pdo->quote((string) __('master_data::person_kind.'.$kind));
        }

        return $sql.' ELSE NULL END';
    }

    /** ব্যক্তির নাম, ব্যবহারকারীর ভাষায় — [[Person::name()]]-এর একই নিয়ম, SQL-এ */
    private static function nameSql(): string
    {
        return app()->getLocale() === 'bn' ? "COALESCE(NULLIF(p.name_bn, ''), p.name_en)" : 'p.name_en';
    }
}
