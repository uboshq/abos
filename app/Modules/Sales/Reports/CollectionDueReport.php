<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CreditExposure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * আদায়ের সূচি — রিপোর্ট সেন্টার ধাপ ৪ ("আজ/সপ্তাহ/মাসের পাওনা, বাকির সীমার ব্যবহার"), ২ অক্টোবর ২০২৬।
 *
 * ── কোন প্রশ্নের উত্তর ──────────────────────────────────────────────────
 * *"আজ কার কাছে যেতে হবে, এই সপ্তাহে কত আসার কথা, আর কে সীমার কত কাছে"*। প্রতি গ্রাহক এক সারি: মেয়াদ পেরোনো,
 * আজ পড়ে, পরের ৭ দিনে, এই মাসের বাকিটায়, মোট বাকি, বাকির সীমা আর তার কত শতাংশ ব্যবহার।
 *
 * ── ⓘ বাকি কোথা থেকে ───────────────────────────────────────────────────
 * প্রতিটা নিশ্চিত বিলের নিজের বাকি — মোট − আদায় − রসিদ − পাকা ফেরত ([[SalesInvoice::scopeWithCollected()]]-এর
 * একই তিন ভাগ, তাই তালিকা আর বিলের পাতা যা বলে, এখানেও তাই)। মেয়াদ বিলের `due_on`; না লেখা থাকলে বিলের দিনই
 * (তখন সেটা "পেরোনো" বা "আজ")। ⓘ বয়সের বালতি ([[customer.ageing]]) বলে টাকা কত পুরনো; এটা বলে কবে আসার কথা।
 *
 * ⚠️ সীমার ব্যবহার এখানে বিলের বাকি দিয়ে — খোলা ব্যালেন্স বা হাতে লেখা জাবেদা এতে নেই; খাতার পূর্ণ বকেয়া বয়সের
 * রিপোর্টে।
 */
final class CollectionDueReport
{
    public const KEY = 'sales.collection_due';

