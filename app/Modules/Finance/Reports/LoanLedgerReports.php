<?php

declare(strict_types=1);

namespace App\Modules\Finance\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Finance\Models\HandLoanMovement;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ হাতধারের খাতা — একজন মানুষের সাথে সব দেওয়া-নেওয়া, গ্রাহকের খাতার মতো (মালিকের সরাসরি আদেশ, ৫ অক্টোবর ২০২৬,
 * সমন্বয়কের মারফত): খোলা জের, প্রতিটা সারি (তারিখ, ভাউচারের নম্বর — পপ-আপে খোলে, বিবরণ, দেওয়া, নেওয়া), চলমান জের আর
 * শেষ জের, "(Dr) 5,000.00" / "(Cr) 3,000.00" — ছাপা, PDF, Excel ইঞ্জিনের নিজের।
 *
 * ── ⭐ কেন খতিয়ান নয়, চলাচল ──────────────────────────────────────────────
 * হাতধারের পাতার বকেয়া চলাচল থেকে গোনা ([[HandLoanService::balanceOf()]]); হাতধারের ভাউচার আজ খতিয়ানে কারও নামে
 * বসে না, তাই ব্যক্তির খতিয়ান-খাতায় ([[PartyLedgerReports::PERSON]]) হাতধারের একটা সারিও আসত না। ⛔ পর্দার বকেয়া
 * যেখান থেকে, খাতাও সেখান থেকে — একই গোনার নিয়মে ([[HandLoanMovement::countedSql()]]), তাই শেষ জের আর তালিকার
 * বাকি কখনো আলাদা হয় না (সমন্বয়কের শর্ত; দাবি: [[TheHandLoanBookEndsWhereTheListSaysTest]])।
 *
 * ⓘ দিক: দেওয়া (টাকা গেল) = ডেবিট, তিনি দেবেন — (Dr); নেওয়া (টাকা এল) = ক্রেডিট, আমরা দেব — (Cr)।
 * ⓘ জের SQL-এ `SUM(…) OVER (…)` — [[PartyLedgerReports]]-এর একই ধাঁচ: খোলা জের প্রথম সারি, তাই দ্বিতীয় পাতাতেও জের ঠিক।
 * ⛔ মানুষ না বাছলে কিছুই নয় — গোটা হাতধার কারও খাতা নয়।
 */
final class LoanLedgerReports
{
    public const HAND_LOAN = 'finance.hand_loan_ledger';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(new ReportDefinition(
            key: self::HAND_LOAN,
            // ⛔ হাতধারের পাতা যে চাবি দেখে, সেটাই
            permission: 'finance.hand_loan.view',
            title: 'finance::loan_ledger.hand_loan_title',
            filters: ['date_range', 'branch', 'person_id'],
            // ⓘ চলমান জের একটাই ধারা — শাখা ধরে ভাগ করলে জের ভাঙত
            splitByBranch: false,
            query: fn (array $f) => self::handLoan($f, (int) ($f['person_id'] ?? 0)),
            summary: function (array $totals): array {
                $net = bcsub((string) ($totals['debit'] ?? '0'), (string) ($totals['credit'] ?? '0'), 4);

                return ['label' => __('finance::loan_ledger.closing'), 'value' => $net, 'text' => Money::drCr($net), 'good' => true];
            },
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
                ['key' => 'debit', 'label' => 'finance::loan_ledger.given', 'type' => ReportColumn::MONEY],
                ['key' => 'credit', 'label' => 'finance::loan_ledger.taken', 'type' => ReportColumn::MONEY],
                ['key' => 'balance', 'label' => 'core.table.balance', 'type' => ReportColumn::DR_CR, 'width' => '10rem'],
            ],
        ));
    }

    /** @param  array<string, mixed>  $f */
    private static function handLoan(array $f, int $personId): Builder
    {
        $pdo = DB::getPdo();
        $debit = "CASE WHEN m.direction = '".HandLoanMovement::OUT."' THEN m.amount ELSE 0 END";
        $credit = "CASE WHEN m.direction = '".HandLoanMovement::IN."' THEN m.amount ELSE 0 END";

        $moves = fn () => DB::table('fin_hand_loan_movements as m')
            ->join('fin_hand_loan_accounts as a', 'a.id', '=', 'm.account_id')
            ->leftJoin('vouchers as v', 'v.id', '=', 'm.voucher_id')
            ->where('m.company_id', $f['company_id'])
            ->where('a.person_id', $personId)
            ->whereRaw(HandLoanMovement::countedSql('m', 'v'))
            ->tap(ReportEngine::branchWall($f, 'a.branch_id'));

        $net = "COALESCE(SUM({$debit}), 0) - COALESCE(SUM({$credit}), 0)";

        // ⓘ খোলা জের — শুরুর দিনের আগের সব; ধনাত্মক হলে দেওয়ার ঘরে, ঋণাত্মক হলে নেওয়ার ঘরে, যাতে যোগফল আর জের মেলে
        $opening = $moves()
            ->where('m.moved_on', '<', $f['from'])
            ->selectRaw(
                $pdo->quote((string) $f['from']).' as trx_date, NULL as document_no, '
                .$pdo->quote((string) __('finance::loan_ledger.opening')).' as narration, '
                ."GREATEST({$net}, 0) as debit, GREATEST(-({$net}), 0) as credit, "
                .'NULL as source_type, NULL as source_id, 0 as sort, 0 as id'
            );

        $kind = 'CASE m.direction WHEN '.$pdo->quote(HandLoanMovement::OUT).' THEN '.$pdo->quote((string) __('finance::loan_ledger.kind_given'))
            .' ELSE '.$pdo->quote((string) __('finance::loan_ledger.kind_taken')).' END';
        $source = 'CASE v.type WHEN '.$pdo->quote(Voucher::RECEIPT).' THEN '.$pdo->quote(Voucher::SOURCE_TYPES[Voucher::RECEIPT])
            .' WHEN '.$pdo->quote(Voucher::PAYMENT).' THEN '.$pdo->quote(Voucher::SOURCE_TYPES[Voucher::PAYMENT]).' ELSE NULL END';

        $lines = $moves()
            ->whereBetween('m.moved_on', [$f['from'], $f['to']])
            ->selectRaw("m.moved_on as trx_date, v.document_no as document_no, COALESCE(NULLIF(m.note, ''), {$kind}) as narration, "
                ."{$debit} as debit, {$credit} as credit, {$source} as source_type, v.id as source_id, 1 as sort, m.id as id");

        $running = DB::query()
            ->fromSub($opening->unionAll($lines), 'l')
            ->select('l.*')
            ->selectRaw('SUM(l.debit - l.credit) OVER (ORDER BY l.sort, l.trx_date, l.id ROWS UNBOUNDED PRECEDING) as balance');

        return DB::query()
            ->fromSub($running, 'r')
            // ⓘ মানুষ না বাছলে শূন্যের খোলা জেরের সারিটাও নয় — প্রশ্নটাই এখনো করা হয়নি
            ->when($personId === 0, fn ($q) => $q->whereRaw('1 = 0'))
            ->orderBy('r.sort')
            ->orderBy('r.trx_date')
            ->orderBy('r.id');
    }
}
