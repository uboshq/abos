<?php

declare(strict_types=1);

namespace App\Modules\Finance\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Finance\Models\RentalContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ ভাড়ার চুক্তি ও জামানতের রিপোর্ট — অর্থ-মডিউলের পরিকল্পনা, অংশ ৫ (৬ অক্টোবর ২০২৬, সমন্বয়কের মারফত; মাইগ্রেশন ছাড়া,
 * মালিকের প্রশ্নের উত্তর না আসা পর্যন্ত):
 *
 *   ক ভাড়ার সময়সূচি — চুক্তি ধরে প্রতি মাস: দেয়, নগদে দেওয়া, জামানত থেকে কাটা, সইয়ের অপেক্ষায়, বাকি ([[SCHEDULE]])
 *   খ অগ্রিম সমন্বয় — চুক্তি ধরে: শুরুর জের, দেওয়া, মাসে মাসে কাটা, ফেরত, শেষের জের, কত মাস চলবে ([[ADVANCE]])
 *   গ জামানতের খাতা — একটা চুক্তির জামানতের প্রতিটা নড়াচড়া, কাগজসহ, চলমান জের ([[DEPOSIT_BOOK]])
 *
 * ── ⭐ একটাই উৎস ─────────────────────────────────────────────────────────────
 * মাসটা "দেওয়া" কেবল যখন তার সারি ([[RentalAdjustment]]) আছে আর ভাউচারে শেষ সই পড়ে খাতায় বসেছে (`confirmed`)।
 * সই ঝুলে থাকলে (`draft`) টাকা যায়নি — বাকিতেই থাকে, আলাদা ঘরে দেখায়। "না" হলে সারিটা সরে যায়
 * ([[RentalContractService::dropRefused()]]), আর ভাউচার পরে বাতিল হলে মাসটা আবার বাকি।
 *
 * ── ⚠️ জানা সীমা ─────────────────────────────────────────────────────────────
 * শর্ত বদলালে ([[RentalContractService::reviseTerms()]]) মাসিক ভাড়ার পুরনো অঙ্ক কোথাও থাকে না। তাই না-দেওয়া পুরনো
 * মাসের দেয় এখনকার ভাড়ায় দেখায়; দেওয়া মাস দেখায় তার নিজের সারির ভাড়া। ইতিহাস রাখা মাইগ্রেশন — বার্ষিক বৃদ্ধির
 * প্রশ্নের (মালিকের প্র১) উত্তরের সাথে।
 */
final class RentalReports
{
    public const SCHEDULE = 'finance.rental_schedule';

    public const ADVANCE = 'finance.rental_advance';

    public const DEPOSIT_BOOK = 'finance.rental_deposit_book';

    // ⛔ ভাড়ার পাতা যে চাবি দেখে, সেটাই — সব কয়টার
    private const KEY = 'finance.rental.view';

