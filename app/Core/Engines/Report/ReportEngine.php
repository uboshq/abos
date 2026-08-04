<?php

declare(strict_types=1);

namespace App\Core\Engines\Report;

use App\Core\Support\CompanyContext;
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
        $count = (clone $query)->count();

        $rows = $query
            ->forPage(max(1, $page), $perPage)
            ->get()
            ->map(fn ($row) => (array) $row);

        if ($report->runningBalance) {
            $rows = $this->addRunningBalance($report, $rows, $filters, $page, $perPage);
        }

        return new ReportResult(
            report: $report,
            rows: $rows->all(),
            totals: $totals,
            totalRows: $count,
            page: max(1, $page),
            perPage: $perPage,
            filters: $filters,
        );
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

            foreach ($before as $row) {
                $row = (array) $row;
                $opening = bcadd($opening, bcsub((string) ($row['debit'] ?? 0), (string) ($row['credit'] ?? 0), 4), 4);
            }
        }

        $balance = $opening;

        return $rows->map(function (array $row) use (&$balance) {
            $balance = bcadd($balance, bcsub((string) ($row['debit'] ?? 0), (string) ($row['credit'] ?? 0), 4), 4);
            $row['balance'] = $balance;

            return $row;
        });
    }
}
