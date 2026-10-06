<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Purchase\Models\PurchaseBill;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * পরিশোধের সূচি — রিপোর্ট সেন্টার ধাপ ৪ ("দেনার বয়স, আজ/সপ্তাহ/মাসের দেনা"), ২ অক্টোবর ২০২৬।
 *
 * *"আজ কাকে দিতে হবে, এই সপ্তাহে কত যাবে"* — [[CollectionDueReport]]-এর আয়না। প্রতি সরবরাহকারী এক সারি: মেয়াদ
 * পেরোনো, আজ, পরের ৭ দিন, এই মাসের বাকিটা, মোট বাকি। বাকি = বিলের মোট − পরিশোধ − পরিশোধ-ভাউচার
 * ([[PurchaseBill::scopeWithPaid()]]-এর একই ভাগ)। মেয়াদ `due_on`, না থাকলে বিলের দিন।
 * ⓘ দেনার বয়স (কত পুরনো) আগে থেকেই আছে ([[supplier.ageing]]); এটা বলে কবে দিতে হবে।
 */
final class PaymentDueReport
{
    public const KEY = 'purchase.payment_due';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::definition());
    }

    public static function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            permission: 'purchase.report',
            title: 'purchase::due.title',
            filters: ['branch', 'supplier_id'],
            query: fn (array $f) => self::query($f),
            columns: [
                ['key' => 'supplier_code', 'label' => 'purchase::due.code', 'type' => ReportColumn::TEXT, 'width' => '7rem'],
                ['key' => 'supplier_name', 'label' => 'purchase::due.supplier', 'type' => ReportColumn::TEXT],
                ['key' => 'overdue', 'label' => 'purchase::due.overdue', 'type' => ReportColumn::MONEY],
                ['key' => 'due_today', 'label' => 'purchase::due.today', 'type' => ReportColumn::MONEY],
                ['key' => 'due_week', 'label' => 'purchase::due.week', 'type' => ReportColumn::MONEY],
                ['key' => 'due_month', 'label' => 'purchase::due.month', 'type' => ReportColumn::MONEY],
                ['key' => 'total_due', 'label' => 'purchase::due.total', 'type' => ReportColumn::MONEY],
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

        $bills = PurchaseBill::query()->posted()->withPaid()->toBase()
            ->where('pur_bills.company_id', $f['company_id'])
            ->tap(ReportEngine::branchWall($f, 'pur_bills.branch_id'))
            ->when($f['supplier_id'] ?? null, fn ($w, $id) => $w->where('pur_bills.supplier_id', (int) $id));

        // ⭐ পাকা ফেরতও বাদ — [[PurchaseBill::dueAmount()]] (ক্রয় ⚠️৬, ৬ অক্টোবর ২০২৬)
        $due = '(b.total - b.paid_total - b.voucher_paid_total - COALESCE(b.returned_total, 0))';
        $on = 'COALESCE(b.due_on, b.trx_date)';
        $name = app()->getLocale() === 'bn' ? "COALESCE(NULLIF(s.name_bn, ''), s.name_en)" : 's.name_en';

        return DB::query()
            ->fromSub($bills, 'b')
            ->join('suppliers as s', 's.id', '=', 'b.supplier_id')
            ->whereRaw("{$due} > 0.0001")
            ->groupBy('b.supplier_id', 's.code', 's.name_en', 's.name_bn')
            ->orderByRaw("SUM(CASE WHEN {$on} < {$now} THEN {$due} ELSE 0 END) DESC")
            ->orderByRaw("SUM({$due}) DESC")
            ->select([
                'b.supplier_id',
                's.code as supplier_code',
                DB::raw("{$name} as supplier_name"),
                DB::raw("SUM(CASE WHEN {$on} < {$now} THEN {$due} ELSE 0 END) as overdue"),
                DB::raw("SUM(CASE WHEN {$on} = {$now} THEN {$due} ELSE 0 END) as due_today"),
                DB::raw("SUM(CASE WHEN {$on} > {$now} AND {$on} <= {$week} THEN {$due} ELSE 0 END) as due_week"),
                DB::raw("SUM(CASE WHEN {$on} > {$week} AND {$on} <= {$monthEnd} THEN {$due} ELSE 0 END) as due_month"),
                DB::raw("SUM({$due}) as total_due"),
            ]);
    }
}
