<?php

declare(strict_types=1);

namespace App\Modules\Finance\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\YearEndService;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\Withdrawal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ মূলধন ও বিনিয়োগের রিপোর্ট — অর্থ-মডিউলের পরিকল্পনা, অংশ ২ (৫ অক্টোবর ২০২৬, মালিকের অনুমোদিত ক্রম, সমন্বয়কের মারফত):
 *
 *   ক মালিকানার পরিবর্তনের বিবরণী (IAS 1) — শুরু + নতুন মূলধন + শুরুর মূলধন + লাভ থেকে − উত্তোলন = শেষ, জন ধরে, শাখা
 *     ধরে, আর কোম্পানির মোট ([[CHANGES]])
 *   খ একজনের মূলধনের খাতা — চলমান জেরসহ ([[LEDGER]])
 *   ঘ মূলধনের আয় (ROI) — শাখা ধরে আর কোম্পানির ([[RETURN]])
 *   ঙ রেজিস্টার বনাম খাতা — শাখা ধরে, মূলধন (৩১০০) আর উত্তোলন (৩২০০) ([[RECONCILE]])
 *
 * ── ⭐ একটাই উৎস ─────────────────────────────────────────────────────────────
 * প্রতিটা সংখ্যা [[rows()]] থেকে — মূলধনের রেজিস্টার ([[CapitalService::positions()]]-এর হুবহু নিয়ম: পাকা, যে রসিদে
 * এসেছিল সেটা বাতিল নয়), মূলধন তোলা (কেবল `drawing`, [[CapitalService::withdrawnBy()]]-এর হুবহু), আর খাতা।
 *
 * ── ⛔ বিবরণীর শেষ জের = ৩১০০ − ৩২০০, হুবহু (সমন্বয়কের সিদ্ধান্ত, ৫ অক্টোবর ২০২৬) ──────────────────────
 * রেজিস্টারে না-ওঠা খাতার টাকা (জাবেদায় সরাসরি ৩১০০/৩২০০, নিজের ব্যবহারে মাল, আমানতের উত্তোলন) একটা আলাদা সারিতে
 * — "খাতায়, কারও নামে নয়"। তাই জন + নামহীন সারির যোগফল সবসময় খাতার সমান, আর নামহীন সারিটাই বলে রেজিস্টার কত পিছিয়ে।
 * ⓘ প্রদেয় মুনাফা (২১৯০) মালিকানা নয় — বাদ। অবণ্টিত মুনাফা (৩১০০/৩২০০ ছাড়া বাকি মালিকানা খাত, আর বছর-না-বন্ধ আয় −
 * খরচ) কোম্পানির নিজের সারিতে; বছর বন্ধের দাখিলা বাদ, কারণ ওটা এই সারির ভেতরেই এক পকেট থেকে আরেক পকেটে।
 */
final class CapitalReports
{
    public const CHANGES = 'finance.capital_changes';

    public const LEDGER = 'finance.capital_ledger';

    public const RETURN = 'finance.capital_return';

    public const RECONCILE = 'finance.capital_reconcile';

    // ⛔ মূলধনের পাতা যে চাবি দেখে, সেটাই — সব কয়টার
    private const KEY = 'finance.capital.view';

    /** সারির ধরন — জন, খাতায় নামহীন, অবণ্টিত মুনাফা; বিবরণীতে এই ক্রমে */
    private const PERSON = 0;

    private const NAMELESS = 1;

