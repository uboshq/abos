<?php

declare(strict_types=1);

namespace App\Modules\Finance\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Finance\Models\RentalContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ ভাড়ার চুক্তি ও জামানতের রিপোর্ট — অর্থ-মডিউলের পরিকল্পনা, অংশ ৫ (৬ অক্টোবর ২০২৬, সমন্বয়কের মারফত; মাইগ্রেশন ছাড়া,
 * মালিকের প্রশ্নের উত্তর না আসা পর্যন্ত):
 *
 *   ক ভাড়ার সময়সূচি — চুক্তি ধরে প্রতি মাস: দেয়, নগদে দেওয়া, জামানত থেকে কাটা, সইয়ের অপেক্ষায়, বাকি ([[SCHEDULE]])
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

    // ⛔ ভাড়ার পাতা যে চাবি দেখে, সেটাই — সব কয়টার
    private const KEY = 'finance.rental.view';

    /** একবারে সর্বোচ্চ কত মাস — দশ বছর; বেশি চাইলে শেষের দিক থেকে কাটা */
    private const MAX_MONTHS = 120;

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::schedule());
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
}
