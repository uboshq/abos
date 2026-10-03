<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * কাস্টমার লেজার আর সাপ্লায়ার লেজার — মালিক, ৩ অক্টোবর ২০২৬: *"একাউন্টসে কাস্টমার লেজার দিতে হবে জরুরি"*।
 *
 * ── কোন প্রশ্নের উত্তর ──────────────────────────────────────────────────
 * একজন পক্ষের খাতা, তারিখ ধরে: শুরুর দিনের আগের জের (Opening balance), তারপর প্রতিটা সারি — তারিখ, কাগজের নম্বর
 * (পপ-আপে খোলে), বিবরণ, ডেবিট, ক্রেডিট আর চলমান জের — আর নিচে শেষ জের। ⓘ গ্রাহকের পাতার "লেনদেন" টেবিল একই
 * খাতা পড়ে, কিন্তু সেখানে না ছাপা যায়, না Excel, না তারিখ ধরে খোলা জের।
 *
 * ── ⭐ জের "(Dr) 250.79" / "(Cr) 22,958.21" — কখনো খালি বিয়োগ চিহ্ন নয় ────────────
 * মালিক, ৩ অক্টোবর: *"+- dile bujte kosto hobe"*। ⓘ গ্রাহকে (Dr) = আমাদের পাওনা, (Cr) = তাঁর অগ্রিম; সরবরাহকারীতে
 * (Cr) = আমাদের দেনা, (Dr) = আমাদের অগ্রিম — হিসাবের একই নিয়ম, তাই দুই লেজারে একই লেখা।
 *
 * ── ⓘ কেন জেরটা SQL-এ, ইঞ্জিনের `runningBalance` নয় ───────────────────────
 * ইঞ্জিনের চলমান জের সংখ্যা দেয় (ঋণাত্মক হলে বিয়োগ), আর শুরু করে শূন্য থেকে — তারিখের আগের জের জানে না।
 * এখানে খোলা জের নিজেই প্রথম সারি (UNION), আর জের `SUM(…) OVER (…)` দিয়ে গোটা ফলের উপর গোনা — তাই দ্বিতীয়
 * পাতাতেও ঠিক, আর খোঁজার শব্দ দিলেও সারির জের বদলায় না। লেখাটা কলামের ধরন থেকে ([[ReportColumn::DR_CR]] →
 * [[Money::drCr()]]) — পর্দা, ছাপা, PDF ও Excel সব জায়গায় এক।
 *
 * ⛔ পক্ষ না বাছলে কিছুই দেখায় না (খোলা জের ০) — প্রকল্প-খতিয়ানের একই নিয়ম: গোটা খাতা "লেজার" নয়।
 * ⓘ শাখা: হেডারে বাছা শাখা ([[ReportEngine::branchWall()]]) — খোলা জের আর সারি দুইটাতেই, তাই জের মেলে।
 */
final class PartyLedgerReports
{
    public const CUSTOMER = 'accounts.customer_ledger';

    public const SUPPLIER = 'accounts.supplier_ledger';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::ledger(self::CUSTOMER, 'customer', 'customer_id', 'accounts::party_ledger.customer_title'));
        $engine->register(self::ledger(self::SUPPLIER, 'supplier', 'supplier_id', 'accounts::party_ledger.supplier_title'));
    }

    private static function ledger(string $key, string $partyType, string $filter, string $title): ReportDefinition
    {
        return new ReportDefinition(
            key: $key,
            // ⛔ ওয়েবের দরজা যে চাবি দেখে, সেটাই — বাকি হিসাবের রিপোর্টের মতো
            permission: 'accounts.report',
            title: $title,
            filters: ['date_range', 'branch', $filter],
            // ⓘ চলমান জের একটাই ধারা — শাখা ধরে ভাগ করলে জের ভাঙত (ইঞ্জিন `runningBalance`-এ নিজেই বন্ধ রাখে; এখানে জের SQL-এ)
            splitByBranch: false,
            query: fn (array $f) => self::query($f, $partyType, (int) ($f[$filter] ?? 0)),
            // ⓘ শেষ জের = সব ডেবিট − সব ক্রেডিট, খোলা জেরের সারিসহ — শেষ সারির জেরের সমান
            summary: function (array $totals): array {
                $net = bcsub((string) ($totals['debit'] ?? '0'), (string) ($totals['credit'] ?? '0'), 4);

                return ['label' => __('accounts::party_ledger.closing'), 'value' => $net, 'text' => Money::drCr($net), 'good' => true];
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
                ['key' => 'debit', 'label' => 'core.table.debit', 'type' => ReportColumn::MONEY],
                ['key' => 'credit', 'label' => 'core.table.credit', 'type' => ReportColumn::MONEY],
                // ⓘ সংখ্যাটাই থাকে, দেখায় "(Dr) 250.79" ([[ReportColumn::DR_CR]]); যোগফল অর্থহীন, শেষ জের উপরের সারাংশে
                ['key' => 'balance', 'label' => 'core.table.balance', 'type' => ReportColumn::DR_CR, 'width' => '10rem'],
            ],
        );
    }

    /** @param  array<string, mixed>  $f */
    private static function query(array $f, string $partyType, int $partyId): Builder
    {
        $party = fn () => DB::table('ledger_entries')
            ->where('company_id', $f['company_id'])
            ->where('party_type', $partyType)
            ->where('party_id', $partyId)
            ->tap(ReportEngine::branchWall($f, 'branch_id'));

        $pdo = DB::getPdo();
        $net = 'COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0)';

        // ⓘ খোলা জের — শুরুর দিনের আগের সব; ধনাত্মক হলে ডেবিটে, ঋণাত্মক হলে ক্রেডিটে, যাতে যোগফল আর জের দুইটাই মেলে
        $opening = $party()
            ->where('trx_date', '<', $f['from'])
            ->selectRaw(
                $pdo->quote((string) $f['from']).' as trx_date, NULL as document_no, '
                .$pdo->quote((string) __('accounts::party_ledger.opening')).' as narration, '
                ."GREATEST({$net}, 0) as debit, GREATEST(-({$net}), 0) as credit, "
                .'NULL as source_type, NULL as source_id, 0 as sort, 0 as id'
            );

        $lines = $party()
            ->whereBetween('trx_date', [$f['from'], $f['to']])
            ->select(['trx_date', 'document_no', 'narration', 'debit', 'credit', 'source_type', 'source_id'])
            ->selectRaw('1 as sort, id');

        $running = DB::query()
            ->fromSub($opening->unionAll($lines), 'l')
            ->select('l.*')
            ->selectRaw('SUM(l.debit - l.credit) OVER (ORDER BY l.sort, l.trx_date, l.id ROWS UNBOUNDED PRECEDING) as balance');

        // ⓘ বাইরের মোড়ক — জের গোটা ফলের উপর গোনা হয়ে গেছে, তাই পাতা ভাগ বা খোঁজা সারির জের বদলায় না
        return DB::query()
            ->fromSub($running, 'r')
            // ⓘ পক্ষ না বাছলে শূন্যের খোলা জেরের সারিটাও নয় — প্রশ্নটাই এখনো করা হয়নি
            ->when($partyId === 0, fn ($q) => $q->whereRaw('1 = 0'))
            ->orderBy('r.sort')
            ->orderBy('r.trx_date')
            ->orderBy('r.id');
    }
}