    private const RETAINED = 2;

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::changes());
        $engine->register(self::ledger());
        $engine->register(self::returns());
        $engine->register(self::reconcile());
    }

    /**
     * মালিকানার সব সারি: line, person_id, branch_id, trx_date, col (`new` / `opening` / `profit` / `drawing` / `other`),
     * amount (ধনাত্মক = সেই ঘরে বাড়ল), আর কাগজের নম্বর।
     *
     * ⓘ `$withBooks` — খাতার সারি (নামহীন আর অবণ্টিত); একজনের খাতায় লাগে না।
     *
     * @param  array<string, mixed>  $f
     */
    public static function rows(array $f, bool $withBooks = true): Builder
    {
        $company = (int) $f['company_id'];
        $pdo = DB::getPdo();
        $cancelled = $pdo->quote(DocumentStatus::CANCELLED);
        $col = 'CASE ce.in_kind WHEN '.$pdo->quote(CapitalEntry::OPENING)." THEN 'opening' WHEN "
            .$pdo->quote(CapitalEntry::PROFIT)." THEN 'profit' ELSE 'new' END";

        // ⓘ রেজিস্টার — `$line` আর `$sign` বদলে নামহীন সারিতে বিয়োগ হিসেবেও বসে
        $capital = fn (int $line, string $sign, string $column) => DB::table('acc_capital_entries as ce')
            ->leftJoin('vouchers as v', 'v.id', '=', 'ce.voucher_id')
            ->where('ce.company_id', $company)
            ->where('ce.status', CapitalEntry::POSTED)
            ->whereRaw("(ce.voucher_id IS NULL OR v.status <> {$cancelled})")
            ->tap(ReportEngine::branchWall($f, 'ce.branch_id'))
            ->selectRaw("{$line} as line, ".($line === self::PERSON ? 'ce.person_id' : 'NULL').' as person_id, ce.branch_id as branch_id, '
                ."ce.trx_date as trx_date, {$column} as col, {$sign}ce.amount as amount, ce.document_no as document_no, "
                .'ce.narration as narration, '.$pdo->quote(CapitalEntry::drillSourceType()).' as source_type, ce.id as source_id, ce.id as id');

        $drawing = fn (int $line, string $sign) => DB::table('fin_withdrawals as w')
            ->where('w.company_id', $company)
            ->where('w.status', DocumentStatus::CONFIRMED)
            ->where('w.kind', Withdrawal::DRAWING)
            ->tap(ReportEngine::branchWall($f, 'w.branch_id'))
            ->selectRaw("{$line} as line, ".($line === self::PERSON ? 'w.person_id' : 'NULL').' as person_id, w.branch_id as branch_id, '
                ."w.trx_date as trx_date, 'drawing' as col, {$sign}w.amount as amount, w.document_no as document_no, "
                .'w.reason as narration, '.$pdo->quote(Withdrawal::drillSourceType()).' as source_type, w.id as source_id, w.id + 500000000 as id');

        $all = $capital(self::PERSON, '', $col)->unionAll($drawing(self::PERSON, ''));

        if ($withBooks) {
            $heads = self::heads();
            $closing = YearEndService::closingSources();

            $books = fn (array $accounts, int $line, string $column, string $amount, bool $skipClosing) => DB::table('ledger_entries as le')
                ->where('le.company_id', $company)
                ->whereIn('le.account_id', $accounts ?: [0])
                ->when($skipClosing, fn ($q) => $q->where(fn ($w) => $w->whereNull('le.source_type')->orWhereNotIn('le.source_type', $closing)))
                ->tap(ReportEngine::branchWall($f, 'le.branch_id'))
                ->selectRaw("{$line} as line, NULL as person_id, le.branch_id as branch_id, le.trx_date as trx_date, '{$column}' as col, "
                    ."{$amount} as amount, le.document_no as document_no, le.narration as narration, le.source_type as source_type, "
                    .'le.source_id as source_id, le.id + 1000000000 as id');

            $all = $all
                // ⓘ নামহীন = খাতা − রেজিস্টার; শুরুর আর লাভের মূলধন খাতায় আলাদা চেনা যায় না, তাই সবই "নতুন"-এর ঘরে
                ->unionAll($books($heads['capital'], self::NAMELESS, 'new', 'le.credit - le.debit', false))
                ->unionAll($capital(self::NAMELESS, '-', "'new'"))
                ->unionAll($books($heads['drawings'], self::NAMELESS, 'drawing', 'le.debit - le.credit', false))
                ->unionAll($drawing(self::NAMELESS, '-'))
                // ⓘ অবণ্টিত মুনাফা — বছরের আয় − খরচ লাভের ঘরে, বাকি মালিকানা খাতের নড়াচড়া (বণ্টন, শুরুর জের) "অন্যান্য"-তে
                ->unionAll($books($heads['results'], self::RETAINED, 'profit', 'le.credit - le.debit', true))
                ->unionAll($books($heads['retained'], self::RETAINED, 'other', 'le.credit - le.debit', true));
        }

        return DB::query()->fromSub($all, 'u');
    }

    /**
     * খাতগুলো — মূলধন (৩১০০ আর নিচের), উত্তোলন (৩২০০ আর নিচের), বাকি মালিকানা, আর আয়-খরচ।
     *
     * @return array{capital: list<int>, drawings: list<int>, retained: list<int>, results: list<int>}
     */
    public static function heads(): array
    {
        $tree = fn (string $code): array => array_map('intval', StandardChart::find($code)?->selfAndDescendants()->modelKeys() ?? []);

        $capital = $tree(StandardChart::OWNER_CAPITAL);
        $drawings = $tree(StandardChart::DRAWINGS);

        return [
            'capital' => $capital,
            'drawings' => $drawings,
            'retained' => array_map('intval', Account::query()->postable()->where('type', Account::EQUITY)
                ->whereNotIn('id', [...$capital, ...$drawings, 0])->pluck('id')->all()),
            'results' => array_map('intval', Account::query()->postable()->whereIn('type', [Account::INCOME, Account::EXPENSE])->pluck('id')->all()),
        ];
    }

    /**
     * ⭐ ক — মালিকানার পরিবর্তনের বিবরণী।
     *
     * প্রতিটা জন: শুরুর জের (দিনের আগের সব), সময়ের মধ্যে নতুন মূলধন, শুরুর মূলধন (খোলা জের), লাভ থেকে মূলধন, তোলা,
     * আর শেষ জের। ⓘ শাখা ধরে ভাগ ইঞ্জিন করে (প্রতিটা শাখার নিজের অংশ আর মোট); "সব শাখা"-য় কোম্পানির মোট।
     */
    private static function changes(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::CHANGES,
            permission: self::KEY,
            title: 'finance::capital_report.changes_title',
            filters: ['date_range'],
            query: function (array $f): Builder {
                $from = DB::getPdo()->quote((string) $f['from']);
                $in = fn (string $col) => "COALESCE(SUM(CASE WHEN u.trx_date >= {$from} AND u.col = '{$col}' THEN u.amount ELSE 0 END), 0)";
                $signed = "CASE WHEN u.col = 'drawing' THEN -u.amount ELSE u.amount END";

                return DB::query()
                    ->fromSub(self::rows($f)->where('u.trx_date', '<=', $f['to']), 'u')
                    ->leftJoin('mdm_people as p', 'p.id', '=', 'u.person_id')
                    ->groupBy('u.line', 'u.person_id')
                    // ⓘ সব ঘর শূন্য হলে সারি নয় — নামহীন সারি কেবল রেজিস্টার পিছিয়ে থাকলে
                    ->havingRaw('opening_balance <> 0 OR new_capital <> 0 OR opening_capital <> 0 OR from_profit <> 0 OR drawings <> 0 OR other_moves <> 0 OR closing_balance <> 0')
                    ->selectRaw(self::whoSql().' as person_name, '
                        ."COALESCE(SUM(CASE WHEN u.trx_date < {$from} THEN {$signed} ELSE 0 END), 0) as opening_balance, "
                        .$in('new').' as new_capital, '.$in('opening').' as opening_capital, '.$in('profit').' as from_profit, '
                        .$in('drawing').' as drawings, '.$in('other').' as other_moves, '
                        ."COALESCE(SUM({$signed}), 0) as closing_balance")
                    ->orderBy('u.line')
                    ->orderByRaw('MIN('.self::nameSql().')');
            },
            summary: fn (array $totals): array => [
                'label' => __('finance::capital_report.changes_summary'),
                'value' => (string) ($totals['closing_balance'] ?? '0'),
                'text' => Money::format((string) ($totals['closing_balance'] ?? '0')),
                'good' => true,
            ],
            columns: [
                ['key' => 'person_name', 'label' => 'finance::capital_report.person'],
                ['key' => 'opening_balance', 'label' => 'finance::capital_report.opening_balance', 'type' => ReportColumn::MONEY],
                ['key' => 'new_capital', 'label' => 'finance::capital_report.new_capital', 'type' => ReportColumn::MONEY],
                ['key' => 'opening_capital', 'label' => 'finance::capital_report.opening_capital', 'type' => ReportColumn::MONEY],
                ['key' => 'from_profit', 'label' => 'finance::capital_report.from_profit', 'type' => ReportColumn::MONEY],
                ['key' => 'drawings', 'label' => 'finance::capital_report.drawings', 'type' => ReportColumn::MONEY],
                ['key' => 'other_moves', 'label' => 'finance::capital_report.other_moves', 'type' => ReportColumn::MONEY],
                ['key' => 'closing_balance', 'label' => 'finance::capital_report.closing_balance', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * ⭐ খ — একজনের মূলধনের খাতা: খোলা জের, প্রতিটা মূলধন (ক্রেডিট) আর তোলা (ডেবিট), চলমান জের।
     *
     * ⓘ চিহ্ন খাতার মতো: মূলধন ক্রেডিট, তাই জমা থাকা মূলধন (Cr); বেশি তুললে (Dr) — তিনি ব্যবসার কাছে ধারেন।
     */
    private static function ledger(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::LEDGER,
            permission: self::KEY,
            title: 'finance::capital_report.ledger_title',
            filters: ['date_range', 'branch', 'person_id'],
            // ⓘ চলমান জের একটাই ধারা — শাখা ধরে ভাগ করলে জের ভাঙত
            splitByBranch: false,
            query: function (array $f): Builder {
                $pdo = DB::getPdo();
                $personId = (int) ($f['person_id'] ?? 0);
                $all = fn () => self::rows($f, withBooks: false)->where('u.person_id', $personId);
                $debit = "CASE WHEN u.col = 'drawing' THEN u.amount ELSE 0 END";
                $credit = "CASE WHEN u.col = 'drawing' THEN 0 ELSE u.amount END";
                $net = "COALESCE(SUM({$debit}), 0) - COALESCE(SUM({$credit}), 0)";

                // ⓘ খোলা জের — শুরুর দিনের আগের সব; যে দিকে জের, সেই ঘরে, যাতে যোগফল আর জের মেলে
                $opening = $all()
                    ->where('u.trx_date', '<', $f['from'])
                    ->selectRaw($pdo->quote((string) $f['from']).' as trx_date, NULL as document_no, '
                        .$pdo->quote((string) __('finance::capital_report.opening_row')).' as narration, '
                        ."GREATEST({$net}, 0) as debit, GREATEST(-({$net}), 0) as credit, "
                        .'NULL as source_type, NULL as source_id, 0 as sort, 0 as id');

                $kind = 'CASE u.col WHEN '.$pdo->quote('drawing').' THEN '.$pdo->quote((string) __('finance::capital_report.kind_drawing'))
                    .' WHEN '.$pdo->quote('opening').' THEN '.$pdo->quote((string) __('finance::field.in_kind_opening'))
                    .' WHEN '.$pdo->quote('profit').' THEN '.$pdo->quote((string) __('finance::field.in_kind_profit'))
                    .' ELSE '.$pdo->quote((string) __('finance::capital_report.kind_capital')).' END';

                $lines = $all()
                    ->whereBetween('u.trx_date', [$f['from'], $f['to']])
                    ->selectRaw("u.trx_date, u.document_no, COALESCE(NULLIF(u.narration, ''), {$kind}) as narration, "
                        ."{$debit} as debit, {$credit} as credit, u.source_type, u.source_id, 1 as sort, u.id");

                $running = DB::query()
                    ->fromSub($opening->unionAll($lines), 'l')
                    ->select('l.*')
                    ->selectRaw('SUM(l.debit - l.credit) OVER (ORDER BY l.sort, l.trx_date, l.id ROWS UNBOUNDED PRECEDING) as balance');

                return DB::query()
                    ->fromSub($running, 'r')
                    // ⓘ মানুষ না বাছলে খোলা জেরের সারিটাও নয় — প্রশ্নটাই এখনো করা হয়নি
                    ->when($personId === 0, fn ($q) => $q->whereRaw('1 = 0'))
                    ->orderBy('r.sort')
                    ->orderBy('r.trx_date')
                    ->orderBy('r.id');
            },
            summary: function (array $totals): array {
                $net = bcsub((string) ($totals['debit'] ?? '0'), (string) ($totals['credit'] ?? '0'), 4);

                return ['label' => __('finance::capital_report.closing_balance'), 'value' => $net, 'text' => Money::drCr($net), 'good' => true];
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
                ['key' => 'debit', 'label' => 'finance::capital_report.drawings', 'type' => ReportColumn::MONEY],
                ['key' => 'credit', 'label' => 'finance::capital_report.kind_capital', 'type' => ReportColumn::MONEY],
                ['key' => 'balance', 'label' => 'core.table.balance', 'type' => ReportColumn::DR_CR, 'width' => '10rem'],
            ],
        );
    }

    /**
     * ⭐ ঘ — মূলধনের আয়: শাখা ধরে শুরুর আর শেষের মূলধন (৩১০০ − ৩২০০, খাতা থেকে), গড়, সময়ের লাভ (আয় − খরচ, বছর
     * বন্ধের দাখিলা ছাড়া), আর লাভ ÷ গড় মূলধন।
     *
     * ⓘ কোম্পানির আয়ের হার সারিগুলোর গড় নয় — মোট লাভ ÷ মোট গড় মূলধন, নিচের সারাংশে। গড় মূলধন শূন্য বা ঋণাত্মক হলে হার
     * নেই (মূলধন ছাড়া শাখা — টাকা অন্য শাখা থেকে চলে)।
     */
    private static function returns(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::RETURN,
            permission: self::KEY,
            title: 'finance::capital_report.return_title',
            filters: ['date_range'],
            // ⓘ সারিগুলোই শাখা
            splitByBranch: false,
            query: function (array $f): Builder {
                $heads = self::heads();
                $from = DB::getPdo()->quote((string) $f['from']);
                $capital = implode(',', [...$heads['capital'], 0]);
                $drawings = implode(',', [...$heads['drawings'], 0]);
                $results = implode(',', [...$heads['results'], 0]);
                $equity = "CASE WHEN le.account_id IN ({$capital}, {$drawings}) THEN le.credit - le.debit ELSE 0 END";
                $closing = YearEndService::closingSources();

                $start = "COALESCE(SUM(CASE WHEN le.trx_date < {$from} THEN {$equity} ELSE 0 END), 0)";
                $end = "COALESCE(SUM({$equity}), 0)";
                $profit = "COALESCE(SUM(CASE WHEN le.trx_date >= {$from} AND le.account_id IN ({$results}) THEN le.credit - le.debit ELSE 0 END), 0)";

                return DB::table('ledger_entries as le')
                    ->leftJoin('branches as b', 'b.id', '=', 'le.branch_id')
                    ->where('le.company_id', $f['company_id'])
                    ->where('le.trx_date', '<=', $f['to'])
                    ->whereIn('le.account_id', [...$heads['capital'], ...$heads['drawings'], ...$heads['results'], 0])
                    ->where(fn ($w) => $w->whereNull('le.source_type')->orWhereNotIn('le.source_type', $closing))
                    ->tap(ReportEngine::branchWall($f, 'le.branch_id'))
                    ->groupBy('le.branch_id')
                    ->havingRaw('capital_start <> 0 OR capital_end <> 0 OR profit <> 0')
                    ->selectRaw(self::branchSql().' as branch_name, '
                        ."{$start} as capital_start, {$end} as capital_end, ({$start} + {$end}) / 2 as capital_average, {$profit} as profit, "
                        ."CASE WHEN ({$start} + {$end}) > 0 THEN ROUND({$profit} * 200 / ({$start} + {$end}), 2) ELSE NULL END as return_pct")
                    ->orderByRaw('le.branch_id IS NULL')
                    ->orderBy('branch_name');
            },
            summary: function (array $totals): array {
                $average = bcadd((string) ($totals['capital_average'] ?? '0'), '0', 4);
                $profit = bcadd((string) ($totals['profit'] ?? '0'), '0', 4);
                // ⓘ সারির মতোই অর্ধেক উপরে গোল (SQL `ROUND`) — কেটে ফেললে সারাংশ সারির চেয়ে এক পয়সা কম বলত
                $rate = bccomp($average, '0', 4) > 0 ? Money::round(bcdiv(bcmul($profit, '100', 8), $average, 8), 2) : null;

                return [
                    'label' => __('finance::capital_report.return_summary'),
                    'value' => $rate ?? '0',
                    'text' => $rate === null
                        ? __('finance::capital_report.return_none')
                        : __('finance::capital_report.return_text', ['rate' => $rate, 'profit' => Money::format($profit), 'average' => Money::format($average)]),
                    'good' => $rate !== null && bccomp($rate, '0', 2) >= 0,
                ];
            },
            columns: [
                ['key' => 'branch_name', 'label' => 'finance::capital_report.branch'],
                ['key' => 'capital_start', 'label' => 'finance::capital_report.capital_start', 'type' => ReportColumn::MONEY],
                ['key' => 'capital_end', 'label' => 'finance::capital_report.capital_end', 'type' => ReportColumn::MONEY],
                ['key' => 'capital_average', 'label' => 'finance::capital_report.capital_average', 'type' => ReportColumn::MONEY],
                ['key' => 'profit', 'label' => 'finance::capital_report.profit', 'type' => ReportColumn::MONEY],
                ['key' => 'return_pct', 'label' => 'finance::capital_report.return_pct', 'type' => ReportColumn::PERCENT],
            ],
        );
    }

    /**
     * ⭐ ঙ — রেজিস্টার বনাম খাতা, শাখা ধরে, একটা দিন পর্যন্ত: মূলধনের রেজিস্টার বনাম ৩১০০, তোলার রেজিস্টার বনাম ৩২০০।
     *
     * ⓘ ফাঁক ধনাত্মক = খাতায় বেশি, রেজিস্টারে কম। মূলধনের ফাঁক মালিকের নামে ভরা যায় ([[OwnerCapital::reconcile()]]);
     * তোলার ফাঁক সাধারণত নিজের ব্যবহারে মাল বা আমানত থেকে তোলা — খাতায় আছে, কারও নামে নয়।
     */
    private static function reconcile(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::RECONCILE,
            permission: self::KEY,
            title: 'finance::capital_report.reconcile_title',
            filters: ['date_range'],
            asOfDate: true,
            // ⓘ সারিগুলোই শাখা
            splitByBranch: false,
            query: function (array $f): Builder {
                $sum = fn (int $line, string $col) => "COALESCE(SUM(CASE WHEN u.line = {$line} AND u.col IN ({$col}) THEN u.amount ELSE 0 END), 0)";
                $capital = "'new', 'opening', 'profit'";

                // ⓘ নামহীন সারি = খাতা − রেজিস্টার, তাই খাতা = রেজিস্টার + নামহীন
                $register = $sum(self::PERSON, $capital);
                $books = "({$register} + ".$sum(self::NAMELESS, $capital).")";
                $drawn = $sum(self::PERSON, "'drawing'");
                $drawBooks = "({$drawn} + ".$sum(self::NAMELESS, "'drawing'").")";

                return DB::query()
                    ->fromSub(self::rows($f)->where('u.trx_date', '<=', $f['to'])->whereIn('u.line', [self::PERSON, self::NAMELESS]), 'u')
                    ->leftJoin('branches as b', 'b.id', '=', 'u.branch_id')
                    ->groupBy('u.branch_id')
                    ->havingRaw('register_capital <> 0 OR books_capital <> 0 OR register_drawings <> 0 OR books_drawings <> 0')
                    ->selectRaw(self::branchSql().' as branch_name, '
                        ."{$register} as register_capital, {$books} as books_capital, {$books} - {$register} as capital_gap, "
                        ."{$drawn} as register_drawings, {$drawBooks} as books_drawings, {$drawBooks} - {$drawn} as drawings_gap")
                    ->orderByRaw('u.branch_id IS NULL')
                    ->orderBy('branch_name');
            },
            summary: function (array $totals): array {
                $capital = bcadd((string) ($totals['capital_gap'] ?? '0'), '0', 4);
                $drawings = bcadd((string) ($totals['drawings_gap'] ?? '0'), '0', 4);

                return [
                    'label' => __('finance::capital_report.reconcile_summary'),
                    'value' => $capital,
                    'text' => __('finance::capital_report.reconcile_text', ['capital' => Money::format($capital), 'drawings' => Money::format($drawings)]),
                    'good' => bccomp($capital, '0', 4) === 0 && bccomp($drawings, '0', 4) === 0,
                ];
            },
            columns: [
                ['key' => 'branch_name', 'label' => 'finance::capital_report.branch'],
                ['key' => 'register_capital', 'label' => 'finance::capital_report.register_capital', 'type' => ReportColumn::MONEY],
                ['key' => 'books_capital', 'label' => 'finance::capital_report.books_capital', 'type' => ReportColumn::MONEY],
                ['key' => 'capital_gap', 'label' => 'finance::capital_report.gap', 'type' => ReportColumn::MONEY],
                ['key' => 'register_drawings', 'label' => 'finance::capital_report.register_drawings', 'type' => ReportColumn::MONEY],
                ['key' => 'books_drawings', 'label' => 'finance::capital_report.books_drawings', 'type' => ReportColumn::MONEY],
                ['key' => 'drawings_gap', 'label' => 'finance::capital_report.gap', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /** সারির নাম — জনের নাম, নয়তো নামহীন বা অবণ্টিত মুনাফার সারি */
    private static function whoSql(): string
    {
        $pdo = DB::getPdo();

        return 'CASE u.line WHEN '.self::NAMELESS.' THEN '.$pdo->quote((string) __('finance::capital_report.nameless'))
            .' WHEN '.self::RETAINED.' THEN '.$pdo->quote((string) __('finance::capital_report.retained'))
            .' ELSE MIN('.self::nameSql().') END';
    }

    private static function nameSql(): string
    {
        return app()->getLocale() === 'bn' ? "COALESCE(NULLIF(p.name_bn, ''), p.name_en)" : 'p.name_en';
    }

    private static function branchSql(): string
    {
        $none = DB::getPdo()->quote((string) __('finance::message.branch_none'));
        $name = app()->getLocale() === 'bn' ? "COALESCE(NULLIF(MIN(b.name_bn), ''), MIN(b.name_en))" : 'MIN(b.name_en)';

        return "COALESCE({$name}, {$none})";
    }
}
