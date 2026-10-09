<?php

declare(strict_types=1);

namespace App\Modules\Executive\Services;

use App\Core\Engines\Report\Trend;
use App\Models\User;
use App\Modules\Sales\Metrics\SalesMetrics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * "আজ" পাতার ছক — কোম্পানি × শাখা, আটটা সংখ্যা, আর গ্রুপের যোগফল।
 *
 * ── ⭐ যোগফল = সারিগুলোর যোগ, সবসময় ────────────────────────────────────
 * প্রতিটা কোম্পানির মোট আসে "সব শাখা" দেখে — মডিউলের নিজের সংখ্যা। শাখার সারি
 * আসে প্রতিটা শাখা বেছে। ⚠️ দুইটা সবসময় মেলে না: শাখাহীন সারি (মালিকের মূলধন,
 * কোম্পানির মজুদের স্তর) কোনো শাখায় পড়ে না। ⓘ তাই ফারাকটা লুকানো হয় না —
 * "শাখায় ভাগ হয়নি" নামে নিজের সারিতে বসে, আর ছকের যোগ মোটের সাথে হুবহু মেলে।
 *
 * ── ⓘ ক্যাশ — কোম্পানি ধরে ৫ মিনিট ───────────────────────────────────
 * আটটা সংখ্যা × প্রতিটা শাখা × প্রতিটা কোম্পানি — প্রতিবার খুললে সবগুলো নতুন
 * করে গুনলে শেয়ার্ড হোস্টে পাতাটা ধীর হত। ⭐ "এখনই নতুন করে" বোতাম মানুষটার
 * নিজের সংস্করণ বাড়ায় ([[refresh()]]), তাই তাঁর সব ক্যাশ একসাথে বাসি হয়।
 * ⚠️ ক্যাশের চাবিতে মানুষটা আছেন — একজনের সীমায় গোনা সংখ্যা আরেকজন পান না।
 */
final class Board
{
    public const CACHE_SECONDS = 300;

    /** বিক্রি বনাম আদায়ের ধারা — কত দিনের */
    public const TREND_DAYS = 30;

    /** ছকের ভিতরে কয়টা সারি পর্যন্ত, তারপর ছকটা নিজেই সরে (পাতা নয়) */
    public const VISIBLE_ROWS = 10;

    public function __construct(
        private readonly CompanyLens $lens,
        private readonly Figures $figures,
        private readonly Eliminations $eliminations,
    ) {}

    /**
     * গোটা ছক।
     *
     * @return array{
     *     companies: list<array<string, mixed>>,
     *     total: array<string, ?string>,
     *     partial: array<string, bool>,
     *     eliminated: array<string, string>|null,
     *     header_branch: ?int,
     *     period: string, from: string, to: string,
     *     trend: list<array{label: string, date: string, sales: string, collections: string}>,
     *     choices: list<array{id: int, name: string, branches: list<array{id: int, name: string}>}>,
     * }
     */
    public function build(User $user, string $period, ?int $companyId = null, ?int $branchId = null): array
    {
        $period = in_array($period, Figures::PERIODS, true) ? $period : Figures::TODAY;
        [$from, $to] = Figures::window($period);

        $header = $this->lens->headerBranch($user);
        $current = (int) ($user->current_company_id ?? 0);

        $choices = [];
        $companies = [];

        foreach ($this->lens->companies($user) as $company) {
            $branches = $this->lens->branches($user, $company['id']);

            /*
             * ⭐ হেডারে একটা শাখা বাছা থাকলে চলতি কোম্পানিটা কেবল সেই শাখা — ঠিক
             * যেমন ঐ কোম্পানির নিজের ড্যাশবোর্ড দেখায় ([[DataScope::viewsOneBranch()]])।
             */
            $only = $company['id'] === $current && $header !== null ? $header : null;

            if ($only !== null) {
                $branches = array_values(array_filter($branches, fn (array $b) => $b['id'] === $only));
            }

            $choices[] = [...$company, 'branches' => $branches];

            if ($companyId !== null && $company['id'] !== $companyId) {
                continue;
            }

            // ⓘ পাতার শাখা-ছাঁকনি — কেবল মানুষটার নাগালের শাখা, আর হেডারের শাখাকে চওড়া করে না
            if ($companyId !== null && $branchId !== null && $only === null
                && in_array($branchId, array_column($branches, 'id'), true)) {
                $only = $branchId;
                $branches = array_values(array_filter($branches, fn (array $b) => $b['id'] === $only));
            }

            $companies[] = [
                ...$company,
                'narrowed' => $only !== null,
                'only' => $only,
                ...$this->company($user, $company['id'], $branches, $only, $from, $to),
            ];
        }

        [$total, $partial] = $this->groupTotal($companies);

        /*
         * ⭐ ভাই-কোম্পানি বাদ — গ্রুপের ভিতরের বিক্রি, পাওনা আর দেনা গ্রুপের মোট থেকে (IFRS 10)।
         * ⓘ ছকে নিজের সারিতে দেখায়, তাই গ্রুপের মোট = সারিগুলোর যোগ − এই সারি; জোড়া না থাকলে সারিটাই নেই।
         */
        $eliminated = $this->eliminations->amounts($user, array_map(
            fn (array $c) => ['id' => $c['id'], 'name' => $c['name'], 'only' => $c['only']], $companies), $from, $to);

        foreach ($eliminated ?? [] as $key => $amount) {
            if ($total[$key] !== Figures::HIDDEN) {
                $total[$key] = bcsub($total[$key], $amount, 4);
            }
        }

        return [
            'companies' => $companies,
            'total' => $total,
            'partial' => $partial,
            'eliminated' => $eliminated,
            'header_branch' => $header,
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'trend' => $this->trend($companies),
            'choices' => $choices,
        ];
    }

