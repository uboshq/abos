<?php

declare(strict_types=1);

namespace App\Modules\Finance\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\DepositMovement;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ আমানতের রিপোর্ট — অর্থ-মডিউলের পরিকল্পনা, অংশ ৪ (৬ অক্টোবর ২০২৬, সমন্বয়কের মারফত; মাইগ্রেশন ছাড়া):
 *
 *   ক জমা সুদ — একটা দিন পর্যন্ত অর্জিত কিন্তু না-পাওয়া মুনাফা, উৎসে কর বাদে নিট ([[ACCRUED]])
 *   খ DPS কিস্তির সময়সূচি — প্রতি মাসে দেয়, দেওয়া, সইয়ের অপেক্ষায়, বাকি, আর দিন পেরোলে বকেয়া ([[INSTALMENTS]])
 *
 * ── ⭐ একটাই উৎস ─────────────────────────────────────────────────────────────
 * প্রতিটা চলাচল ([[DepositMovement]]) গোনা হয় কেবল যখন তার ভাউচার খাতায় বসেছে (শেষ সই পড়েছে), বা ভাউচারই নেই (পুরনো
 * সারি) — [[movementsSql()]]। সইয়ের অপেক্ষার বা বাতিল ভাউচারের চলাচল টাকা নয়।
 */
final class DepositReports
{
    public const ACCRUED = 'finance.deposit_accrued';

    public const INSTALMENTS = 'finance.deposit_instalments';

    // ⛔ জমার পাতা যে চাবি দেখে, সেটাই — সব কয়টার
    private const KEY = 'finance.deposit.view';

