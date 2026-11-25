<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Accounts\Reports\CoreReports;
use App\Modules\MasterData\Models\Location;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ টাকা আসা — এই সময়ে আসল টাকা কত এল, আর কোন দিক থেকে (মালিক, ২৩ নভেম্বর ২০২৬; রোডম্যাপ §৩, রিপোর্ট ৩; অনুমোদিত নমুনা
 * claude.ai artifact 7jU3Q2Gz2WSGQp6Bt6QhSL সংস্করণ ২)।
 *
 * ── কোন টাকা ────────────────────────────────────────────────────────────────
 * হিসাবের "আদায়ের তালিকা"র হুবহু একই সারি ([[CoreReports::moneyIn()]]): নগদ, ব্যাংক আর মোবাইল ব্যাংকিংয়ের খাতে ডেবিট, কেবল রসিদের
 * কাগজে (RCV, পুরনো COL, পাশ হওয়া চেক)। ⛔ জাবেদা, ক্রেডিট নোট, কন্ট্রা, এক খাত থেকে আরেক খাতে নেওয়া, শুরুর জের, বাতিল বা উল্টানো
 * কাগজ গোনা হয় না — সেগুলো আলাদা রিপোর্টে, কারণসহ ([[EXCLUDED]], "বাদ গেছে")। দ্বিতীয় কোনো গণনা এখানে নেই।
 *
 * ── রূপ (পাতায় ট্যাব) ─────────────────────────────────────────────────────────
 * দিন · শাখা · এরিয়া › টেরিটরি › পয়েন্ট › গ্রাহক · বিক্রয়কর্মী · মাধ্যম (কোন খাতে) · রসিদ ধরে · বাদ গেছে।
 * প্রতিটা রূপে নগদ / ব্যাংক / মোবাইল ব্যাংকিং আলাদা ঘরে, আর যেকোনো অঙ্কে চাপলে সেই রসিদগুলো ([[PAPERS]]) — সেখান থেকে কাগজটা।
 * এলাকার স্তরে "মোট" চাপলে এক স্তর নিচে নামে; গ্রাহকে পৌঁছে রসিদ।
 *
 * ── কার নামে ────────────────────────────────────────────────────────────────
 * ⭐ দায়িত্বপ্রাপ্ত জন — ডিলারের বাঁধন → জায়গার সিঁড়ি → কোম্পানির ডিফল্ট জন, রসিদের তারিখে ([[ResponsibleSql]]); রসিদ যিনি লিখেছেন
 * তিনি নন। উপরওয়ালার দলের মোট [[SalespersonReports::teamsFor()]]-এর একই ভাগে (DSM-এর নিচে সবাই), সর্বমোট মানুষ ধরে একবার।
 *
 * ⓘ শাখা — খাতার সারির নিজের শাখা (টাকা যে শাখায় ঢুকেছে); হেডারে বাছা শাখা আর নাগাল ইঞ্জিন মানায়। বিক্রয়কর্মী কেবল নিজের ডিলার।
 * ⓘ গ্রাহক — একই কাগজের গ্রাহক-সারি থেকে (টাকার সারিতে পক্ষ থাকে না)। গ্রাহক ছাড়া আসা টাকা (যেমন অন্য আয়ের রসিদ) "গ্রাহক ছাড়া" সারিতে।
 */
final class MoneyInReports
{
    public const DAY = 'sales.money_in_day';

    public const BRANCH = 'sales.money_in_branch';

    public const PERSON = 'sales.money_in_person';

    public const CHANNEL = 'sales.money_in_channel';

    public const PAPERS = 'sales.money_in_papers';

    public const EXCLUDED = 'sales.money_in_excluded';

    public const EXCLUDED_PAPERS = 'sales.money_in_excluded_papers';

    /** ওয়েবের ঠিকানার শেষ অংশ — চাবি থেকে ([[SalesReportController::SLUGS]]-এ একই নাম) */
    public const SLUG = [
        self::DAY => 'money-in',
        self::BRANCH => 'money-in-branch',
        self::PERSON => 'money-in-salesperson',
        self::CHANNEL => 'money-in-channel',
        self::PAPERS => 'money-in-receipts',
        self::EXCLUDED => 'money-in-excluded',
        self::EXCLUDED_PAPERS => 'money-in-excluded-papers',
    ];