    /** মানুষটার সব ক্যাশ একসাথে বাসি — "এখনই নতুন করে" বোতাম */
    public function refresh(User $user): void
    {
        Cache::forever(self::versionKey($user), self::version($user) + 1);
    }

    /**
     * এক কোম্পানির সারি, মোট আর ধারা — ক্যাশে।
     *
     * @param  list<array{id: int, name: string}>  $branches
     * @return array{rows: list<array<string, mixed>>, unsplit: ?array<string, ?string>, values: array<string, ?string>, trend: array<string, array{sales: ?string, collections: ?string}>}
     */
    private function company(User $user, int $companyId, array $branches, ?int $only, string $from, string $to): array
    {
        $key = implode(':', [
            'executive', 'v'.self::version($user), 'u'.$user->id, 'c'.$companyId, 'b'.($only ?? 'all'),
            $from, $to, Carbon::today()->toDateString(), app()->getLocale(),
        ]);

        return Cache::remember($key, self::CACHE_SECONDS, fn () => $this->compute($user, $companyId, $branches, $only, $from, $to));
    }

    /**
     * @param  list<array{id: int, name: string}>  $branches
     * @return array<string, mixed>
     */
    private function compute(User $user, int $companyId, array $branches, ?int $only, string $from, string $to): array
    {
        $values = $this->lens->within($user, $companyId, $only, fn () => $this->values($user, $from, $to));

        $rows = [];

        foreach ($branches as $branch) {
            $rowValues = $only === $branch['id']
                ? $values
                : $this->lens->within($user, $companyId, $branch['id'], fn () => $this->values($user, $from, $to));

            $rows[] = [...$branch, 'values' => $this->onlyBranchFigures($rowValues, $values)];
        }

        return [
            'rows' => $rows,
            'unsplit' => $only === null ? $this->unsplit($values, $rows) : null,
            'values' => $values,
            'trend' => $this->lens->within($user, $companyId, $only, fn () => $this->dailyTrend($user)),
        ];
    }

    /**
     * আটটা সংখ্যা, চলতি প্রসঙ্গে। ঢাকা ঘর = [[Figures::HIDDEN]]।
     *
     * @return array<string, string>
     */
    private function values(User $user, string $from, string $to): array
    {
        $out = [];

        foreach (Figures::KEYS as $key) {
            // ⓘ জের-এর সংখ্যা (বাকি, তহবিল, মজুদ) পরিসর পড়ে না — "এখন"-এর
            $out[$key] = $this->figures->value($user, $key, $from, $to) ?? Figures::HIDDEN;
        }

        return $out;
    }

    /**
     * শাখার সারিতে কেবল শাখা ধরে ভাগ হওয়া সংখ্যা; বাকিগুলো `null` (পর্দায় "—")।
     *
     * @param  array<string, string>  $row
     * @param  array<string, string>  $total
     * @return array<string, ?string>
     */
    private function onlyBranchFigures(array $row, array $total): array
    {
        $out = [];

        foreach (Figures::KEYS as $key) {
            $out[$key] = match (true) {
                $total[$key] === Figures::HIDDEN => Figures::HIDDEN,
                ! Figures::definition($key)['byBranch'] => null,
                default => $row[$key],
            };
        }

        return $out;
    }