    /** বছরের দিন — বাংলাদেশের ব্যাংকের সুদের প্রচলিত গোনা (Actual/365) */
    public const DAYS_IN_YEAR = 365;

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::accrued());
        $engine->register(self::instalments());
    }

    /**
     * গোনার যোগ্য চলাচল — `m`, যার ভাউচার খাতায় বসেছে বা ভাউচার নেই।
     *
     * @param  array<string, mixed>  $f
     */
    public static function movements(array $f): Builder
    {
        return DB::table('fin_deposit_movements as m')
            ->leftJoin('vouchers as mv', 'mv.id', '=', 'm.voucher_id')
            ->where('m.company_id', $f['company_id'])
            ->whereRaw('(m.voucher_id IS NULL OR mv.status = ?)', [DocumentStatus::CONFIRMED]);
    }

    /**
     * যে জমাগুলো দিনটায় খোলা ছিল — চালু, বা দিনের পরে বন্ধ। ⓘ সইয়ের অপেক্ষায় খোলা আর বাতিল জমা টাকা নয়।
     *
     * @param  array<string, mixed>  $f
     */
    public static function openOn(array $f, string $on): Builder
    {
        return DB::table('fin_deposits as d')
            ->join('fin_deposit_kinds as k', 'k.id', '=', 'd.kind_id')
            ->where('d.company_id', $f['company_id'])
            ->where('d.opened_on', '<=', $on)
            ->where(fn ($q) => $q->where('d.status', Deposit::ACTIVE)
                ->orWhere(fn ($c) => $c->where('d.status', Deposit::CLOSED)->where('d.closed_on', '>', $on)))
            ->tap(ReportEngine::branchWall($f, 'd.branch_id'));
    }

    /**
     * ⭐ ক — জমা সুদ: দিনটা পর্যন্ত অর্জিত, এখনো না-পাওয়া মুনাফা।
     *
     * ── হিসাব (Actual/365, সরল) ──────────────────────────────────────────────
     *   প্রতিটা আসলের চলাচল (খোলা, DPS কিস্তি): অঙ্ক × হার × দিন ÷ ৩৬৫
     *   দিন = শুরু থেকে শেষ — শুরু = চলাচলের দিন, বা শেষ মুনাফা তোলার দিন (যেটা পরে); শেষ = দিনটা, বা মেয়াদপূর্তি
     *   (যেটা আগে)। ⓘ তাই DPS-এর প্রতিটা কিস্তি নিজের দিন থেকে, মাসিক-মুনাফার জমা শেষ তোলার পর থেকে, আর মেয়াদের পরে
     *   আর কিছু জমে না।
     *   উৎসে কর = অর্জিত × করের হার; নিট = অর্জিত − কর
     *
     * ⓘ হার না থাকলে কিছুই জমে না — সারিটা দেখায়, হারের ঘর খালি, যাতে কেউ হারটা বসান। মালিকের নামের জমা আলাদা দেখায়
     * (খাতায় উত্তোলন, ব্যবসার আয় নয়)।
     */
    private static function accrued(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::ACCRUED,
            permission: self::KEY,
            title: 'finance::deposit_report.accrued_title',
            filters: ['date_range'],
            asOfDate: true,
            query: function (array $f): Builder {
                $pdo = DB::getPdo();
                $on = (string) $f['to'];
                $at = $pdo->quote($on);

                $lastPayout = self::movements($f)
                    ->where('m.kind', DepositMovement::PAYOUT)
                    ->where('m.moved_on', '<=', $on)
                    ->groupBy('m.deposit_id')
                    ->selectRaw('m.deposit_id, MAX(m.moved_on) as paid_on');

                $principal = self::movements($f)
                    ->whereIn('m.kind', [DepositMovement::OPENED, DepositMovement::INSTALMENT])
                    ->where('m.moved_on', '<=', $on)
                    ->select('m.deposit_id', 'm.amount', 'm.moved_on');

                $start = 'GREATEST(p.moved_on, COALESCE(lp.paid_on, p.moved_on))';
                $end = "LEAST({$at}, COALESCE(d.matures_on, {$at}))";
                $days = "GREATEST(DATEDIFF({$end}, {$start}), 0)";
                $gross = 'COALESCE(SUM(p.amount * COALESCE(d.profit_rate, 0) / 100 * '.$days.' / '.self::DAYS_IN_YEAR.'), 0)';
                $tax = "{$gross} * COALESCE(MIN(d.tax_rate), 0) / 100";

                $holder = 'CASE MIN(d.held_by) WHEN '.$pdo->quote(Deposit::OWNER).' THEN '.$pdo->quote((string) __('finance::deposit_report.holder_owner'))
                    .' ELSE '.$pdo->quote((string) __('finance::deposit_report.holder_business')).' END';

                return self::openOn($f, $on)
                    ->joinSub($principal, 'p', 'p.deposit_id', '=', 'd.id')
                    ->leftJoinSub($lastPayout, 'lp', 'lp.deposit_id', '=', 'd.id')
                    ->groupBy('d.id')
                    ->selectRaw('MIN(d.document_no) as document_no, '.$pdo->quote(Deposit::drillSourceType()).' as source_type, d.id as source_id, '
                        ."MIN(d.institution) as institution, {$holder} as holder, COALESCE(SUM(p.amount), 0) as principal, "
                        .'MIN(d.profit_rate) as profit_rate, MIN(d.matures_on) as matures_on, '
                        ."ROUND({$gross}, 2) as accrued, ROUND({$tax}, 2) as source_tax, ROUND({$gross}, 2) - ROUND({$tax}, 2) as net_accrued")
                    ->orderByRaw('MIN(d.matures_on) IS NULL, MIN(d.matures_on)')
                    ->orderBy('d.id');
            },
            summary: fn (array $totals): array => [
                'label' => __('finance::deposit_report.accrued_summary'),
                'value' => (string) ($totals['net_accrued'] ?? '0'),
                'text' => __('finance::deposit_report.accrued_text', [
                    'gross' => Money::format((string) ($totals['accrued'] ?? '0')),
                    'tax' => Money::format((string) ($totals['source_tax'] ?? '0')),
                    'net' => Money::format((string) ($totals['net_accrued'] ?? '0')),
                ]),
                'good' => true,
            ],
            columns: [
                [
                    'key' => 'document_no',
                    'label' => 'finance::deposit_report.deposit',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type',
                    'source_id' => 'source_id',
                ],
                ['key' => 'institution', 'label' => 'finance::deposit_report.institution'],
                ['key' => 'holder', 'label' => 'finance::deposit_report.holder'],
                ['key' => 'principal', 'label' => 'finance::deposit_report.principal', 'type' => ReportColumn::MONEY],
                ['key' => 'profit_rate', 'label' => 'finance::deposit_report.profit_rate', 'type' => ReportColumn::PERCENT],
                ['key' => 'matures_on', 'label' => 'finance::deposit_report.matures_on', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'accrued', 'label' => 'finance::deposit_report.accrued', 'type' => ReportColumn::MONEY],
                ['key' => 'source_tax', 'label' => 'finance::deposit_report.source_tax', 'type' => ReportColumn::MONEY],
                ['key' => 'net_accrued', 'label' => 'finance::deposit_report.net_accrued', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * ⭐ খ — DPS কিস্তির সময়সূচি: কিস্তির জমার প্রতিটা মাস, খোলার মাস থেকে মেয়াদের মাস (বা দিনটা) পর্যন্ত।
     *
     * ── নিয়ম ─────────────────────────────────────────────────────────────────
     *   দেয় = মাসিক কিস্তি; দেওয়া = সেই মাসে খাতায় বসা কিস্তি (খোলার টাকাও — প্রথম মাসের কিস্তি)
     *   সইয়ের অপেক্ষায় = সেই মাসের সই-ঝুলে থাকা কিস্তি — টাকা যায়নি, বাকিতে থাকে
     *   বাকি = দেয় − দেওয়া (শূন্যের নিচে নয়; বেশি দিলে পরের মাস মাফ নয় — ব্যাংকও মাস ধরে গোনে)
     *   বকেয়া = কিস্তির দিন (`instalment_day`, না থাকলে খোলার দিন) দিনটার আগে পেরিয়েছে আর বাকি আছে
     *
     * ⓘ মাসগুলো PHP থেকে ([[RentalReports::months()]]) — ডাটাবেজের ঘড়ি নয়।
     */
    private static function instalments(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::INSTALMENTS,
            permission: self::KEY,
            title: 'finance::deposit_report.instalments_title',
            filters: ['date_range'],
            query: function (array $f): Builder {
                $pdo = DB::getPdo();
                $on = $pdo->quote((string) $f['to']);
                $draft = $pdo->quote(DocumentStatus::DRAFT);
                $kinds = [DepositMovement::OPENED, DepositMovement::INSTALMENT];

                // ⓘ দিনটার পরের টাকা নয় — মাসের বাকি দিনগুলো এখনো আসেনি
                $paid = self::movements($f)->whereIn('m.kind', $kinds)->where('m.moved_on', '<=', $f['to'])
                    ->selectRaw("m.deposit_id, DATE_FORMAT(m.moved_on, '%Y-%m-01') as m, SUM(m.amount) as amount")
                    ->groupByRaw("m.deposit_id, DATE_FORMAT(m.moved_on, '%Y-%m-01')");

                $waiting = DB::table('fin_deposit_movements as w')
                    ->join('vouchers as wv', 'wv.id', '=', 'w.voucher_id')
                    ->where('w.company_id', $f['company_id'])
                    ->whereIn('w.kind', $kinds)
                    ->where('w.moved_on', '<=', $f['to'])
                    ->whereRaw("wv.status = {$draft}")
                    ->selectRaw("w.deposit_id, DATE_FORMAT(w.moved_on, '%Y-%m-01') as m, SUM(w.amount) as amount")
                    ->groupByRaw("w.deposit_id, DATE_FORMAT(w.moved_on, '%Y-%m-01')");

                $day = 'LEAST(28, GREATEST(1, COALESCE(d.instalment_day, DAY(d.opened_on))))';
                $due = "DATE_ADD(mo.m, INTERVAL {$day} - 1 DAY)";
                $owed = 'COALESCE(d.instalment_amount, 0)';
                $left = "GREATEST({$owed} - COALESCE(pd.amount, 0), 0)";

                return self::openOn($f, (string) $f['to'])
                    ->where('k.shape', DepositKind::INSTALMENT)
                    ->joinSub(RentalReports::months($f), 'mo', function ($j) {
                        $j->whereRaw("mo.m >= DATE_FORMAT(d.opened_on, '%Y-%m-01')")
                            ->whereRaw('(d.matures_on IS NULL OR mo.m <= d.matures_on)');
                    })
                    ->leftJoinSub($paid, 'pd', fn ($j) => $j->on('pd.deposit_id', '=', 'd.id')->on('pd.m', '=', 'mo.m'))
                    ->leftJoinSub($waiting, 'wt', fn ($j) => $j->on('wt.deposit_id', '=', 'd.id')->on('wt.m', '=', 'mo.m'))
                    ->selectRaw('mo.m as for_month, d.document_no as document_no, '.$pdo->quote(Deposit::drillSourceType()).' as source_type, '
                        ."d.id as source_id, d.institution as institution, {$due} as due_on, {$owed} as due, COALESCE(pd.amount, 0) as paid, "
                        ."COALESCE(wt.amount, 0) as waiting, {$left} as outstanding, "
                        ."CASE WHEN {$due} < {$on} AND {$left} > 0 THEN {$left} ELSE 0 END as overdue")
                    ->orderBy('mo.m')
                    ->orderBy('d.id');
            },
            summary: fn (array $totals): array => [
                'label' => __('finance::deposit_report.instalments_summary'),
                'value' => (string) ($totals['overdue'] ?? '0'),
                'text' => __('finance::deposit_report.instalments_text', [
                    'overdue' => Money::format((string) ($totals['overdue'] ?? '0')),
                    'waiting' => Money::format((string) ($totals['waiting'] ?? '0')),
                ]),
                'good' => bccomp((string) ($totals['overdue'] ?? '0'), '0', 4) === 0,
            ],
            columns: [
                ['key' => 'for_month', 'label' => 'finance::deposit_report.month', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                [
                    'key' => 'document_no',
                    'label' => 'finance::deposit_report.deposit',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type',
                    'source_id' => 'source_id',
                ],
                ['key' => 'institution', 'label' => 'finance::deposit_report.institution'],
                ['key' => 'due_on', 'label' => 'finance::deposit_report.due_on', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'due', 'label' => 'finance::deposit_report.due', 'type' => ReportColumn::MONEY],
                ['key' => 'paid', 'label' => 'finance::deposit_report.paid', 'type' => ReportColumn::MONEY],
                ['key' => 'waiting', 'label' => 'finance::deposit_report.waiting', 'type' => ReportColumn::MONEY],
                ['key' => 'outstanding', 'label' => 'finance::deposit_report.outstanding', 'type' => ReportColumn::MONEY],
                ['key' => 'overdue', 'label' => 'finance::deposit_report.overdue', 'type' => ReportColumn::MONEY],
            ],
        );
    }
}
