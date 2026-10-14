<?php

declare(strict_types=1);

namespace App\Modules\Executive\Services;

use App\Core\Engines\Report\ReportEngine;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * সবচেয়ে বড় ক্রেতা, পণ্য, এলাকা, বিক্রয়কর্মী — সব কোম্পানি মিলিয়ে।
 *
 * ── ⛔ নিজের কোয়েরি নেই ────────────────────────────────────────────────
 * প্রতিটা তালিকা কেন্দ্রীয় রিপোর্টের নিজের ফল ([[ReportEngine::run()]]) — ঐ
 * কোম্পানিতে, মানুষটার সীমায় বসে। ⓘ তাই "ঐ ক্রেতার বিক্রি" এখানে আর ঐ রিপোর্টে
 * এক সংখ্যা, আর সারিতে চাপলে যে রিপোর্ট খোলে সেটাও এই সংখ্যাই দেখায়।
 *
 * ⚠️ চাবি না থাকলে ঐ কোম্পানির সারি আসে না ([[ReportDefinition::allows()]]) — খালি
 * তালিকাকে "কেউ কেনেনি" পড়া যায়, তাই পর্দা কোম্পানির নাম ধরে সেটা বলে দেয়।
 */
final class Rankings
{
    /** একটা রিপোর্ট থেকে প্রথম টানে কয়টা সারি — রিপোর্টে এর বেশি থাকলে পুরোটা ([[MOST_ROWS]] পর্যন্ত) */
    private const PULL = 200;

    /** ⓘ একটা রিপোর্ট থেকে সর্বোচ্চ সারি — [[Alerts::MOST_ROWS]]-এর একই সীমা */
    private const MOST_ROWS = 5000;

    public function __construct(
        private readonly CompanyLens $lens,
        private readonly ReportEngine $reports,
    ) {}

    /**
     * রিপোর্টের সারি, সব কোম্পানির, `$measure` ধরে বড় থেকে ছোট — প্রথম `$limit`টা।
     *
     * ⓘ `only` থাকলে ঐ কোম্পানি কেবল ঐ শাখায় ([[Board::build()]]-এর একই হেডার-নিয়ম)।
     *
     * @param  list<array{id: int, name: string, only?: ?int}>  $companies
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, refused: list<string>}
     */
    public function top(User $user, array $companies, string $report, string $measure, int $limit, array $filters = [], bool $ascending = false): array
    {
        $rows = [];
        $refused = [];

        foreach ($companies as $company) {
            // ⓘ মাপ, দিক আর সংখ্যাও চাবিতে — কোম্পানির ভাগ এখন বাছাই করে কাটা, তাই একই রিপোর্টের দুই তালিকা আলাদা
            $key = implode(':', ['executive-rank', 'u'.$user->id, 'c'.$company['id'], 'b'.($company['only'] ?? 'all'), $report, md5(serialize($filters)),
                $measure, $ascending ? 'asc' : 'desc', $limit, app()->getLocale(), Board::version($user)]);

            $part = Cache::remember($key, Board::CACHE_SECONDS, fn () => $this->lens->within($user, $company['id'], $company['only'] ?? null, function () use ($user, $report, $filters, $measure, $limit, $ascending): ?array {
                $definition = $this->reports->get($report);

                if (! $definition->allows($user)) {
                    return null;
                }

                /*
                 * ⛔ রিপোর্টের নিজের ক্রমে প্রথম ২০০ সারি কেটে তারপর অন্য মাপে সাজালে সারি হারায় — ec, Executive লাইন-নিরীক্ষা,
                 * ১৪ অক্টোবর ২০২৬ ([[TheRankingsSortEveryRowTest]])। ⓘ পণ্য ধরে বিক্রি সাজায় লাভে, অথচ "কম মার্জিন" চায়
                 * শতাংশের নিচ থেকে: ৩৫০ পণ্যের ৩৪০ নম্বরটা −৮% হলেও তালিকায় আসত না। এখন রিপোর্টে বেশি সারি থাকলে পুরোটা
                 * টানা ([[MOST_ROWS]] পর্যন্ত), সাজিয়ে কেবল প্রথম `$limit`টা রাখা।
                 */
                $result = $this->reports->run($report, $filters, 1, self::PULL);

                if ($result->totalRows > self::PULL) {
                    $result = $this->reports->run($report, $filters, 1, min($result->totalRows, self::MOST_ROWS));
                }

                return array_slice($this->sorted($result->rows, $measure, $ascending), 0, $limit);
            }));

            if ($part === null) {
                $refused[] = $company['name'];

                continue;
            }

            foreach ($part as $row) {
                $rows[] = [...$row, 'company_id' => $company['id'], 'company_name' => $company['name']];
            }
        }

        return ['rows' => array_slice($this->sorted($rows, $measure, $ascending), 0, $limit), 'refused' => $refused];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sorted(array $rows, string $measure, bool $ascending): array
    {
        usort($rows, fn (array $a, array $b) => $ascending
            ? bccomp((string) ($a[$measure] ?? '0'), (string) ($b[$measure] ?? '0'), 4)
            : bccomp((string) ($b[$measure] ?? '0'), (string) ($a[$measure] ?? '0'), 4));

        return $rows;
    }

    /**
     * রিপোর্টের নিজের যোগফল — সব কোম্পানির যোগ (যেমন বাকির বয়সের খোপগুলো)।
     *
     * ⓘ রিপোর্ট যোগফল গোনে গোটা ফলের উপর ([[ReportEngine::run()]]), পাতার নয়; এখানে কেবল কোম্পানিগুলো যোগ।
     *
     * @param  list<array{id: int, name: string, only?: ?int}>  $companies
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $filters
     * @return array{totals: array<string, string>, refused: list<string>}
     */
    public function totals(User $user, array $companies, string $report, array $columns, array $filters = []): array
    {
        $totals = array_fill_keys($columns, '0');
        $refused = [];

        foreach ($companies as $company) {
            $key = implode(':', ['executive-total', 'u'.$user->id, 'c'.$company['id'], 'b'.($company['only'] ?? 'all'), $report, md5(serialize($filters)), Board::version($user)]);

            $part = Cache::remember($key, Board::CACHE_SECONDS, fn () => $this->lens->within($user, $company['id'], $company['only'] ?? null, function () use ($user, $report, $filters): ?array {
                if (! $this->reports->get($report)->allows($user)) {
                    return null;
                }

                return $this->reports->run($report, $filters, 1, 1)->totals;
            }));

            if ($part === null) {
                $refused[] = $company['name'];

                continue;
            }

            foreach ($columns as $column) {
                $totals[$column] = bcadd($totals[$column], (string) ($part[$column] ?? '0'), 4);
            }
        }

        return ['totals' => $totals, 'refused' => $refused];
    }
}