    /**
     * "শাখায় ভাগ হয়নি" — মোট থেকে শাখাগুলোর যোগ বাদ। সবগুলো শূন্য হলে সারিটাই নেই।
     *
     * @param  array<string, string>  $total
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, ?string>|null
     */
    private function unsplit(array $total, array $rows): ?array
    {
        $out = [];
        $any = false;

        foreach (Figures::KEYS as $key) {
            if ($total[$key] === Figures::HIDDEN) {
                $out[$key] = Figures::HIDDEN;

                continue;
            }

            $rest = $total[$key];

            foreach ($rows as $row) {
                if ($row['values'][$key] !== null && $row['values'][$key] !== Figures::HIDDEN) {
                    $rest = bcsub($rest, $row['values'][$key], 4);
                }
            }

            $out[$key] = $rest;
            $any = $any || bccomp($rest, '0', 4) !== 0;
        }

        return $any ? $out : null;
    }

    /**
     * গ্রুপের যোগ — প্রতিটা কোম্পানির মোটের যোগ (= সব সারির যোগ)।
     *
     * ⚠️ কোনো কোম্পানিতে ঘরটা ঢাকা থাকলে যোগটা অসম্পূর্ণ — `partial` বলে দেয়,
     * পর্দা পাশে লেখে। ⛔ নীরবে কম যোগ দেখানো একটা মিথ্যা সংখ্যা।
     *
     * @param  list<array<string, mixed>>  $companies
     * @return array{0: array<string, ?string>, 1: array<string, bool>}
     */
    private function groupTotal(array $companies): array
    {
        $total = [];
        $partial = [];

        foreach (Figures::KEYS as $key) {
            $sum = null;
            $partial[$key] = false;

            foreach ($companies as $company) {
                $value = $company['values'][$key];

                if ($value === Figures::HIDDEN) {
                    $partial[$key] = true;

                    continue;
                }

                $sum = bcadd($sum ?? '0', $value, 4);
            }

            $total[$key] = $sum ?? Figures::HIDDEN;
        }

        return [$total, $partial];
    }

    /**
     * গত ৩০ দিনের বিক্রি আর আদায় — প্রতিদিন, বিক্রয়ের নিজের সংজ্ঞায় ([[Trend::series()]])।
     *
     * @return array<string, array{sales: ?string, collections: ?string}>
     */
    private function dailyTrend(User $user): array
    {
        $to = Carbon::today()->toDateString();
        $from = Carbon::today()->subDays(self::TREND_DAYS - 1)->toDateString();

        $sales = $this->figures->visible($user, Figures::SALES)
            ? Trend::series(Trend::DAILY, $from, $to, fn (string $f, string $t) => SalesMetrics::invoiceTotal($f, $t))
            : null;

        $collections = $this->figures->visible($user, Figures::COLLECTIONS)
            ? Trend::series(Trend::DAILY, $from, $to, fn (string $f, string $t) => SalesMetrics::collectionTotal($f, $t))
            : null;

        $out = [];

        foreach (Trend::buckets(Trend::DAILY, $from, $to) as $i => $bucket) {
            $out[$bucket['from']] = [
                'sales' => $sales === null ? null : $sales[$i]['value'],
                'collections' => $collections === null ? null : $collections[$i]['value'],
            ];
        }

        return $out;
    }

    /**
     * সব কোম্পানির ধারা একসাথে — দিন ধরে যোগ।
     *
     * @param  list<array<string, mixed>>  $companies
     * @return list<array{label: string, date: string, sales: string, collections: string}>
     */
    private function trend(array $companies): array
    {
        $out = [];

        foreach (Trend::buckets(Trend::DAILY, Carbon::today()->subDays(self::TREND_DAYS - 1)->toDateString(), Carbon::today()->toDateString()) as $bucket) {
            $sales = '0';
            $collections = '0';

            foreach ($companies as $company) {
                $day = $company['trend'][$bucket['from']] ?? null;
                $sales = bcadd($sales, (string) ($day['sales'] ?? '0'), 4);
                $collections = bcadd($collections, (string) ($day['collections'] ?? '0'), 4);
            }

            $out[] = ['label' => $bucket['label'], 'date' => $bucket['from'], 'sales' => $sales, 'collections' => $collections];
        }

        return $out;
    }

    public static function version(User $user): int
    {
        return (int) Cache::get(self::versionKey($user), 0);
    }

    private static function versionKey(User $user): string
    {
        return 'executive:version:u'.$user->id;
    }
}
