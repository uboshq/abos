<?php

declare(strict_types=1);

namespace App\Core\Engines\Report;

use App\Core\Services\DataScope;
use App\Core\Services\DealerScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\RunningBalance;
use App\Models\Branch;
use App\Models\UserDataScope;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * রিপোর্ট চালানোর একমাত্র পথ — প্ল্যান সেকশন ২.২, ষষ্ঠ engine।
 *
 * ৩০+ রিপোর্ট, আর প্রতিটাতেই একই কাজ: ফিল্টার নাও, কোয়েরি চালাও, যোগফল
 * বের করো, পাতা ভাগ করো, ড্রিল-ডাউন লিংক বসাও, রপ্তানি করো। একবার লেখা
 * হলে ৩০ বার নয়।
 *
 * পেজিনেশন বাধ্যতামূলক (সেকশন ৯): শেয়ার্ড হোস্টে পুরো লেজার এক রেসপন্সে
 * পাঠানো মানে টাইমআউট। কিন্তু যোগফল পুরো ফলের উপর, শুধু দৃশ্যমান পাতার
 * উপর নয় — নাহলে "মোট" মানে "এই পাতার মোট", যা কেউ চায় না আর কেউ বুঝবেও না।
 */
final class ReportEngine
{
    /** পর্দার এক পাতা — রিপোর্টের পর্দাগুলো এই মাপেই ডাকে */
    public const SCREEN_ROWS = 100;

    /** ছাপা আর ফাইলের ছাদ — ফোনের রপ্তানি আর নির্ধারিত রিপোর্টের একই সীমা ([[ReportExportApiController::MAX_ROWS]]) */
    public const WHOLE_DOCUMENT_ROWS = 100000;

    /** ঠিকানায় `from=all` — শুরু থেকে ([[normaliseFilters()]]) */
    public const ALL_TIME = 'all';

    /** "শুরু থেকে"-র তারিখ — খাতার কোনো সারি এর আগে নয় */
    public const BEGINNING = '1900-01-01';

    /** ঠিক ততদিন আগের পরিসর — গতি বোঝায় */
    public const COMPARE_PREVIOUS = 'previous';

    /** গত বছরের একই পরিসর — মৌসুম বাদ দিয়ে বোঝায় */
    public const COMPARE_LAST_YEAR = 'last_year';

    /** @var array<string, ReportDefinition> */
    private array $reports = [];

    /**
     * কতবার [[branchWall()]] ডাকা হয়েছে — কেবল পার্থক্যটা মাপা হয়।
     *
     * ⓘ দেয়ালটা রিপোর্টের নিজের কোয়েরিতে বসে (কলামের নাম কেবল রিপোর্টই
     * জানে), তাই ইঞ্জিন দেয়াল বসাতে পারে না — কিন্তু বসেছে কি না গুনতে পারে।
     */
    private static int $wallsBound = 0;

    /**
     * ⭐ কতবার [[dealerWall()]] ডাকা হয়েছে — শাখার দেয়ালের অবিকল একই হিসাব (⛔১৬, ২ অক্টোবর ২০২৬)।
     */
    private static int $dealerWallsBound = 0;

    /** ⓘ খোঁজের মোড়কে ভিতরের ক্রম বয়ে আনার ঘর ([[applySearch()]]) — সারিতে দেখানো হয় না */
    private const SEARCH_ORDER = '__search_order';

    public function register(ReportDefinition $report): void
    {
        if (isset($this->reports[$report->key])) {
            throw new RuntimeException("Two reports claim the key '{$report->key}'.");
        }

        $this->reports[$report->key] = $report;
    }