    private const KINDS = ['cash', 'bank', 'mfs'];

    private const FILTERS = ['date_range', 'branch', 'location_id', 'salesman_id', 'customer_id', 'account', 'kind'];

    /** এলাকার এক স্তরের রিপোর্টের চাবি — `sales.money_in_area`, `…_territory`, `…_point`, `…_customer` */
    public static function levelKey(string $level): string
    {
        return 'sales.money_in_'.$level;
    }

    public static function levelSlug(string $level): string
    {
        return 'money-in-'.$level;
    }

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::byDay());
        $engine->register(self::byBranch());

        foreach (SalesPlace::LEVELS as $level) {
            $engine->register(self::byLevel($level));
        }

        $engine->register(self::byPerson());
        $engine->register(self::byChannel());
        $engine->register(self::papers());
        $engine->register(self::excluded());
        $engine->register(self::excludedPapers());
    }

    /**
     * ⭐ আসা টাকার প্রতিটা সারি, রিপোর্টের সব রূপের জন্য এক জায়গায় — গ্রাহক, জায়গার সিঁড়ি আর দায়িত্বপ্রাপ্ত জনসহ।
     * ঘর: `trx_date, document_no, source_type, source_id, amount, branch_id, account_id, kind, channel, customer_id, customer,
     * area_id, area, area_branch_id, territory_id, territory, point_id, point, person_id`।
     *
     * @param  array<string, mixed>  $f
     */
    public static function rows(array $f): Builder
    {
        $company = (int) $f['company_id'];
        $channel = app()->getLocale() === 'bn' ? "COALESCE(NULLIF(accounts.name_bn, ''), accounts.name_en)" : 'accounts.name_en';

        // ⭐ আগে টাকার সারি আর তার গ্রাহক, তারপর জোড়া — জোড়ার শর্তে উপ-প্রশ্ন থাকলে প্রতিটা গ্রাহকে আবার চলত (লাইভ, ২৫ নভেম্বর ২০২৬)
        $money = CoreReports::moneyIn($f)
            ->when(! empty($f['account_id']), fn (Builder $q) => $q->where('ledger_entries.account_id', (int) $f['account_id']))
            ->when(in_array($f['kind'] ?? null, self::KINDS, true), fn (Builder $q) => $q->where('accounts.money_kind', $f['kind']))
            ->selectRaw('ledger_entries.id as entry_id, ledger_entries.trx_date as trx_date, ledger_entries.document_no as document_no, '
                .'ledger_entries.source_type as source_type, ledger_entries.source_id as source_id, ledger_entries.debit as amount, '
                .'ledger_entries.branch_id as branch_id, ledger_entries.account_id as account_id, accounts.money_kind as kind, '
                ."{$channel} as channel, ".self::customerOfPaper().' as payer_id');

        $inner = DB::query()->fromSub($money, 'mo')
            ->leftJoin('customers as cu', fn ($join) => $join->on('cu.id', '=', 'mo.payer_id')->whereNull('cu.deleted_at'))
            ->tap(fn (Builder $q) => SalesPlace::join($q))
            ->tap(ReportEngine::dealerWall($f, 'cu.id'))
            ->when(! empty($f['location_id']), fn (Builder $q) => SalesPlace::under($q, (int) $f['location_id']))
            ->when(! empty($f['customer_id']), fn (Builder $q) => $q->where('cu.id', (int) $f['customer_id']))
            ->selectRaw('mo.*, cu.id as customer_id, '.SalesPlace::named('cu').' as customer, '
                .SalesPlace::first(Location::AREA, 'id').' as area_id, '.SalesPlace::first(Location::AREA, 'name').' as area, '
                .SalesPlace::first(Location::AREA, 'branch_id').' as area_branch_id, '
                .SalesPlace::first(Location::TERRITORY, 'id').' as territory_id, '.SalesPlace::first(Location::TERRITORY, 'name').' as territory, '
                .SalesPlace::first(Location::POINT, 'id').' as point_id, '.SalesPlace::first(Location::POINT, 'name').' as point, '
                .ResponsibleSql::person($company, 'cu.id', 'mo.trx_date').' as person_id');

        return DB::query()->fromSub($inner, 'm')
            // ⓘ টেরিটরির ভাগ ([[territories()]]) — ০ মানে টেরিটরি বসানো নেই
            ->when(isset($f['territory']), fn (Builder $q) => $f['territory'] === 0 ? $q->whereNull('m.territory_id') : $q->where('m.territory_id', $f['territory']))
            ->when(! empty($f['salesman_id']), fn (Builder $q) => $q->where('m.person_id', (int) $f['salesman_id']))
            // ⓘ দলের ভাগ ([[SalespersonReports::teamsFor()]]) — এই জনেরা, বা এঁরা বাদে আর জন বসানো নেই এমন
            ->when(isset($f['team_ids']), fn (Builder $q) => $q->whereIn('m.person_id', $f['team_ids'] === [] ? [0] : $f['team_ids']))
            ->when(isset($f['team_outside']), fn (Builder $q) => $q->where(fn ($w) => $w->whereNull('m.person_id')
                ->when($f['team_outside'] !== [], fn ($n) => $n->orWhereNotIn('m.person_id', $f['team_outside']))));
    }

    /** একই কাগজের গ্রাহক — টাকার সারিতে পক্ষ থাকে না ([[CoreReports]]-এর `partyKey()`-র একই উপায়, কেবল গ্রাহক) */
    private static function customerOfPaper(): string
    {
        return '(SELECT MIN(pk.party_id) FROM ledger_entries AS pk WHERE pk.company_id = ledger_entries.company_id'
            .' AND pk.source_type = ledger_entries.source_type AND pk.source_id = ledger_entries.source_id'
            ." AND pk.party_type = 'customer' AND pk.party_id IS NOT NULL)";
    }

    /** রসিদের সংখ্যা আর তিন মাধ্যমের যোগ — সব ভাগের রিপোর্টের একই চার ঘর */
    private static function sums(): string
    {
        $kinds = implode(', ', array_map(
            fn (string $k) => "SUM(CASE WHEN m.kind = '{$k}' THEN m.amount ELSE 0 END) as {$k}", self::KINDS));

        return "COUNT(DISTINCT CONCAT(m.source_type, ':', m.source_id)) as receipts, {$kinds}, SUM(m.amount) as total";
    }

    /**
     * চার অঙ্কের ঘর — প্রতিটা অঙ্কে চাপলে সেই রসিদগুলো; `$next` থাকলে "মোট" এক স্তর নিচের রিপোর্ট খোলে।
     *
     * @param  array<string, string>  $row  সারি থেকে রসিদের তালিকার ছাঁকনি — ঠিকানার চাবি → সারির ঘর
     * @param  array{slug: string, params: array<string, string>}|null  $next
     * @return list<array<string, mixed>>
     */
    private static function figures(array $row, ?array $next = null): array
    {
        $asked = ['from' => '@from', 'to' => '@to', 'branch_id' => '@branch_id', 'location_id' => '@location_id',
            'salesman_id' => '@salesman_id', 'customer_id' => '@customer_id', 'account_id' => '@account_id', 'kind' => '@kind'];
        $papers = fn (array $more = []): array => [
            'route' => 'sales.report.show',
            'params' => ['slug' => '='.self::SLUG[self::PAPERS], ...$asked, ...$row, ...$more],
            'total' => ['slug' => '='.self::SLUG[self::PAPERS], ...$asked, ...$more],
            'label' => 'sales::money_in.drill_receipts',
        ];

        $columns = [['key' => 'receipts', 'label' => 'sales::money_in.receipts', 'type' => ReportColumn::QUANTITY, 'width' => '5rem']];

        foreach (self::KINDS as $kind) {
            $columns[] = ['key' => $kind, 'label' => 'sales::money_in.kind_'.$kind, 'type' => ReportColumn::MONEY, 'drill' => $papers(['kind' => '='.$kind])];
        }

        $columns[] = ['key' => 'total', 'label' => 'sales::money_in.total', 'type' => ReportColumn::MONEY,
            'drill' => $next === null ? $papers() : [
                'route' => 'sales.report.show',
                'params' => ['slug' => '='.$next['slug'], ...$asked, ...$next['params']],
                'total' => ['slug' => '='.$next['slug'], ...$asked],
                'label' => 'sales::money_in.drill_down',
            ]];

        return $columns;
    }

    /**
     * ⭐ মাথার সারাংশ — নমুনার পাঁচ ঘর: মোট টাকা এসেছে (বড় করে), পাশে নগদ, ব্যাংক, মোবাইল ব্যাংকিং, রসিদ আর দিনে গড় (মালিকের অনুমোদিত
     * নমুনা, ২৩ নভেম্বর ২০২৬)। ⓘ রিপোর্টের নিজের মোট থেকে — সারির যোগ নয়; দিনের গড় বাছা সময়ের দিন ধরে।
     *
     * @param  array<string, string>  $totals
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function summaryOf(array $totals, array $filters = []): array
    {
        $total = (string) ($totals['total'] ?? '0');
        $days = isset($filters['from'], $filters['to']) && empty($filters['all_time'])
            ? (int) \Illuminate\Support\Carbon::parse((string) $filters['from'])->diffInDays(\Illuminate\Support\Carbon::parse((string) $filters['to'])) + 1
            : 0;
        $lines = [];

        foreach (self::KINDS as $kind) {
            $lines[] = ['label' => (string) __('sales::money_in.kind_'.$kind), 'value' => (string) ($totals[$kind] ?? '0')];
        }

        if ($days > 0) {
            $lines[] = ['label' => (string) __('sales::money_in.per_day', ['days' => $days]), 'value' => bcdiv($total, (string) $days, 2)];
        }

        return [
            'label' => (string) __('sales::money_in.summary', ['receipts' => \App\Core\Support\Money::quantity((string) ($totals['receipts'] ?? '0'))]),
            'value' => $total,
            'good' => true,
            'lines' => $lines,
        ];
    }

    private static function define(string $key, string $title, \Closure $query, array $columns, ?string $groupBy = null, ?\Closure $sections = null, ?array $chart = null): ReportDefinition
    {
        return new ReportDefinition(
            key: $key,
            permission: 'sales.report',
            title: $title,
            filters: self::FILTERS,
            choices: ['kind' => array_combine(self::KINDS, array_map(fn (string $k) => 'sales::money_in.kind_'.$k, self::KINDS))],
            query: $query,
            columns: $columns,
            groupBy: $groupBy,
            rankBy: $groupBy === null ? null : 'total',
            // ⓘ শাখা খাতার সারির — নিজেই ছাঁকে; "সব শাখা"-তে শাখার ভাগ আলাদা রিপোর্ট ([[BRANCH]]), প্রতিটা রূপে আবার নয়
            splitByBranch: false,
            sections: $sections,
            chart: $chart,
            // ⭐ প্রতিটা রূপের মাথায় একই সারাংশ — মোট আর তিন মাধ্যম (রসিদ ধরে রূপে মাধ্যমের ঘর নেই, তাই কেবল সেখানে নয়)
            summary: $key === self::PAPERS ? null : fn (array $totals, array $filters = []): array => self::summaryOf($totals, $filters),
        );
    }

    /** ⭐ মোট এসেছে — ভাগের গ্রাফ (কম সারি) বা শীর্ষ দশ (অনেক সারি); নমুনার "শাখা/এরিয়া/বিক্রয়কর্মী ধরে" ঘর */
    private static function totalChart(string $label, string $type = 'bars'): array
    {
        return ['type' => $type, 'label' => $label, 'series' => ['total'], 'title' => 'sales::money_in.total'];
    }

    private static function byDay(): ReportDefinition
    {
        return self::define(self::DAY, 'sales::money_in.title_day',
            fn (array $f): Builder => self::rows($f)->groupBy('m.trx_date')
                ->selectRaw('m.trx_date as trx_date, '.self::sums())->orderBy('m.trx_date'),
            [
                ['key' => 'trx_date', 'label' => 'core.print.date', 'type' => ReportColumn::DATE, 'width' => '8rem'],
                ...self::figures(['from' => 'trx_date', 'to' => 'trx_date']),
            ],
            // ⭐ নমুনার "দিন ধরে" গ্রাফ — প্রতিদিন নগদ, ব্যাংক, মোবাইল ব্যাংকিং
            chart: ['type' => 'pair', 'label' => 'trx_date', 'series' => self::KINDS, 'title' => 'sales::money_in.tab_day']);
    }

    private static function byBranch(): ReportDefinition
    {
        return self::define(self::BRANCH, 'sales::money_in.title_branch',
            function (array $f): Builder {
                $none = DB::getPdo()->quote((string) __('sales::money_in.no_branch'));

                return self::rows($f)->leftJoin('branches as b', 'b.id', '=', 'm.branch_id')
                    ->groupBy('m.branch_id', 'b.name_bn', 'b.name_en')
                    ->selectRaw('m.branch_id as branch_id, COALESCE('.SalesPlace::named('b').", {$none}) as branch, ".self::sums())
                    ->orderByRaw('SUM(m.amount) DESC')->orderBy('m.branch_id');
            },
            [
                ['key' => 'branch', 'label' => 'sales::money_in.branch'],
                ...self::figures(['branch_id' => 'branch_id']),
            ], 'branch_id', chart: self::totalChart('branch', 'donut'));
    }

    /** এলাকার এক স্তর — এরিয়া, টেরিটরি, পয়েন্ট বা গ্রাহক ধরে; "মোট" এক স্তর নিচে নামে, গ্রাহকে রসিদ */
    private static function byLevel(string $level): ReportDefinition
    {
        $below = SalesPlace::below($level);
        $row = $level === SalesPlace::CUSTOMER ? ['customer_id' => 'place_id'] : ['location_id' => 'place_id'];

        return self::define(self::levelKey($level), 'sales::money_in.title_'.$level,
            function (array $f) use ($level): Builder {
                $pdo = DB::getPdo();
                $q = self::rows($f)->leftJoin('branches as ab', 'ab.id', '=', 'm.area_branch_id');
                // ⭐ এরিয়ার নাম "শাখা › এরিয়া" — ADI-র চার শাখার চার এরিয়ার একই নাম (মালিক, ২৩ নভেম্বর ২০২৬)
                $area = "CASE WHEN m.area_id IS NULL THEN NULL ELSE CONCAT_WS(' › ', ".SalesPlace::named('ab').', m.area) END';
                $q->groupBy('m.area_id', 'm.area', 'ab.name_bn', 'ab.name_en');

                [$id, $name, $parent, $none] = match ($level) {
                    Location::AREA => ['m.area_id', $area, 'NULL', 'no_place'],
                    Location::TERRITORY => ['m.territory_id', 'm.territory', $area, 'no_territory'],
                    Location::POINT => ['m.point_id', 'm.point', "CONCAT_WS(' · ', m.territory, {$area})", 'no_place'],
                    default => ['m.customer_id', 'm.customer', 'm.point', 'no_customer'],
                };

                match ($level) {
                    Location::AREA => null,
                    Location::TERRITORY => $q->groupBy('m.territory_id', 'm.territory'),
                    Location::POINT => $q->groupBy('m.point_id', 'm.point', 'm.territory'),
                    default => $q->groupBy('m.customer_id', 'm.customer', 'm.point', 'm.territory'),
                };

                // ⓘ টেরিটরি বসানো নেই এমন সারিতে চাপলে তার এরিয়ার পয়েন্টগুলো — নিচে নামার জায়গা এরিয়াই
                $drill = $level === Location::TERRITORY ? 'COALESCE(m.territory_id, m.area_id)' : $id;

                return $q->selectRaw("{$drill} as place_id, COALESCE({$name}, ".$pdo->quote((string) __('sales::money_in.'.$none)).') as place, '
                    ."{$parent} as parent, ".($level === SalesPlace::CUSTOMER ? 'COALESCE(m.territory, '.$pdo->quote((string) __('sales::money_in.no_territory')).') as territory, ' : '').self::sums())
                    ->orderByRaw('SUM(m.amount) DESC')->orderByRaw("{$id}");
            },
            [
                ['key' => 'place', 'label' => 'sales::money_in.level_'.$level],
                ...($level === Location::AREA ? [] : [['key' => 'parent', 'label' => 'sales::money_in.parent_'.$level]]),
                // ⭐ গ্রাহক ধরে: টেরিটরির কলাম, আর তার সাব টোটাল ([[territories()]]; মালিক, ২৫ নভেম্বর ২০২৬)
                ...($level === SalesPlace::CUSTOMER ? [['key' => 'territory', 'label' => 'sales::money_in.level_territory', 'width' => '11rem']] : []),
                ...self::figures($row, $below === null ? null : ['slug' => self::levelSlug($below), 'params' => $row]),
            ], 'place_id', $level === SalesPlace::CUSTOMER ? fn (array $f): array => self::territories($f) : null, self::totalChart('place'));
    }

    /**
     * ⭐ টেরিটরি ধরে ভাগ, প্রতিটার শেষে সাব টোটাল (মালিকের নমুনা, ২৫ নভেম্বর ২০২৬) — বেশি টাকার টেরিটরি আগে, "বসানো নেই" শেষে।
     * ⓘ একটাই ভাগ হলে ভাগ নয় — সাব টোটাল তখন সর্বমোটের হুবহু একই অঙ্ক, আর তালিকা পাতা ধরে চলে।
     *
     * @param  array<string, mixed>  $f
     * @return list<array{id: ?int, name: string, filters: array<string, mixed>, total_label: string, of_total: bool}>
     */
    private static function territories(array $f): array
    {
        $groups = self::rows($f)
            ->leftJoin('branches as b', 'b.id', '=', 'm.area_branch_id')
            ->groupBy('m.territory_id', 'm.territory', 'b.name_bn', 'b.name_en')
            ->selectRaw('m.territory_id, m.territory, b.name_bn, b.name_en, COUNT(DISTINCT m.customer_id) as customers, SUM(m.amount) as total')
            ->orderByRaw('m.territory_id IS NULL')->orderByRaw('SUM(m.amount) DESC')->get();

        if ($groups->count() < 2) {
            return [];
        }

        $sum = $groups->reduce(fn (string $s, object $g) => bcadd($s, (string) $g->total, 4), '0');
        $branches = $groups->pluck('name_en')->filter()->unique()->count();
        $bn = app()->getLocale() === 'bn';
        $plan = [];

        foreach ($groups as $g) {
            $name = $g->territory === null ? (string) __('sales::money_in.no_territory') : (string) $g->territory;
            // ⓘ একাধিক শাখা দেখালে কোন শাখার টেরিটরি — একই নাম চার শাখায় আছে
            $named = $branches > 1 && $g->name_en !== null ? (($bn && filled($g->name_bn) ? $g->name_bn : $g->name_en).' › '.$name) : $name;
            $share = bccomp($sum, '0', 4) > 0 ? bcdiv(bcmul((string) $g->total, '100', 6), $sum, 2) : '0.00';

            $plan[] = [
                'id' => $g->territory_id === null ? null : (int) $g->territory_id,
                'name' => $named,
                'filters' => [...$f, 'territory' => (int) ($g->territory_id ?? 0)],
                'total_label' => (string) __('sales::money_in.territory_total', ['territory' => $named, 'customers' => $g->customers, 'share' => $share]),
                'of_total' => true,
            ];
        }

        return $plan;
    }

    private static function byPerson(): ReportDefinition
    {
        return self::define(self::PERSON, 'sales::money_in.title_person',
            function (array $f): Builder {
                $nobody = DB::getPdo()->quote((string) __('sales::money_in.no_person'));

                return self::rows($f)->leftJoin('users as u', 'u.id', '=', 'm.person_id')
                    ->groupBy('m.person_id', 'u.name')
                    ->selectRaw("m.person_id as person_id, COALESCE(u.name, {$nobody}) as person, COUNT(DISTINCT m.customer_id) as dealers, ".self::sums())
                    ->orderByRaw('SUM(m.amount) DESC')->orderBy('m.person_id');
            },
            [
                ['key' => 'person', 'label' => 'sales::money_in.person'],
                ['key' => 'dealers', 'label' => 'sales::money_in.dealers', 'type' => ReportColumn::QUANTITY, 'total' => false, 'width' => '5rem'],
                ...self::figures(['salesman_id' => 'person_id']),
            ], 'person_id',
            // ⭐ দলের মোট — উপরওয়ালার গাছ ধরে, বিক্রয়কর্মী ধরে বিক্রির একই ভাগ; সর্বমোট মানুষ ধরে একবার
            fn (array $f): array => SalespersonReports::teamsFor($f), self::totalChart('person'));
    }

    private static function byChannel(): ReportDefinition
    {
        return self::define(self::CHANNEL, 'sales::money_in.title_channel',
            fn (array $f): Builder => self::rows($f)->groupBy('m.account_id', 'm.channel', 'm.kind')
                ->selectRaw('m.account_id as account_id, m.channel as channel, '.self::kindLabel('m.kind').' as kind_label, '.self::sums())
                ->orderByRaw("FIELD(m.kind, 'cash', 'bank', 'mfs')")->orderByRaw('SUM(m.amount) DESC')->orderBy('m.account_id'),
            [
                ['key' => 'channel', 'label' => 'sales::money_in.channel'],
                ['key' => 'kind_label', 'label' => 'sales::money_in.kind', 'width' => '9rem'],
                ...self::figures(['account_id' => 'account_id']),
            ], 'account_id', chart: self::totalChart('channel', 'donut'));
    }

    /** রসিদ ধরে — প্রতিটা আসা টাকার সারি; অঙ্কে চাপলে কাগজটা */
    private static function papers(): ReportDefinition
    {
        return self::define(self::PAPERS, 'sales::money_in.title_papers',
            function (array $f): Builder {
                $nobody = DB::getPdo()->quote((string) __('sales::money_in.no_person'));

                return self::rows($f)->leftJoin('users as u', 'u.id', '=', 'm.person_id')
                    ->selectRaw("m.trx_date, m.document_no, m.source_type, m.source_id, CONCAT('customer:', m.customer_id) as party_key, "
                        ."COALESCE(u.name, {$nobody}) as person, m.channel, ".self::kindLabel('m.kind').' as kind_label, m.amount')
                    ->orderBy('m.trx_date')->orderBy('m.entry_id');
            },
            [
                ['key' => 'trx_date', 'label' => 'core.print.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'document_no', 'label' => 'core.table.document', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type', 'source_id' => 'source_id', 'width' => '11rem'],
                // ⭐ কার কাছ থেকে — "নাম · পয়েন্ট" (মালিক, ১৯ অক্টোবর ২০২৬); নাম বসায় ইঞ্জিন, পাতার একবারে
                ['key' => 'party', 'label' => 'sales::money_in.received_from', 'party' => 'party_key', 'width' => '16rem'],
                ['key' => 'person', 'label' => 'sales::money_in.person'],
                ['key' => 'channel', 'label' => 'sales::money_in.channel'],
                ['key' => 'kind_label', 'label' => 'sales::money_in.kind', 'width' => '9rem'],
                ['key' => 'amount', 'label' => 'sales::money_in.amount', 'type' => ReportColumn::MONEY,
                    'drill' => ['source' => ['type' => 'source_type', 'id' => 'source_id']]],
            ]);
    }

    /**
     * ⭐ বাদ গেছে — টাকার খাতে ডেবিট হয়েছে অথচ "আসা টাকা"য় গোনা হয়নি, কারণ ধরে ([[CoreReports::moneyKeptOut()]])।
     * ⓘ পাতায় ইঙ্গিতের বাক্স নয় (মালিকের নিয়ম) — আলাদা ট্যাব; অঙ্কে চাপলে কাগজগুলো ([[EXCLUDED_PAPERS]])।
     */
    private static function excluded(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::EXCLUDED,
            permission: 'sales.report',
            title: 'sales::money_in.title_excluded',
            filters: ['date_range', 'branch'],
            splitByBranch: false,
            query: fn (array $f): Builder => DB::query()->fromSub(self::keptOut($f), 'x')
                ->groupBy('x.reason')
                ->selectRaw('x.reason as reason, '.self::reasonLabel('x.reason').' as reason_label, '
                    ."COUNT(DISTINCT CONCAT(x.source_type, ':', x.source_id)) as papers, SUM(x.amount) as amount")
                ->orderByRaw('FIELD(x.reason, '.implode(', ', array_map(fn (string $r) => "'{$r}'", CoreReports::KEPT_OUT)).')'),
            columns: [
                ['key' => 'reason_label', 'label' => 'sales::money_in.reason'],
                ['key' => 'papers', 'label' => 'sales::money_in.papers', 'type' => ReportColumn::QUANTITY, 'width' => '6rem'],
                ['key' => 'amount', 'label' => 'sales::money_in.amount', 'type' => ReportColumn::MONEY, 'drill' => [
                    'route' => 'sales.report.show',
                    'params' => ['slug' => '='.self::SLUG[self::EXCLUDED_PAPERS], 'from' => '@from', 'to' => '@to', 'branch_id' => '@branch_id', 'reason' => 'reason'],
                    'total' => ['slug' => '='.self::SLUG[self::EXCLUDED_PAPERS], 'from' => '@from', 'to' => '@to', 'branch_id' => '@branch_id'],
                    'label' => 'sales::money_in.drill_papers',
                ]],
            ],
            chart: ['type' => 'donut', 'label' => 'reason_label', 'series' => ['amount'], 'title' => 'sales::money_in.tab_excluded'],
        );
    }

    private static function excludedPapers(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::EXCLUDED_PAPERS,
            permission: 'sales.report',
            title: 'sales::money_in.title_excluded_papers',
            filters: ['date_range', 'branch', 'reason'],
            choices: ['reason' => array_combine(CoreReports::KEPT_OUT, array_map(fn (string $r) => 'sales::money_in.reason_'.$r, CoreReports::KEPT_OUT))],
            splitByBranch: false,
            query: fn (array $f): Builder => DB::query()->fromSub(self::keptOut($f), 'x')
                ->when(in_array($f['reason'] ?? null, CoreReports::KEPT_OUT, true), fn (Builder $q) => $q->where('x.reason', $f['reason']))
                ->selectRaw('x.trx_date, x.document_no, x.source_type, x.source_id, '.self::reasonLabel('x.reason').' as reason_label, '
                    .'x.channel, x.narration, x.amount')
                ->orderBy('x.trx_date')->orderBy('x.entry_id'),
            columns: [
                ['key' => 'trx_date', 'label' => 'core.print.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'document_no', 'label' => 'core.table.document', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type', 'source_id' => 'source_id', 'width' => '11rem'],
                ['key' => 'reason_label', 'label' => 'sales::money_in.reason'],
                ['key' => 'channel', 'label' => 'sales::money_in.channel'],
                ['key' => 'narration', 'label' => 'core.table.narration'],
                ['key' => 'amount', 'label' => 'sales::money_in.amount', 'type' => ReportColumn::MONEY,
                    'drill' => ['source' => ['type' => 'source_type', 'id' => 'source_id']]],
            ],
        );
    }

    /**
     * বাদ পড়া সারি — কারণসহ; ⛔ বিক্রয়কর্মী কেবল নিজের ডিলারের কাগজ দেখেন (গ্রাহক ছাড়া কাগজ তাঁর কাছে নয়)।
     *
     * @param  array<string, mixed>  $f
     */
    private static function keptOut(array $f): Builder
    {
        $channel = app()->getLocale() === 'bn' ? "COALESCE(NULLIF(accounts.name_bn, ''), accounts.name_en)" : 'accounts.name_en';

        return CoreReports::moneyKeptOut($f)
            ->leftJoin('customers as cu', fn ($join) => $join->on('cu.id', '=', DB::raw(self::customerOfPaper()))->whereNull('cu.deleted_at'))
            ->tap(ReportEngine::dealerWall($f, 'cu.id'))
            ->selectRaw('ledger_entries.id as entry_id, ledger_entries.trx_date as trx_date, ledger_entries.document_no as document_no, '
                .'ledger_entries.source_type as source_type, ledger_entries.source_id as source_id, ledger_entries.debit as amount, '
                ."ledger_entries.narration as narration, {$channel} as channel, ".CoreReports::keptOutReason().' as reason');
    }

    private static function kindLabel(string $column): string
    {
        $pdo = DB::getPdo();
        $cases = implode(' ', array_map(
            fn (string $k) => "WHEN '{$k}' THEN ".$pdo->quote((string) __('sales::money_in.kind_'.$k)), self::KINDS));

        return "CASE {$column} {$cases} ELSE {$column} END";
    }

    private static function reasonLabel(string $column): string
    {
        $pdo = DB::getPdo();
        $cases = implode(' ', array_map(
            fn (string $r) => "WHEN '{$r}' THEN ".$pdo->quote((string) __('sales::money_in.reason_'.$r)), CoreReports::KEPT_OUT));

        return "CASE {$column} {$cases} END";
    }
}
