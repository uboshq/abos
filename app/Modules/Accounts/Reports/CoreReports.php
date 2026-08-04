<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Accounts\Models\Account;
use Illuminate\Database\Query\Expression;
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
    }

    /** দৈনিক খতিয়ান — একটা তারিখ পরিসরের সব লেনদেন, ক্রমানুসারে। */
    public static function dayBook(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'accounts.day_book',
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
                ->when($f['branch_id'], fn ($q, $branch) => $q->where('ledger_entries.branch_id', $branch))
                ->whereBetween('ledger_entries.trx_date', [$f['from'], $f['to']])
                ->orderBy('ledger_entries.trx_date')
                ->orderBy('ledger_entries.id')
                ->select([
                    'ledger_entries.trx_date', 'ledger_entries.document_no',
                    self::accountName(), 'ledger_entries.narration',
                    'ledger_entries.debit', 'ledger_entries.credit',
                    'ledger_entries.source_type', 'ledger_entries.source_id',
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
            title: 'accounts::menu.ledger',
            filters: ['date_range', 'branch', 'account'],
            runningBalance: true,
            query: fn (array $f) => DB::table('ledger_entries')
                ->where('company_id', $f['company_id'])
                ->when($f['account_id'] ?? null, fn ($q, $account) => $q->where('account_id', $account))
                ->when($f['branch_id'], fn ($q, $branch) => $q->where('branch_id', $branch))
                ->whereBetween('trx_date', [$f['from'], $f['to']])
                ->orderBy('trx_date')
                ->orderBy('id')
                ->select([
                    'trx_date', 'document_no', 'narration',
                    'debit', 'credit', 'source_type', 'source_id',
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
                ['key' => 'narration', 'label' => 'core.table.narration'],
                ['key' => 'debit', 'label' => 'core.table.debit', 'type' => ReportColumn::MONEY],
                ['key' => 'credit', 'label' => 'core.table.credit', 'type' => ReportColumn::MONEY],
                // চলমান ব্যালেন্স যোগ করা হয় না — শেষ সারির মানটাই ব্যালেন্স,
                // যোগফল দেখালে অর্থহীন একটা সংখ্যা আসত।
                ['key' => 'balance', 'label' => 'core.table.balance', 'type' => ReportColumn::MONEY, 'total' => false],
            ],
        );
    }

    /** রেওয়ামিল — প্রতি হিসাবের ডেবিট ও ক্রেডিটের যোগফল। */
    public static function trialBalance(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'accounts.trial_balance',
            title: 'accounts::menu.trial_balance',
            filters: ['date_range', 'branch'],
            groupBy: 'account_id',
            query: fn (array $f) => DB::table('ledger_entries')
                ->leftJoin('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
                ->where('ledger_entries.company_id', $f['company_id'])
                ->when($f['branch_id'], fn ($q, $branch) => $q->where('ledger_entries.branch_id', $branch))
                ->whereBetween('ledger_entries.trx_date', [$f['from'], $f['to']])
                ->groupBy('ledger_entries.account_id', 'accounts.code', 'accounts.name_en', 'accounts.name_bn')
                ->orderBy('accounts.code')
                ->select([
                    'ledger_entries.account_id',
                    self::accountName(),
                    DB::raw('SUM(ledger_entries.debit) as debit'),
                    DB::raw('SUM(ledger_entries.credit) as credit'),
                ]),
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
        return self::moneyBook('accounts.cash_book', 'accounts::menu.cash_book', 'is_cash');
    }

    /** ব্যাংক বই — ব্যাংক ও MFS খাতের চলাচল। */
    public static function bankBook(): ReportDefinition
    {
        return self::moneyBook('accounts.bank_book', 'accounts::menu.bank_book', 'is_bank');
    }

    /**
     * দুইটা বই একই আকারের, শুধু ফিল্টারটা আলাদা।
     *
     * আলাদা করে দুইবার লিখলে একদিন একটায় কলাম যোগ হত আর অন্যটায় না।
     */
    private static function moneyBook(string $key, string $title, string $flag): ReportDefinition
    {
        return new ReportDefinition(
            key: $key,
            title: $title,
            filters: ['date_range', 'branch'],
            runningBalance: true,
            query: fn (array $f) => DB::table('ledger_entries')
                ->leftJoin('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
                ->where('ledger_entries.company_id', $f['company_id'])
                ->where('accounts.'.$flag, true)
                ->when($f['branch_id'], fn ($q, $branch) => $q->where('ledger_entries.branch_id', $branch))
                ->whereBetween('ledger_entries.trx_date', [$f['from'], $f['to']])
                ->orderBy('ledger_entries.trx_date')
                ->orderBy('ledger_entries.id')
                ->select([
                    'ledger_entries.trx_date', 'ledger_entries.document_no',
                    self::accountName(), 'ledger_entries.narration',
                    'ledger_entries.debit', 'ledger_entries.credit',
                    'ledger_entries.source_type', 'ledger_entries.source_id',
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
                ['key' => 'account_name', 'label' => 'core.print.account'],
                ['key' => 'narration', 'label' => 'core.table.narration'],
                ['key' => 'debit', 'label' => 'accounts::field.received', 'type' => ReportColumn::MONEY],
                ['key' => 'credit', 'label' => 'accounts::field.paid', 'type' => ReportColumn::MONEY],
                ['key' => 'balance', 'label' => 'core.table.balance', 'type' => ReportColumn::MONEY, 'total' => false],
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
        );
    }

    /**
     * দুইটা সারাংশ রিপোর্ট একই আকারের — শুধু কোন ধরনগুলো আর কোন সময়।
     *
     * @param  list<string>  $types
     */
    private static function summaryByAccount(string $key, string $title, array $types, bool $dateRange): ReportDefinition
    {
        return new ReportDefinition(
            key: $key,
            title: $title,
            filters: ['date_range', 'branch'],
            groupBy: 'account_id',
            query: fn (array $f) => DB::table('ledger_entries')
                ->leftJoin('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
                ->where('ledger_entries.company_id', $f['company_id'])
                ->whereIn('accounts.type', $types)
                ->when($f['branch_id'], fn ($q, $branch) => $q->where('ledger_entries.branch_id', $branch))
                // ব্যালেন্স শিটে শুরুর তারিখ ধরা হয় না — একটা মুহূর্তের
                // ছবিতে "কবে থেকে" প্রশ্নটাই অর্থহীন
                ->when($dateRange, fn ($q) => $q->where('ledger_entries.trx_date', '>=', $f['from']))
                ->where('ledger_entries.trx_date', '<=', $f['to'])
                ->groupBy(
                    'ledger_entries.account_id', 'accounts.code',
                    'accounts.name_en', 'accounts.name_bn', 'accounts.type',
                )
                ->orderBy('accounts.code')
                ->select([
                    'ledger_entries.account_id',
                    'accounts.type',
                    self::accountName(),
                    DB::raw('SUM(ledger_entries.debit) as debit'),
                    DB::raw('SUM(ledger_entries.credit) as credit'),
                    DB::raw('SUM(ledger_entries.debit) - SUM(ledger_entries.credit) as net'),
                ]),
            columns: [
                ['key' => 'account_name', 'label' => 'core.print.account'],
                ['key' => 'type', 'label' => 'accounts::field.type', 'width' => '8rem'],
                ['key' => 'debit', 'label' => 'core.table.debit', 'type' => ReportColumn::MONEY],
                ['key' => 'credit', 'label' => 'core.table.credit', 'type' => ReportColumn::MONEY],
                ['key' => 'net', 'label' => 'core.table.balance', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * ক্যাশ ফ্লো — টাকার খাতে কী ঢুকল আর কী বেরোল, দিনে দিনে।
     *
     * অ্যাকাউন্টিং মানের তিন-ভাগ (পরিচালন, বিনিয়োগ, অর্থায়ন) ক্যাশ ফ্লো
     * নয়, ইচ্ছাকৃতভাবে: ওই ভাগটা করতে প্রতিটা খাতকে তিন ভাগের একটায়
     * ফেলতে হয়, আর সেই মানচিত্রটা ব্যবসাভেদে আলাদা। ভুল মানচিত্রে তৈরি
     * একটা "মানসম্মত" রিপোর্টের চেয়ে সত্যিকারের দিনভিত্তিক নগদ চলাচল
     * বেশি কাজে লাগে — বিশেষত যে প্রশ্নটা আসলে জিজ্ঞাসা করা হয়:
     * "এই মাসে টাকা কোথায় গেল"।
     */
    public static function cashFlow(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'accounts.cash_flow',
            title: 'accounts::menu.cash_flow',
            filters: ['date_range', 'branch'],
            groupBy: 'trx_date',
            runningBalance: true,
            query: fn (array $f) => DB::table('ledger_entries')
                ->leftJoin('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
                ->where('ledger_entries.company_id', $f['company_id'])
                ->where(fn ($q) => $q->where('accounts.is_cash', true)->orWhere('accounts.is_bank', true))
                ->when($f['branch_id'], fn ($q, $branch) => $q->where('ledger_entries.branch_id', $branch))
                ->whereBetween('ledger_entries.trx_date', [$f['from'], $f['to']])
                ->groupBy('ledger_entries.trx_date')
                ->orderBy('ledger_entries.trx_date')
                ->select([
                    'ledger_entries.trx_date',
                    DB::raw('SUM(ledger_entries.debit) as debit'),
                    DB::raw('SUM(ledger_entries.credit) as credit'),
                ]),
            columns: [
                ['key' => 'trx_date', 'label' => 'core.print.date', 'type' => ReportColumn::DATE, 'width' => '9rem'],
                ['key' => 'debit', 'label' => 'accounts::field.money_in', 'type' => ReportColumn::MONEY],
                ['key' => 'credit', 'label' => 'accounts::field.money_out', 'type' => ReportColumn::MONEY],
                ['key' => 'balance', 'label' => 'accounts::field.net_change', 'type' => ReportColumn::MONEY, 'total' => false],
            ],
        );
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
}
