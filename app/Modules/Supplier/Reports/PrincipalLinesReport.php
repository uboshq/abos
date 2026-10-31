<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ প্রিন্সিপালের কমিশনের অঙ্কের উৎস — আদায় আর প্রিন্সিপালকে দেওয়ার খাতা-সারি (মালিক, ২৪ অক্টোবর ২০২৬: *"যেখানে চিহ্ন দিয়েছি,
 * সেখানে লিংক থাকার কথা, যাতে হিসাব কোথা থেকে এল বোঝা যায়"*)।
 *
 * ⓘ সারিগুলো [[PrincipalCommission::collectionLines()]] / [[PrincipalCommission::paymentLines()]] — রিপোর্টের অঙ্ক ঠিক এদেরই যোগফল,
 * তাই খোলা তালিকার মোট আর রিপোর্টের অঙ্ক পয়সা পর্যন্ত এক। ছাঁকনি: `principal` (একজন; না দিলে সবাই, প্রত্যেকে নিজের চক্রে —
 * মোটের সারির লিংক), `from` আর `to` (রিপোর্টের সারির চক্র), `month`, শাখা। ⛔ কেবল রিপোর্ট — খাতায় কিছু বসে না।
 */
final class PrincipalLinesReport
{
    public const COLLECTIONS = 'supplier.principal_collections';

    public const PAYMENTS = 'supplier.principal_payments';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::definition(self::COLLECTIONS, 'supplier::principal.lines_collections_title'));
        $engine->register(self::definition(self::PAYMENTS, 'supplier::principal.lines_payments_title'));
    }

    public static function definition(string $key, string $title): ReportDefinition
    {
        return new ReportDefinition(
            key: $key,
            permission: 'supplier.report',
            title: $title,
            // ⓘ `from`/`to` নিজের নামে, `date_range` নয় — ইঞ্জিন তখন খালি তারিখে মাস বসাত, আর "চক্র নিজের" মানেই হারাত
            filters: ['principal', 'from', 'to', 'month', 'branch'],
            // ⓘ প্রিন্সিপাল একজন সরবরাহকারী — ফোনের বাছাই সরবরাহকারীর তালিকা থেকে, মান সার্ভার আইডিতে বদলায় (fe, ৩১ অক্টোবর ২০২৬;
            //   [[TheReportFiltersOnlyTheWebCouldAskTest]]: আগে ফোনে "কেবল ওয়েবে")
            sources: ['principal' => \App\Modules\Supplier\Reports\Filters\SupplierFilter::class],
            splitByBranch: false,
            query: fn (array $f) => self::lines($key, $f),
            columns: [
                ['key' => 'trx_date', 'label' => 'supplier::principal.line_date', 'type' => ReportColumn::DATE],
                ['key' => 'principal_name', 'label' => 'supplier::principal.principal'],
                [
                    'key' => 'document_no',
                    'label' => 'supplier::principal.line_document',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type',
                    'source_id' => 'source_id',
                ],
                ['key' => 'party_name', 'label' => $key === self::COLLECTIONS ? 'supplier::principal.line_customer' : 'supplier::principal.principal', 'party' => 'party_key'],
                ['key' => 'account_name', 'label' => 'supplier::principal.line_account'],
                [
                    'key' => 'amount',
                    'label' => $key === self::COLLECTIONS ? 'supplier::principal.inflow' : 'supplier::principal.paid',
                    'type' => ReportColumn::MONEY,
                    'currency' => true,
                    // ⓘ অঙ্কে চাপলে যে কাগজের টাকা, সেটা
                    'drill' => ['source' => ['type' => 'source_type', 'id' => 'source_id'], 'label' => 'supplier::principal.drill_line'],
                ],
            ],
        );
    }

    /**
     * প্রতিটা প্রিন্সিপালের সারি, তার নিজের সময়ে — একটা স্থির `UNION ALL`; কেউ না থাকলে শূন্য সারির কোয়েরি।
     *
     * @param  array<string, mixed>  $f
     */
    public static function lines(string $key, array $f): Builder
    {
        $commission = app(PrincipalCommission::class);
        $company = (int) $f['company_id'];
        $bn = app()->getLocale() === 'bn';
        $union = null;

        foreach ($commission->principals($f) as $p) {
            [$from, $to] = $commission->cycleOf($p, $f);
            $name = PrincipalCommission::shortName($p->short_name, $p->name_bn, (string) $p->name_en, $bn);

            $one = $key === self::COLLECTIONS
                ? $commission->collectionLines($company, (int) $p->principal_branch_id, $from->toDateString(), $to->toDateString())
                : $commission->paymentLines($company, (int) $p->id, $from->toDateString(), $to->toDateString())
                    ->join('accounts as a', 'a.id', '=', 'm.account_id');

            $union = $union === null ? self::select($one, $key, $name, $bn) : $union->unionAll(self::select($one, $key, $name, $bn));
        }

        if ($union === null) {
            $union = self::select(DB::table('ledger_entries as m')->join('accounts as a', 'a.id', '=', 'm.account_id')
                ->where('m.company_id', $company)->whereRaw('1 = 0'), $key, '', $bn);
        }

        return DB::query()->fromSub($union, 'lines')->select('*')->orderBy('trx_date')->orderBy('entry_id');
    }

    private static function select(Builder $q, string $key, string $principal, bool $bn): Builder
    {
        // ⓘ কার কাছ থেকে — আদায়ে কাগজের গ্রাহক-সারি, দেওয়ায় প্রিন্সিপাল নিজে ([[ReportEngine::withParties()]] নাম বসায়)
        $party = $key === self::COLLECTIONS
            ? "(SELECT CONCAT(c.party_type, ':', c.party_id) FROM ledger_entries c WHERE c.company_id = m.company_id AND c.source_type = m.source_type"
                ." AND c.source_id = m.source_id AND c.party_type = '".PrincipalCommission::customerParty()."' ORDER BY c.id LIMIT 1)"
            : "CONCAT('".Supplier::drillSourceType()."', ':', m.party_id)";
        $account = $bn ? "COALESCE(NULLIF(a.name_bn, ''), a.name_en)" : 'a.name_en';

        return $q->selectRaw(
            'm.id as entry_id, m.trx_date, ? as principal_name, m.document_no, m.source_type, m.source_id, '
            .$party.' as party_key, NULL as party_name, CONCAT(a.code, \' — \', '.$account.') as account_name, '
            .'CAST(m.debit - m.credit AS DECIMAL(18,4)) as amount',
            [$principal],
        );
    }
}
