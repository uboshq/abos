<?php

declare(strict_types=1);

namespace App\Modules\Finance\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\HandLoanMovement;
use App\Modules\Finance\Services\BankFacilityService;
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

    /*
     * ⭐ ব্যাংক ঋণের খাতা — একই ধাঁচ, একই নীতি: পর্দার "বাকি আসল" যেখান থেকে ([[BankFacilityService::standing()]] —
     * খতিয়ানে ঋণের নিজের সারি, আর খাতায় না-বসা পুরনো তোলা), খাতাও সেখান থেকে ([[BankFacilityService::ledgerRowsOf()]],
     * [[BankFacilityService::legacyOpening()]])। ⓘ দিক: তোলা (দেনা বাড়ে) = ক্রেডিট, তাই জের (Cr) মানে আমরা ব্যাংকের কাছে ধারি।
     */
    public const BANK_LOAN = 'finance.bank_loan_ledger';

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

        $engine->register(new ReportDefinition(
            key: self::BANK_LOAN,
            // ⛔ ব্যাংক ঋণের পাতা যে চাবি দেখে, সেটাই
            permission: 'finance.bank_facility.view',
            title: 'finance::loan_ledger.bank_loan_title',
            filters: ['date_range', 'facility_id'],
            splitByBranch: false,
            query: fn (array $f) => self::bankLoan($f, (int) ($f['facility_id'] ?? 0)),
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
                ['key' => 'debit', 'label' => 'finance::loan_ledger.repaid', 'type' => ReportColumn::MONEY],
                ['key' => 'credit', 'label' => 'finance::loan_ledger.drawn', 'type' => ReportColumn::MONEY],
                ['key' => 'balance', 'label' => 'core.table.balance', 'type' => ReportColumn::DR_CR, 'width' => '10rem'],
            ],
        ));
    }

    /** @param  array<string, mixed>  $f */
    private static function bankLoan(array $f, int $facilityId): Builder
    {
        $facility = $facilityId > 0 ? BankFacility::query()->find($facilityId) : null;
        $service = app(BankFacilityService::class);
        $rows = $facility === null ? null : $service->ledgerRowsOf($facility);

        // ⓘ ঋণ না বাছলে, বা খাত নেই (গ্যারান্টি) — খালি খাতা
        if ($rows === null) {
            return DB::query()->fromSub(DB::table('ledger_entries')->where('company_id', $f['company_id'])
                ->selectRaw("NULL as trx_date, NULL as document_no, NULL as narration, 0 as debit, 0 as credit, NULL as source_type, NULL as source_id, 0 as sort, 0 as id, 0 as balance")
                ->whereRaw('1 = 0'), 'r');
        }

        $pdo = DB::getPdo();
        $legacy = $service->legacyOpening($facility);
        $net = 'COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) - '.$pdo->quote($legacy);

        $opening = (clone $rows)
            ->where('trx_date', '<', $f['from'])
            ->selectRaw(
                $pdo->quote((string) $f['from']).' as trx_date, NULL as document_no, '
                .$pdo->quote((string) __('finance::loan_ledger.opening')).' as narration, '
                ."GREATEST({$net}, 0) as debit, GREATEST(-({$net}), 0) as credit, "
                .'NULL as source_type, NULL as source_id, 0 as sort, 0 as id'
            );

        $lines = (clone $rows)
            ->whereBetween('trx_date', [$f['from'], $f['to']])
            ->select(['trx_date', 'document_no'])
            ->selectRaw("COALESCE(NULLIF(narration, ''), ".$pdo->quote((string) __('finance::loan_ledger.kind_bank')).') as narration')
            ->addSelect(['debit', 'credit', 'source_type', 'source_id'])
            ->selectRaw('1 as sort, id');

        $running = DB::query()
            ->fromSub($opening->unionAll($lines), 'l')
            ->select('l.*')
            ->selectRaw('SUM(l.debit - l.credit) OVER (ORDER BY l.sort, l.trx_date, l.id ROWS UNBOUNDED PRECEDING) as balance');

        return DB::query()->fromSub($running, 'r')->orderBy('r.sort')->orderBy('r.trx_date')->orderBy('r.id');
    }

    /**
     * ⭐ হাতধার খাতের (১১৭০ আর নিচের সব) খতিয়ান-সারি, ব্যক্তির নামে, যেগুলো কোনো হাতধারের চলাচলের নিজের ভাউচার নয় —
     * সাধারণ রসিদ/পরিশোধ/জাবেদায় হাতধার খাতে বসা টাকা (মালিকের অভিযোগ, ৫ অক্টোবর ২০২৬: আভা ট্রেডের RCV-0001-এ Aminul-এর
     * ৳৯,০০০ হাতধার খাতে জমা, অথচ হাতধারের তালিকায় ০)।
     *
     * ── ⛔ দুবার নয় ──────────────────────────────────────────────────────────
     * চলাচলের ভাউচার (আর তার `…:reversal`) এখানে বাদ — ওই টাকা চলাচল থেকেই গোনা হয় ([[HandLoanMovement::scopeCounted()]]),
     * একই অঙ্ক। তাই চলাচলের ভাউচারে খতিয়ানে পক্ষ বসুক বা না বসুক (পুরনো সারি), যোগফল এক — পুরনো সারি ঠিক করার আগেও।
     * ⓘ হাতধারের বাকি = চলাচল + এই সারিগুলো — তালিকা ([[HandLoanService::people()]]), হিসাবের বকেয়া
     * ([[HandLoanService::balanceOf()]]) আর খাতা ([[handLoan()]]) তিনটাই এই একটা কোয়েরি পড়ে।
     *
     * @param  list<int>  $personIds
     */
    public static function looseRows(int $companyId, array $personIds): Builder
    {
        $head = StandardChart::find(StandardChart::HAND_LOAN);
        $heads = $head === null ? [] : $head->selfAndDescendants()->modelKeys();
        $own = [
            ...array_values(Voucher::SOURCE_TYPES),
            ...array_map(fn (string $t) => $t.':reversal', array_values(Voucher::SOURCE_TYPES)),
        ];

        return DB::table('ledger_entries as le')
            ->where('le.company_id', $companyId)
            ->whereIn('le.account_id', $heads ?: [0])
            ->where('le.party_type', 'person')
            ->whereIn('le.party_id', $personIds ?: [0])
            ->whereNot(fn ($q) => $q->whereIn('le.source_type', $own)
                ->whereExists(fn ($m) => $m->selectRaw('1')->from('fin_hand_loan_movements as hm')
                    ->where('hm.company_id', $companyId)
                    ->whereColumn('hm.voucher_id', 'le.source_id')));
    }

    /** @param  array<string, mixed>  $f */
    private static function handLoan(array $f, int $personId): Builder
    {
        $pdo = DB::getPdo();
        $debit = "CASE WHEN m.direction = '".HandLoanMovement::OUT."' THEN m.amount ELSE 0 END";
        $credit = "CASE WHEN m.direction = '".HandLoanMovement::IN."' THEN m.amount ELSE 0 END";

        $kind = 'CASE m.direction WHEN '.$pdo->quote(HandLoanMovement::OUT).' THEN '.$pdo->quote((string) __('finance::loan_ledger.kind_given'))
            .' ELSE '.$pdo->quote((string) __('finance::loan_ledger.kind_taken')).' END';
        $source = 'CASE v.type WHEN '.$pdo->quote(Voucher::RECEIPT).' THEN '.$pdo->quote(Voucher::SOURCE_TYPES[Voucher::RECEIPT])
            .' WHEN '.$pdo->quote(Voucher::PAYMENT).' THEN '.$pdo->quote(Voucher::SOURCE_TYPES[Voucher::PAYMENT]).' ELSE NULL END';

        // ⓘ দুই উৎস এক সারিতে: হাতধারের চলাচল, আর হাতধার খাতে তাঁর নামে বাকি খতিয়ান-সারি ([[looseRows()]])
        $moves = DB::table('fin_hand_loan_movements as m')
            ->join('fin_hand_loan_accounts as a', 'a.id', '=', 'm.account_id')
            ->leftJoin('vouchers as v', 'v.id', '=', 'm.voucher_id')
            ->where('m.company_id', $f['company_id'])
            ->where('a.person_id', $personId)
            ->whereRaw(HandLoanMovement::countedSql('m', 'v'))
            ->tap(ReportEngine::branchWall($f, 'a.branch_id'))
            ->selectRaw("m.moved_on as trx_date, v.document_no as document_no, COALESCE(NULLIF(m.note, ''), {$kind}) as narration, "
                ."{$debit} as debit, {$credit} as credit, {$source} as source_type, v.id as source_id, m.id as id");

        $loose = self::looseRows((int) $f['company_id'], [$personId])
            ->tap(ReportEngine::branchWall($f, 'le.branch_id'))
            ->selectRaw("le.trx_date as trx_date, le.document_no as document_no, COALESCE(NULLIF(le.narration, ''), "
                .$pdo->quote((string) __('finance::loan_ledger.kind_books')).') as narration, '
                .'le.debit as debit, le.credit as credit, le.source_type as source_type, le.source_id as source_id, le.id + 1000000000 as id');

        $all = fn () => DB::query()->fromSub((clone $moves)->unionAll(clone $loose), 'u');

        // ⓘ খোলা জের — শুরুর দিনের আগের সব; ধনাত্মক হলে দেওয়ার ঘরে, ঋণাত্মক হলে নেওয়ার ঘরে, যাতে যোগফল আর জের মেলে
        $net = 'COALESCE(SUM(u.debit), 0) - COALESCE(SUM(u.credit), 0)';
        $opening = $all()
            ->where('u.trx_date', '<', $f['from'])
            ->selectRaw(
                $pdo->quote((string) $f['from']).' as trx_date, NULL as document_no, '
                .$pdo->quote((string) __('finance::loan_ledger.opening')).' as narration, '
                ."GREATEST({$net}, 0) as debit, GREATEST(-({$net}), 0) as credit, "
                .'NULL as source_type, NULL as source_id, 0 as sort, 0 as id'
            );

        $lines = $all()
            ->whereBetween('u.trx_date', [$f['from'], $f['to']])
            ->selectRaw('u.trx_date, u.document_no, u.narration, u.debit, u.credit, u.source_type, u.source_id, 1 as sort, u.id');

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
