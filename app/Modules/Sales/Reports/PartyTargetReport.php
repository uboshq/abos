<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\Money;
use App\Modules\Customer\Models\DealerBinding;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Services\CustomerTargetService;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ পক্ষভিত্তিক লক্ষ্য বনাম আদায় — মালিকের সরাসরি নির্দেশ, ১৪ অক্টোবর ২০২৬ (fe-র মাধ্যমে): Accounts → রিপোর্ট, Sales-এর
 * ড্যাশবোর্ডেও (*"eta sales er dashboard eo daw"*), আর এলাকা ধরে (*"report area wise jug thakbe"*)।
 *
 * দুই রূপ:
 *   · গ্রাহক ধরে — ক্রমিক · গ্রাহক · পয়েন্ট · আদায় · লক্ষ্য · অর্জন % · মন্তব্য; এলাকা ধরে দলে, প্রতিটা এলাকার নিচে উপ-মোট, শেষে
 *     সর্বমোট ([[ReportDefinition::$sections]] — প্রতিটা ভাগের যোগ নিজের কোয়েরিতে, তাই Σ উপ-মোট = সর্বমোট)।
 *   · এলাকা ধরে সারাংশ — ক্রমিক · এলাকা · গ্রাহক · আদায় · লক্ষ্য · অর্জন % · মন্তব্য।
 * ⭐ % সব জায়গায় আদায় ÷ লক্ষ্য — সারি, উপ-মোট আর সর্বমোট, গড় নয় ([[ReportColumn::$ratio]])।
 *
 * ⛔ দ্বিতীয় গণনা নয়:
 *   · লক্ষ্য — ডিলারের মাসিক লক্ষ্য (`sal_customer_targets`, [[CustomerTargetService]]); বাছা সময় যে মাসগুলো ছোঁয় তাদের যোগ।
 *   · আদায় — [[CustomerTargetService::achievedQuery()]], লক্ষ্যের পাতা, বিলের বাক্স আর পুরনো লক্ষ্যের রিপোর্টের একই নিয়ম: আদায়ের
 *     কাগজ, রসিদ ভাউচার, পাশ হওয়া চেক; ফেরত চেক, বাতিল আদায়, পাশ না হওয়া চেক বাদ; বিক্রয় ফেরত, ছাড়, জাবেদা কখনো নয়।
 *
 * ⓘ ঘর Sales-এ, কারণ লক্ষ্য Sales-এর; দরজা একটাই — Accounts → রিপোর্ট (`accounts.report`), মালিক যেমন বলেছেন। ⛔ দুই মেনুতে একই নাম
 * দুই পাতায় যায় না ([[OneNameInTwoMenusAndNobodyKnowsWhichDoorTest]]), আর রিপোর্টের চাবি দরজার চাবির হুবহু
 * ([[EveryReportNamesTheKeyItsWebDoorAsksForTest]]) — তাই Sales-এর দরজা নেই।
 * ⓘ শাখা: গ্রাহকের নিজের শাখা (হেডারের বাছা মানে); বিক্রয়কর্মী কেবল নিজের ডিলার; এলাকা/পয়েন্ট ছাঁকনি পাঁচ ধাপ উপরে মেলে।
 */
final class PartyTargetReport
{
    public const KEY = 'sales.party_target';

    public const AREAS = 'sales.party_target_areas';

    /** মন্তব্যের সীমা — ১০০ বা বেশি পূরণ, ৮০–৯৯ কাছাকাছি, নিচে পিছিয়ে */
    public const MET_AT = '100';

    public const NEAR_AT = '80';

