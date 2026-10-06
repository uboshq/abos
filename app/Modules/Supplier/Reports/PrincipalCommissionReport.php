<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * প্রিন্সিপালের কমিশন — প্রতিটা প্রিন্সিপালের এক সারি, তার নিজের চক্রে (মালিক, ৫ অক্টোবর ২০২৬)।
 *
 * ⓘ অঙ্ক আর নিয়ম [[PrincipalCommission]]-এ। ⛔ কেবল রিপোর্ট — খাতায় কিছু বসে না।
 *
 * ── কেন সারিগুলো PHP-তে, তারপর কোয়েরি ───────────────────────────────────
 * প্রতিটা সারির সময় আলাদা (একজনের ২৬–২৫, আরেকজনের ১–মাসশেষ), আর কমিশনের অঙ্ক bcmath-এ। ⓘ তাই সারিগুলো আগে গোনা
 * হয়, তারপর একটা স্থির `UNION ALL` কোয়েরি হয়ে ইঞ্জিনে যায় — যোগফল, খোঁজা, ছাপা, PDF, Excel সব ইঞ্জিনের নিজের পথে।
 * ⓘ টাকার ঘর `DECIMAL`-এ বাঁধা, যাতে যোগফলের SUM লেখায় নয়, দশমিকে হয়।
 */
final class PrincipalCommissionReport
{
    public const KEY = 'supplier.principal_commission';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::definition());
    }

    public static function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            // ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই ([[SupplierReportController]])
            permission: 'supplier.report',
            title: 'supplier::principal.title',
            // ⓘ `month` — যে মাসে চক্র শেষ (2026-10); খালি হলে প্রতিটা প্রিন্সিপালের চলতি চক্র
            filters: ['month', 'branch'],
            groupBy: 'supplier_id',
            // ⓘ শাখা একটা কলাম, আর প্রতিটা সারির সময় আলাদা — শাখা ধরে আলাদা টেবিলে ভাগের কিছু নেই
            splitByBranch: false,
            query: fn (array $f) => self::table(app(PrincipalCommission::class)->rows($f), (int) $f['company_id']),
            columns: [
                [
                    'key' => 'supplier_name',
                    'label' => 'supplier::principal.principal',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'party_type_literal',
                    'source_id' => 'supplier_id',
                ],
                ['key' => 'branch_name', 'label' => 'supplier::principal.branch'],
                ['key' => 'period', 'label' => 'supplier::principal.period', 'width' => '13rem'],
                ['key' => 'inflow', 'label' => 'supplier::principal.inflow', 'type' => ReportColumn::MONEY],
                ['key' => 'basis_rate', 'label' => 'supplier::principal.basis_rate'],
                [
                    'key' => 'commission',
                    'label' => 'supplier::principal.commission',
                    // ⓘ "আসল" ভিত্তিতে বিক্রি কেনা দামের নিচে গেলে কমিশন ঋণাত্মক — খালি বিয়োগ নয়, কথায় (৬ অক্টোবর ২০২৬)
                    'type' => ReportColumn::DR_CR,
                    'words' => ['supplier::principal.commission_earned', 'supplier::principal.commission_lost'],
                    'total' => true,
                ],
                ['key' => 'share', 'label' => 'supplier::principal.share', 'type' => ReportColumn::MONEY],
                ['key' => 'paid', 'label' => 'supplier::principal.paid', 'type' => ReportColumn::MONEY],
                [
                    'key' => 'balance',
                    'label' => 'supplier::principal.balance',
                    // ⓘ খালি বিয়োগ নয় — "দিতে হবে ৳…" বা "কোম্পানির কাছে পাব ৳…" ([[ReportColumn::signed()]])
                    'type' => ReportColumn::DR_CR,
                    'words' => ['supplier::principal.balance_to_pay', 'supplier::principal.balance_to_get'],
                    'total' => true,
                ],
            ],
        );
    }

    /**
     * গোনা সারিগুলো একটা কোয়েরি হয়ে — ফাঁকা হলে শূন্য সারির কোয়েরি।
     *
     * ⓘ প্রতিটা সারি তার নিজের সরবরাহকারীর সারিতে বাঁধা, কোম্পানিসহ — কোয়েরিটা কখনো "কোনো টেবিল নয়" থেকে আসে না,
     * আর অন্য কোম্পানির নম্বর এলে সারিটাই পড়ে না।
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function table(array $rows, int $company): Builder
    {
        $select = 'CAST(? AS UNSIGNED) as supplier_id, ? as supplier_name, ? as party_type_literal, ? as branch_name, '
            .'? as period, ? as period_from, ? as period_to, CAST(? AS DECIMAL(18,2)) as inflow, ? as basis_rate, '
            .'CAST(? AS DECIMAL(18,2)) as commission, CAST(? AS DECIMAL(18,2)) as share, CAST(? AS DECIMAL(18,2)) as paid, '
            .'CAST(? AS DECIMAL(18,2)) as balance';

        $keys = ['supplier_id', 'supplier_name', 'party_type_literal', 'branch_name', 'period', 'period_from', 'period_to',
            'inflow', 'basis_rate', 'commission', 'share', 'paid', 'balance'];

        if ($rows === []) {
            $empty = DB::table('suppliers')->where('company_id', $company)->whereRaw('1 = 0')
                ->selectRaw($select, array_fill(0, count($keys), null));

            return DB::query()->fromSub($empty, 'principals')->select('*');
        }

        $union = null;

        foreach ($rows as $row) {
            $one = DB::table('suppliers')->where('company_id', $company)->where('id', $row['supplier_id'])
                ->selectRaw($select, array_map(fn (string $k) => $row[$k], $keys));
            $union = $union === null ? $one : $union->unionAll($one);
        }

        return DB::query()->fromSub($union, 'principals')->select('*')->orderBy('supplier_name');
    }
}
