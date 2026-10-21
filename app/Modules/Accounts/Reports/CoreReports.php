<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Models\FinancialYear;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\YearEndService;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * হিসাবের কোর রিপোর্ট — Day Book, Ledger, Trial Balance।
 *
 * প্রতিটা রিপোর্ট শুধু বলে দেয় কোয়েরিটা কী আর কলামগুলো কী; পাতা ভাগ করা,
 * যোগফল, ড্রিল-ডাউন ও রপ্তানি — সবই engine করে (সেকশন ২.২)।
 *
 * প্রতিটা সংখ্যায় source_type ও source_id আছে, তাই প্রতিটা সারি তার
 * ডকুমেন্টে ক্লিকযোগ্য — নিয়ম ১।
 */
final class CoreReports
{
    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::dayBook());
        $engine->register(self::cashBook());
        $engine->register(self::bankBook());
        $engine->register(self::ledger());
        $engine->register(self::trialBalance());
        $engine->register(self::profitAndLoss());
        $engine->register(self::balanceSheet());
        $engine->register(self::cashFlow());
        $engine->register(self::inflow());
        $engine->register(self::byCostCentre());
        // ⭐ প্রকল্পভিত্তিক খতিয়ান — ২০ সেপ্টেম্বর ২০২৬ (অর্থের মানচিত্র §১৮)
        $engine->register(self::projectLedger());
        $engine->register(self::expenseByHead());
        // ⭐ আয়ের আয়না — ২০ সেপ্টেম্বর ২০২৬ (অর্থের মানচিত্র §১০)
        $engine->register(self::incomeByHead());
        // ⭐ মাসওয়ারি টাকা আসা-যাওয়া — মালিকের নির্দেশ, ১ অক্টোবর ২০২৬
        $engine->register(MonthlyCashReport::definition());
        // ⭐ চেকের খাতা — রিপোর্ট সেন্টার ধাপ ৪ (২ অক্টোবর ২০২৬)
        ChequeReports::registerAll($engine);
        // ⭐ খরচের বিশ্লেষণ — আগের সময়ের সাথে তুলনা, সবচেয়ে বড় খরচ (রিপোর্ট সেন্টার ধাপ ৪)
        $engine->register(ExpenseAnalysisReport::definition());
        // ⭐ শাখা পাশাপাশি — নির্বাহী পাতা ও শাখাভিত্তিক লাভ-ক্ষতি (রিপোর্ট সেন্টার ধাপ ২)
        $engine->register(BranchesSideBySideReport::definition());
        // ⭐ কাস্টমার ও সাপ্লায়ার লেজার — মালিক, ৩ অক্টোবর ২০২৬: "একাউন্টসে কাস্টমার লেজার দিতে হবে জরুরি"
        PartyLedgerReports::registerAll($engine);
    }

    /**
     * আদায়ের তালিকা — কোন টাকা কার কাছ থেকে ঢুকল।
     *
     * ── কেন নগদ বই দিয়ে কাজ চলে না ──────────────────────────────────
     * নগদ বই বলে টাকাটা **কোন ড্রয়ারে** ঢুকল, আর তাতে ঢোকা-বেরোনো
     * দুইটাই থাকে। কিন্তু আদায় নিয়ে যে প্রশ্নটা রোজ করা হয় সেটা আলাদা:
     * "আজ কার কাছ থেকে কত এল"। উত্তরটা পক্ষের নামে, খাতের নামে নয় —
     * আর মালিক ওই তালিকাটাই মেলান আদায়কারীর জমা দেওয়া টাকার সাথে।
     *
     * শুধু ডেবিট, আর কেবল টাকার খাতে: টাকা ঢোকা মানে নগদ বা ব্যাংক
     * বাড়া। ক্রেডিট বাদ, নইলে বেরোনো টাকাও তালিকায় আসত আর যোগফলটা
     * "মোট আদায়" থাকত না।
     */
    public static function inflow(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'accounts.inflow',
            // ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই — সূচি ও ফোন এখান থেকে পড়ে (২৭ সেপ্টেম্বর ২০২৬)
            permission: 'accounts.report',
            title: 'accounts::menu.inflow',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => DB::table('ledger_entries')
                ->leftJoin('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
                ->where('ledger_entries.company_id', $f['company_id'])
                // টাকার যেকোনো খাত — নগদ, ব্যাংক, MFS ([[Account::scopeMoney]])।
                // দল বাদ, কারণ পুরনো `is_cash`/`is_bank` পতাকাও দলে কখনো
                // true হত না — অথচ `money_kind` দলেও বসে।
                ->whereNotNull('accounts.money_kind')
                ->where('accounts.is_group', false)
                ->tap(ReportEngine::branchWall($f, 'ledger_entries.branch_id'))
                ->whereBetween('ledger_entries.trx_date', [$f['from'], $f['to']])
                ->where('ledger_entries.debit', '>', 0)
                /*
                 * ⛔ আদায় মানে বাইরে থেকে টাকা আসা — Accounts লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬ ([[TheInflowListIsMoneyFromOutsideTest]])।
                 * ⓘ আগে টাকার খাতের প্রতিটা ডেবিট আসত: টিল থেকে ব্যাংকে জমা পুরো অঙ্কে "আদায়", বাতিল পরিশোধের উল্টো সারিও "আদায়", আর
                 * বাতিল হওয়া রসিদ তবু তালিকায়। মাসিক নগদের পাতা ([[MonthlyCashReport]]) এগুলো বাদ দেয়, তাই দুই পাতার "আসা টাকা" আলাদা ছিল।
                 * এখন: উল্টো দাখিলা বাদ, পরে উল্টানো কাগজ বাদ, আর একই কাগজে টাকা-নয় (পথের টাকা নয়) খাতে ক্রেডিট থাকতে হয়।
                 */
                ->where('ledger_entries.source_type', 'not like', '%:reversal%')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ledger_entries as rv')
                    ->whereColumn('rv.company_id', 'ledger_entries.company_id')
                    ->whereColumn('rv.source_id', 'ledger_entries.source_id')
                    ->whereRaw("rv.source_type LIKE CONCAT(ledger_entries.source_type, ':reversal%')"))
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('ledger_entries as o')
                    ->join('accounts as oa', 'oa.id', '=', 'o.account_id')
                    ->whereColumn('o.company_id', 'ledger_entries.company_id')
                    ->whereColumn('o.source_type', 'ledger_entries.source_type')
                    ->whereColumn('o.source_id', 'ledger_entries.source_id')
                    ->where('o.credit', '>', 0)
                    ->whereNull('oa.money_kind')
                    ->where('oa.code', '<>', StandardChart::CASH_IN_TRANSIT))
                ->orderBy('ledger_entries.trx_date')
                ->orderBy('ledger_entries.id')
                ->select([
                    'ledger_entries.trx_date',
                    'ledger_entries.document_no',
                    self::accountName(),
                    'ledger_entries.narration',
                    'ledger_entries.debit',
                    'ledger_entries.source_type',
                    'ledger_entries.source_id',
                    self::partyKey(),
                ]),
            columns: [
                ['key' => 'trx_date', 'label' => 'core.print.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                [
                    'key' => 'document_no',
                    'label' => 'core.table.document',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type',
                    'source_id' => 'source_id',
                    'width' => '12rem',
                ],
                // ⭐ কার কাছ থেকে — "নাম · পয়েন্ট" (মালিক, ১৯ অক্টোবর ২০২৬); টাকার সারিতে পক্ষ নেই, তাই একই কাগজের পক্ষ ([[partyKey()]])
                ['key' => 'party', 'label' => 'accounts::field.received_from', 'party' => 'party_key', 'width' => '14rem'],
                ['key' => 'account_name', 'label' => 'accounts::field.received_into'],
                ['key' => 'narration', 'label' => 'core.table.narration'],
                ['key' => 'debit', 'label' => 'accounts::field.received', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /*
     * ⭐ পক্ষের নাম এখন আছে — মালিক, ১৯ অক্টোবর ২০২৬: *"নম্বরের পাশে গ্রাহকের নাম আর পয়েন্টের নাম দিলে সহজে বোঝা যায়।"*
     *
     * ⓘ আগের আপত্তিটা সত্যি ছিল এবং এখনো সত্যি: খতিয়ান কেবল party_type ও party_id রাখে, আর নাম আনতে গ্রাহক-সরবরাহকারীর
     * টেবিলে জোড় লাগালে Accounts-কে ঐ মডিউলগুলোর টেবিল জানতে হত (depends_on ফাঁকা)। ⭐ তাই জোড় এখনো নেই: SQL কেবল
     * "ধরন:id" বের করে — খাতার সারি থেকে, সবার সাধারণ টেবিল ([[partyKey()]]) — আর নামটা বসায় কোরের [[PartyRegistry]],
     * মডিউলগুলোর নিজের ঘোষণা (`parties`) থেকে, পাতা তোলার পরে একবারে ([[ReportEngine::withParties()]])। সীমানা অক্ষত।
     */

    /** দৈনিক খতিয়ান — একটা তারিখ পরিসরের সব লেনদেন, ক্রমানুসারে। */
    public static function dayBook(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'accounts.day_book',
            // ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই — সূচি ও ফোন এখান থেকে পড়ে (২৭ সেপ্টেম্বর ২০২৬)
            permission: 'accounts.report',
            title: 'accounts::menu.day_book',
            filters: ['date_range', 'branch'],
            // খাতের নাম join করে আনা হয়, id নয়: "১৪" দেখে কেউ বলতে পারে
            // না কোন খাত, আর প্রতিটা সারির জন্য আলাদা কোয়েরি করলে একশো
            // সারিতে একশো কোয়েরি হত।
            //
            // join আসার পর প্রতিটা কলামের নামে টেবিল লেখা বাধ্যতামূলক:
            // company_id দুই টেবিলেই আছে, আর যোগফলের সাব-কোয়েরিতে MySQL
            // "ambiguous" বলে থেমে গিয়েছিল।
            query: fn (array $f) => DB::table('ledger_entries')
                ->leftJoin('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
                ->where('ledger_entries.company_id', $f['company_id'])
                ->tap(ReportEngine::branchWall($f, 'ledger_entries.branch_id'))
                ->whereBetween('ledger_entries.trx_date', [$f['from'], $f['to']])
                ->orderBy('ledger_entries.trx_date')
                ->orderBy('ledger_entries.id')
                ->select([
                    'ledger_entries.trx_date', 'ledger_entries.document_no',
                    self::accountName(), 'ledger_entries.narration',
                    'ledger_entries.debit', 'ledger_entries.credit',
                    'ledger_entries.source_type', 'ledger_entries.source_id',
                    self::partyKey(),
                ]),
            columns: [
                ['key' => 'trx_date', 'label' => 'core.print.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                [
                    'key' => 'document_no',
                    'label' => 'core.table.document',
                    'type' => ReportColumn::DOCUMENT,
                    // এই দুটো থাকায় নম্বরটা ক্লিকযোগ্য হয় — নিয়ম ১
                    'source_type' => 'source_type',
                    'source_id' => 'source_id',
                ],
                // ⭐ নম্বরের পাশে পক্ষ — "নাম · পয়েন্ট" (মালিক, ১৯ অক্টোবর ২০২৬); নাম বসায় ইঞ্জিন, পাতার একবারে ([[partyKey()]])
                ['key' => 'party', 'label' => 'accounts::field.party', 'party' => 'party_key', 'width' => '14rem'],
                ['key' => 'account_name', 'label' => 'core.print.account'],
                ['key' => 'narration', 'label' => 'core.table.narration'],
                ['key' => 'debit', 'label' => 'core.table.debit', 'type' => ReportColumn::MONEY],
                ['key' => 'credit', 'label' => 'core.table.credit', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /** খতিয়ান — এক হিসাবের চলাচল ও চলমান ব্যালেন্স। */
    public static function ledger(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'accounts.ledger',
            // ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই — সূচি ও ফোন এখান থেকে পড়ে (২৭ সেপ্টেম্বর ২০২৬)
            permission: 'accounts.report',
            title: 'accounts::menu.ledger',
            filters: ['date_range', 'branch', 'account'],
            runningBalance: true,
            // ⭐ শুরুর জের — অডিট গ৯, ৪ অক্টোবর ২০২৬
            opening: fn (array $f) => self::openingOf(DB::table('ledger_entries')
                ->where('company_id', $f['company_id'])
                ->when($f['account_id'] ?? null, fn ($q, $account) => $q->where('account_id', $account))
                ->tap(ReportEngine::branchWall($f, 'branch_id'))
                ->where('trx_date', '<', $f['from']), 'debit', 'credit'),
            query: fn (array $f) => DB::table('ledger_entries')
                ->where('company_id', $f['company_id'])
                ->when($f['account_id'] ?? null, fn ($q, $account) => $q->where('account_id', $account))
                ->tap(ReportEngine::branchWall($f, 'branch_id'))
                ->whereBetween('trx_date', [$f['from'], $f['to']])
                ->orderBy('trx_date')
                ->orderBy('id')
                ->select([
                    'trx_date', 'document_no', 'narration',
                    'debit', 'credit', 'source_type', 'source_id',
                    self::partyKey(),
                ]),
            columns: [
                ['key' => 'trx_date', 'label' => 'core.print.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                [
                    'key' => 'document_no',
                    'label' => 'core.table.document',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type',
                    'source_id' => 'source_id',
                ],
                // ⭐ নম্বরের পাশে পক্ষ — "নাম · পয়েন্ট" (মালিক, ১৯ অক্টোবর ২০২৬); নাম বসায় ইঞ্জিন, পাতার একবারে ([[partyKey()]])
                ['key' => 'party', 'label' => 'accounts::field.party', 'party' => 'party_key', 'width' => '14rem'],
                ['key' => 'narration', 'label' => 'core.table.narration'],
                ['key' => 'debit', 'label' => 'core.table.debit', 'type' => ReportColumn::MONEY],
                ['key' => 'credit', 'label' => 'core.table.credit', 'type' => ReportColumn::MONEY],
                // চলমান ব্যালেন্স যোগ করা হয় না — শেষ সারির মানটাই ব্যালেন্স,
                // যোগফল দেখালে অর্থহীন একটা সংখ্যা আসত।
                // ⭐ "(Dr) 250.79" / "(Cr) 22,958.21" — খালি বিয়োগ নয় (অডিট ⓘ১৬, ৭ অক্টোবর ২০২৬; মালিকের নিয়ম, ৩ অক্টোবর ২০২৬;
                // [[TheTrialBalanceShowsNetBalancesTest]])। খাতা, প্রকল্পের খাতা আর নগদ/ব্যাংক বই — তিনটাই।
                ['key' => 'balance', 'label' => 'core.table.balance', 'type' => ReportColumn::DR_CR, 'width' => '10rem', 'total' => false],
            ],
        );
    }

    /**
     * ⭐ প্রকল্পভিত্তিক খতিয়ান — মানচিত্র §১৮, ২০ সেপ্টেম্বর ২০২৬।
     *
     * ── কেন [[byCostCentre]] দিয়ে কাজ চলে না ────────────────────────
     * ওটা **যোগফল**: কোন কেন্দ্রে মোট কত খরচ, কত আয়। ⓘ কিন্তু "খতিয়ান"
     * শব্দটার মানে আলাদা — তারিখ ধরে প্রতিটা সারি, আর চলমান জের। ⚠️
     * প্রকল্পের হিসাব নিয়ে যে প্রশ্নটা সত্যিই ওঠে সেটা "মোট কত" নয়,
     * **"টাকাটা কোথায় গেল"** — আর তার উত্তর সারিগুলোতে, যোগফলে নয়।
     *
     * ── ⚠️ "প্রকল্প" আর "খরচের কেন্দ্র" এখানে একই জিনিস ─────────────
     * ⓘ ডিপোতে প্রকল্প মানে একটা রুট, একটা গুদাম, একটা গাড়ি, বা একটা
     * কাজ — আর ঐ মাত্রাটা ছকে আগে থেকেই আছে (`cost_center_id`), খতিয়ান
     * থেকে ভাউচার পর্যন্ত পুরো পথেই।
     *
     * ⛔ আলাদা একটা `project_id` কলাম বসানো হয়নি, আর কারণটা ছোট নয়:
     * খতিয়ানের প্রতিটা সারি হ্যাশ-শিকলে বাঁধা, আর সই করা ঘরের তালিকায়
     * ([[App\Core\Security\LedgerChain::SIGNED]]) নতুন নাম ঢোকালে
     * **আগের প্রতিটা সারির হ্যাশ অবৈধ** হয়ে যেত — অর্থাৎ খাতা
     * "বদলানো হয়েছে" বলে চিৎকার করত, অথচ কিছুই বদলায়নি। ⓘ প্রকল্পকে
     * সত্যিই আলাদা মাত্রা বানাতে হলে সেটা মালিকের সিদ্ধান্ত, আর তার
     * সাথে শিকলের একটা যুগ-বদল লাগবে।
     *
     * ⓘ সব ধরনের খাত রাখা হয় — [[byCostCentre]]-এর মতো কেবল আয়-ব্যয়
     * নয়। ⚠️ খতিয়ানে "প্রকল্পের টাকা কোন ব্যাংক থেকে বেরোল" সারিটাও
     * দরকার; ওটা বাদ দিলে জেরটাই মিলত না।
     */
    public static function projectLedger(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'accounts.project_ledger',
            // ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই — সূচি ও ফোন এখান থেকে পড়ে (২৭ সেপ্টেম্বর ২০২৬)
            permission: 'accounts.report',
            title: 'accounts::menu.project_ledger',
            filters: ['date_range', 'branch', 'cost_centre'],
            runningBalance: true,
            /*
             * ⭐ শুরুর জের — অডিট (সমন্বয়ক, ১০ অক্টোবর ২০২৬): আগে প্রকল্পের খতিয়ান পরিসরের প্রথম দিন শূন্য থেকে গুনত, তাই
             * চলমান প্রকল্পের জের প্রতি মাসে ভুল ছাপত। ⓘ খতিয়ান, নগদ বই আর ব্যাংক বইয়ের একই নিয়ম (801247e2) — একই কেন্দ্র,
             * একই শাখার দেয়াল, পরিসরের আগের সব সারি। কেন্দ্র না বাছলে শূন্য, নিচের সারিগুলোর মতোই।
             */
            opening: fn (array $f) => self::openingOf(DB::table('ledger_entries')
                ->where('ledger_entries.company_id', $f['company_id'])
                ->where('ledger_entries.cost_center_id', $f['cost_center_id'] ?? 0)
                ->tap(ReportEngine::branchWall($f, 'ledger_entries.branch_id'))
                ->where('ledger_entries.trx_date', '<', $f['from']), 'ledger_entries.debit', 'ledger_entries.credit'),
            query: fn (array $f) => DB::table('ledger_entries')
                ->leftJoin('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
                ->where('ledger_entries.company_id', $f['company_id'])

                /*
                 * ⛔ কেন্দ্র না বাছলে কিছুই দেখানো হয় না।
                 *
                 * ⚠️ ছাঁকনিটা না বসালে এটা গোটা খতিয়ান হয়ে যেত, আর
                 * "প্রকল্পভিত্তিক" নামের সাথে পর্দার কোনো সম্পর্ক থাকত না।
                 * ⓘ `0` দিলে কোনো সারি মেলে না — খালি পর্দাই ঠিক উত্তর,
                 * কারণ প্রশ্নটাই এখনো করা হয়নি।
                 */
                ->where('ledger_entries.cost_center_id', $f['cost_center_id'] ?? 0)
                ->tap(ReportEngine::branchWall($f, 'ledger_entries.branch_id'))
                ->whereBetween('ledger_entries.trx_date', [$f['from'], $f['to']])
                ->orderBy('ledger_entries.trx_date')
                ->orderBy('ledger_entries.id')
                ->select([
                    'ledger_entries.trx_date',
                    'ledger_entries.document_no',
                    self::accountName(),
                    'ledger_entries.narration',
                    'ledger_entries.debit',
                    'ledger_entries.credit',
                    'ledger_entries.source_type',
                    'ledger_entries.source_id',
                    self::partyKey(),
                ]),
            columns: [
                ['key' => 'trx_date', 'label' => 'core.print.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                [
                    'key' => 'document_no',
                    'label' => 'core.table.document',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type',
                    'source_id' => 'source_id',
                ],
                // ⭐ নম্বরের পাশে পক্ষ — "নাম · পয়েন্ট" (মালিক, ১৯ অক্টোবর ২০২৬); নাম বসায় ইঞ্জিন, পাতার একবারে ([[partyKey()]])
                ['key' => 'party', 'label' => 'accounts::field.party', 'party' => 'party_key', 'width' => '14rem'],
                ['key' => 'account_name', 'label' => 'core.print.account', 'type' => ReportColumn::TEXT],
                ['key' => 'narration', 'label' => 'core.table.narration'],
                ['key' => 'debit', 'label' => 'core.table.debit', 'type' => ReportColumn::MONEY],
                ['key' => 'credit', 'label' => 'core.table.credit', 'type' => ReportColumn::MONEY],
                ['key' => 'balance', 'label' => 'core.table.balance', 'type' => ReportColumn::DR_CR, 'width' => '10rem', 'total' => false],
            ],
        );
    }

    /**
     * রেওয়ামিল — প্রতি হিসাবের নিট জের, ডেবিট বা ক্রেডিট কলামে।
     *
     * ⭐ আন্তর্জাতিক উপস্থাপন — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (ⓘ১৬; সমন্বয়কের আদেশ ৭ অক্টোবর ২০২৬, মালিক ১০০% আন্তর্জাতিক মান চেয়েছেন;
     * [[TheTrialBalanceShowsNetBalancesTest]])। ⓘ আগে প্রতিটা খাতের মোট ডেবিট আর মোট ক্রেডিট পাশাপাশি বসত — লেনদেনের যোগফল, জের নয়;
     * নগদের খাতে দুই কলামেই লাখ লাখ, অথচ হাতে কত তা পড়ে বুঝতে হত। এখন প্রতিটা খাতের জের একটাই কলামে: ডেবিট-জের ডেবিটে, ক্রেডিট-জের
     * ক্রেডিটে; শূন্য-জেরের খাত বাদ। খাতা মেলে বলে দুই কলামের মোট আগের মতোই সমান।
     */
    public static function trialBalance(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'accounts.trial_balance',
            // ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই — সূচি ও ফোন এখান থেকে পড়ে (২৭ সেপ্টেম্বর ২০২৬)
            // ⛔ চূড়ান্ত হিসাবের অঙ্ক — চূড়ান্ত রিপোর্টের চাবি (অডিট ম১৩/ম১৮, ১০ অক্টোবর ২০২৬); আগে accounts.report-ই যথেষ্ট ছিল
            // ⭐ কেবল চূড়ান্ত হিসাবের চাবি — মেনু, ওয়েবের দরজা আর ফোন একই কথা বলে, লাভ-ক্ষতির মতো (fe hotfix, ১৯ অক্টোবর ২০২৬);
            // ⛔ আগে মেনু কেবল `.final` দেখে সারি দেখাত অথচ এখানে `accounts.report`-ও চাইত — খুললেই ৪০৩
            permission: 'accounts.report.final',
            asOfDate: true,
            title: 'accounts::menu.trial_balance',
            filters: ['date_range', 'branch'],
            groupBy: 'account_id',
            query: fn (array $f) => DB::table('ledger_entries')
                ->leftJoin('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
                ->where('ledger_entries.company_id', $f['company_id'])
                ->tap(ReportEngine::branchWall($f, 'ledger_entries.branch_id'))
                /*
                 * শুরুর তারিখ ধরা হয় না — রেওয়ামিল একটা মুহূর্তের ছবি।
                 *
                 * ── যে ভুলটা এখানে ছিল ─────────────────────────────────
                 * আগে whereBetween(from, to) ছিল, অর্থাৎ পরিসরের ভেতরের
                 * চলাচল যোগ হত। ফিল্টারের ডিফল্ট "চলতি মাসের ১ তারিখ"
                 * ভাঁজ করা থাকে, তাই কেউ সেটা দেখতেও পেত না — অথচ ওর
                 * আগের প্রতিটা দাখিলা (খোলা মজুদ, খোলা ব্যালেন্স, গত
                 * মাসের সব লেনদেন) রেওয়ামিল থেকে বাদ পড়ে যেত।
                 *
                 * ফলটা সবচেয়ে খারাপ ধরনের: সংখ্যাটা ভুল, অথচ ডেবিট আর
                 * ক্রেডিট তবু সমান — তাই দেখে বোঝার উপায় ছিল না। ৮,৪০,০০০
                 * টাকার খোলা মজুদ পর্দায় ৩,৪০০ দেখাচ্ছিল, আর সেটাকে
                 * "ডেমো ডেটার সমস্যা" ভাবা হয়েছিল।
                 *
                 * ব্যালেন্স শিট শুরু থেকেই ঠিক ছিল (summaryByAccount-এ
                 * dateRange: false); রেওয়ামিলও তারই মতো — জেরের রিপোর্ট,
                 * সময়ের রিপোর্ট নয়।
                 */
                ->where('ledger_entries.trx_date', '<=', $f['to'])
                ->groupBy('ledger_entries.account_id', 'accounts.code', 'accounts.name_en', 'accounts.name_bn')
                ->orderBy('accounts.code')
                ->select([
                    'ledger_entries.account_id',
                    self::accountName(),
                    DB::raw('CASE WHEN SUM(ledger_entries.debit) > SUM(ledger_entries.credit) THEN SUM(ledger_entries.debit) - SUM(ledger_entries.credit) ELSE 0 END as debit'),
                    DB::raw('CASE WHEN SUM(ledger_entries.credit) > SUM(ledger_entries.debit) THEN SUM(ledger_entries.credit) - SUM(ledger_entries.debit) ELSE 0 END as credit'),
                ])
                // ⓘ জের শূন্য — রেওয়ামিলে সারি নেই
                ->havingRaw('SUM(ledger_entries.debit) <> SUM(ledger_entries.credit)'),
            columns: [
                ['key' => 'account_name', 'label' => 'core.print.account'],
                ['key' => 'debit', 'label' => 'core.table.debit', 'type' => ReportColumn::MONEY],
                ['key' => 'credit', 'label' => 'core.table.credit', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * ক্যাশ বই — শুধু নগদের খাতগুলোর চলাচল।
     *
     * ডে বুক থেকে আলাদা, কারণ প্রশ্ন দুইটা আলাদা: ডে বুক বলে "আজ কী কী
     * হল", ক্যাশ বই বলে "নগদের ঘরে কী ঢুকল আর কী বেরোল"। ক্যাশিয়ার
     * দিনশেষে দ্বিতীয়টাই মেলায়।
     */
    public static function cashBook(): ReportDefinition
    {
        return self::moneyBook('accounts.cash_book', 'accounts::menu.cash_book', Account::CASH);
    }

    /**
     * ব্যাংক বই — ব্যাংক ও MFS খাতের চলাচল।
     *
     * ⛔ আচরণ বদলায়নি: পুরনো `is_bank` পতাকাটাই ব্যাংক ও MFS দুইটাকে
     * বোঝাত, তাই দুইটা ধরন এখানে একই বইয়ে থাকাটা সেই নিয়মেরই সৎ বানান।
     */
    public static function bankBook(): ReportDefinition
    {
        return self::moneyBook(
            'accounts.bank_book',
            'accounts::menu.bank_book',
            [Account::BANK, Account::MFS],
        );
    }

    /** পরিসরের আগের জের — ডেবিট − ক্রেডিট ([[ReportDefinition::$opening]]; অডিট গ৯) */
    private static function openingOf(Builder $before, string $debit, string $credit): string
    {
        return (string) ($before->selectRaw("COALESCE(SUM({$debit}), 0) - COALESCE(SUM({$credit}), 0) as net")->value('net') ?? '0');
    }

    /**
     * দুইটা বই একই আকারের, শুধু ফিল্টারটা আলাদা।
     *
     * আলাদা করে দুইবার লিখলে একদিন একটায় কলাম যোগ হত আর অন্যটায় না।
     *
     * @param  string|list<string>  $kind  টাকার ধরন — [[Account::MONEY_KINDS]]
     */
    private static function moneyBook(string $key, string $title, string|array $kind): ReportDefinition
    {
        return new ReportDefinition(
            key: $key,
            // ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই — সূচি ও ফোন এখান থেকে পড়ে (২৭ সেপ্টেম্বর ২০২৬)
            permission: 'accounts.report',
            title: $title,
            filters: ['date_range', 'branch'],
            runningBalance: true,
            // ⭐ শুরুর জের — অডিট গ৯: ৫ লাখ নগদে মাসের ২ তারিখের ২০,০০০ খরচ আর "−২০,০০০" নয়
            opening: fn (array $f) => self::openingOf(DB::table('ledger_entries')
                ->leftJoin('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
                ->where('ledger_entries.company_id', $f['company_id'])
                ->whereIn('accounts.money_kind', (array) $kind)
                ->where('accounts.is_group', false)
                ->tap(ReportEngine::branchWall($f, 'ledger_entries.branch_id'))
                ->where('ledger_entries.trx_date', '<', $f['from']), 'ledger_entries.debit', 'ledger_entries.credit'),
            query: fn (array $f) => DB::table('ledger_entries')
                ->leftJoin('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
                ->where('ledger_entries.company_id', $f['company_id'])
                ->whereIn('accounts.money_kind', (array) $kind)
                ->where('accounts.is_group', false)
                ->tap(ReportEngine::branchWall($f, 'ledger_entries.branch_id'))
                ->whereBetween('ledger_entries.trx_date', [$f['from'], $f['to']])
                ->orderBy('ledger_entries.trx_date')
                ->orderBy('ledger_entries.id')
                ->select([
                    'ledger_entries.trx_date', 'ledger_entries.document_no',
                    self::accountName(), 'ledger_entries.narration',
                    'ledger_entries.debit', 'ledger_entries.credit',
                    'ledger_entries.source_type', 'ledger_entries.source_id',
                    self::partyKey(),
                ]),
            columns: [
                ['key' => 'trx_date', 'label' => 'core.print.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                [
                    'key' => 'document_no',
                    'label' => 'core.table.document',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type',
                    'source_id' => 'source_id',
                ],
                // ⭐ নম্বরের পাশে পক্ষ — "নাম · পয়েন্ট" (মালিক, ১৯ অক্টোবর ২০২৬); নাম বসায় ইঞ্জিন, পাতার একবারে ([[partyKey()]])
                ['key' => 'party', 'label' => 'accounts::field.party', 'party' => 'party_key', 'width' => '14rem'],
                ['key' => 'account_name', 'label' => 'core.print.account'],
                ['key' => 'narration', 'label' => 'core.table.narration'],
                ['key' => 'debit', 'label' => 'accounts::field.received', 'type' => ReportColumn::MONEY],
                ['key' => 'credit', 'label' => 'accounts::field.paid', 'type' => ReportColumn::MONEY],
                ['key' => 'balance', 'label' => 'core.table.balance', 'type' => ReportColumn::DR_CR, 'width' => '10rem', 'total' => false],
            ],
        );
    }

    /**
     * লাভ-লোকসান — আয় ও খরচ, একটা সময়ের।
     *
     * ব্যালেন্স শিট থেকে আলাদা করার নিয়মটা খাতের ধরনেই লেখা আছে: আয় ও
     * খরচ এখানে, বাকি তিনটা ওখানে। তাই নতুন খাত যোগ হলে সেটা নিজে থেকেই
     * ঠিক রিপোর্টে চলে যায় — কোথাও তালিকা হালনাগাদ করতে হয় না।
     */
    public static function profitAndLoss(): ReportDefinition
    {
        return self::summaryByAccount(
            'accounts.profit_loss',
            'accounts::menu.profit_loss',
            [Account::INCOME, Account::EXPENSE],
            dateRange: true,
            // ⭐ চলতি অর্থবছর থেকে — স্থিতিপত্রের "চলতি বছরের লাভ"-এর একই বছর, দুই দরজায় ([[ReportDefinition::$defaultPeriod]])
            defaultPeriod: ReportDefinition::PERIOD_FISCAL_YEAR,
            /*
             * ⭐ বিক্রয়, বিক্রীত পণ্যের ব্যয়, মোট মুনাফা — তারপর বাকি আয় আর খরচ (IAS 1) — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬
             * (হিসাব, ঠিক ৭)। ⚠️ আগে সব আয়-খরচ কোড ধরে এক তালিকায়: বিক্রয় থেকে মালের দাম বাদে কত থাকল — ব্যবসার প্রথম
             * প্রশ্ন — কোথাও লেখা ছিল না। ⓘ ভাগটা খাতের ধরন আর ছকের জায়গা থেকে ([[plSections()]]), খাতা বদলায় না।
             */
            grossProfit: true,

            /*
             * ⭐ ফলটা এক লাইনে, সবার উপরে — ৭ সেপ্টেম্বর ২০২৬।
             *
             * ── ⛔ কী ছিল না ─────────────────────────────────────────
             * পর্দাটা খাত ধরে ধরে সব দেখাত আর নিচে লিখত "সর্বমোট"।
             * ⓘ লাভ হয়েছে না ক্ষতি — একটা শব্দও ছিল না।
             *
             * ── কেন `credit − debit` ─────────────────────────────────
             * আয়ের খাত ক্রেডিটে বাড়ে, খরচের খাত ডেবিটে। ⓘ তাই সব
             * ক্রেডিটের যোগফল থেকে সব ডেবিটের যোগফল বাদ দিলে যা থাকে,
             * সেটাই নিট ফল — ধনাত্মক হলে লাভ, ঋণাত্মক হলে ক্ষতি।
             *
             * ⚠️ বিক্রয় ফেরত (৪১১০) আয়ের ঘরে বসে কিন্তু ডেবিটে বাড়ে,
             * তাই সে নিজে থেকেই আয় কমায় — আলাদা করে বাদ দেওয়ার কিছু
             * নেই। ⛔ আলাদা করে বাদ দিলে **দুইবার** বাদ যেত।
             */
            summary: function (array $totals): array {
                $income = (string) ($totals['credit'] ?? '0');
                $spent = (string) ($totals['debit'] ?? '0');
                $net = bcsub($income ?: '0', $spent ?: '0', 4);

                $profit = bccomp($net, '0', 4) >= 0;

                // ⓘ মোট মুনাফা — বিক্রয় আর বিক্রীত পণ্যের ব্যয়ের সারির `gross` ঘরের যোগ ([[summaryByAccount()]])
                $gross = (string) ($totals['gross'] ?? '0') ?: '0';
                $grossGood = bccomp($gross, '0', 4) >= 0;

                return [
                    'lines' => [[
                        'label' => $grossGood ? __('accounts::message.gross_profit') : __('accounts::message.gross_loss'),
                        'value' => $grossGood ? $gross : bcmul($gross, '-1', 4),
                    ]],
                    'label' => $profit
                        ? __('accounts::message.net_profit')
                        : __('accounts::message.net_loss'),
                    // ⓘ ক্ষতিও ধনাত্মক সংখ্যায় দেখানো হয় — শব্দটাই দিক বলে
                    'value' => $profit ? $net : bcmul($net, '-1', 4),
                    'good' => $profit,
                ];
            },
            /*
             * ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই — কেবল চূড়ান্ত হিসাব ([[FinalAccountsReportController]], cec88dd3)। ⓘ আগে
             * এখানে `accounts.report`-ও ছিল, তাই যাঁর কেবল চূড়ান্ত হিসাবের চাবি (মালিকের পাঠক, নিরীক্ষক) তিনি পাতা খুলতেন
             * কিন্তু সূচি, ফোন আর ডাউনলোডে পেতেন না (সমন্বয়কের সিদ্ধান্ত, ৬ অক্টোবর ২০২৬; স্থিতিপত্রের মতো)।
             */
            permission: 'accounts.report.final',
        );
    }

    /**
     * ব্যালেন্স শিট — সম্পদ, দায় ও মূলধন, একটা তারিখে।
     *
     * পরিসর নয়, একটা তারিখ পর্যন্ত: ব্যালেন্স শিট একটা মুহূর্তের ছবি।
     * শুরুর তারিখটা তাই সবসময় সময়ের শুরু থেকে।
     */
    public static function balanceSheet(): ReportDefinition
    {
        return self::summaryByAccount(
            'accounts.balance_sheet',
            'accounts::menu.balance_sheet',
            Account::BALANCE_SHEET_TYPES,
            dateRange: false,
            /*
             * ⭐ অগ্রিম নিজের লাইনে — পর্দার স্থিতিপত্রের মতো ([[BalanceSheetService]]) — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬
             * (হিসাব, ঠিক ৮)। ⚠️ আগে পাওনা আর দেনা নিট দেখাত, তাই একই খাতায় দুই স্থিতিপত্রের মোট সম্পদ আর মোট দায় আলাদা।
             */
            advancesApart: true,

            /*
             * ⛔ বন্ধ-না-হওয়া লাভ এক লাইনে — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (হিসাব ⚠️১১; [[TheEngineBalanceSheetNamesTheUnclosedProfitTest]])।
             * ⓘ এই সংস্করণ ফোনের রিপোর্ট আর নির্ধারিত রিপোর্টে খোলে, অথচ আয়-ব্যয় এখানে থাকে না — বছর বন্ধের আগে সম্পদ আর
             * দায়-মূলধনের ফারাক কোথাও ব্যাখ্যা হত না, আর রিপোর্টটা কখনো মিলত না। খাতা সবসময় মেলে, তাই এখানের নিট ডেবিট ঠিক
             * চলতি বছরের বন্ধ-না-হওয়া লাভ ([[BalanceSheetService::profitSoFar()]]-এর একই অঙ্ক) — সেটাই নাম ধরে দেখানো।
             */
            summary: function (array $totals, array $f = []): array {
                $profit = bcsub((string) ($totals['debit'] ?? '0') ?: '0', (string) ($totals['credit'] ?? '0') ?: '0', 4);
                $good = bccomp($profit, '0', 4) >= 0;

                return [
                    // ⛔ পাতার স্থিতিপত্রের একই ভাগ — চলতি বছর আর আগের বন্ধ-না-হওয়া বছর ([[self::unclosedSplit()]]; ধাপ ০, ১২ অক্টোবর ২০২৬)
                    'lines' => self::unclosedSplit($profit, $f),
                    'label' => $good ? __('accounts::message.unclosed_profit') : __('accounts::message.unclosed_loss'),
                    'value' => $good ? $profit : bcmul($profit, '-1', 4),
                    'good' => $good,
                ];
            },
            /* ⓘ লাইভের ব্যালেন্স শিটের পাতা আলাদা নিয়ামক ([[BalanceSheetController]]),
               আর সেটা কেবল এই চাবি চায় — ইঞ্জিনের এই সংস্করণ ওয়েবে পৌঁছায়ই না */
            permission: 'accounts.report.final',
        );
    }

    /**
     * বন্ধ-না-হওয়া লাভের দুই ভাগ — চলতি বছরের, আর তার আগের বন্ধ-না-হওয়া বছরগুলোর ([[BalanceSheetService::build()]]-এর একই ভাগ)।
     *
     * ⛔ a5-এর পাতা-সাজানোর পরিকল্পনা, ধাপ ০ (সমন্বয়ক, ১২ অক্টোবর ২০২৬; [[TheSheetsProfitThisYearIsTheProfitAndLossTest]])। ⓘ পাতার স্থিতিপত্রে
     * "চলতি বছরের লাভ" এখন লাভ-ক্ষতির নিট লাভ; এখানে না ভাঙলে ফোন আর নির্ধারিত রিপোর্ট অন্য অঙ্ক বলত। ছাঁকনি না পেলে (পুরনো ডাক) ভাগ নেই।
     *
     * @param  array<string, mixed>  $f
     * @return list<array{label: string, value: string}>
     */
    private static function unclosedSplit(string $profit, array $f): array
    {
        if (! isset($f['to'], $f['company_id'])) {
            return [];
        }

        $start = FinancialYear::forDate((string) $f['to'])?->starts_on;

        $thisYear = DB::table('ledger_entries')
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_entries.company_id', $f['company_id'])
            ->whereIn('accounts.type', [Account::INCOME, Account::EXPENSE])
            ->tap(ReportEngine::branchWall($f, 'ledger_entries.branch_id'))
            ->when($start !== null, fn ($q) => $q->where('ledger_entries.trx_date', '>=', Carbon::parse($start)->toDateString()))
            ->where('ledger_entries.trx_date', '<=', $f['to'])
            ->selectRaw('COALESCE(SUM(ledger_entries.credit - ledger_entries.debit), 0) as net')
            ->value('net');

        $thisYear = bcadd((string) $thisYear, '0', 4);
        $earlier = bcsub($profit, $thisYear, 4);

        return array_values(array_filter([
            ['label' => __('accounts::field.profit_this_year'), 'value' => $thisYear],
            bccomp($earlier, '0', 4) !== 0 ? ['label' => __('accounts::field.profit_earlier_years'), 'value' => $earlier] : null,
        ]));
    }

    /**
     * দুইটা সারাংশ রিপোর্ট একই আকারের — শুধু কোন ধরনগুলো আর কোন সময়।
     *
     * @param  list<string>  $types
     */
    private static function summaryByAccount(
        string $key,
        string $title,
        array $types,
        bool $dateRange,
        ?\Closure $summary = null,
        string|array $permission = ['accounts.report', 'accounts.report.final'],
        bool $grossProfit = false,
        bool $advancesApart = false,
        string $defaultPeriod = ReportDefinition::PERIOD_MONTH,
    ): ReportDefinition {
        return new ReportDefinition(
            key: $key,
            defaultPeriod: $defaultPeriod,
            // ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই — সূচি ও ফোন এখান থেকে পড়ে (২৭ সেপ্টেম্বর ২০২৬)
            permission: $permission,
            title: $title,
            filters: ['date_range', 'branch'],

            /*
             * dateRange না মানেই ব্যালেন্স শিট — একটা তারিখ পর্যন্ত জের।
             *
             * তাই পর্দাতেও "From" ঘরটা দেখানোর কিছু নেই; একই পতাকা
             * দুইটা কাজ করে, আর দুইটা আলাদা রাখলে একদিন একটা বদলে
             * অন্যটা রয়ে যেত।
             */
            asOfDate: ! $dateRange,

            // ⓘ কেবল লাভ-ক্ষতি এটা দেয়; রেওয়ামিল ও স্থিতিপত্রে `null`
            summary: $summary,

            groupBy: 'account_id',
            query: fn (array $f) => $advancesApart ? self::sheetWithAdvancesApart($f, $types) : DB::table('ledger_entries')
                ->leftJoin('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
                ->where('ledger_entries.company_id', $f['company_id'])
                ->whereIn('accounts.type', $types)
                ->tap(ReportEngine::branchWall($f, 'ledger_entries.branch_id'))
                // ব্যালেন্স শিটে শুরুর তারিখ ধরা হয় না — একটা মুহূর্তের
                // ছবিতে "কবে থেকে" প্রশ্নটাই অর্থহীন
                ->when($dateRange, fn ($q) => $q->where('ledger_entries.trx_date', '>=', $f['from']))
                ->where('ledger_entries.trx_date', '<=', $f['to'])
                /*
                 * বছর সমাপনীর দাখিলাটা লাভ-লোকসানে গোনা হয় না।
                 *
                 * ওটা আয়-ব্যয়ের খাতগুলো শূন্য করার জন্য, বছরের শেষ দিনে।
                 * ধরলে বন্ধ করা বছরের লাভ-লোকসান খুললে সব শূন্য দেখাত —
                 * অর্থাৎ বছর বন্ধ করার সাথে সাথে ওই বছরের ফলটাই পর্দা
                 * থেকে মুছে যেত।
                 *
                 * ব্যালেন্স শিটে বাদ দেওয়ার দরকার নেই: ওখানে আয়-ব্যয়
                 * থাকেই না, আর সঞ্চিত মুনাফার লাইনটা ওখানে থাকা জরুরি।
                 */
                /*
                 * ⚠️ দুইটা নামই বাদ — ২০ সেপ্টেম্বর ২০২৬। আগে কেবল
                 * `year_close` বাদ যেত, আর `year_close:reversal` গোনা হত —
                 * তাই বছর আবার খুললে লাভ দ্বিগুণ দেখাত।
                 */
                ->when($dateRange, fn ($q) => $q->whereNotIn(
                    'ledger_entries.source_type', YearEndService::closingSources(),
                ))
                ->groupBy(
                    'ledger_entries.account_id', 'accounts.code',
                    'accounts.name_en', 'accounts.name_bn', 'accounts.type',
                )
                ->when($grossProfit, fn ($q) => $q->orderByRaw(self::plSections((int) $f['company_id'])['rank']))
                ->orderBy('accounts.code')
                ->select([
                    'ledger_entries.account_id',
                    'accounts.type',
                    self::accountName(),
                    DB::raw('SUM(ledger_entries.debit) as debit'),
                    DB::raw('SUM(ledger_entries.credit) as credit'),
                    DB::raw('SUM(ledger_entries.debit) - SUM(ledger_entries.credit) as net'),
                ])
                ->when($grossProfit, function ($q) use ($f) {
                    $sections = self::plSections((int) $f['company_id']);

                    $q->selectRaw($sections['label'].' as section', $sections['bindings'])
                        // ⓘ কেবল বিক্রয় আর বিক্রীত পণ্যের ব্যয়ের সারিতে — যোগফলই মোট মুনাফা
                        ->selectRaw($sections['gross'].' as gross');
                }),
            columns: [
                ['key' => 'account_name', 'label' => 'core.print.account'],
                ...($grossProfit ? [['key' => 'section', 'label' => 'accounts::field.pl_section', 'width' => '12rem']] : []),
                ['key' => 'type', 'label' => 'accounts::field.type', 'width' => '8rem'],
                ['key' => 'debit', 'label' => 'core.table.debit', 'type' => ReportColumn::MONEY],
                ['key' => 'credit', 'label' => 'core.table.credit', 'type' => ReportColumn::MONEY],
                ['key' => 'net', 'label' => 'core.table.balance', 'type' => ReportColumn::MONEY],
                ...($grossProfit ? [['key' => 'gross', 'label' => 'accounts::message.gross_profit', 'type' => ReportColumn::MONEY]] : []),
            ],
        );
    }

    /**
     * ⭐ ইঞ্জিনের স্থিতিপত্র — পাওনা আর দেনা মোট অঙ্কে, অগ্রিম আলাদা লাইনে (পুনঃঅডিট, ৯ অক্টোবর ২০২৬; হিসাব, ঠিক ৮)।
     *
     * ⓘ পর্দার নিয়মই ([[BalanceSheetService::build()]]): পাওনার খাতে (১১১০) যে গ্রাহকদের জের ক্রেডিট, তাঁদের যোগ "গ্রাহকের
     * অগ্রিম" — দায়ের দিকে; দেনার খাতে (২১১১) যে সরবরাহকারীদের জের ডেবিট, তাঁদের যোগ "সরবরাহকারীর অগ্রিম" — সম্পদের দিকে।
     * ⭐ খাতার সারির পাশে দুই জোড়া হিসাবের সারি বসে: পক্ষের খাতে উল্টো দিকে অগ্রিমটা (খাতটা মোট দেখায়), আর অগ্রিমের নিজের
     * লাইনে একই অঙ্ক। জোড়া সমান, তাই নিট ডেবিট আর বন্ধ-না-হওয়া লাভ একটুও নড়ে না। ⛔ খাতায় কিছু লেখা হয় না।
     * ⓘ শাখার দেয়াল দুই জায়গাতেই একই ([[ReportEngine::branchWall()]])।
     *
     * @param  list<string>  $types
     */
    private static function sheetWithAdvancesApart(array $f, array $types): Builder
    {
        $codes = DB::table('accounts')->where('company_id', $f['company_id'])->whereNull('deleted_at')
            ->whereIn('code', [StandardChart::RECEIVABLE, StandardChart::PAYABLE])->pluck('id', 'code');

        $lines = DB::table('ledger_entries')
            ->where('ledger_entries.company_id', $f['company_id'])
            ->tap(ReportEngine::branchWall($f, 'ledger_entries.branch_id'))
            ->where('ledger_entries.trx_date', '<=', $f['to'])
            ->selectRaw('ledger_entries.account_id, ledger_entries.debit, ledger_entries.credit, NULL as advance');

        foreach ([
            [StandardChart::RECEIVABLE, true, 'customer_advance'],
            [StandardChart::PAYABLE, false, 'supplier_advance'],
        ] as [$code, $creditSide, $label]) {
            $accountId = $codes[$code] ?? null;

            if ($accountId === null) {
                continue;
            }

            $amount = self::partyAdvances($f, (int) $accountId, $creditSide);

            if (bccomp($amount, '0', 4) === 0) {
                continue;
            }

            // ⓘ গ্রাহকের অগ্রিম: পাওনায় ডেবিট (খাতটা মোট দেখায়), অগ্রিমে ক্রেডিট; সরবরাহকারীর অগ্রিম ঠিক উল্টো
            [$onParty, $onAdvance] = $creditSide ? [[$amount, '0'], ['0', $amount]] : [['0', $amount], [$amount, '0']];
            $lines->unionAll(DB::query()->selectRaw('?, ?, ?, NULL', [(int) $accountId, ...$onParty]));
            $lines->unionAll(DB::query()->selectRaw('NULL, ?, ?, ?', [...$onAdvance, $label]));
        }

        $name = app()->getLocale() === 'bn' ? "COALESCE(NULLIF(accounts.name_bn, ''), accounts.name_en)" : 'accounts.name_en';
        $advance = "CASE t.advance WHEN 'customer_advance' THEN ? ELSE ? END";
        $labels = [__('accounts::field.customer_advance'), __('accounts::field.supplier_advance')];

        return DB::query()->fromSub($lines, 't')
            ->leftJoin('accounts', 'accounts.id', '=', 't.account_id')
            ->where(fn ($q) => $q->whereIn('accounts.type', $types)->orWhereNotNull('t.advance'))
            ->groupBy('t.account_id', 't.advance', 'accounts.code', 'accounts.name_en', 'accounts.name_bn', 'accounts.type')
            // ⓘ অগ্রিমের লাইন ছকে তার নিজের জায়গায় — গ্রাহকের অগ্রিম ২১৫০, সরবরাহকারীর অগ্রিম ১১৩০-এর পাশে
            ->orderByRaw("COALESCE(accounts.code, CASE t.advance WHEN 'customer_advance' THEN '2150' ELSE '1130' END)")
            ->selectRaw('t.account_id')
            ->selectRaw("COALESCE(accounts.type, CASE t.advance WHEN 'customer_advance' THEN '".Account::LIABILITY."' ELSE '".Account::ASSET."' END) as type")
            ->selectRaw("CASE WHEN t.advance IS NULL THEN COALESCE(CONCAT(accounts.code, ' — ', {$name}), CONCAT('#', t.account_id)) ELSE {$advance} END as account_name", $labels)
            ->selectRaw('SUM(t.debit) as debit, SUM(t.credit) as credit, SUM(t.debit) - SUM(t.credit) as net');
    }

    /** পক্ষ ধরে জের, একটা দিকের যোগ — [[BalanceSheetService]]-এর `partyAdvances()`-এর একই হিসাব, রিপোর্টের ছাঁকনিতে */
    private static function partyAdvances(array $f, int $accountId, bool $creditSide): string
    {
        $nets = DB::table('ledger_entries')
            ->where('ledger_entries.company_id', $f['company_id'])
            ->where('ledger_entries.account_id', $accountId)
            ->whereNotNull('ledger_entries.party_id')
            ->where('ledger_entries.trx_date', '<=', $f['to'])
            ->tap(ReportEngine::branchWall($f, 'ledger_entries.branch_id'))
            ->groupBy('ledger_entries.party_type', 'ledger_entries.party_id')
            ->selectRaw('SUM(ledger_entries.debit) - SUM(ledger_entries.credit) as net')
            ->pluck('net');

        $sum = '0';

        foreach ($nets as $net) {
            $net = (string) $net;

            if ($creditSide ? bccomp($net, '0', 4) < 0 : bccomp($net, '0', 4) > 0) {
                $sum = bcadd($sum, $creditSide ? bcmul($net, '-1', 4) : $net, 4);
            }
        }

        return $sum;
    }

    /**
     * ⭐ লাভ-ক্ষতির চার ভাগ — বিক্রয়, বিক্রীত পণ্যের ব্যয়, অন্যান্য আয়, খরচ (IAS 1; পুনঃঅডিট, ৯ অক্টোবর ২০২৬)।
     *
     * ⓘ বিক্রয় = ৪১০০ আর ৪১১০ (ফেরত বিক্রয় কমায়) ও তাদের নিচের খাত; বিক্রীত পণ্যের ব্যয় = ৫১০০ আর ৫১৫০ (ক্রয়মূল্যের
     * পার্থক্য মালেরই দাম) ও নিচের খাত। ⛔ ৫১৬০ (মজুদ ঘাটতি) নয় — তার নিজের মন্তব্য বলে ওটা বিক্রির খরচ নয়
     * ([[StandardChart::INVENTORY_SHORTAGE_SURPLUS]])। বাকি আয়ের খাত "অন্যান্য আয়", বাকি খরচ "খরচ"।
     * ⓘ সব শর্ত `ledger_entries.account_id` আর `accounts.type`-এ — দুইটাই গোষ্ঠীতে, তাই ONLY_FULL_GROUP_BY-তে বৈধ।
     *
     * @return array{label: string, bindings: list<string>, gross: string, rank: string}
     */
    private static function plSections(int $companyId): array
    {
        $in = fn (array $codes) => 'ledger_entries.account_id IN ('.(implode(',', self::subtreeIds($companyId, $codes)) ?: '0').')';
        $revenue = $in([StandardChart::SALES, StandardChart::SALES_RETURN]);
        $cost = $in([StandardChart::COST_OF_GOODS_SOLD, StandardChart::PURCHASE_PRICE_VARIANCE]);
        $income = "accounts.type = '".Account::INCOME."'";

        return [
            'label' => "CASE WHEN {$revenue} THEN ? WHEN {$cost} THEN ? WHEN {$income} THEN ? ELSE ? END",
            'bindings' => [
                __('accounts::message.pl_revenue'), __('accounts::message.pl_cost_of_sales'),
                __('accounts::message.pl_other_income'), __('accounts::message.pl_expenses'),
            ],
            'gross' => "CASE WHEN {$revenue} OR {$cost} THEN SUM(ledger_entries.credit) - SUM(ledger_entries.debit) END",
            'rank' => "CASE WHEN {$revenue} THEN 1 WHEN {$cost} THEN 2 WHEN {$income} THEN 3 ELSE 4 END",
        ];
    }

    /**
     * ছকের কয়েকটা খাত আর তাদের নিচের সব খাতের id — একটা কোয়েরিতে, কোম্পানি ধরে।
     *
     * @param  list<string>  $codes
     * @return list<int>
     */
    private static function subtreeIds(int $companyId, array $codes): array
    {
        $all = DB::table('accounts')->where('company_id', $companyId)->whereNull('deleted_at')->get(['id', 'parent_id', 'code']);
        $children = $all->groupBy('parent_id');
        $ids = [];
        $queue = $all->whereIn('code', $codes)->pluck('id')->all();

        while ($queue !== []) {
            $id = (int) array_shift($queue);

            if (isset($ids[$id])) {
                continue;
            }

            $ids[$id] = $id;
            array_push($queue, ...($children->get($id)?->pluck('id')->all() ?? []));
        }

        return array_values($ids);
    }

    /**
     * ক্যাশ ফ্লো — টাকা কোথা থেকে এল আর কোথায় গেল, পরিচালন / বিনিয়োগ / অর্থায়নে ভাগ করে (IAS 7)।
     *
     * ⛔ পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (হিসাব, ঠিক ৭)। ⚠️ আগে টাকার খাতের সব সারি দিনে দিনে যোগ হত: নগদ থেকে ব্যাংকে
     * জমা একবার "ঢুকল" আর একবার "বেরোল" দেখাত — দুই দিকই ফুলত, অথচ কোম্পানির টাকা এক পয়সাও নড়েনি। আর তিন ভাগ ছিলই না।
     * ⭐ এখন সারি হলো টাকার **উল্টো দিকের** খাত — যে কাগজে টাকার খাত আছে, তার বাকি সারিগুলো ([[cashFlowSections()]]):
     * গ্রাহকের পাওনা জমা হলে টাকা এল, খরচে ডেবিট হলে গেল। ⓘ তাই নিজের দুই টাকার খাতের মধ্যে স্থানান্তরের কোনো সারিই
     * আসে না, আর স্থানান্তরের ব্যাংক চার্জ ঠিক চার্জটুকুই আসে। ভাগটা খাতের ধরন আর ছকের জায়গা থেকে।
     */
    public static function cashFlow(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'accounts.cash_flow',
            // ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই — সূচি ও ফোন এখান থেকে পড়ে (২৭ সেপ্টেম্বর ২০২৬)। ⓘ দরজা কেবল চূড়ান্ত
            // হিসাব চায় ([[FinalAccountsReportController]], cec88dd3) — লাভ-ক্ষতির মতোই (৬ অক্টোবর ২০২৬)
            permission: 'accounts.report.final',
            title: 'accounts::menu.cash_flow',
            filters: ['date_range', 'branch'],
            groupBy: 'account_id',
            query: function (array $f) {
                $sections = self::cashFlowSections((int) $f['company_id']);
                $overdrafts = self::overdraftIds((int) $f['company_id']);

                return DB::table('ledger_entries')
                    ->leftJoin('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
                    ->where('ledger_entries.company_id', $f['company_id'])
                    // ⓘ টাকার খাতের বাইরের সারি… (⛔ ওভারড্রাফটও নগদ-সমতুল্য, IAS 7.8 — সারি নয়, টাকার পাশ)
                    ->whereNull('accounts.money_kind')
                    ->whereNotIn('ledger_entries.account_id', $overdrafts ?: [0])
                    ->tap(ReportEngine::branchWall($f, 'ledger_entries.branch_id'))
                    ->whereBetween('ledger_entries.trx_date', [$f['from'], $f['to']])
                    // …যে কাগজে টাকার খাতের অন্তত একটা সারি আছে — টাকা নড়ার উল্টো দিক
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('ledger_entries as m')
                        ->join('accounts as ma', 'ma.id', '=', 'm.account_id')
                        ->whereColumn('m.company_id', 'ledger_entries.company_id')
                        ->whereColumn('m.source_type', 'ledger_entries.source_type')
                        ->whereColumn('m.source_id', 'ledger_entries.source_id')
                        ->where(fn ($w) => $w->whereNotNull('ma.money_kind')->orWhereIn('ma.id', $overdrafts ?: [0])))
                    ->groupBy('ledger_entries.account_id', 'accounts.code', 'accounts.name_en', 'accounts.name_bn', 'accounts.type')
                    ->orderByRaw($sections['rank'])
                    ->orderBy('accounts.code')
                    ->select([
                        'ledger_entries.account_id',
                        self::accountName(),
                        // ⓘ উল্টো দিকে ক্রেডিট মানে টাকা এল (গ্রাহকের পাওনা কমল), ডেবিট মানে গেল
                        DB::raw('SUM(ledger_entries.credit) as money_in'),
                        DB::raw('SUM(ledger_entries.debit) as money_out'),
                        DB::raw('SUM(ledger_entries.credit) - SUM(ledger_entries.debit) as net'),
                    ])
                    ->selectRaw($sections['label'].' as section', $sections['bindings']);
            },
            columns: [
                ['key' => 'section', 'label' => 'accounts::field.cf_section', 'width' => '10rem'],
                ['key' => 'account_name', 'label' => 'core.print.account'],
                ['key' => 'money_in', 'label' => 'accounts::field.money_in', 'type' => ReportColumn::MONEY],
                ['key' => 'money_out', 'label' => 'accounts::field.money_out', 'type' => ReportColumn::MONEY],
                ['key' => 'net', 'label' => 'accounts::field.net_change', 'type' => ReportColumn::MONEY],
            ],
            /*
             * ⭐ শুরুর আর শেষের নগদ ও নগদ-সমতুল্য — ব্যাংক ওভারড্রাফট বিয়োগ করে (IAS 7.8; মালিক, ১৩ অক্টোবর ২০২৬: "int standrad
             * onuzayi koro"; [[OverdraftIsCashNotFinancingTest]])। ⓘ চাহিবামাত্র শোধযোগ্য ওভারড্রাফট নগদ ব্যবস্থাপনার অংশ: ওখান থেকে
             * টানা টাকা "অর্থায়নে আসা" নয়, আর শুরু-শেষের নগদে সেটা বিয়োগ হয়ে থাকে। ছাঁকনি না পেলে (পুরনো ডাক) কেবল মোট।
             */
            summary: function (array $totals, array $f = []): array {
                $net = bcsub((string) ($totals['money_in'] ?? '0') ?: '0', (string) ($totals['money_out'] ?? '0') ?: '0', 4);
                $good = bccomp($net, '0', 4) >= 0;

                return [
                    'lines' => isset($f['from'], $f['to'], $f['company_id']) ? [
                        ['label' => __('accounts::message.cf_opening_cash'), 'value' => self::cashAt($f, (string) $f['from'], false)],
                        ['label' => __('accounts::message.cf_closing_cash'), 'value' => self::cashAt($f, (string) $f['to'], true)],
                        ...self::indirectLines($f),
                    ] : [],
                    'label' => __('accounts::message.cf_net_change'),
                    'value' => $good ? $net : bcmul($net, '-1', 4),
                    'good' => $good,
                ];
            },
        );
    }

    /**
     * ⭐ পরোক্ষ পদ্ধতির মিলন — নিট লাভ থেকে পরিচালনার নগদ (IAS 7.18(b), 7.20; ERP ১০০%-এর হিসাবের ফাঁক #৩৪, ২০ অক্টোবর ২০২৬)।
     *
     * <p>পরিচালনার খাত = নগদ প্রবাহের পরিচালন ভাগ ([[cashFlowSections()]]): টাকা, ওভারড্রাফট, বিনিয়োগ আর অর্থায়নের খাত বাদে
     * সব। সময়ের সব কাগজে এদের (Cr − Dr)-এর যোগ = নিট লাভ + চলতি মূলধনের বদল; টাকার সারি আছে এমন কাগজে এদের যোগ = প্রত্যক্ষ
     * পদ্ধতির পরিচালনার নগদ। তফাত কেবল টাকাহীন যে কাগজ বিনিয়োগ বা অর্থায়নের খাত ছোঁয় (অবচয়, বাকিতে সম্পদ কেনা) — সেটাই
     * "নগদহীন খরচ ও লেনদেন"। ⓘ টাকাহীন যে কাগজ কেবল পরিচালনার খাতে (বাকিতে বিক্রি) নিজেই শূন্যে মেলে। তাই দুই পদ্ধতি
     * গঠনেই মেলে, আর শেষ সারি দুটো পাশাপাশি দেখায় — না মিললে খাতায় কিছু ভাঙা। বছর বন্ধের দাখিলা বাদ (টাকাহীন, লাভ সরায় মাত্র)।
     *
     * @param  array<string, mixed>  $f
     * @return list<array{label: string, value: string}>
     */
    private static function indirectLines(array $f): array
    {
        $companyId = (int) $f['company_id'];
        $overdrafts = self::overdraftIds($companyId) ?: [0];
        $investing = self::subtreeIds($companyId, [StandardChart::FIXED_ASSETS, StandardChart::DEPOSITS_AND_INVESTMENTS, '1161', '1162', '1163', StandardChart::HAND_LOAN]);
        $financing = self::subtreeIds($companyId, ['2200', '2170', '2180', StandardChart::PROFIT_PAYABLE]);
        $nonOperating = [...$investing, ...$financing] ?: [0];
        $close = \App\Modules\Accounts\Services\YearEndService::closingSources();

        $sameDoc = fn (string $where) => 'EXISTS (SELECT 1 FROM ledger_entries m JOIN accounts ma ON ma.id = m.account_id'
            .' WHERE m.company_id = ledger_entries.company_id AND m.source_type = ledger_entries.source_type'
            .' AND m.source_id = ledger_entries.source_id AND ('.$where.'))';
        $cashDoc = $sameDoc('ma.money_kind IS NOT NULL OR ma.id IN ('.implode(',', $overdrafts).')');
        $nonOpDoc = $sameDoc("ma.type = '".Account::EQUITY."' OR ma.id IN (".implode(',', $nonOperating).')');

        $rows = DB::table('ledger_entries')
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_entries.company_id', $companyId)
            ->whereNull('accounts.money_kind')
            ->whereNotIn('ledger_entries.account_id', [...$overdrafts, ...$nonOperating])
            ->where('accounts.type', '!=', Account::EQUITY)
            ->whereNotIn('ledger_entries.source_type', $close)
            ->tap(ReportEngine::branchWall($f, 'ledger_entries.branch_id'))
            ->whereBetween('ledger_entries.trx_date', [$f['from'], $f['to']])
            ->groupBy('ledger_entries.account_id', 'accounts.type')
            ->select(['ledger_entries.account_id', 'accounts.type'])
            ->selectRaw('SUM(ledger_entries.credit - ledger_entries.debit) as all_net')
            ->selectRaw("SUM(CASE WHEN {$cashDoc} THEN ledger_entries.credit - ledger_entries.debit ELSE 0 END) as cash_net")
            ->selectRaw("SUM(CASE WHEN NOT {$cashDoc} AND {$nonOpDoc} THEN ledger_entries.credit - ledger_entries.debit ELSE 0 END) as noncash_net")
            ->get();

        $receivable = array_flip(self::subtreeIds($companyId, [StandardChart::RECEIVABLE]));
        $inventory = array_flip(self::subtreeIds($companyId, [StandardChart::INVENTORY]));
        $payable = array_flip(self::subtreeIds($companyId, [StandardChart::PAYABLE_GROUP]));

        $sum = ['profit' => '0', 'noncash' => '0', 'receivable' => '0', 'inventory' => '0', 'payable' => '0', 'other' => '0', 'direct' => '0'];
        foreach ($rows as $row) {
            $id = (int) $row->account_id;
            $bucket = in_array($row->type, [Account::INCOME, Account::EXPENSE], true) ? 'profit'
                : (isset($receivable[$id]) ? 'receivable' : (isset($inventory[$id]) ? 'inventory' : (isset($payable[$id]) ? 'payable' : 'other')));
            $sum[$bucket] = bcadd($sum[$bucket], (string) $row->all_net, 4);
            // ⓘ নগদহীন কাগজের অংশ লাভ বা চলতি মূলধনে ঢুকে আছে, অথচ টাকা নড়েনি — ফিরিয়ে নেওয়া
            $sum['noncash'] = bcsub($sum['noncash'], (string) $row->noncash_net, 4);
            $sum['direct'] = bcadd($sum['direct'], (string) $row->cash_net, 4);
        }

        $indirect = array_reduce(['profit', 'noncash', 'receivable', 'inventory', 'payable', 'other'], fn (string $t, string $k) => bcadd($t, $sum[$k], 4), '0');

        return [
            ['label' => __('accounts::message.cf_ind_profit'), 'value' => $sum['profit']],
            ['label' => __('accounts::message.cf_ind_noncash'), 'value' => $sum['noncash']],
            ['label' => __('accounts::message.cf_ind_receivable'), 'value' => $sum['receivable']],
            ['label' => __('accounts::message.cf_ind_inventory'), 'value' => $sum['inventory']],
            ['label' => __('accounts::message.cf_ind_payable'), 'value' => $sum['payable']],
            ['label' => __('accounts::message.cf_ind_other'), 'value' => $sum['other']],
            ['label' => __('accounts::message.cf_ind_operating'), 'value' => $indirect],
            ['label' => __('accounts::message.cf_ind_direct'), 'value' => $sum['direct']],
        ];
    }

    /**
     * ব্যাংক ওভারড্রাফটের খাতগুলো (২১০৫ ও নিচের) — নগদ-সমতুল্যের বিয়োগের অংশ (IAS 7.8)।
     *
     * ⓘ এদের `money_kind` দেওয়া হয় না — দিলে আদায় বা পরিশোধে "টাকার খাত" হিসেবে বাছা যেত; নগদ প্রবাহ নিজেই এদের নগদের পাশে ধরে।
     *
     * @return list<int>
     */
    private static function overdraftIds(int $companyId): array
    {
        return self::subtreeIds($companyId, [StandardChart::BANK_OVERDRAFT]);
    }

    /**
     * একটা তারিখে নগদ ও নগদ-সমতুল্য — টাকার খাতের জের, ওভারড্রাফট বিয়োগ করে (নিট Dr)।
     *
     * @param  array<string, mixed>  $f
     */
    private static function cashAt(array $f, string $date, bool $inclusive): string
    {
        $overdrafts = self::overdraftIds((int) $f['company_id']);

        $net = DB::table('ledger_entries')
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_entries.company_id', $f['company_id'])
            ->where(fn ($w) => $w->whereNotNull('accounts.money_kind')->orWhereIn('ledger_entries.account_id', $overdrafts ?: [0]))
            ->tap(ReportEngine::branchWall($f, 'ledger_entries.branch_id'))
            ->where('ledger_entries.trx_date', $inclusive ? '<=' : '<', $date)
            ->selectRaw('COALESCE(SUM(ledger_entries.debit - ledger_entries.credit), 0) as n')
            ->value('n');

        return bcadd((string) $net, '0', 4);
    }

    /**
     * ⭐ নগদ প্রবাহের তিন ভাগ (IAS 7) — উল্টো দিকের খাতের ধরন আর ছকের জায়গা ধরে (পুনঃঅডিট, ৯ অক্টোবর ২০২৬)।
     *
     * ⓘ বিনিয়োগ: স্থায়ী সম্পদ (১২০০), সঞ্চয় ও বিনিয়োগ (১১৬০–১১৬৩), দেওয়া হাতধার (১১৭০) — ও নিচের খাত।
     * ⓘ অর্থায়ন: মূলধনের যেকোনো খাত (মালিকের টাকা, উত্তোলন), দীর্ঘমেয়াদি ঋণ (২২০০), LTR (২১৭০), নেওয়া হাতধার (২১৮০),
     *   প্রদেয় মুনাফা (২১৯০) — ও নিচের খাত।
     * ⓘ পরিচালন: বাকি সব — আয়, খরচ, পাওনা, দেনা, মজুদ, অগ্রিম, ভ্যাট, বেতন।
     *
     * @return array{label: string, bindings: list<string>, rank: string}
     */
    private static function cashFlowSections(int $companyId): array
    {
        $in = fn (array $codes) => 'ledger_entries.account_id IN ('.(implode(',', self::subtreeIds($companyId, $codes)) ?: '0').')';
        $investing = $in([StandardChart::FIXED_ASSETS, StandardChart::DEPOSITS_AND_INVESTMENTS, '1161', '1162', '1163', StandardChart::HAND_LOAN]);
        $financing = "(accounts.type = '".Account::EQUITY."' OR ".$in(['2200', '2170', '2180', StandardChart::PROFIT_PAYABLE]).')';

        return [
            'label' => "CASE WHEN {$investing} THEN ? WHEN {$financing} THEN ? ELSE ? END",
            'bindings' => [__('accounts::message.cf_investing'), __('accounts::message.cf_financing'), __('accounts::message.cf_operating')],
            'rank' => "CASE WHEN {$investing} THEN 2 WHEN {$financing} THEN 3 ELSE 1 END",
        ];
    }

    /**
     * খাতের নাম — কোড সহ, ব্যবহারকারীর ভাষায়।
     *
     * SQL-এ ভাষা বাছা হয় কারণ নামটা কোয়েরির অংশ: PHP-তে বাছলে প্রতিটা
     * সারির জন্য মডেল লাগত, আর রিপোর্ট engine সারিগুলোকে সাধারণ অ্যারে
     * হিসেবেই দেখে।
     *
     * বাংলা নাম খালি হলে ইংরেজিটা — সেকশন ১৮.৩-এর একই নিয়ম, শুধু
     * অন্য জায়গায় লেখা।
     */
    /**
     * ⭐ সারির পক্ষ "ধরন:id" — নিজের পক্ষ, না থাকলে একই কাগজের পক্ষ-ওয়ালা সারির (মালিক, ১৯ অক্টোবর ২০২৬)।
     *
     * ⓘ ক্যাশ/ব্যাংকের সারিতে পক্ষ বসে না — গ্রাহকের রসিদে পক্ষ থাকে পাওনার সারিতে, একই `source_type`/`source_id`-এ। তাই
     * সাব-কোয়েরি একই কাগজের অন্য সারি দেখে (`ledger_source` সূচিতে), কোম্পানির দেয়ালসহ। ⓘ উল্টো দাখিলার (`…:reversal`)
     * সারিতেও পক্ষ নকল হয়, তাই তারাও নিজের কাগজে নাম পায়। এক কাগজে দুই পক্ষ বিরল — তখন একটাই, স্থির নিয়মে (MIN)।
     *
     * ⛔ এখানে কেবল চাবি, নাম নয় — নাম বসায় ইঞ্জিন ([[ReportColumn::$partyKey]]); গ্রাহক/সরবরাহকারীর টেবিলে জোড় নেই।
     * ⓘ দল বাঁধা নেই এমন কোয়েরির ঘর, আর সাব-কোয়েরির MIN নিজের মধ্যেই সম্পূর্ণ — ONLY_FULL_GROUP_BY অক্ষত।
     */
    private static function partyKey(string $table = 'ledger_entries'): Expression
    {
        return DB::raw(
            "COALESCE(CONCAT({$table}.party_type, ':', {$table}.party_id), ("
            ."SELECT MIN(CONCAT(pk.party_type, ':', pk.party_id)) FROM ledger_entries AS pk"
            ." WHERE pk.source_type = {$table}.source_type AND pk.source_id = {$table}.source_id"
            ." AND pk.company_id = {$table}.company_id AND pk.party_type IS NOT NULL AND pk.party_id IS NOT NULL"
            .')) as party_key'
        );
    }

    private static function accountName(): Expression
    {
        $name = app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF(accounts.name_bn, ''), accounts.name_en)"
            : 'accounts.name_en';

        /*
         * খাত না পাওয়া গেলেও সারিটা চেনা যায়।
         *
         * join LEFT, তাই খাত না থাকলে code ও name দুইটাই NULL — আর
         * CONCAT-এ একটা NULL মানে পুরো ফলটাই NULL। তখন রিপোর্টে সারিটা
         * থাকত ঠিকই, কিন্তু খাতের ঘরটা ফাঁকা: টাকার অঙ্ক আছে, কীসের
         * টাকা তা নেই। "#১১০১" অন্তত খোঁজার একটা সূত্র দেয়।
         */
        return DB::raw(
            "COALESCE(CONCAT(accounts.code, ' — ', {$name}), CONCAT('#', ledger_entries.account_id)) "
            .'as account_name'
        );
    }

    /**
     * কোন কেন্দ্রে কত খরচ — আর সেখান থেকে কত এল।
     *
     * ── কোন প্রশ্নের উত্তর ──────────────────────────────────────────
     * *"নেত্রকোনার রুটে মাসে কত খরচ হয়, আর ওখান থেকে কত আসে?"*
     *
     * ৪% মার্জিনের ব্যবসায় একটা রুটের খরচ তার আয়ের চেয়ে বেশি হওয়া
     * খুবই সম্ভব — আর মোট হিসাবে সেটা দেখাই যায় না, কারণ অন্য রুটগুলো
     * টেনে নেয়। **কোনটা টানছে আর কোনটা ডোবাচ্ছে**, এই পাতাটা সেটাই
     * বলে।
     *
     * ── কেন্দ্রহীন সারিগুলো আলাদা করে দেখানো হয় ──────────────────────
     * যে খরচে কেউ কেন্দ্র বসায়নি সেটা "(কেন্দ্র বসানো হয়নি)" নামে
     * নিজের সারিতে আসে। বাদ দিলে যোগফল মিলত না, আর মালিক ভাবতেন
     * খরচটা কম। সারিটা বড় হলে সেটাই সবচেয়ে দরকারি খবর: **অভ্যাসটা
     * এখনো গড়ে ওঠেনি।**
     */
    public static function byCostCentre(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'accounts.by_cost_centre',
            // ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই — সূচি ও ফোন এখান থেকে পড়ে (২৭ সেপ্টেম্বর ২০২৬)
            permission: 'accounts.report',
            title: 'accounts::menu.by_cost_centre',
            filters: ['date_range', 'branch'],
            groupBy: 'cost_center_id',
            query: fn (array $f) => DB::table('ledger_entries')
                ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
                ->leftJoin('acc_cost_centers as c', 'c.id', '=', 'ledger_entries.cost_center_id')
                ->where('ledger_entries.company_id', $f['company_id'])
                ->whereBetween('ledger_entries.trx_date', [$f['from'], $f['to']])
                ->tap(ReportEngine::branchWall($f, 'ledger_entries.branch_id'))

                /*
                 * কেবল আয় ও ব্যয় — সম্পদ ও দায় নয়।
                 *
                 * "রুটে কত খরচ" প্রশ্নে ব্যাংক থেকে টাকা তোলার সারিটার
                 * কোনো জায়গা নেই; ওটা টাকা সরানো, খরচ নয়।
                 */
                ->whereIn('accounts.type', [Account::EXPENSE, Account::INCOME])
                // ⛔ বছর বন্ধের দাখিলা বাদ — নইলে বছরের শেষ দিন পড়লে আয়-খরচ শূন্য দেখাত ([[YearEndService::closingSources()]])
                ->whereNotIn('ledger_entries.source_type', YearEndService::closingSources())
                ->groupBy('ledger_entries.cost_center_id', 'c.code', 'c.name_en', 'c.name_bn')
                ->orderByRaw('SUM(CASE WHEN accounts.type = ? THEN ledger_entries.debit - ledger_entries.credit ELSE 0 END) DESC', [Account::EXPENSE])
                ->select([
                    'ledger_entries.cost_center_id',
                    DB::raw(self::costCentreName()),
                    DB::raw('SUM(CASE WHEN accounts.type = '.DB::getPdo()->quote(Account::EXPENSE)
                        .' THEN ledger_entries.debit - ledger_entries.credit ELSE 0 END) as spent'),
                    DB::raw('SUM(CASE WHEN accounts.type = '.DB::getPdo()->quote(Account::INCOME)
                        .' THEN ledger_entries.credit - ledger_entries.debit ELSE 0 END) as earned'),
                    DB::raw('SUM(CASE WHEN accounts.type = '.DB::getPdo()->quote(Account::INCOME)
                        .' THEN ledger_entries.credit - ledger_entries.debit ELSE 0 END)'
                        .' - SUM(CASE WHEN accounts.type = '.DB::getPdo()->quote(Account::EXPENSE)
                        .' THEN ledger_entries.debit - ledger_entries.credit ELSE 0 END) as net'),
                ]),
            columns: [
                ['key' => 'centre_name', 'label' => 'accounts::field.cost_center', 'type' => ReportColumn::TEXT],
                ['key' => 'spent', 'label' => 'accounts::field.spent', 'type' => ReportColumn::MONEY],
                ['key' => 'earned', 'label' => 'accounts::field.earned', 'type' => ReportColumn::MONEY],
                ['key' => 'net', 'label' => 'accounts::field.net', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * কোন খাতে কত খরচ — মাস ধরে।
     *
     * ── ⓘ এটা অর্থ মডিউল থেকে এখানে এল, ১৮ সেপ্টেম্বর ২০২৬ ──
     * মালিক দুই মেনুতে একই নাম দেখে প্রশ্ন করলেন, তারপর বললেন
     * *"খরচ ভাউচার finance থেকে তুলে দাও … যা রাখতে হয় accounts-এ রাখো"*।
     *
     * ── ⛔ লাভ-ক্ষতি এই প্রশ্নের উত্তর নয় ───────────────────
     * সংখ্যাগুলো লাভ-ক্ষতিতেও আছে, কিন্তু ওটা দেখতে `accounts.report.final`
     * লাগে — ডিপো ম্যানেজারের সেটা থাকে না। ⚠️ অথচ রোজ খরচ লেখেন
     * তিনিই, আর "এই মাসে জ্বালানিতে কত গেল" প্রশ্নটা তাঁরই।
     *
     * ⓘ তাই এই পাতাটা সাধারণ `accounts.report` অনুমতিতে — লাভ বা
     * মূলধনের কোনো সংখ্যা এখানে নেই, কেবল খরচ।
     *
     * ── ⭐ কেবল পোস্ট হওয়া খরচ ──────────────────────────
     * খাতায় বসেনি এমন খরচ এখানে নেই, আর সেটাই ঠিক — রিপোর্ট
     * খাতা পড়ে। ⚠️ ঝুলে থাকা কাগজগুলো খরচ ভাউচারের তালিকার মাথায়
     * একটা চিপে গোনা হয় — দুইটা আলাদা প্রশ্ন, দুই জায়গায়।
     */
    public static function expenseByHead(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'accounts.expense_by_head',
            // ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই — সূচি ও ফোন এখান থেকে পড়ে (২৭ সেপ্টেম্বর ২০২৬)
            // ⛔ চূড়ান্ত হিসাবের অঙ্ক — চূড়ান্ত রিপোর্টের চাবি (অডিট ম১৩/ম১৮, ১০ অক্টোবর ২০২৬); আগে accounts.report-ই যথেষ্ট ছিল
            // ⭐ কেবল চূড়ান্ত হিসাবের চাবি — মেনু, ওয়েবের দরজা আর ফোন একই কথা বলে, লাভ-ক্ষতির মতো (fe hotfix, ১৯ অক্টোবর ২০২৬);
            // ⛔ আগে মেনু কেবল `.final` দেখে সারি দেখাত অথচ এখানে `accounts.report`-ও চাইত — খুললেই ৪০৩
            permission: 'accounts.report.final',
            title: 'accounts::menu.expense_by_head',
            filters: ['date_range', 'branch'],
            groupBy: 'account_id',
            query: fn (array $f) => DB::table('ledger_entries')
                ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
                ->where('ledger_entries.company_id', $f['company_id'])
                ->whereBetween('ledger_entries.trx_date', [$f['from'], $f['to']])
                ->tap(ReportEngine::branchWall($f, 'ledger_entries.branch_id'))
                ->where('accounts.type', Account::EXPENSE)
                // ⛔ বছর বন্ধের দাখিলা বাদ — নইলে বছরের শেষ দিন পড়লে আয়-খরচ শূন্য দেখাত ([[YearEndService::closingSources()]])
                ->whereNotIn('ledger_entries.source_type', YearEndService::closingSources())

                /*
                 * ⓘ গ্রুপ বাদ — গ্রুপে দাখিলা বসে না, তাই সারিও আসবে না।
                 *   শর্তটা তবু লেখা, কারণ দাবিটা পড়ে বোঝা যাওয়া উচিত।
                 */
                ->where('accounts.is_group', false)
                ->groupBy('ledger_entries.account_id', 'accounts.code', 'accounts.name_en', 'accounts.name_bn')
                ->orderByRaw('SUM(ledger_entries.debit - ledger_entries.credit) DESC')
                ->select([
                    'ledger_entries.account_id',
                    DB::raw(self::accountName()),
                    DB::raw('SUM(ledger_entries.debit - ledger_entries.credit) as spent'),
                ]),
            columns: [
                ['key' => 'account_name', 'label' => 'accounts::field.expense_head', 'type' => ReportColumn::TEXT],
                ['key' => 'spent', 'label' => 'accounts::field.spent', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * কোন খাতে কত আয় — খরচের আয়না।
     *
     * ── ⭐ কেন লাগল, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────
     * অর্থের মানচিত্রে §১০-এর "আয়ের শ্রেণি" লাইনটা বাকি ছিল, অথচ
     * খরচের দিকে ঠিক একই পাতা ([[expenseByHead()]]) অনেক দিন ধরেই আছে।
     * ⓘ মালিকের প্রশ্নটা রোজকার: *"এই মাসে ভাড়া থেকে কত এল, সুদ থেকে কত"*।
     *
     * ── ⚠️ চিহ্নটা উল্টো, আর সেটাই মূল কথা ──────────────────────────
     * আয়ের খাত ক্রেডিটে বাড়ে, তাই `credit − debit`। ⛔ খরচের সূত্র নকল
     * করলে প্রতিটা সংখ্যা ঋণাত্মক দেখাত, আর সবচেয়ে বড় আয়টা তালিকার
     * নিচে পড়ে থাকত।
     *
     * ⓘ অনুমতি `accounts.report` — লাভ-ক্ষতির সংখ্যা এখানে নেই, কেবল আয়।
     */
    public static function incomeByHead(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'accounts.income_by_head',
            // ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই — সূচি ও ফোন এখান থেকে পড়ে (২৭ সেপ্টেম্বর ২০২৬)
            // ⛔ চূড়ান্ত হিসাবের অঙ্ক — চূড়ান্ত রিপোর্টের চাবি (অডিট ম১৩/ম১৮, ১০ অক্টোবর ২০২৬); আগে accounts.report-ই যথেষ্ট ছিল
            // ⭐ কেবল চূড়ান্ত হিসাবের চাবি — মেনু, ওয়েবের দরজা আর ফোন একই কথা বলে, লাভ-ক্ষতির মতো (fe hotfix, ১৯ অক্টোবর ২০২৬);
            // ⛔ আগে মেনু কেবল `.final` দেখে সারি দেখাত অথচ এখানে `accounts.report`-ও চাইত — খুললেই ৪০৩
            permission: 'accounts.report.final',
            title: 'accounts::field.income_by_head',
            filters: ['date_range', 'branch'],
            groupBy: 'account_id',
            query: fn (array $f) => DB::table('ledger_entries')
                ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
                ->where('ledger_entries.company_id', $f['company_id'])
                ->whereBetween('ledger_entries.trx_date', [$f['from'], $f['to']])
                ->tap(ReportEngine::branchWall($f, 'ledger_entries.branch_id'))
                ->where('accounts.type', Account::INCOME)
                // ⛔ বছর বন্ধের দাখিলা বাদ — নইলে বছরের শেষ দিন পড়লে আয়-খরচ শূন্য দেখাত ([[YearEndService::closingSources()]])
                ->whereNotIn('ledger_entries.source_type', YearEndService::closingSources())

                // ⓘ গ্রুপে দাখিলা বসে না — শর্তটা তবু লেখা, দাবিটা পড়ে বোঝা যাক
                ->where('accounts.is_group', false)
                ->groupBy('ledger_entries.account_id', 'accounts.code', 'accounts.name_en', 'accounts.name_bn')
                ->orderByRaw('SUM(ledger_entries.credit - ledger_entries.debit) DESC')
                ->select([
                    'ledger_entries.account_id',
                    DB::raw(self::accountName()),
                    DB::raw('SUM(ledger_entries.credit - ledger_entries.debit) as earned'),
                ]),
            columns: [
                ['key' => 'account_name', 'label' => 'accounts::field.income_head', 'type' => ReportColumn::TEXT],
                ['key' => 'earned', 'label' => 'accounts::field.earned', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /** কেন্দ্রের নাম — বসানো না থাকলে সেটাও একটা নাম। */
    private static function costCentreName(): string
    {
        $column = app()->getLocale() === 'bn'
            ? "COALESCE(NULLIF(c.name_bn, ''), c.name_en)"
            : 'c.name_en';

        $none = DB::getPdo()->quote(__('accounts::field.no_cost_center'));

        return "COALESCE({$column}, {$none}) as centre_name";
    }
}
