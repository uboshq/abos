<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Accounts\Models\Cheque;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * চেকের খাতা — রিপোর্ট সেন্টার ধাপ ৪ ("টাকার অবস্থা: চেকের খাতা, আগামী তারিখের চেক, ফেরত চেক"), ২ অক্টোবর ২০২৬।
 *
 * ── কোন প্রশ্নের উত্তর ──────────────────────────────────────────────────
 * *"কোন চেক কবে জমা দিতে হবে, কোনটা ফেরত এল, আর হাতে আগামী তারিখের কত টাকার চেক"* — গৃহীত আর দেওয়া, দুইটাই।
 * প্রতিটা চেক এক সারি, চেকের তারিখ ধরে; "দিন" = চেকের তারিখ থেকে আজ কত দিন বাকি (ধনাত্মক) বা পেরিয়েছে (ঋণাত্মক)।
 *
 * ── ছাঁকনি ──────────────────────────────────────────────────────────────
 * তারিখ (চেকের তারিখ), শাখা, দিক (`direction`: গৃহীত/দেওয়া), অবস্থা (`status`): খাতার নিজের অবস্থাগুলো, আর দুইটা
 * কাজের প্রশ্ন — `pdc` (আগামী তারিখের, এখনো হাতে) আর `due` (তারিখ এসে গেছে, এখনো জমা হয়নি)।
 *
 * ⓘ পক্ষের নাম এখানে নেই, ইচ্ছাকৃত — Accounts কারও উপর নির্ভর করে না ([[CoreReports::inflow()]]-এর একই কারণ);
 * নম্বরটা ক্লিকযোগ্য, আর চেকের পাতায় পক্ষ আছে।
 */
final class ChequeReports
{
    public const KEY = 'accounts.cheque_register';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::register());
    }

    public static function register(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            permission: 'accounts.report',
            title: 'accounts::menu.cheque_register',
            filters: ['date_range', 'branch', 'direction', 'status'],
            query: fn (array $f) => self::query($f),
            columns: [
                ['key' => 'cheque_date', 'label' => 'accounts::cheque.cheque_date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                [
                    'key' => 'document_no',
                    'label' => 'core.table.document',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type',
                    'source_id' => 'source_id',
                    'width' => '10rem',
                ],
                ['key' => 'cheque_no', 'label' => 'accounts::cheque.cheque_no', 'type' => ReportColumn::TEXT],
                ['key' => 'bank_name', 'label' => 'accounts::cheque.bank_name', 'type' => ReportColumn::TEXT],
                ['key' => 'direction_label', 'label' => 'accounts::cheque.direction', 'type' => ReportColumn::TEXT, 'width' => '7rem'],
                ['key' => 'status_label', 'label' => 'accounts::cheque.status', 'type' => ReportColumn::TEXT, 'width' => '8rem'],
                ['key' => 'days', 'label' => 'accounts::cheque.days_left', 'type' => ReportColumn::QUANTITY, 'total' => false, 'width' => '6rem'],
                ['key' => 'amount', 'label' => 'core.table.amount', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /** @param  array<string, mixed>  $f */
    private static function query(array $f): Builder
    {
        $today = Carbon::today()->toDateString();

        return DB::table('acc_cheques as c')
            ->where('c.company_id', $f['company_id'])
            ->whereNull('c.deleted_at')
            ->tap(ReportEngine::branchWall($f, 'c.branch_id'))
            ->whereBetween('c.cheque_date', [$f['from'], $f['to']])
            ->when(in_array($f['direction'] ?? null, [Cheque::RECEIVED, Cheque::ISSUED], true),
                fn ($q) => $q->where('c.direction', $f['direction']))
            ->when($f['status'] ?? null, function ($q, $status) use ($today) {
                match ($status) {
                    // ⓘ আগামী তারিখের — এখনো হাতে, তারিখ আসেনি
                    'pdc' => $q->where('c.status', Cheque::PENDING)->where('c.cheque_date', '>', $today),
                    // ⓘ তারিখ এসে গেছে, এখনো জমা হয়নি — আজকের কাজ
                    'due' => $q->where('c.status', Cheque::PENDING)->where('c.cheque_date', '<=', $today),
                    default => in_array($status, [Cheque::PENDING, Cheque::DEPOSITED, Cheque::CLEARED, Cheque::BOUNCED, Cheque::CANCELLED], true)
                        ? $q->where('c.status', $status)
                        : null,
                };
            })
            ->orderBy('c.cheque_date')
            ->orderBy('c.id')
            ->select([
                'c.cheque_date',
                'c.document_no',
                'c.cheque_no',
                'c.bank_name',
                'c.amount',
                DB::raw("'".Cheque::STOCK_SOURCE."' as source_type"),
                'c.id as source_id',
                DB::raw(self::label('c.direction', [Cheque::RECEIVED, Cheque::ISSUED]).' as direction_label'),
                DB::raw(self::label('c.status', [Cheque::PENDING, Cheque::DEPOSITED, Cheque::CLEARED, Cheque::BOUNCED, Cheque::CANCELLED]).' as status_label'),
                DB::raw('DATEDIFF(c.cheque_date, '.DB::getPdo()->quote($today).') as days'),
            ]);
    }

    /**
     * কোড থেকে ব্যবহারকারীর ভাষার নাম — SQL-এ, কারণ ইঞ্জিন সারিগুলো সাধারণ অ্যারে হিসেবে দেখে
     * ([[MonthlyCashReport]]-এর মাসের নামের একই ছাঁচ)।
     *
     * @param  list<string>  $codes
     */
    private static function label(string $column, array $codes): string
    {
        $cases = '';

        foreach ($codes as $code) {
            $cases .= ' WHEN '.DB::getPdo()->quote($code).' THEN '.DB::getPdo()->quote(__('accounts::cheque.state_'.$code));
        }

        return "CASE {$column}{$cases} ELSE {$column} END";
    }
}