    public function get(string $key): ReportDefinition
    {
        return $this->reports[$key] ?? throw new RuntimeException(
            "No report registered as '{$key}'. Registered: ".(implode(', ', array_keys($this->reports)) ?: 'none').'.'
        );
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->reports);
    }

    /**
     * রিপোর্ট চালাও।
     *
     * @param  array<string, mixed>  $filters
     */
    public function run(string $key, array $filters = [], int $page = 1, int $perPage = self::SCREEN_ROWS, bool $byBranch = false): ReportResult
    {
        $report = $this->get($key);
        $filters = $this->normaliseFilters($report, $filters);

        /*
         * ⭐ ছাপা আর ফাইল গোটা পরিসর নেয়, পর্দার পাতা নয় — মালিক, ৩ অক্টোবর ২০২৬ (কাস্টমার লেজার): ছাপা বেরোত
         * পাতা ধরে ধরে, প্রতিটা পাতা আলাদা করে ছাপতে হত, আর CSV/Excel-এও কেবল চলতি পাতার ১০০ সারি।
         * ⓘ এক জায়গায়, তাই রিপোর্টের প্রতিটা পর্দা (১৩টা কন্ট্রোলার) একসাথে সারে। কেবল পর্দার মাপের ডাকে
         * (`SCREEN_ROWS`) — ড্যাশবোর্ডের গোনা (`perPage: 1`) আর ফোনের নিজের মাপ যেমন ছিল।
         */
        if ($perPage === self::SCREEN_ROWS && self::wholeDocumentWanted()) {
            $page = 1;
            $perPage = self::WHOLE_DOCUMENT_ROWS;
        }

        $query = $this->queryFor($report, $filters);

        /*
         * ⭐ খোঁজার শব্দটা এখানে বসে — যোগফল ও গণনার **আগে**।
         *
         * ⓘ নিচের দুইটা লাইন `clone $query` নেয়, তাই এখানে বসালে
         * যোগফল আর সারির সংখ্যা আপনা থেকেই খোঁজা ফলের হয়ে যায়।
         * ⛔ পরে বসালে পর্দায় দশটা সারি দেখা যেত আর নিচে চারশোর
         * যোগফল — আর সংখ্যাটা ভুল বলে চেনার কোনো উপায় থাকত না।
         */
        $query = $this->applySearch($report, $query, $filters['q'] ?? null);

        // যোগফল পুরো ফলের উপর — আলাদা কোয়েরিতে, কারণ পাতাভিত্তিক যোগফল
        // ভুল উত্তর দেয় এবং সেটা ভুল বলে চেনাও যায় না।
        $totals = $this->totalsFor($report, clone $query);
        $count = $this->countFor($report, clone $query);

        /*
         * "শুধু উপরের দশটা" — চাওয়া হলে।
         *
         * যোগফল ও গণনা **উপরে** বের হয়ে গেছে, ইচ্ছাকৃতভাবে: অবদানের
         * শতাংশ গোটা মোটের বিপরীতে গোনা হয়, উপরের দশটার মোটের বিপরীতে
         * নয়। উল্টো করলে প্রতিটা তালিকার প্রথম দশটা মিলে সবসময় ১০০%
         * হত, আর বাক্যটার কোনো মানেই থাকত না।
         */
        $top = $this->topN($report, $filters);

        if ($top !== null) {
            $page = 1;
            $perPage = $top;
        }

        $rows = $query
            ->forPage(max(1, $page), $perPage)
            ->get()
            // ⓘ খোঁজের মোড়কের ক্রমিক ঘরটা সারিতে থাকে না ([[applySearch()]])
            ->map(fn ($row) => array_diff_key((array) $row, [self::SEARCH_ORDER => true]));

        if ($report->runningBalance) {
            $rows = $this->addRunningBalance($report, $rows, $filters, $page, $perPage);
        }

        if ($report->rankBy !== null) {
            $rows = $this->addContribution($report, $rows, $totals);
        }

        $comparison = $this->comparisonPeriod($report, $filters);

        if ($comparison !== null) {
            $rows = $this->addComparison($report, $rows, $filters, $comparison);
        }

        /*
         * ⭐ "সব শাখা"-তে শাখা ধরে ভাগ — চাওয়া হলে (মালিকের নির্দেশ, ২৯ সেপ্টেম্বর
         * ২০২৬)। ⓘ `$totals` তখনো পুরো নাগালের — সেটাই Grand Total; প্রতিটা শাখার
         * মোট তার নিজের কোয়েরিতে, তাই Σ শাখা = Grand Total, যোগ করে বানানো নয়।
         */
        $sections = [];

        if ($byBranch) {
            foreach ($this->branchPlan($report, $filters, $top, $comparison) as $part) {
                $section = $this->section($report, $part['filters'], $part['id'], $part['name'], $perPage);

                if ($section->rowCount > 0) {
                    $sections[] = $section;
                }
            }
        }

        return new ReportResult(
            report: $report,
            rows: $rows->all(),
            totals: $totals,
            totalRows: $top === null ? $count : min($top, $count),
            page: max(1, $page),
            perPage: $perPage,
            filters: $filters,
            comparison: $comparison,
            fullRowCount: $count,
            sections: $sections,
        );
    }

    /**
     * কোন কোন শাখায় ভাগ হবে — ভাগ না হলে খালি।
     *
     * ⛔ ভাগ নেই: রিপোর্ট বন্ধ রেখেছে, শাখাহীন রিপোর্ট, চলমান জের, Top-N বা তুলনা,
     * রিপোর্টের নিজের শাখা-ছাঁকনি বাছা, হেডারে একটা শাখা বাছা, বা নাগালে একটাই শাখা।
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{id: ?int, name: string, filters: array<string, mixed>}>
     */
    private function branchPlan(ReportDefinition $report, array $filters, ?int $top, ?array $comparison): array
    {
        if (! $report->splitByBranch || $report->branchless !== null || $report->runningBalance
            || $top !== null || $comparison !== null || ! empty($filters['branch_id'])
            || app(DataScope::class)->viewsOneBranch(auth()->user())) {
            return [];
        }

        $ids = $filters['branch_ids'] ?? null;

        $branches = Branch::query()->withoutGlobalScopes()
            ->where('company_id', CompanyContext::id())
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('code')
            ->get();

        if ($branches->count() < 2) {
            return [];
        }

        $plan = [];

        foreach ($branches as $branch) {
            $plan[] = ['id' => (int) $branch->id, 'name' => (string) $branch->name(), 'filters' => [...$filters, 'branch_id' => (int) $branch->id]];
        }

        // ⓘ শাখাহীন দল শেষে — প্রধান অফিসের, কোম্পানি-স্তরের সারি
        $plan[] = ['id' => null, 'name' => (string) __('core.report.no_branch'), 'filters' => [...$filters, 'branch_id' => null, 'branch_only_null' => true]];

        return $plan;
    }

    /** @param  array<string, mixed>  $filters */
    private function section(ReportDefinition $report, array $filters, ?int $id, string $name, int $perPage): BranchSection
    {
        $query = $this->applySearch($report, $this->queryFor($report, $filters), $filters['q'] ?? null);

        $totals = $this->totalsFor($report, clone $query);
        $count = $this->countFor($report, clone $query);

        $rows = $query->forPage(1, $perPage)->get()
            ->map(fn ($row) => array_diff_key((array) $row, [self::SEARCH_ORDER => true]));

        if ($report->rankBy !== null) {
            $rows = $this->addContribution($report, $rows, $totals);
        }

        return new BranchSection($id, $name, $rows->values()->all(), $totals, $count);
    }

    /**
     * উপরের কয়টা সারি — চাওয়া হলে।
     *
     * ── কেন সীমা আছে ────────────────────────────────────────────────
     * `?top=100000` লিখে দিলে সেটা আর Top N নয়, পুরো তালিকা এক পাতায় —
     * ঠিক যেটা পেজিনেশন আটকাতে বসানো (সেকশন ৯)। ৫০-ই যথেষ্ট: যে
     * প্রশ্নটার জন্য এটা বানানো ("কারা আসল"), তার উত্তর দশ-বিশ সারিতেই
     * থাকে।
     */
    private function topN(ReportDefinition $report, array $filters): ?int
    {
        if ($report->rankBy === null || ($filters['top'] ?? null) === null) {
            return null;
        }

        $top = (int) $filters['top'];

        return $top > 0 ? min($top, 50) : null;
    }

    /**
     * প্রতিটা সারি মোটের কত অংশ।
     *
     * ── কেন এটা একটা আলাদা কলাম, মাথার একটা বাক্য নয় ────────────────
     * "প্রথম দশজন ক্রেতা মোট বিক্রয়ের ৬৮%" — বাক্যটা দরকারি, কিন্তু
     * তার চেয়েও দরকারি কোন সারিটা কত। এক ক্রেতা যদি একাই ৪০% হন, সেটা
     * একটা ঝুঁকি: তিনি চলে গেলে ব্যবসার প্রায় অর্ধেক যায়। ওই সংখ্যাটা
     * শুধু সারির পাশে বসলেই চোখে পড়ে।
     *
     * শূন্য মোটে ভাগ করা হয় না — শূন্য বিক্রয়ে "কত অংশ" প্রশ্নটারই
     * কোনো উত্তর নেই, আর ডাটাবেজ ওখানে ভাগ-শূন্যে ভাঙত।
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, string>  $totals
     * @return Collection<int, array<string, mixed>>
     */
    private function addContribution(ReportDefinition $report, Collection $rows, array $totals): Collection
    {
        $total = $totals[$report->rankBy] ?? '0';

        return $rows->map(function (array $row) use ($report, $total): array {
            $value = (string) ($row[$report->rankBy] ?? '0');

            $row['contribution_percent'] = bccomp($total, '0', 4) === 0
                ? '0.00'
                : bcdiv(bcmul($value, '100', 6), $total, 2);

            return $row;
        });
    }

    /**
     * কোন সময়ের সাথে তুলনা — আর সেই সময়টা কোনটা।
     *
     * ── কেন কেবল গ্রুপ করা রিপোর্টে ─────────────────────────────────
     * তুলনা করতে হলে দুই সময়ের সারিগুলো **জোড়া বাঁধতে** হয়, আর জোড়া
     * বাঁধার একটা চাবি লাগে (কোন ক্রেতা, কোন পণ্য)। ডে বুকের সারিগুলোর
     * এমন কোনো চাবি নেই — ওখানে "গত মাসের একই সারি" বলে কিছু নেই।
     *
     * @return array{key: string, from: string, to: string}|null
     */
    private function comparisonPeriod(ReportDefinition $report, array $filters): ?array
    {
        $want = $filters['compare'] ?? null;

        if ($want === null || $report->groupBy === null || ! $report->hasFilter('date_range')) {
            return null;
        }

        $from = Carbon::parse($filters['from']);
        $to = Carbon::parse($filters['to']);

        /*
         * দুইটা তুলনাই দরকার, আর তারা আলাদা প্রশ্ন।
         *
         * আগের মাস বলে **গতি** — বাড়ছে না কমছে। গত বছরের একই মাস বলে
         * **মৌসুম বাদে** কেমন — রোজার মাসের বিক্রয় আগের মাসের চেয়ে
         * সবসময়ই বেশি, আর ওই তুলনাটা তাই কিছুই বলে না।
         */
        return match ($want) {
            self::COMPARE_PREVIOUS => [
                'key' => self::COMPARE_PREVIOUS,
                /*
                 * ঠিক ততদিন আগে, "গত মাস" নয়।
                 *
                 * ক্যালেন্ডারের মাস ধরলে ১–১০ তারিখের একটা পরিসর গোটা
                 * আগের মাসের সাথে তুলনা হত — দশ দিনের সাথে ত্রিশ দিনের,
                 * আর সংখ্যাটা সবসময় ভয়ংকর কমে যাওয়া দেখাত।
                 */
                'from' => $from->copy()->subDays($from->diffInDays($to) + 1)->toDateString(),
                'to' => $from->copy()->subDay()->toDateString(),
            ],
            self::COMPARE_LAST_YEAR => [
                'key' => self::COMPARE_LAST_YEAR,
                'from' => $from->copy()->subYear()->toDateString(),
                'to' => $to->copy()->subYear()->toDateString(),
            ],
            default => null,
        };
    }

    /**
     * আগের সময়ের সংখ্যা ও পরিবর্তন প্রতিটা সারিতে।
     *
     * ── কেন আগের সময়ে না-থাকা সারিও দেখানো হয় ──────────────────────
     * নতুন একটা ক্রেতা আগের মাসে ছিলেন না, তাই তাঁর আগের সংখ্যা শূন্য।
     * শতাংশে সেটা অসীম, তাই `change_percent` খালি রাখা হয় — "নতুন" আর
     * "১০০% বেড়েছে" এক কথা নয়, আর দ্বিতীয়টা মিথ্যা।
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array{key: string, from: string, to: string}  $period
     * @return Collection<int, array<string, mixed>>
     */
    private function addComparison(
        ReportDefinition $report,
        Collection $rows,
        array $filters,
        array $period,
    ): Collection {
        if ($rows->isEmpty() || $report->rankBy === null) {
            return $rows;
        }

        $before = $this->queryFor($report, [
            ...$filters,
            'from' => $period['from'],
            'to' => $period['to'],
        ])->get();

        $key = $this->groupKey($report);
        $rank = $report->rankBy;

        $previous = [];

        foreach ($before as $row) {
            $row = (array) $row;

            if (isset($row[$key])) {
                $previous[(string) $row[$key]] = (string) ($row[$rank] ?? '0');
            }
        }

        return $rows->map(function (array $row) use ($key, $rank, $previous): array {
            $was = $previous[(string) ($row[$key] ?? '')] ?? null;

            $row['previous_value'] = $was ?? '0';

            $now = (string) ($row[$rank] ?? '0');

            // আগের সময়ে কিছুই ছিল না — শতাংশে সেটা অসীম, তাই খালি
            $row['change_percent'] = ($was === null || bccomp($was, '0', 4) === 0)
                ? null
                : bcdiv(bcmul(bcsub($now, $was, 4), '100', 6), $was, 2);

            return $row;
        });
    }

    /**
     * সারিগুলো কোন কলাম ধরে জোড়া বাঁধে।
     *
     * `groupBy` SQL-এর ভাষায় লেখা হতে পারে (`i.customer_id`), অথচ ফলের
     * সারিতে নামটা থাকে উপসর্গ ছাড়া। এক জায়গায় ছেঁটে নিলে প্রতিটা
     * রিপোর্টকে দ্বিতীয় একটা নাম ঘোষণা করতে হয় না।
     */
    private function groupKey(ReportDefinition $report): string
    {
        $key = (string) $report->groupBy;

        return str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key;
    }

    /**
     * রপ্তানির জন্য সব সারি — পাতা ছাড়া, কিন্তু খণ্ডে খণ্ডে।
     *
     * শেয়ার্ড হোস্টে ১ লাখ রো একবারে মেমরিতে তুললে PHP-র সীমা ছাড়িয়ে যায়
     * (সেকশন ৯)। chunk করলে মেমরি স্থির থাকে।
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function stream(string $key, array $filters = [], int $chunk = 1000, bool $byBranch = false): \Generator
    {
        $report = $this->get($key);
        $filters = $this->normaliseFilters($report, $filters);

        /*
         * ⭐ শাখা ধরে ভাগ — চাওয়া হলে: প্রতিটা শাখার মাথা, সারি, শাখার মোট; শেষে
         * Grand Total। চিহ্নিত সারিগুলো `__section` ঘরে চেনা যায় — লেখক সেগুলো দেখে
         * মাথা ও মোটের সারি বসায়।
         */
        $plan = $byBranch ? $this->branchPlan($report, $filters, $this->topN($report, $filters), null) : [];

        if ($plan !== []) {
            foreach ($plan as $part) {
                $base = $this->queryFor($report, $part['filters']);

                if ($this->countFor($report, clone $base) === 0) {
                    continue;
                }

                yield ['__section' => 'head', '__branch' => $part['name']];

                $page = 1;

                do {
                    $rows = $this->queryFor($report, $part['filters'])->forPage($page, $chunk)->get();

                    foreach ($rows as $row) {
                        yield (array) $row;
                    }

                    $page++;
                } while ($rows->count() === $chunk);

                yield ['__section' => 'total', '__branch' => $part['name'], ...$this->totalsFor($report, $base)];
            }

            yield ['__section' => 'grand', ...$this->totalsFor($report, $this->queryFor($report, $filters))];

            return;
        }

        $page = 1;

        do {
            $rows = $this->queryFor($report, $filters)->forPage($page, $chunk)->get();

            foreach ($rows as $row) {
                yield (array) $row;
            }

            $page++;
        } while ($rows->count() === $chunk);
    }

    /**
     * শাখার দেয়াল — রিপোর্টের কোয়েরিতে `->tap(ReportEngine::branchWall($f, 'table.branch_id'))`।
     *
     * ── ⛔ কী ভাঙা ছিল, অডিট ২৭ সেপ্টেম্বর ২০২৬, §৩ ─────────────────────
     * রিপোর্টগুলো কাঁচা `DB::table` কোয়েরিতে লেখা, তাই মডেলের শাখা-ছাঁকনি সেখানে
     * পৌঁছায় না। ⚠️ এক শাখায় আটকানো কর্মী বিক্রয়ের রিপোর্ট খুললে গোটা
     * কোম্পানির সারি আর যোগফল পেতেন — পর্দায়, ফোনে, নির্ধারিত ফাইলে।
     *
     * ⭐ শাখা বাছা থাকলে কেবল সেটা (নাগালের ভেতরে কি না [[normaliseFilters()]]
     * আগেই দেখেছে); না বাছলে আটকানো মানুষের সব শাখা **আর শাখাহীন সারি**
     * ([[DataScope::allows()]]-এর একই নিয়ম — প্রধান অফিসের জাবেদায় শাখা
     * থাকে না); সীমা না থাকলে কিছুই না।
     *
     * @param  array<string, mixed>  $f
     * @return Closure(Builder|EloquentBuilder): void
     */
    public static function branchWall(array $f, string $column): Closure
    {
        self::$wallsBound++;

        return function ($query) use ($f, $column): void {
            // ⓘ শাখা ধরে ভাগের শাখাহীন দল — কেবল শাখা লেখা নেই এমন সারি
            if (! empty($f['branch_only_null'])) {
                $query->whereNull($column);

                return;
            }

            if (! empty($f['branch_id'])) {
                $query->where($column, $f['branch_id']);

                return;
            }

            $ids = $f['branch_ids'] ?? null;

            if ($ids !== null) {
                // ⓘ শাখাহীন সারি কেবল "সব শাখা"-তে — একটা শাখা বাছা থাকলে নয়
                $nulls = (bool) ($f['branch_nulls'] ?? true);
                $query->where(fn ($q) => $nulls
                    ? $q->whereIn($column, $ids)->orWhereNull($column)
                    : $q->whereIn($column, $ids));
            }
        };
    }

    /**
     * ⭐ ডিলারের দেয়াল — রিপোর্টের কোয়েরিতে `->tap(ReportEngine::dealerWall($f, 'i.customer_id'))`
     * (⛔১৬, ২ অক্টোবর ২০২৬)।
     *
     * ⓘ মালিক (২৬ সেপ্টেম্বর ২০২৬): বিক্রয়কর্মী কেবল নিজের ডিলারের বিল আর বকেয়া দেখবেন।
     * ⚠️ পণ্য বা ব্র্যান্ড ধরে বিক্রির রিপোর্ট ডিলারের নাম দেখায় না, তবু ছাঁকতে হয় — নইলে
     * বিক্রয়কর্মী গোটা কোম্পানির বিক্রির অঙ্ক দেখতেন।
     * ⛔ যে রিপোর্ট এটা ডাকে না, দেয়ালের ভিতরের মানুষ সেটা পান না ([[queryFor()]]) — ফেরত, ফাঁস নয়।
     *
     * @param  array<string, mixed>  $f
     * @return Closure(Builder|EloquentBuilder): void
     */
    public static function dealerWall(array $f, string $column): Closure
    {
        self::$dealerWallsBound++;

        return function ($query) use ($column): void {
            app(DealerScope::class)->restrict($query, $column);
        };
    }

    /**
     * রিপোর্টের কোয়েরি — আর দেয়াল বসেছে কি না তার হিসাব।
     *
     * ⛔ দেয়াল ভুলে যাওয়া রিপোর্ট শাখায় আটকানো মানুষের জন্য **চলে না**:
     * ফেরানোটা একটা ভাঙা পাতা, ফাঁস হওয়াটা চুপচাপ ভুল উত্তর — প্রথমটা
     * সেদিনই কেউ জানায়, দ্বিতীয়টা কেউ কোনোদিন টের পায় না।
     *
     * @param  array<string, mixed>  $filters
     */
    private function queryFor(ReportDefinition $report, array $filters): Builder|EloquentBuilder
    {
        $before = self::$wallsBound;
        $dealersBefore = self::$dealerWallsBound;
        $query = ($report->query)($filters);

        if (($filters['branch_ids'] ?? null) !== null && $report->branchless === null && self::$wallsBound === $before) {
            throw new RuntimeException(
                "Report '{$report->key}' never called ReportEngine::branchWall(), and this user is limited to branches — "
                .'it would show every branch. Bind the wall, or declare branchless with a written reason.'
            );
        }

        /*
         * ⛔ ডিলারের দেয়াল না বসানো রিপোর্ট দেয়ালের ভিতরের মানুষকে দেওয়া হয় না — ৪০৩
         * (⛔১৬, ২ অক্টোবর ২০২৬)। ⓘ গোটা কোম্পানির অঙ্ক চুপচাপ দেখানোর চেয়ে ভাঙা পাতা ভালো:
         * প্রথমটা কেউ টের পায় না, দ্বিতীয়টা সেদিনই কেউ জানায়।
         *
         * ⓘ যে রিপোর্টের কোয়েরি ডিলারের কোনো টেবিল ছোঁয়ই না (মজুদ, ক্রয়), তার দেখানোর মতো ডিলারও নেই —
         * সেটা চলে। ⚠️ মাপ কোয়েরির নিজের SQL থেকে, নাম থেকে নয় ([[touchesDealers()]]); সন্দেহ হলে ফেরত।
         * ⓘ ধরা পড়েছে হোম পর্দায়: মজুদের উইজেট সবার জন্য রিপোর্ট চালায়, আর বিক্রয়কর্মীর পুরো হোম ৪০৩ দিত।
         */
        if (! empty($filters['dealer_walled']) && self::$dealerWallsBound === $dealersBefore && self::touchesDealers($query)) {
            throw new AuthorizationException(
                "Report '{$report->key}' never called ReportEngine::dealerWall(), and this user sees only the dealers bound to him — "
                .'it would show every dealer. Lay the dealer wall on its query.'
            );
        }

        return $query;
    }

    /**
     * ⭐ কোয়েরিটা কি ডিলারের কোনো টেবিল বা পক্ষের ঘর ছোঁয় — গ্রাহক, বিক্রয়ের কাগজ, খাতা, ভাউচার,
     * চেক, প্রমোশন, বাঁধন, বা যেকোনো `party_` ঘর। ⛔ ছুঁলে দেয়াল ছাড়া চলে না (⛔১৬, ৪ অক্টোবর ২০২৬)।
     */
    private static function touchesDealers(Builder|EloquentBuilder $query): bool
    {
        return preg_match('/[`"\s.(](customers|customer_[a-z_]+|dealer_bindings|sal_[a-z_]+|ledger_entries|vouchers|acc_[a-z_]+|promotion_[a-z_]+|party_id|party_type)[`"\s.,)]/', ' '.$query->toSql().' ') === 1;
    }

    /**
     * ফিল্টারের ডিফল্ট — প্রতিটা তালিকায় একটা ডেট রেঞ্জ থাকতেই হবে
     * (সেকশন ৯), নাহলে প্রথম খোলাতেই পুরো ইতিহাস টানার চেষ্টা হয়।
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normaliseFilters(ReportDefinition $report, array $filters): array
    {
        if ($report->hasFilter('date_range')) {
            // ⭐ "শুরু থেকে আজ পর্যন্ত" — শুরুর তারিখ লাগে না (মালিক, ৩ অক্টোবর ২০২৬); খোলা জের তখন শূন্য
            $filters['all_time'] = ($filters['from'] ?? null) === self::ALL_TIME;

            if ($filters['all_time']) {
                $filters['from'] = self::BEGINNING;
            }

            $filters['to'] = $filters['to'] ?? Carbon::today()->toDateString();
            // ⛔ শুরু না দিলে "শেষ" তারিখের মাসের ১ তারিখ, আজকের মাসের নয় — পাতা-ঝাড়ু ধাপ ০ (১০ অক্টোবর ২০২৬): রেওয়ামিলে "যে
            // তারিখ পর্যন্ত" ৩০ সেপ্টেম্বর বাছলে শুরু বসত ১ অক্টোবর, আর পাতা ৫০০ (শুরুর ঘর ওখানে দেখানোই হয় না)
            $filters['from'] = $filters['from'] ?? Carbon::parse($filters['to'])->startOfMonth()->toDateString();

            if (Carbon::parse($filters['from'])->gt(Carbon::parse($filters['to']))) {
                throw new RuntimeException(
                    'The start date is after the end date — that range holds nothing.'
                );
            }
        }

        $filters['company_id'] = CompanyContext::id();
        $filters['branch_id'] = $filters['branch_id'] ?? null;

        /*
         * ⛔ শাখা একটাই সংখ্যা — অডিট ⛔৭ (৬ অক্টোবর ২০২৬)। `branch_id[]=5` এলে নিচের নাগালের যাচাই `(int) array` = 1 দেখত,
         * অথচ কোয়েরি `where(col, [5])` শাখা ৫ পড়ত: শাখা ১-এ সীমিত মানুষ যেকোনো শাখার রিপোর্ট খুলতে পারতেন।
         * ⓘ তাই অ্যারে, শূন্য, ঋণাত্মক বা লেখা — সব ফেরত; সংখ্যা হলে পূর্ণসংখ্যায় বসে, যাচাই আর কোয়েরি একই শাখা দেখে।
         */
        // ⓘ ০ বা খালি মানে আগের মতোই "শাখা বাছা নেই"
        if ($filters['branch_id'] !== null && $filters['branch_id'] !== '' && $filters['branch_id'] !== 0 && $filters['branch_id'] !== '0') {
            if (! is_scalar($filters['branch_id']) || ! ctype_digit((string) $filters['branch_id'])) {
                throw ValidationException::withMessages(['branch_id' => __('validation.branch_out_of_reach')]);
            }

            $filters['branch_id'] = (int) $filters['branch_id'];
        } else {
            $filters['branch_id'] = null;
        }

        /*
         * ⛔ শাখার সীমা — পর্দা, ফোন, নির্ধারিত ফাইল, সবার একটাই দরজা এটা।
         *
         * ⓘ নির্ধারিত ফাইল মালিকের নামে চলে (runner লগইন করায়), তাই
         * এখানে "কে" মানে সবসময় যাঁর জন্য সংখ্যাগুলো।
         */
        $allowed = app(DataScope::class)->idsFor(auth()->user(), UserDataScope::BRANCH);

        /*
         * ⭐ কোম্পানির সব শাখা যাঁর হাতে, তিনি সীমিত নন — লাইভে ধরা, ২৮ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ মালিক নিজের ব্যবহারকারীতে কোম্পানির একমাত্র শাখাটা টিক দিয়েছিলেন, আর
         * অনুমোদনের সব রিপোর্ট ৪০৩ দিল — "পুরো কোম্পানি" রিপোর্ট সীমিত লোকের নয় বলে।
         * ⛔ তালিকাটা সব শাখা ঢাকলে সীমা কিছুই আটকায় না; তাকে "সীমিত" ধরা মানে কেবল
         * বাধা, সুরক্ষা নয়। ⚠️ পরে নতুন শাখা খুললে তিনি আবার সীমিত — নতুন শাখা তাঁর
         * তালিকায় নেই, আর সেটাই ঠিক।
         */
        if ($allowed !== null) {
            // ⚠️ কাঁচা প্রশ্ন, স্কোপ ছাড়া — শাখার মডেলে ব্যবহারকারীর সীমা বসলে "সব শাখা" মানে
            // "তাঁর শাখা" হয়ে যেত, আর যে-কেউ নিজেকে অসীম দেখাতেন
            $every = DB::table('branches')->where('company_id', CompanyContext::id())->pluck('id')
                ->map(fn ($id) => (int) $id)->all();

            if ($every !== [] && array_diff($every, $allowed) === []) {
                $allowed = null;
            }
        }

        if ($allowed !== null && $report->branchless === ReportDefinition::WHOLE_COMPANY) {
            throw new AuthorizationException(
                "Report '{$report->key}' counts the whole company and cannot be split by branch; a branch-limited user cannot have it."
            );
        }

        /*
         * ⛔ নাগালের বাইরের শাখা চাইলে ফেরত — চুপচাপ নিজের শাখায় নামিয়ে
         * আনা নয়: তাহলে "নেত্রকোনার বিক্রয়" শিরোনামে ময়মনসিংহের সংখ্যা
         * ছাপা হত, আর সেটা ভুল বলে চেনার উপায় থাকত না।
         */
        if ($allowed !== null && ! empty($filters['branch_id']) && ! in_array((int) $filters['branch_id'], $allowed, true)) {
            throw ValidationException::withMessages(['branch_id' => __('validation.branch_out_of_reach')]);
        }

        $filters['branch_ids'] = $allowed;
        $filters['branch_nulls'] = true;

        // ⭐ ডিলারের দেয়াল — দেখার মানুষ বিক্রয়কর্মী কি না; প্রশ্নটা প্রতি রানে নতুন (⛔১৬)
        $filters['dealer_walled'] = app(DealerScope::class)->walled();

        /*
         * ⭐ দেখার শাখা — হেডারে একটা শাখা বাছা থাকলে রিপোর্টও কেবল সেটা, শাখাহীন
         * সারি ছাড়া (মালিকের নির্দেশ, ২৯ সেপ্টেম্বর ২০২৬: "শুধু সেই শাখার ডাটা")।
         * ⓘ রিপোর্টের নিজের শাখা-ছাঁকনি বাছা থাকলে সেটাই আগে — উপরে নাগালের ভেতরে
         * কি না দেখা হয়ে গেছে।
         */
        $scope = app(DataScope::class);

        if (empty($filters['branch_id']) && $scope->viewsOneBranch(auth()->user())) {
            $filters['branch_ids'] = $scope->viewBranchIds(auth()->user());
            $filters['branch_nulls'] = false;
        }

        /*
         * ঘরটা সবসময় থাকে, খালি হলেও।
         *
         * না থাকলে যে রিপোর্ট এটা ব্যবহার করে সেটা `undefined index`-এ
         * ভাঙত ঠিক তখন, যখন কেউ ছাঁকনিটা ছোঁয়নি — অর্থাৎ সবচেয়ে
         * সাধারণ ব্যবহারেই। `branch_id` ঠিক একই কারণে এখানে আছে।
         */
        $filters['party_type_id'] = $filters['party_type_id'] ?? null;

        /*
         * ⭐ খরচের কেন্দ্র — একই কারণে সবসময় থাকে (২০ সেপ্টেম্বর ২০২৬)।
         *
         * ⓘ প্রকল্পভিত্তিক খতিয়ান এই ঘরটাই ব্যবহার করে (মানচিত্র §১৮):
         * ডিপোতে "প্রকল্প" আর "খরচের কেন্দ্র" একই জিনিস — রুট, গুদাম,
         * গাড়ি, বা একটা কাজ। ⚠️ খতিয়ানে আলাদা `project_id` কলাম বসালে
         * হ্যাশ-শিকলে সই করা ঘরের তালিকা বদলাত, আর তাতে **আগের প্রতিটা
         * সারির হ্যাশ অবৈধ** হয়ে যেত ([[App\Core\Security\LedgerChain]])।
         */
        $filters['cost_center_id'] = $filters['cost_center_id'] ?? null;

        /*
         * ⛔ গুদাম, পণ্য, ব্র্যান্ড… — ঠিকানার মান কেবল যা এই মানুষ দেখতে পারেন (রিপোর্ট সেন্টার ধাপ ১)।
         *
         * ⓘ আগে যেকোনো নম্বর সোজা কোয়েরিতে বসত — অন্যের দোকানের নম্বর লিখলেই তার বিক্রয়। এখন উৎস দিয়ে মেলানো,
         * আর বাছাই-ঘরের লেখা ("কোড · নাম") নম্বরে বদলায়। ⛔ না মিললে রিপোর্টই ফেরে, ছাঁকনি ফেলে গোটা তালিকা নয়।
         */
        return app(ReportFilters::class)->resolve($report, $filters);
    }

    /**
     * পুরো ফলের যোগফল।
     *
     * মূল কোয়েরিটাকে সাব-কোয়েরি বানিয়ে তার উপর SUM — সরাসরি SELECT-এ
     * SUM জুড়ে দিলে দুইভাবে ভাঙে: বাকি কলামগুলো তখনো select তালিকায়
     * থাকে (only_full_group_by আপত্তি করে), আর রেওয়ামিলের মতো GROUP BY
     * করা রিপোর্টে যোগফলটা গ্রুপের ভেতরে চলে যায়, সব গ্রুপের উপরে নয়।
     *
     * @return array<string, string>
     */
    /**
     * কতটা সারি — গ্রুপ করা রিপোর্টেও ঠিক।
     *
     * সরাসরি count() ডাকলে GROUP BY করা কোয়েরিতে ভুল উত্তর আসে: SQL
     * তখন প্রতিটা গ্রুপের জন্য একটা করে গণনা ফেরত দেয়, আর Laravel
     * প্রথমটাই নিয়ে নেয়। ফলে রেওয়ামিলে "৪টি সারি" মানে ছিল "প্রথম
     * খাতে ৪টি এন্ট্রি" — সংখ্যাটা ভুল, অথচ দেখতে যুক্তিসঙ্গত।
     *
     * ভুলটা ধরা পড়েছে ক্যাশ ফ্লোতে: তিন দিনের ডাটায় "১টি সারি" দেখাচ্ছিল,
     * অথচ পর্দায় তিনটা সারিই ছিল।
     *
     * যোগফলের মতোই সমাধান — কোয়েরিটাকে সাব-কোয়েরি বানিয়ে তার উপর গণনা।
     */
    /**
     * ⭐ পর্দার খোঁজার ঘর — ৪১টা রিপোর্টে একটাই জায়গা।
     *
     * ── ⓘ কেন `HAVING`, `WHERE` নয় ──────────────────────────────────
     * খোঁজাটা **পর্দায় যা দেখা যাচ্ছে** তার উপর হয়, ভিতরের টেবিলের
     * কলামের উপর নয় — ব্যবহারকারী যে নামটা চোখে দেখছেন সেটাই টাইপ
     * করেন। ⚠️ কিন্তু ঐ নামগুলো ছদ্মনাম (`supplier_name`), আর ছদ্মনাম
     * `WHERE`-এ ব্যবহার করা যায় না। ⓘ `HAVING`-এ যায়।
     *
     * ⛔ সহজ বিকল্প ছিল পুরো কোয়েরিটাকে একটা সাবকোয়েরিতে মুড়ে দেওয়া
     * (ইঞ্জিন যোগফলের জন্য তা-ই করে)। কিন্তু সাবকোয়েরির ভিতরের
     * `ORDER BY` MySQL ফেলে দিতে পারে, আর তখন **খুঁজলেই সারির ক্রম
     * বদলে যেত** — একটা রিপোর্ট যেটা বড় থেকে ছোট সাজানো, খুঁজলে আর
     * সাজানো থাকত না, অথচ কিছুই ভাঙত না।
     *
     * ⓘ ৪১টা রিপোর্টেই মেপে দেখা হয়েছে `HAVING` চলে (MySQL ৮.৪)।
     *
     * ── ⚠️ কোন কলামে খোঁজা হয় ──────────────────────────────────────
     * কেবল লেখার কলামে — নাম, নথি, তারিখ। ⛔ টাকার কলামে নয়: `১২৩`
     * টাইপ করলে `১২৩৪৫.০০`ও মিলত, আর ফলটা দেখতে এলোমেলো লাগত।
     *
     * @param  Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    private function applySearch(ReportDefinition $report, $query, mixed $term)
    {
        $term = is_string($term) ? trim($term) : '';

        if ($term === '') {
            return $query;
        }

        $columns = $report->searchableColumns();

        if ($columns === []) {
            return $query;
        }

        /*
         * ⚠️ কলামের নামগুলো ঘোষণা থেকে আসে, ব্যবহারকারীর কাছ থেকে নয় —
         * তাই ওগুলো নিরাপদ। শব্দটা বাঁধা মান হিসেবেই যায়।
         *
         * ⓘ `\` আর `%` আর `_` পালানো হয়: না করলে কেউ `%` টাইপ করলে
         * সব সারি মিলত, আর খোঁজাটা কিছুই ছাঁকত না।
         */
        $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        $base = $query instanceof \Illuminate\Database\Eloquent\Builder ? $query->getQuery() : $query;

        /*
         * ⓘ `GROUP BY` থাকলে `HAVING` লাইভেও চলে — আগের মতো, ছদ্মনামের উপর।
         */
        if (! empty($base->groups)) {
            $sql = implode(' OR ', array_map(fn (string $c) => "`{$c}` LIKE ?", $columns));

            return $query->havingRaw('('.$sql.')', array_fill(0, count($columns), $needle));
        }

        /*
         * ⛔ `GROUP BY` ছাড়া `HAVING` লাইভের MariaDB (`ONLY_FULL_GROUP_BY`) নেয় না — *1463 Non-grouping
         * field … is used in HAVING clause*; ২৯ সেপ্টেম্বর ২০২৬ লাইভে ৬৭টার ২৭টা রিপোর্ট ভাঙত, খাতা আর
         * ক্যাশ বই সহ। লোকালের MySQL ৮.৪ এটা মেনে নেয়, তাই কোনো টেস্ট লাল হয়নি।
         *
         * ⭐ তাই কোয়েরিটা মোড়া হয়, আর ছাঁকা হয় বাইরের `WHERE`-এ — ছদ্মনামগুলো তখন সাধারণ কলাম।
         * ⚠️ উপরের পুরনো আপত্তিটা (মোড়ালে ভিতরের `ORDER BY` হারায়) সত্যি, তাই ভিতরের ক্রমটা
         * `ROW_NUMBER() OVER (ORDER BY …)` হয়ে একটা ঘরে বাইরে আসে, আর বাইরে তা দিয়েই সাজানো হয় —
         * খুঁজলে সারির ক্রম বদলায় না। (MySQL ৮ আর MariaDB ১০.২+ দুইটাতেই চলে।)
         */
        $inner = clone $query;
        $order = $this->orderSql($base);

        if ($order !== null) {
            $innerBase = $inner instanceof \Illuminate\Database\Eloquent\Builder ? $inner->getQuery() : $inner;

            if ($innerBase->columns === null) {
                $inner->select('*');
            }

            $inner->reorder()->selectRaw(
                'ROW_NUMBER() OVER (ORDER BY '.$order[0].') AS '.self::SEARCH_ORDER,
                $order[1],
            );
        }

        $outer = DB::query()
            ->fromSub($inner, 'searched')
            ->where(function ($where) use ($columns, $needle) {
                foreach ($columns as $column) {
                    $where->orWhere('searched.'.$column, 'like', $needle);
                }
            });

        return $order !== null ? $outer->orderBy('searched.'.self::SEARCH_ORDER) : $outer;
    }

    /**
     * কোয়েরির `ORDER BY` অংশটা SQL হিসেবে, বাঁধা মানসহ — নাকি কোনো ক্রমই নেই।
     *
     * @return array{0: string, 1: list<mixed>}|null
     */
    private function orderSql(\Illuminate\Database\Query\Builder $base): ?array
    {
        if (empty($base->orders)) {
            return null;
        }

        $grammar = $base->getGrammar();
        $parts = [];

        foreach ($base->orders as $order) {
            $parts[] = isset($order['sql'])
                ? (string) $order['sql']
                : $grammar->wrap($order['column']).' '.$order['direction'];
        }

        return [implode(', ', $parts), array_values($base->getRawBindings()['order'] ?? [])];
    }

    private function countFor(ReportDefinition $report, $query): int
    {
        /*
         * ⛔ কোয়েরি নিজে দল বাঁধলে (`GROUP BY`) সরল `count()` প্রথম দলের সারি গোনে, দলের সংখ্যা নয় — পাতা-ঝাড়ু ধাপ ০
         * (১০ অক্টোবর ২০২৬): "শাখা পাশাপাশি" দেখাত "৯০টি সারি", অথচ শাখা একটা (৯০ = প্রধান শাখার খাতার সারি)।
         * ⓘ তাই ঘোষণা না থাকলেও কোয়েরির নিজের দল দেখা হয়।
         */
        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;

        if ($report->groupBy === null && empty($base->groups) && empty($base->havings)) {
            return $query->count();
        }

        return (int) DB::query()
            ->fromSub($query->reorder(), 'grouped')
            ->count();
    }

    private function totalsFor(ReportDefinition $report, $query): array
    {
        $columns = $report->totalledColumns();

        if ($columns === []) {
            return [];
        }

        $selects = array_map(
            fn (ReportColumn $c) => "SUM(t.{$c->key}) as total_{$c->key}",
            $columns,
        );

        $row = DB::query()
            ->fromSub($query->reorder(), 't')
            ->selectRaw(implode(', ', $selects))
            ->first();

        $totals = [];

        foreach ($columns as $column) {
            $value = data_get((array) $row, 'total_'.$column->key, 0);

            // bcadd দিয়ে স্বাভাবিক করা — SUM() null ফেরত দিতে পারে (ফাঁকা
            // ফল), আর তখন "0.00"-ই সঠিক উত্তর, ফাঁকা ঘর নয়।
            $totals[$column->key] = bcadd((string) ($value ?? 0), '0', $column->decimals());
        }

        return $totals;
    }

    /**
     * চলমান ব্যালেন্স — লেজারে প্রতিটা সারির পর কত দাঁড়াল।
     *
     * দ্বিতীয় পাতায় শুরুটা শূন্য নয়, আগের পাতাগুলোর যোগফল। এটা না করলে
     * প্রতিটা পাতা শূন্য থেকে শুরু হত আর ব্যালেন্স কলামটা মিথ্যা বলত।
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function addRunningBalance(
        ReportDefinition $report,
        Collection $rows,
        array $filters,
        int $page,
        int $perPage,
    ): Collection {
        // ⭐ পরিসরের আগের জের থেকে — অডিট গ৯ ([[ReportDefinition::$opening]])
        $opening = $report->opening !== null ? bcadd((string) ($report->opening)($filters), '0', 4) : '0';
        $before = $opening;

        if ($page > 1) {
            /*
             * ⛔ এখানে `->reorder()` ছিল — ২০ সেপ্টেম্বর ২০২৬ পর্যন্ত।
             *
             * ⚠️ ওটা লেজারের নিজের `orderBy('trx_date')->orderBy('id')`
             * **মুছে দিত**, আর ক্রম ছাড়া `forPage(1, N)`-এর মানে দাঁড়াত
             * "যেকোনো N সারি"। ⓘ সারির **সংখ্যা** ঠিক আসত, তাই যোগফলটা
             * দেখতে বিশ্বাসযোগ্য — কিন্তু কোন সারিগুলো, সেটা MySQL-এর
             * খেয়াল। ⛔ ফল: দ্বিতীয় পাতা থেকে চলমান ব্যালেন্স ভুল, আর
             * ভুলটা প্রতিবার একই রকমও নয়।
             *
             * ⭐ আগের সারিগুলো এখন PHP-তে আনাই হয় না — যোগটা SQL-এ।
             * ⓘ ৫০ নম্বর পাতায় আগে ৪,৯০০টা সারি মেমোরিতে উঠত, কেবল
             * দুইটা সংখ্যা বের করতে। ⚠️ ক্রমটা ভেতরের কোয়েরিতেই থাকে,
             * কারণ **কোন** সারিগুলো গোনা হবে সেটা ক্রমই ঠিক করে; বাইরের
             * যোগফল ক্রম নিয়ে মাথা ঘামায় না।
             */
            $earlier = $this->queryFor($report, $filters)->forPage(1, ($page - 1) * $perPage);

            $sums = DB::connection($earlier->getConnection()->getName())
                ->query()
                ->fromSub($earlier, 'earlier')
                ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
                ->first();

            /*
             * ⓘ যোগফলটা এক সারি, আর সেটা [[RunningBalance]]-এর ভিতর দিয়েই
             * যায় — টাকার অঙ্ক bcmath ছাড়া জোড়া লাগে না, আর নিয়মটা দুই
             * জায়গায় লিখলে একদিন দুইটা আলাদা উত্তর দিত।
             */
            $opening = bcadd($before, RunningBalance::sumOf(
                $sums === null ? [] : [$sums],
                fn ($row) => ((array) $row)['debit'] ?? 0,
                fn ($row) => ((array) $row)['credit'] ?? 0,
            ), 4);
        }

        // হিসাবটা RunningBalance-এ, এখানে নয় — গ্রাহকের পর্দাতেও একই
        // চলমান ব্যালেন্স লাগে, আর দুই জায়গায় দুইবার লিখলে একদিন দুইটা
        // আলাদা উত্তর দিত।
        $running = new RunningBalance($opening);

        return $rows->map(function (array $row) use ($running) {
            $row['balance'] = $running->add($row['debit'] ?? 0, $row['credit'] ?? 0);

            return $row;
        });
    }

    /**
     * ছাপা (`?print=1`) বা ফাইল (`?export=csv|xlsx|json`) চাওয়া হয়েছে কি না — তখন গোটা পরিসর, পাতা নয়।
     * ⓘ রিকোয়েস্ট না থাকলে (কনসোল, নির্ধারিত রিপোর্ট) কখনো নয়।
     */
    public static function wholeDocumentWanted(): bool
    {
        if (! app()->bound('request')) {
            return false;
        }

        return request()->boolean('print') || app(\App\Core\Services\ListExport::class)->wanted();
    }

}
