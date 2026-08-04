<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
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
        $engine->register(self::ledger());
        $engine->register(self::trialBalance());
    }

    /** দৈনিক খতিয়ান — একটা তারিখ পরিসরের সব লেনদেন, ক্রমানুসারে। */
    public static function dayBook(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'accounts.day_book',
            title: 'accounts::menu.day_book',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => DB::table('ledger_entries')
                ->where('company_id', $f['company_id'])
                ->when($f['branch_id'], fn ($q, $branch) => $q->where('branch_id', $branch))
                ->whereBetween('trx_date', [$f['from'], $f['to']])
                ->orderBy('trx_date')
                ->orderBy('id')
                ->select([
                    'trx_date', 'document_no', 'account_id', 'narration',
                    'debit', 'credit', 'source_type', 'source_id',
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
                ['key' => 'account_id', 'label' => 'core.print.account'],
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
                ->where('company_id', $f['company_id'])
                ->when($f['branch_id'], fn ($q, $branch) => $q->where('branch_id', $branch))
                ->whereBetween('trx_date', [$f['from'], $f['to']])
                ->groupBy('account_id')
                ->orderBy('account_id')
                ->select([
                    'account_id',
                    DB::raw('SUM(debit) as debit'),
                    DB::raw('SUM(credit) as credit'),
                ]),
            columns: [
                ['key' => 'account_id', 'label' => 'core.print.account'],
                ['key' => 'debit', 'label' => 'core.table.debit', 'type' => ReportColumn::MONEY],
                ['key' => 'credit', 'label' => 'core.table.credit', 'type' => ReportColumn::MONEY],
            ],
        );
    }
}