    public static function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            permission: 'sales.report',
            title: 'sales::due.title',
            filters: ['branch', 'customer_id'],
            query: fn (array $f) => self::query($f),
            columns: [
                ['key' => 'customer_code', 'label' => 'sales::due.code', 'type' => ReportColumn::TEXT, 'width' => '7rem'],
                ['key' => 'customer_name', 'label' => 'sales::due.customer', 'type' => ReportColumn::TEXT],
                ['key' => 'overdue', 'label' => 'sales::due.overdue', 'type' => ReportColumn::MONEY],
                ['key' => 'due_today', 'label' => 'sales::due.today', 'type' => ReportColumn::MONEY],
                ['key' => 'due_week', 'label' => 'sales::due.week', 'type' => ReportColumn::MONEY],
                ['key' => 'due_month', 'label' => 'sales::due.month', 'type' => ReportColumn::MONEY],
                ['key' => 'total_due', 'label' => 'sales::due.total', 'type' => ReportColumn::MONEY],
                ['key' => 'credit_limit', 'label' => 'sales::due.limit', 'type' => ReportColumn::MONEY, 'total' => false],
                ['key' => 'limit_used', 'label' => 'sales::due.used', 'type' => ReportColumn::PERCENT, 'total' => false],
            ],
        );
    }

    /** @param  array<string, mixed>  $f */
    private static function query(array $f): Builder
    {
        $today = Carbon::today();
        $q = fn (Carbon $d) => DB::getPdo()->quote($d->toDateString());
        $now = $q($today);
        $week = $q($today->copy()->addDays(7));
        $monthEnd = $q($today->copy()->endOfMonth());

        $bills = SalesInvoice::query()->posted()->withCollected()->toBase()
            ->where('sal_invoices.company_id', $f['company_id'])
            ->tap(ReportEngine::branchWall($f, 'sal_invoices.branch_id'))
                ->tap(ReportEngine::dealerWall($f, 'sal_invoices.customer_id'))
            ->when($f['customer_id'] ?? null, fn ($w, $id) => $w->where('sal_invoices.customer_id', (int) $id));

        $due = '(i.total - i.collected_total - i.voucher_total - i.returned_total)';
        $on = 'COALESCE(i.due_on, i.trx_date)';

        /*
         * ⛔ সীমার ব্যবহার খাতা থেকে, সীমার দেয়ালের মতো — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (বিক্রয় ⚠️১১;
         * [[TheDueReportMeasuresTheLimitLikeTheWallTest]])। ⓘ আগে বিলের বাকি ÷ সীমা — খোলা জের, অগ্রিম আর বাতিলের পরে বাকি টাকা বাদ,
         * অপেক্ষার কাগজও নয়; অথচ সীমার দেয়াল ([[CreditExposure]]) খাতা + অপেক্ষার কাগজ গোনে। এখন "বাকি-নিয়ন্ত্রণ" রিপোর্টের
         * ([[CreditControlReports::creditUse()]]) একই উৎস: গ্রাহকের নামে খাতার জের (গোটা কোম্পানি — সীমা গ্রাহকের, শাখার নয়) আর
         * বিল না হওয়া চালান, খসড়া বিল, DO, খোলা আদেশ ও ক্লিয়ার না হওয়া চেক। বিলের বকেয়ার কলামগুলো আগের মতো বিল থেকে।
         */
        $ids = (clone $bills)->distinct()->pluck('sal_invoices.customer_id')->map(fn ($id) => (int) $id)->all();
        $exposure = app(CreditExposure::class);
        $held = $exposure->pendingFor($ids);

        foreach ($exposure->unclearedChequesFor($ids) as $id => $amount) {
            $held[(int) $id] = bcadd($held[(int) $id] ?? '0', (string) $amount, 4);
        }

        $cases = '';

        foreach ($held as $id => $amount) {
            $cases .= ' WHEN '.(int) $id.' THEN '.bcadd((string) $amount, '0', 4);
        }

        $heldSql = $cases === '' ? '0' : "(CASE i.customer_id{$cases} ELSE 0 END)";
        $used = "(COALESCE(l.due, 0) + {$heldSql})";
        $name = app()->getLocale() === 'bn' ? "COALESCE(NULLIF(cu.name_bn, ''), cu.name_en)" : 'cu.name_en';

        return DB::query()
            ->fromSub($bills, 'i')
            ->join('customers as cu', 'cu.id', '=', 'i.customer_id')
            ->leftJoinSub(
                DB::table('ledger_entries')
                    ->where('company_id', $f['company_id'])
                    ->where('party_type', Customer::drillSourceType())
                    ->groupBy('party_id')
                    ->select(['party_id', DB::raw('SUM(debit) - SUM(credit) as due')]),
                'l', 'l.party_id', '=', 'i.customer_id',
            )
            ->whereRaw("{$due} > 0.0001")
            ->groupBy('i.customer_id', 'cu.code', 'cu.name_en', 'cu.name_bn', 'cu.credit_limit', 'l.due')
            ->orderByRaw("SUM(CASE WHEN {$on} < {$now} THEN {$due} ELSE 0 END) DESC")
            ->orderByRaw("SUM({$due}) DESC")
            ->select([
                'i.customer_id',
                'cu.code as customer_code',
                DB::raw("{$name} as customer_name"),
                DB::raw("SUM(CASE WHEN {$on} < {$now} THEN {$due} ELSE 0 END) as overdue"),
                DB::raw("SUM(CASE WHEN {$on} = {$now} THEN {$due} ELSE 0 END) as due_today"),
                DB::raw("SUM(CASE WHEN {$on} > {$now} AND {$on} <= {$week} THEN {$due} ELSE 0 END) as due_week"),
                DB::raw("SUM(CASE WHEN {$on} > {$week} AND {$on} <= {$monthEnd} THEN {$due} ELSE 0 END) as due_month"),
                DB::raw("SUM({$due}) as total_due"),
                'cu.credit_limit',
                DB::raw("CASE WHEN COALESCE(cu.credit_limit, 0) > 0 THEN ROUND({$used} * 100 / cu.credit_limit, 1) END as limit_used"),
            ]);
    }
}
