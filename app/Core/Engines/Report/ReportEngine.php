<?php

declare(strict_types=1);

namespace App\Core\Engines\Report;

use App\Core\Support\CompanyContext;
use App\Core\Support\RunningBalance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
    /** ঠিক ততদিন আগের পরিসর — গতি বোঝায় */
    public const COMPARE_PREVIOUS = 'previous';

    /** গত বছরের একই পরিসর — মৌসুম বাদ দিয়ে বোঝায় */
    public const COMPARE_LAST_YEAR = 'last_year';

    /** @var array<string, ReportDefinition> */
    private array $reports = [];

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
    public function run(string $key, array $filters = [], int $page = 1, int $perPage = 100): ReportResult
    {
        $report = $this->get($key);
        $filters = $this->normaliseFilters($report, $filters);

        $query = ($report->query)($filters);

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
            ->map(fn ($row) => (array) $row);

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
        );
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

        $before = ($report->query)([
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
    public function stream(string $key, array $filters = [], int $chunk = 1000): \Generator
    {
        $report = $this->get($key);
        $filters = $this->normaliseFilters($report, $filters);

        $page = 1;

        do {
            $rows = ($report->query)($filters)->forPage($page, $chunk)->get();

            foreach ($rows as $row) {
                yield (array) $row;
            }

            $page++;
        } while ($rows->count() === $chunk);
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
            $filters['from'] = $filters['from'] ?? Carbon::today()->startOfMonth()->toDateString();
            $filters['to'] = $filters['to'] ?? Carbon::today()->toDateString();

            if (Carbon::parse($filters['from'])->gt(Carbon::parse($filters['to']))) {
                throw new RuntimeException(
                    'The start date is after the end date — that range holds nothing.'
                );
            }
        }

        $filters['company_id'] = CompanyContext::id();
        $filters['branch_id'] = $filters['branch_id'] ?? null;

        /*
         * ঘরটা সবসময় থাকে, খালি হলেও।
         *
         * না থাকলে যে রিপোর্ট এটা ব্যবহার করে সেটা `undefined index`-এ
         * ভাঙত ঠিক তখন, যখন কেউ ছাঁকনিটা ছোঁয়নি — অর্থাৎ সবচেয়ে
         * সাধারণ ব্যবহারেই। `branch_id` ঠিক একই কারণে এখানে আছে।
         */
        $filters['party_type_id'] = $filters['party_type_id'] ?? null;

        return $filters;
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
    private function countFor(ReportDefinition $report, $query): int
    {
        if ($report->groupBy === null) {
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
        $opening = '0';

        if ($page > 1) {
            $before = ($report->query)($filters)
                ->reorder()
                ->forPage(1, ($page - 1) * $perPage)
                ->get();

            $opening = RunningBalance::sumOf(
                $before,
                fn ($row) => ((array) $row)['debit'] ?? 0,
                fn ($row) => ((array) $row)['credit'] ?? 0,
            );
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
}