    /** একবারে সর্বোচ্চ কত মাস — দশ বছর; বেশি চাইলে শেষের দিক থেকে কাটা */
    private const MAX_MONTHS = 120;

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::schedule());
        $engine->register(self::advance());
        $engine->register(self::depositBook());
    }

    /**
     * জামানতের সব নড়াচড়া, চুক্তি ধরে, তারিখসহ: contract_id, trx_date, given, refunded, deducted, legacy।
     *
     * ── উৎস ──────────────────────────────────────────────────────────────────
     *   · দেওয়া আর ফেরত — চুক্তির নিজের খাতে, চুক্তির নামে বাঁধা ভাউচারের খতিয়ান-সারি (খোলা, বাড়ানো, শেষে ফেরত:
     *     `against_type` = চুক্তি)। খতিয়ানে বসেছে মানে শেষ সই পড়েছে; বাতিল হলে উল্টো সারি নিজেই কাটে।
     *   · মাসে মাসে কাটা — মাসের সারির `from_deposit`, কেবল যার ভাউচারে শেষ সই পড়েছে ([[SCHEDULE]]-এর একই নিয়ম)।
     *   · শুরুর জামানত (`legacy`) — চুক্তির জামানতের অঙ্ক থেকে খাতায় চুক্তির নামে বসা টাকা বাদ, চুক্তির শুরুর দিনে।
     *     ভাউচার ছাড়া বসা জামানত (খোলা জেরের আমদানি) এভাবে আসে; সব ভাউচারে বসলে এটা শূন্য।
     *
     * ⓘ তাই আজকের শেষ জের = চুক্তির পাতার "জামানতে বাকি" ([[RentalContract::depositLeft()]]), হুবহু।
     *
     * @param  array<string, mixed>  $f
     */
    public static function depositMoves(array $f): Builder
    {
        $company = (int) $f['company_id'];
        $posted = DB::getPdo()->quote(DocumentStatus::CONFIRMED);
        $contracts = fn () => DB::table('fin_rental_contracts as c')
            ->where('c.company_id', $company)
            ->whereNull('c.deleted_at')
            ->whereIn('c.status', [RentalContract::ACTIVE, RentalContract::CLOSED])
            ->tap(ReportEngine::branchWall($f, 'c.branch_id'));

        $booked = fn () => $contracts()
            ->join('vouchers as v', function ($j) {
                $j->on('v.against_id', '=', 'c.id')->where('v.against_type', RentalContract::drillSourceType());
            })
            ->join('ledger_entries as le', function ($j) {
                $j->on('le.source_id', '=', 'v.id')->on('le.account_id', '=', 'c.account_id')
                    // ⛔ উল্টো সারিও — বাতিল বা সংশোধনে খাতা `…:reversal` নামে উল্টায় ([[PostingEngine::reverse()]]); বাদ দিলে
                    // বাতিল জামানত "দেওয়া" দেখাত
                    ->whereIn('le.source_type', self::voucherSources());
            })
            ->where('le.company_id', $company);

        // ⓘ উল্টো সারি নিজের মূল ঘর থেকেই বিয়োগ — বাতিল বাড়ানো "দেওয়া"-তে +৫০০০ আর "ফেরত"-এ +৫০০০ দেখাত, অথচ কিছুই ফেরত আসেনি
        $reversal = "le.source_type LIKE '%:reversal'";
        $moves = $booked()->selectRaw("c.id as contract_id, le.trx_date as trx_date, CASE WHEN {$reversal} THEN -le.credit ELSE le.debit END as given, "
            ."CASE WHEN {$reversal} THEN -le.debit ELSE le.credit END as refunded, 0 as deducted, 0 as legacy, "
            .'v.document_no as document_no, le.source_type as source_type, v.id as source_id, le.id as row_id');
        $unbooked = $booked()->selectRaw('c.id as contract_id, c.starts_on as trx_date, 0 as given, 0 as refunded, 0 as deducted, le.credit - le.debit as legacy, '
            .'NULL as document_no, NULL as source_type, NULL as source_id, 0 as row_id');
        $promised = $contracts()->selectRaw('c.id as contract_id, c.starts_on as trx_date, 0 as given, 0 as refunded, 0 as deducted, c.deposit_amount as legacy, '
            .'NULL as document_no, NULL as source_type, NULL as source_id, 0 as row_id');

        $deducted = $contracts()
            ->join('fin_rental_adjustments as a', function ($j) {
                $j->on('a.rental_contract_id', '=', 'c.id')->whereNull('a.deleted_at');
            })
            ->join('vouchers as av', 'av.id', '=', 'a.voucher_id')
            ->whereRaw("av.status = {$posted}")
            ->selectRaw('c.id as contract_id, a.for_month as trx_date, 0 as given, 0 as refunded, a.from_deposit as deducted, 0 as legacy, '
                .'av.document_no as document_no, '.DB::getPdo()->quote(Voucher::SOURCE_TYPES[Voucher::PAYMENT]).' as source_type, av.id as source_id, '
                .'a.id + 1000000000 as row_id');

        return DB::query()->fromSub($moves->unionAll($unbooked)->unionAll($promised)->unionAll($deducted), 'd');
    }

    /** @return list<string> রসিদ আর খরচের ভাউচারের খতিয়ান-নাম, উল্টোসহ */
    private static function voucherSources(): array
    {
        $types = [Voucher::SOURCE_TYPES[Voucher::RECEIPT], Voucher::SOURCE_TYPES[Voucher::PAYMENT]];

        return [...$types, ...array_map(fn (string $t) => $t.':reversal', $types)];
    }

    /**
     * তারিখের মধ্যের মাসগুলো — প্রতিটা মাসের প্রথম দিন, একটা সারি করে (`mo.m`)।
     *
     * ⓘ PHP-তে বানানো, ডাটাবেজের ঘড়ি বা পুনরাবৃত্ত CTE ছাড়া — লাইভের MariaDB আর পরীক্ষার MySQL দুই জায়গায় একই।
     *
     * @param  array<string, mixed>  $f
     */
    public static function months(array $f): Builder
    {
        $end = Carbon::parse((string) $f['to'])->startOfMonth();
        $first = Carbon::parse((string) $f['from'])->startOfMonth();
        $floor = $end->copy()->subMonths(self::MAX_MONTHS - 1);

        // ⛔ কপি — `max()` একই বস্তু ফেরত দিতে পারে, আর তখন `addMonth()` শেষটাকেও সরাত, লুপ থামত না।
        // ⓘ শুরু শেষের পরে হলে শেষের মাসটাই — খালি তালিকা নয়, তাই কোয়েরি সবসময় বৈধ
        $month = ($first->lt($floor) ? $floor : $first)->copy();
        $month = $month->gt($end) ? $end->copy() : $month;

        $rows = null;

        for (; $month->lte($end); $month->addMonth()) {
            $one = DB::query()->selectRaw('? as m', [$month->toDateString()]);
            $rows = $rows === null ? $one : $rows->unionAll($one);
        }

        return DB::query()->fromSub($rows, 'mo');
    }

    /**
     * ⭐ ক — ভাড়ার সময়সূচি।
     *
     * প্রতিটা চালু বা শেষ চুক্তির প্রতিটা মাস, যে মাসে চুক্তিটা অন্তত এক দিন চলেছে (শুরুর মাস থেকে মেয়াদ বা আগেভাগে শেষের
     * মাস পর্যন্ত)। ⓘ সইয়ের অপেক্ষায় খোলা চুক্তি (`awaiting`) এখনো চলে না — বাদ।
     */
    private static function schedule(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::SCHEDULE,
            permission: self::KEY,
            title: 'finance::rental_report.schedule_title',
            filters: ['date_range'],
            query: function (array $f): Builder {
                $posted = DB::getPdo()->quote(DocumentStatus::CONFIRMED);
                $waiting = DB::getPdo()->quote(DocumentStatus::DRAFT);
                $paid = fn (string $column) => "CASE WHEN v.status = {$posted} THEN a.{$column} ELSE 0 END";
                $due = 'COALESCE(a.rent, c.monthly_rent)';

                return DB::query()
                    ->fromSub(self::months($f), 'mo')
                    ->join('fin_rental_contracts as c', function ($j) {
                        $j->whereRaw('c.starts_on < DATE_ADD(mo.m, INTERVAL 1 MONTH)')
                            ->whereRaw('c.ends_on >= mo.m')
                            ->whereRaw('(c.closed_on IS NULL OR c.closed_on >= mo.m)');
                    })
                    ->leftJoin('fin_rental_adjustments as a', function ($j) {
                        $j->on('a.rental_contract_id', '=', 'c.id')->on('a.for_month', '=', 'mo.m')->whereNull('a.deleted_at');
                    })
                    ->leftJoin('vouchers as v', 'v.id', '=', 'a.voucher_id')
                    ->where('c.company_id', $f['company_id'])
                    ->whereNull('c.deleted_at')
                    ->whereIn('c.status', [RentalContract::ACTIVE, RentalContract::CLOSED])
                    ->tap(ReportEngine::branchWall($f, 'c.branch_id'))
                    ->selectRaw('mo.m as for_month, c.document_no as document_no, '
                        .DB::getPdo()->quote(RentalContract::drillSourceType()).' as source_type, c.id as source_id, '
                        .'c.counterparty as counterparty, c.subject as subject, '
                        ."{$due} as rent_due, ".$paid('paid_cash').' as paid_cash, '.$paid('from_deposit').' as from_deposit, '
                        ."CASE WHEN v.status = {$waiting} THEN a.rent ELSE 0 END as waiting, "
                        ."{$due} - (".$paid('paid_cash').') - ('.$paid('from_deposit').') as outstanding')
                    ->orderBy('mo.m')
                    ->orderBy('c.counterparty')
                    ->orderBy('c.id');
            },
            summary: fn (array $totals): array => [
                'label' => __('finance::rental_report.schedule_summary'),
                'value' => (string) ($totals['outstanding'] ?? '0'),
                'text' => __('finance::rental_report.schedule_text', [
                    'due' => Money::format((string) ($totals['rent_due'] ?? '0')),
                    'outstanding' => Money::format((string) ($totals['outstanding'] ?? '0')),
                    'waiting' => Money::format((string) ($totals['waiting'] ?? '0')),
                ]),
                'good' => bccomp((string) ($totals['outstanding'] ?? '0'), '0', 4) === 0,
            ],
            columns: [
                ['key' => 'for_month', 'label' => 'finance::rental_report.month', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                [
                    'key' => 'document_no',
                    'label' => 'finance::rental_report.contract',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type',
                    'source_id' => 'source_id',
                ],
                ['key' => 'counterparty', 'label' => 'finance::rental_report.counterparty'],
                ['key' => 'subject', 'label' => 'finance::rental_report.subject'],
                ['key' => 'rent_due', 'label' => 'finance::rental_report.rent_due', 'type' => ReportColumn::MONEY],
                ['key' => 'paid_cash', 'label' => 'finance::rental_report.paid_cash', 'type' => ReportColumn::MONEY],
                ['key' => 'from_deposit', 'label' => 'finance::rental_report.from_deposit', 'type' => ReportColumn::MONEY],
                ['key' => 'waiting', 'label' => 'finance::rental_report.waiting', 'type' => ReportColumn::MONEY],
                ['key' => 'outstanding', 'label' => 'finance::rental_report.outstanding', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * ⭐ খ — অগ্রিম সমন্বয়: চুক্তি ধরে জামানতের শুরুর জের, সময়ের মধ্যে দেওয়া (বাড়ানোসহ), মাসে মাসে কাটা, ফেরত, শেষের জের,
     * আর এই হারে কাটলে আর কত মাস চলবে। ⓘ সব [[depositMoves()]] থেকে।
     */
    private static function advance(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::ADVANCE,
            permission: self::KEY,
            title: 'finance::rental_report.advance_title',
            filters: ['date_range'],
            query: function (array $f): Builder {
                $from = DB::getPdo()->quote((string) $f['from']);
                $net = 'd.given + d.legacy - d.refunded - d.deducted';
                $in = fn (string $sum) => "COALESCE(SUM(CASE WHEN d.trx_date >= {$from} THEN {$sum} ELSE 0 END), 0)";
                $closing = "COALESCE(SUM({$net}), 0)";

                return DB::query()
                    ->fromSub(self::depositMoves($f)->where('d.trx_date', '<=', $f['to']), 'd')
                    ->join('fin_rental_contracts as c', 'c.id', '=', 'd.contract_id')
                    ->groupBy('d.contract_id')
                    ->havingRaw('opening_balance <> 0 OR given <> 0 OR deducted <> 0 OR refunded <> 0 OR closing_balance <> 0')
                    ->selectRaw('MIN(c.document_no) as document_no, '.DB::getPdo()->quote(RentalContract::drillSourceType()).' as source_type, '
                        .'d.contract_id as source_id, MIN(c.counterparty) as counterparty, MIN(c.subject) as subject, '
                        ."COALESCE(SUM(CASE WHEN d.trx_date < {$from} THEN {$net} ELSE 0 END), 0) as opening_balance, "
                        .$in('d.given + d.legacy').' as given, '.$in('d.deducted').' as deducted, '.$in('d.refunded').' as refunded, '
                        ."{$closing} as closing_balance, MIN(c.monthly_adjustment) as monthly_adjustment, "
                        ."CASE WHEN MIN(c.monthly_adjustment) > 0 THEN FLOOR({$closing} / MIN(c.monthly_adjustment)) ELSE NULL END as months_left")
                    ->orderByRaw('MIN(c.counterparty)')
                    ->orderBy('d.contract_id');
            },
            summary: fn (array $totals): array => [
                'label' => __('finance::rental_report.advance_summary'),
                'value' => (string) ($totals['closing_balance'] ?? '0'),
                'text' => Money::format((string) ($totals['closing_balance'] ?? '0')),
                'good' => true,
            ],
            columns: [
                [
                    'key' => 'document_no',
                    'label' => 'finance::rental_report.contract',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type',
                    'source_id' => 'source_id',
                ],
                ['key' => 'counterparty', 'label' => 'finance::rental_report.counterparty'],
                ['key' => 'subject', 'label' => 'finance::rental_report.subject'],
                ['key' => 'opening_balance', 'label' => 'finance::rental_report.opening_balance', 'type' => ReportColumn::MONEY],
                ['key' => 'given', 'label' => 'finance::rental_report.given', 'type' => ReportColumn::MONEY],
                ['key' => 'deducted', 'label' => 'finance::rental_report.deducted', 'type' => ReportColumn::MONEY],
                ['key' => 'refunded', 'label' => 'finance::rental_report.refunded', 'type' => ReportColumn::MONEY],
                ['key' => 'closing_balance', 'label' => 'finance::rental_report.closing_balance', 'type' => ReportColumn::MONEY],
                ['key' => 'monthly_adjustment', 'label' => 'finance::rental_report.monthly_adjustment', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'months_left', 'label' => 'finance::rental_report.months_left', 'type' => ReportColumn::QUANTITY, 'total' => false],
            ],
        );
    }

    /**
     * ⭐ গ — জামানতের খাতা: একটা চুক্তির খোলা জের, তারপর প্রতিটা দেওয়া, কাটা আর ফেরত — কাগজের নম্বরসহ, চলমান জের।
     *
     * ⓘ [[depositMoves()]] থেকে, তাই শেষ জের [[ADVANCE]]-এর সেই চুক্তির শেষ জেরের হুবহু। চুক্তি না বাছলে কিছুই নয়।
     */
    private static function depositBook(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::DEPOSIT_BOOK,
            permission: self::KEY,
            title: 'finance::rental_report.book_title',
            filters: ['date_range', 'rental_contract_id'],
            // ⓘ চলমান জের একটাই ধারা — শাখা ধরে ভাগ করলে জের ভাঙত
            splitByBranch: false,
            query: function (array $f): Builder {
                $pdo = DB::getPdo();
                $contract = (int) ($f['rental_contract_id'] ?? 0);
                $all = fn () => self::depositMoves($f)->where('d.contract_id', $contract);
                $debit = 'd.given + d.legacy';
                $credit = 'd.deducted + d.refunded';
                $net = "COALESCE(SUM({$debit}), 0) - COALESCE(SUM({$credit}), 0)";

                // ⓘ খোলা জের — শুরুর দিনের আগের সব; যে দিকে জের, সেই ঘরে
                $opening = $all()
                    ->where('d.trx_date', '<', $f['from'])
                    ->selectRaw($pdo->quote((string) $f['from']).' as trx_date, NULL as document_no, '
                        .$pdo->quote((string) __('finance::rental_report.opening_row')).' as narration, '
                        ."GREATEST({$net}, 0) as debit, GREATEST(-({$net}), 0) as credit, "
                        .'NULL as source_type, NULL as source_id, 0 as sort, 0 as row_id');

                $kind = 'CASE WHEN d.deducted <> 0 THEN '.$pdo->quote((string) __('finance::rental_report.deducted'))
                    .' WHEN d.refunded <> 0 THEN '.$pdo->quote((string) __('finance::rental_report.refunded'))
                    .' ELSE '.$pdo->quote((string) __('finance::rental_report.given')).' END';

                $lines = $all()
                    ->whereBetween('d.trx_date', [$f['from'], $f['to']])
                    ->where('d.legacy', 0)
                    ->whereRaw("(({$debit}) <> 0 OR ({$credit}) <> 0)")
                    ->selectRaw("d.trx_date, d.document_no, {$kind} as narration, {$debit} as debit, {$credit} as credit, "
                        .'d.source_type, d.source_id, 1 as sort, d.row_id');

                // ⓘ পুরনো জামানত এক সারিতে — চুক্তির অঙ্ক আর খাতায় বসা অংশের বিয়োগ জোড়া লাগিয়ে; সব ভাউচারে বসলে শূন্য, সারি নয়
                $legacy = $all()
                    ->whereBetween('d.trx_date', [$f['from'], $f['to']])
                    ->where('d.legacy', '<>', 0)
                    ->havingRaw('SUM(d.legacy) <> 0')
                    ->selectRaw('MIN(d.trx_date) as trx_date, NULL as document_no, '
                        .$pdo->quote((string) __('finance::rental_report.legacy')).' as narration, '
                        .'GREATEST(SUM(d.legacy), 0) as debit, GREATEST(-SUM(d.legacy), 0) as credit, '
                        .'NULL as source_type, NULL as source_id, 1 as sort, 0 as row_id');

                $running = DB::query()
                    ->fromSub($opening->unionAll($legacy)->unionAll($lines), 'l')
                    ->select('l.*')
                    ->selectRaw('SUM(l.debit - l.credit) OVER (ORDER BY l.sort, l.trx_date, l.row_id ROWS UNBOUNDED PRECEDING) as balance');

                return DB::query()
                    ->fromSub($running, 'r')
                    ->when($contract === 0, fn ($q) => $q->whereRaw('1 = 0'))
                    ->orderBy('r.sort')
                    ->orderBy('r.trx_date')
                    ->orderBy('r.row_id');
            },
            summary: function (array $totals): array {
                $net = bcsub((string) ($totals['debit'] ?? '0'), (string) ($totals['credit'] ?? '0'), 4);

                return ['label' => __('finance::rental_report.closing_balance'), 'value' => $net, 'text' => Money::format($net), 'good' => true];
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
                ['key' => 'debit', 'label' => 'finance::rental_report.given', 'type' => ReportColumn::MONEY],
                ['key' => 'credit', 'label' => 'finance::rental_report.taken_back', 'type' => ReportColumn::MONEY],
                ['key' => 'balance', 'label' => 'finance::rental_report.closing_balance', 'type' => ReportColumn::MONEY, 'width' => '10rem'],
            ],
        );
    }
}
