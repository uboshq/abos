<?php

declare(strict_types=1);

namespace App\Modules\Executive\Services;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Engines\Report\Trend;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * তুলনা — কোম্পানি বনাম কোম্পানি, শাখা বনাম শাখা; এ মাস বনাম আগের মাস বা গত বছরের একই মাস।
 *
 * ── ⓘ কোনো নতুন গোনা নেই ───────────────────────────────────────────────
 * "এখন" আর "তখন" দুইটাই [[Figures::value()]] — মডিউলের নিজের সংজ্ঞা, কেবল তারিখ আলাদা। ⓘ আগের
 * সময়টা ঠিক হয় একটাই নিয়মে ([[Trend::previous()]]), রিপোর্টের "তুলনা" যেটা ডাকে।
 *
 * ── ⚠️ বাকির "তখন" ──────────────────────────────────────────────────────
 * বাকি একটা জের — "আজ কত"। আগের মাসের শেষে কত ছিল, সেটা আজকের খাতা থেকে বের করা যায় বটে, কিন্তু
 * তখনকার শাখার সীমা আর পরে বসানো পিছনের তারিখের কাগজ মিলিয়ে সেটা আরেকটা সংজ্ঞা হত। ⭐ তাই রাতের
 * হিসাব ([[Snapshots]]) থেকে নেওয়া হয় — ঐ দিন রাতে পর্দায় যা ছিল, ঠিক তাই। না থাকলে "—"।
 */
final class Comparison
{
    public const COMPANIES = 'companies';

    public const BRANCHES = 'branches';

    /** @var list<string> */
    public const AGAINST = [Trend::PREVIOUS_MONTH, ReportEngine::COMPARE_LAST_YEAR];

    public function __construct(
        private readonly CompanyLens $lens,
        private readonly Figures $figures,
    ) {}

    /**
     * তুলনার সারি — প্রতিটায় ছয়টা সংখ্যার এখন, তখন আর বদল (%)।
     *
     * @return array{
     *     mode: string, against: string, company: ?int,
     *     now: array{from: string, to: string}, was: array{from: string, to: string},
     *     rows: list<array{id: int, name: string, company_id: int, branch_id: ?int, now: array<string, ?string>, was: array<string, ?string>, change: array<string, ?string>}>,
     *     companies: list<array{id: int, name: string}>,
     * }
     */
    public function build(User $user, string $mode, ?int $companyId, string $against): array
    {
        $against = in_array($against, self::AGAINST, true) ? $against : Trend::PREVIOUS_MONTH;
        $now = ['from' => Carbon::today()->startOfMonth()->toDateString(), 'to' => Carbon::today()->toDateString()];
        $was = Trend::previous($against, $now['from'], $now['to']);

        $companies = $this->lens->companies($user);
        $ids = array_column($companies, 'id');

        // ⓘ শাখা বনাম শাখা একটা কোম্পানির ভিতরে — বাছা না থাকলে প্রথমটা
        $mode = $mode === self::BRANCHES ? self::BRANCHES : self::COMPANIES;
        $companyId = in_array($companyId, $ids, true) ? $companyId : ($mode === self::BRANCHES ? ($ids[0] ?? null) : null);

        $places = [];

        if ($mode === self::COMPANIES) {
            foreach ($companies as $company) {
                if ($companyId === null || $company['id'] === $companyId) {
                    $places[] = ['id' => $company['id'], 'name' => $company['name'], 'company_id' => $company['id'], 'branch_id' => null];
                }
            }
        } elseif ($companyId !== null) {
            foreach ($this->lens->branches($user, $companyId) as $branch) {
                $places[] = ['id' => $branch['id'], 'name' => $branch['name'], 'company_id' => $companyId, 'branch_id' => $branch['id']];
            }
        }

        $rows = [];

        foreach ($places as $place) {
            $key = implode(':', ['executive-compare', 'v'.Board::version($user), 'u'.$user->id, 'c'.$place['company_id'],
                'b'.($place['branch_id'] ?? 'all'), $now['from'], $now['to'], $was['from'], $was['to']]);

            $rows[] = [...$place, ...Cache::remember($key, Board::CACHE_SECONDS, fn () => $this->compare($user, $place, $now, $was))];
        }

        return [
            'mode' => $mode,
            'against' => $against,
            'company' => $companyId,
            'now' => $now,
            'was' => $was,
            'rows' => $rows,
            'companies' => $companies,
        ];
    }

    /**
     * একটা সংখ্যার ধারা — সব বাছা জায়গা মিলিয়ে, খোপে খোপে ([[Trend::series()]])।
     *
     * @param  list<array{company_id: int, branch_id: ?int}>  $places
     * @return list<array{label: string, from: string, to: string, value: string}>
     */
    public function trend(User $user, array $places, string $key, string $grain, string $from, string $to): array
    {
        $grain = in_array($grain, Trend::GRAINS, true) ? $grain : Trend::MONTHLY;
        $total = [];

        foreach ($places as $place) {
            $cache = implode(':', ['executive-trend', 'v'.Board::version($user), 'u'.$user->id, 'c'.$place['company_id'],
                'b'.($place['branch_id'] ?? 'all'), $key, $grain, $from, $to]);

            $series = Cache::remember($cache, Board::CACHE_SECONDS, fn () => $this->lens->within($user, $place['company_id'], $place['branch_id'],
                fn () => $this->figures->visible($user, $key)
                    ? Trend::series($grain, $from, $to, fn (string $f, string $t) => (string) $this->figures->value($user, $key, $f, $t))
                    : null));

            foreach ($series ?? [] as $i => $bucket) {
                $total[$i] ??= [...$bucket, 'value' => '0'];
                $total[$i]['value'] = bcadd($total[$i]['value'], $bucket['value'], 4);
            }
        }

        return array_values($total);
    }

    /**
     * @param  array{company_id: int, branch_id: ?int}  $place
     * @param  array{from: string, to: string}  $now
     * @param  array{from: string, to: string}  $was
     * @return array{now: array<string, ?string>, was: array<string, ?string>, change: array<string, ?string>}
     */
    private function compare(User $user, array $place, array $now, array $was): array
    {
        return $this->lens->within($user, $place['company_id'], $place['branch_id'], function () use ($user, $place, $now, $was): array {
            $out = ['now' => [], 'was' => [], 'change' => []];

            foreach (Figures::COMPARED as $key) {
                $current = $this->figures->value($user, $key, $now['from'], $now['to']);

                $previous = match (true) {
                    $current === null => null,
                    Figures::definition($key)['period'] => $this->figures->value($user, $key, $was['from'], $was['to']),
                    default => null,
                };

                $out['now'][$key] = $current ?? Figures::HIDDEN;
                $out['was'][$key] = $current === null ? Figures::HIDDEN : $previous;
                $out['change'][$key] = $current !== null && $previous !== null ? Trend::change($current, $previous) : null;
            }

            return $out;
        });
    }
}