    /** গ্রাহকের স্থান থেকে কত ধাপ উপরে এলাকা খোঁজা — রুট › পয়েন্ট › টেরিটরি › এরিয়া (টেরিটরি না থাকলেও চলে) */
    private const DEPTH = 5;

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::customers());
        $engine->register(self::areas());
    }

    /**
     * প্রতি গ্রাহকের এক সারি — `source_id`, `code`, `customer`, `point`, `area_id`, `area`, `inflow`, `target`। রিপোর্টের দুই রূপ
     * আর ড্যাশবোর্ড — তিনটাই এটাই পড়ে।
     *
     * ⓘ সারিতে আসেন যাঁদের এই সময়ে লক্ষ্য আছে, বা লক্ষ্য না থাকলেও টাকা এসেছে ("লক্ষ্য নেই")।
     *
     * @param  Closure(Builder): void  $walls  গ্রাহকের সারির দেয়াল আর ছাঁকনি (`cu`)
     */
    public static function base(int $company, string $from, string $to, Closure $walls): Builder
    {
        $targets = DB::table('sal_customer_targets as st')
            ->where('st.company_id', $company)
            ->whereBetween('st.month', [Carbon::parse($from)->startOfMonth()->toDateString(), Carbon::parse($to)->startOfMonth()->toDateString()])
            ->groupBy('st.customer_id')
            ->selectRaw('st.customer_id as customer_id, SUM(st.amount) as target');

        $bn = app()->getLocale() === 'bn';
        $named = fn (string $t) => $bn ? "COALESCE(NULLIF({$t}.name_bn, ''), {$t}.name_en)" : "COALESCE(NULLIF({$t}.name_en, ''), {$t}.name_bn)";

        $query = DB::table('customers as cu')
            ->where('cu.company_id', $company)
            ->leftJoinSub($targets, 't', 't.customer_id', '=', 'cu.id')
            ->leftJoinSub(CustomerTargetService::achievedQuery($company, $from, $to), 'a', 'a.customer_id', '=', 'cu.id')
            ->leftJoin('mdm_locations as l1', 'l1.id', '=', 'cu.location_id');

        for ($i = 2; $i <= self::DEPTH; $i++) {
            $query->leftJoin("mdm_locations as l{$i}", "l{$i}.id", '=', 'l'.($i - 1).'.parent_id');
        }

        // ⓘ নিজের স্থান বা উপরের প্রথম যে ধাপটা পয়েন্ট / এরিয়া — [[Customer::pointName()]]-এর একই নিয়ম
        $first = function (string $level, string $what) use ($named): string {
            $cases = [];
            for ($i = 1; $i <= self::DEPTH; $i++) {
                $cases[] = "WHEN l{$i}.level = ".DB::getPdo()->quote($level).' THEN '.($what === 'id' ? "l{$i}.id" : $named("l{$i}"));
            }

            return 'CASE '.implode(' ', $cases).' END';
        };

        return $query
            ->whereNull('cu.deleted_at')
            ->where(fn ($q) => $q->where('t.target', '>', 0)->orWhere('a.achieved', '>', 0))
            ->tap($walls)
            ->selectRaw('cu.id as source_id, cu.code as code, '.$named('cu').' as customer, '
                .$first(Location::POINT, 'name').' as point, '
                .$first(Location::AREA, 'id').' as area_id, '
                .$first(Location::AREA, 'name').' as area, '
                .'COALESCE(a.achieved, 0) as inflow, COALESCE(t.target, 0) as target');
    }

    /**
     * ⭐ Sales ড্যাশবোর্ডের জন্য এ মাস — মোট আদায়, মোট লক্ষ্য, % আর সবচেয়ে পিছিয়ে থাকা পাঁচজন (মালিক, ১৪ অক্টোবর ২০২৬:
     * *"eta sales er dashboard eo daw"*)।
     *
     * ⓘ রিপোর্টের একই সারি ([[base()]]) আর চাবি না দিলে রিপোর্ট যে সময় নেয় সেটাই — মাসের ১ তারিখ থেকে আজ; তাই ঘরের অঙ্ক আর
     * রিপোর্টের সর্বমোট হুবহু এক। দেয়াল গ্রাহকের নিজের: কোম্পানি, হেডারের শাখা আর নাগাল, বিক্রয়কর্মী কেবল নিজের ডিলার।
     *
     * @return array{inflow: string, target: string, percent: ?string, lagging: \Illuminate\Support\Collection<int, object>}
     */
    public static function thisMonth(): array
    {
        $today = Carbon::today();
        $rows = DB::query()->fromSub(self::base(
            (int) \App\Core\Support\CompanyContext::id(),
            $today->copy()->startOfMonth()->toDateString(),
            $today->toDateString(),
            fn (Builder $q) => $q->whereIn('cu.id', \App\Modules\Customer\Models\Customer::query()->inViewedBranch()->select('customers.id')),
        ), 'r');

        $sum = (clone $rows)->selectRaw('COALESCE(SUM(r.inflow), 0) as inflow, COALESCE(SUM(r.target), 0) as target')->first();
        $inflow = bcadd((string) ($sum->inflow ?? '0'), '0', 4);
        $target = bcadd((string) ($sum->target ?? '0'), '0', 4);

        // ⓘ পিছিয়ে — যাঁদের লক্ষ্য আছে, সবচেয়ে কম % আগে; সমান হলে বড় লক্ষ্যটা আগে
        $lagging = (clone $rows)->where('r.target', '>', 0)
            ->orderByRaw('r.inflow / r.target')
            ->orderByDesc('r.target')
            ->orderBy('r.source_id')
            ->limit(5)
            ->get(['r.source_id', 'r.customer', 'r.point', 'r.inflow', 'r.target']);

        return ['inflow' => $inflow, 'target' => $target, 'percent' => self::percent($inflow, $target), 'lagging' => $lagging];
    }

    /** অর্জন % — লক্ষ্য না থাকলে `null` */
    public static function percent(string $inflow, string $target): ?string
    {
        return bccomp($target, '0', 4) > 0 ? bcdiv(bcmul($inflow, '100', 4), $target, 1) : null;
    }

    /**
     * মন্তব্য — মালিকের চার কথা। ⓘ গোল করা % নয়, আসল অঙ্কে মাপা — রিপোর্টের SQL-এর একই তুলনা ([[remarkSql()]]), তাই ৯৯.৯৬% কোথাও
     * "পূরণ" আর কোথাও "কাছাকাছি" হয় না।
     */
    public static function remark(string $inflow, string $target): string
    {
        $scaled = bcmul($inflow, '100', 4);

        return match (true) {
            bccomp($target, '0', 4) <= 0 => __('sales::party_target.no_target'),
            bccomp($scaled, bcmul($target, self::MET_AT, 4), 4) >= 0 => __('sales::party_target.met'),
            bccomp($scaled, bcmul($target, self::NEAR_AT, 4), 4) >= 0 => __('sales::party_target.near'),
            default => __('sales::party_target.behind'),
        };
    }

    /** একই নিয়ম SQL-এ — আদায় আর লক্ষ্যের দুই ঘর ধরে */
    private static function remarkSql(string $inflow, string $target): string
    {
        $pdo = DB::getPdo();

        return "CASE WHEN {$target} <= 0 THEN ".$pdo->quote((string) __('sales::party_target.no_target'))
            ." WHEN {$inflow} * 100 >= {$target} * ".self::MET_AT.' THEN '.$pdo->quote((string) __('sales::party_target.met'))
            ." WHEN {$inflow} * 100 >= {$target} * ".self::NEAR_AT.' THEN '.$pdo->quote((string) __('sales::party_target.near'))
            .' ELSE '.$pdo->quote((string) __('sales::party_target.behind')).' END';
    }

    /**
     * রিপোর্টের দেয়াল আর ছাঁকনি — শাখা (গ্রাহকের), ডিলারের দেয়াল, এলাকা/পয়েন্ট, বিক্রয়কর্মী; আর ভাগের নিজের এলাকা।
     *
     * @param  array<string, mixed>  $f
     */
    private static function rows(array $f): Builder
    {
        $rows = self::base((int) $f['company_id'], (string) $f['from'], (string) $f['to'], fn (Builder $q) => $q
            ->tap(ReportEngine::branchWall($f, 'cu.branch_id'))
            ->tap(ReportEngine::dealerWall($f, 'cu.id'))
            ->when(! empty($f['location_id']), fn ($q) => self::underLocation($q, (int) $f['location_id']))
            ->when(! empty($f['salesman_id']), fn ($q) => $q->whereIn('cu.id', DealerBinding::query()
                ->current()->where('user_id', (int) $f['salesman_id'])->select('customer_id'))));

        // ⓘ এলাকার ভাগ ([[sectionsFor()]]) — একটা এলাকা, বা এলাকা নেই এমন গ্রাহক
        return DB::query()->fromSub($rows, 'r')
            ->when(array_key_exists('area_id', $f) && $f['area_id'] !== null, fn ($q) => $q->where('r.area_id', (int) $f['area_id']))
            ->when(! empty($f['area_none']), fn ($q) => $q->whereNull('r.area_id'));
    }

    private static function customers(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            // ⭐ হিসাব বা বিক্রয়ের রিপোর্টের চাবি — যেকোনো একটা (fe, মালিক: "sales er dashboard eo daw"); বিক্রয়কর্মী কেবল নিজের ডিলার
            permission: ['accounts.report', 'sales.report'],
            anyKey: true,
            title: 'sales::party_target.title',
            filters: ['date_range', 'branch', 'location_id', 'salesman_id'],
            // ⓘ শাখা গ্রাহকের নিজের — নিজেই ছাঁকে; ভাগ এলাকা ধরে ([[sectionsFor()]])
            splitByBranch: false,
            query: function (array $f): Builder {
                $pdo = DB::getPdo();

                return self::rows($f)
                    ->selectRaw('ROW_NUMBER() OVER (ORDER BY r.area IS NULL, r.area, r.customer, r.source_id) as sl, r.customer, '
                        .$pdo->quote('customer').' as source_type, r.source_id, r.code, r.point, r.area, r.inflow, r.target, '
                        .'CASE WHEN r.target > 0 THEN ROUND(r.inflow * 100 / r.target, 2) END as percent, '
                        .self::remarkSql('r.inflow', 'r.target').' as remark')
                    ->orderByRaw('r.area IS NULL')
                    ->orderBy('r.area')
                    ->orderBy('r.customer')
                    ->orderBy('r.source_id');
            },
            summary: fn (array $totals): array => self::summaryOf($totals),
            columns: [
                ['key' => 'sl', 'label' => 'sales::party_target.sl', 'type' => ReportColumn::QUANTITY, 'total' => false, 'width' => '4rem'],
                ['key' => 'customer', 'label' => 'sales::party_target.customer', 'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type', 'source_id' => 'source_id'],
                // ⭐ "পয়েন্ট · শাখা" — শাখা ইঞ্জিন জোড়ে, পাতার একবারে (মালিক, ১৯ অক্টোবর ২০২৬, স্থায়ী নিয়ম); SQL অক্ষত
                ['key' => 'point', 'label' => 'sales::party_target.place', 'place' => ['customer', 'source_id', 'branch']],
                ['key' => 'inflow', 'label' => 'sales::party_target.inflow', 'type' => ReportColumn::MONEY],
                ['key' => 'target', 'label' => 'sales::party_target.target', 'type' => ReportColumn::MONEY],
                ['key' => 'percent', 'label' => 'sales::party_target.percent', 'type' => ReportColumn::PERCENT, 'ratio' => ['inflow', 'target']],
                ['key' => 'remark', 'label' => 'sales::party_target.remark'],
            ],
            sections: fn (array $f): array => self::sectionsFor($f),
        );
    }

    private static function areas(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::AREAS,
            // ⭐ হিসাব বা বিক্রয়ের রিপোর্টের চাবি — যেকোনো একটা (fe, মালিক: "sales er dashboard eo daw"); বিক্রয়কর্মী কেবল নিজের ডিলার
            permission: ['accounts.report', 'sales.report'],
            anyKey: true,
            title: 'sales::party_target.areas_title',
            filters: ['date_range', 'branch', 'location_id', 'salesman_id'],
            splitByBranch: false,
            query: function (array $f): Builder {
                // ⓘ ভিতরে এলাকা ধরে যোগ, বাইরে ক্রমিক আর মন্তব্য — ONLY_FULL_GROUP_BY-তে নিরাপদ
                $grouped = self::rows($f)
                    ->groupBy('r.area_id', 'r.area')
                    ->selectRaw('r.area_id as area_id, COALESCE(r.area, '.DB::getPdo()->quote((string) __('sales::party_target.no_area')).') as area, '
                        .'COUNT(*) as customers, SUM(r.inflow) as inflow, SUM(r.target) as target');

                return DB::query()->fromSub($grouped, 'g')
                    ->selectRaw('ROW_NUMBER() OVER (ORDER BY g.area_id IS NULL, g.area) as sl, g.area, g.customers, g.inflow, g.target, '
                        .'CASE WHEN g.target > 0 THEN ROUND(g.inflow * 100 / g.target, 2) END as percent, '
                        .self::remarkSql('g.inflow', 'g.target').' as remark')
                    ->orderByRaw('g.area_id IS NULL')
                    ->orderBy('g.area');
            },
            summary: fn (array $totals): array => self::summaryOf($totals),
            columns: [
                ['key' => 'sl', 'label' => 'sales::party_target.sl', 'type' => ReportColumn::QUANTITY, 'total' => false, 'width' => '4rem'],
                ['key' => 'area', 'label' => 'sales::party_target.area'],
                ['key' => 'customers', 'label' => 'sales::party_target.customers', 'type' => ReportColumn::QUANTITY],
                ['key' => 'inflow', 'label' => 'sales::party_target.inflow', 'type' => ReportColumn::MONEY],
                ['key' => 'target', 'label' => 'sales::party_target.target', 'type' => ReportColumn::MONEY],
                ['key' => 'percent', 'label' => 'sales::party_target.percent', 'type' => ReportColumn::PERCENT, 'ratio' => ['inflow', 'target']],
                ['key' => 'remark', 'label' => 'sales::party_target.remark'],
            ],
        );
    }

    /**
     * এলাকার ভাগ — যে এলাকাগুলোর গ্রাহক এই ছাঁকনিতে আসেন, নামের ক্রমে; শেষে "এলাকা নেই"।
     *
     * @param  array<string, mixed>  $f
     * @return list<array{id: ?int, name: string, filters: array<string, mixed>}>
     */
    public static function sectionsFor(array $f): array
    {
        $found = self::rows($f)->selectRaw('r.area_id as area_id, MAX(r.area) as area')->groupBy('r.area_id')->get();

        $plan = $found->filter(fn ($r) => $r->area_id !== null)
            ->sortBy(fn ($r) => (string) $r->area)
            ->map(fn ($r) => ['id' => (int) $r->area_id, 'name' => (string) $r->area, 'filters' => [...$f, 'area_id' => (int) $r->area_id]])
            ->values()->all();

        if ($found->contains(fn ($r) => $r->area_id === null)) {
            $plan[] = ['id' => null, 'name' => (string) __('sales::party_target.no_area'), 'filters' => [...$f, 'area_id' => null, 'area_none' => true]];
        }

        // ⓘ একটাই ভাগ হলে ভাগের দরকার নেই — সর্বমোটই উপ-মোট
        return count($plan) > 1 ? $plan : [];
    }

    /**
     * সর্বমোটের এক লাইন — মোট আদায়, মোট লক্ষ্য, মোট % (মোট ÷ মোট)।
     *
     * @param  array<string, string>  $totals
     * @return array<string, mixed>
     */
    private static function summaryOf(array $totals): array
    {
        $inflow = (string) ($totals['inflow'] ?? '0');
        $target = (string) ($totals['target'] ?? '0');
        $rate = self::percent($inflow, $target);

        return [
            'label' => __('sales::party_target.summary'),
            'value' => $rate ?? '0',
            'text' => __('sales::party_target.summary_text', ['inflow' => Money::format($inflow), 'target' => Money::format($target), 'rate' => $rate ?? '—']),
            'good' => $rate !== null && bccomp($rate, self::MET_AT, 1) >= 0,
        ];
    }

    /** গ্রাহকের স্থান বা তার উপরের যেকোনো ধাপ — পয়েন্ট, এরিয়া বা আরও উপরে বাছলে ([[MonthlySalesReport]]-এর নিয়ম, গভীরতর) */
    private static function underLocation(Builder $q, int $want): void
    {
        $q->where(function ($m) use ($want): void {
            for ($i = 1; $i <= self::DEPTH; $i++) {
                $m->orWhere("l{$i}.id", $want);
            }
        });
    }
}
