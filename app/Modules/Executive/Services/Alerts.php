<?php

declare(strict_types=1);

namespace App\Modules\Executive\Services;

use App\Core\Engines\Report\ReportEngine;
use App\Models\Approval;
use App\Models\User;
use App\Modules\Governance\Services\CompanylessRows;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * সতর্কতা — সব কোম্পানির, এক তালিকায়।
 *
 * ── ⛔ প্রতিটা সংখ্যা একটা রিপোর্টের সারি গোনা ──────────────────────────
 * "সীমার বাইরে ১২ জন" মানে ঠিক যে রিপোর্টে চাপলে খোলে, সেই রিপোর্টের ১২টা সারি
 * — একই ছাঁকনিতে, একই কোম্পানিতে, একই শাখার সীমায়। ⓘ নিজে গুনলে সংখ্যা আর
 * তালিকা একদিন আলাদা হত, আর মালিক তালিকায় ১০ জন দেখে ভাবতেন ২ জন কোথায়।
 *
 * ⚠️ চাবি না থাকলে ঐ কোম্পানির ঘরটা `null` — শূন্য নয় (শূন্য মানে "সব ঠিক আছে")।
 */
final class Alerts
{
    public const OVER_LIMIT = 'over_limit';

    public const OVERDUE = 'overdue';

    public const STOCK_LOW = 'stock_low';

    public const STOCK_NEGATIVE = 'stock_negative';

    public const STOCK_DEAD = 'stock_dead';

    public const LATE_DELIVERY = 'late_delivery';

    public const FAILED_LOGIN = 'failed_login';

    public const BACKDATED = 'backdated';

    /** @var list<string> */
    public const KINDS = [
        self::OVER_LIMIT, self::OVERDUE, self::STOCK_LOW, self::STOCK_NEGATIVE,
        self::STOCK_DEAD, self::LATE_DELIVERY, self::FAILED_LOGIN, self::BACKDATED,
    ];

    /** ব্যর্থ লগইন আর দেরির চালান — কত দিন পেছনে দেখা */
    public const LOOKBACK_DAYS = 7;

    /** গোনার জন্য একটা রিপোর্ট থেকে সর্বোচ্চ সারি */
    private const MOST_ROWS = 5000;

    public function __construct(
        private readonly CompanyLens $lens,
        private readonly ReportEngine $reports,
    ) {}

    /**
     * প্রতিটা কোম্পানির প্রতিটা সতর্কতা — গোনা আর কোথায় খুলবে।
     *
     * @param  list<array{id: int, name: string, only?: ?int}>  $companies
     * @return list<array{kind: string, total: int, companies: list<array{id: int, name: string, count: ?int, route: string, params: array<string, mixed>}>}>
     */
    public function all(User $user, array $companies): array
    {
        $out = [];

        foreach (self::KINDS as $kind) {
            $parts = [];
            $total = 0;

            foreach ($companies as $company) {
                $count = $this->count($user, $company, $kind);
                [$route, $params] = self::target($kind);

                $parts[] = ['id' => $company['id'], 'name' => $company['name'], 'count' => $count, 'route' => $route, 'params' => $params];
                $total += $count ?? 0;
            }

            $out[] = ['kind' => $kind, 'total' => $total, 'companies' => $parts];
        }

        return $out;
    }

    /**
     * সইয়ের অপেক্ষায় — সব কোম্পানির, নতুনগুলো আগে।
     *
     * @param  list<array{id: int, name: string, only?: ?int}>  $companies
     * @return list<array{company_id: int, company_name: string, label: string, amount: ?string, requested_at: ?string, route: string, params: array<string, mixed>}>
     */
    public function waiting(User $user, array $companies, int $limit = 8): array
    {
        $rows = [];

        foreach ($companies as $company) {
            $part = $this->lens->within($user, $company['id'], $company['only'] ?? null, function () use ($user, $limit): array {
                if (! $user->can('approval.view')) {
                    return [];
                }

                return Approval::query()
                    ->pending()
                    ->orderByDesc('requested_at')
                    ->orderByDesc('id')
                    ->limit($limit)
                    ->get()
                    ->map(fn (Approval $a) => [
                        'label' => $a->drillLabel(),
                        'amount' => $a->amount === null ? null : (string) $a->amount,
                        'requested_at' => $a->requested_at?->toDateTimeString(),
                        'route' => $a->drillRoute()[0],
                        'params' => $a->drillRoute()[1],
                    ])
                    ->all();
            });

            foreach ($part as $row) {
                $rows[] = [...$row, 'company_id' => $company['id'], 'company_name' => $company['name']];
            }
        }

        usort($rows, fn (array $a, array $b) => strcmp((string) $b['requested_at'], (string) $a['requested_at']));

        return array_slice($rows, 0, $limit);
    }

    /**
     * কোথায় খুলবে — রিপোর্টের নিজের পাতা, ঠিক এই ছাঁকনিতে।
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function target(string $kind): array
    {
        $since = Carbon::today()->subDays(self::LOOKBACK_DAYS - 1)->toDateString();
        $today = Carbon::today()->toDateString();

        return match ($kind) {
            self::OVER_LIMIT => ['sales.report.show', ['slug' => 'risky-customers']],
            self::OVERDUE => ['sales.report.show', ['slug' => 'collection-due']],
            self::STOCK_LOW => ['inventory.report.show', ['slug' => 'stock-alerts', 'status' => 'below']],
            self::STOCK_NEGATIVE => ['inventory.report.show', ['slug' => 'stock-alerts', 'status' => 'negative']],
            self::STOCK_DEAD => ['inventory.report.show', ['slug' => 'slow-dead', 'status' => 'dead']],
            self::LATE_DELIVERY => ['sales.report.show', ['slug' => 'otif', 'from' => $since, 'to' => $today]],
            self::FAILED_LOGIN => ['governance.login.index', ['only' => 'failed', 'from' => $since, 'to' => $today]],
            self::BACKDATED => ['governance.report.show', ['slug' => 'backdated', 'from' => Carbon::today()->startOfMonth()->toDateString(), 'to' => $today]],
        };
    }

    /**
     * এক কোম্পানির এক সতর্কতা — `null` = দেখার চাবি নেই।
     *
     * @param  array{id: int, name: string, only?: ?int}  $company
     */
    private function count(User $user, array $company, string $kind): ?int
    {
        $key = implode(':', ['executive-alert', 'v'.Board::version($user), 'u'.$user->id, 'c'.$company['id'], 'b'.($company['only'] ?? 'all'), $kind, Carbon::today()->toDateString()]);

        return Cache::remember($key, Board::CACHE_SECONDS, fn () => $this->lens->within(
            $user, $company['id'], $company['only'] ?? null, fn (): ?int => $this->countHere($user, $kind),
        ));
    }

    private function countHere(User $user, string $kind): ?int
    {
        [, $params] = self::target($kind);
        $filters = array_diff_key($params, ['slug' => true]);

        return match ($kind) {
            self::OVER_LIMIT => $this->rowsWhere($user, 'sales.credit_risk', $filters, fn (array $r) => bccomp((string) ($r['over_limit'] ?? '0'), '0', 4) > 0),
            self::OVERDUE => $this->rowsWhere($user, 'sales.collection_due', $filters, fn (array $r) => bccomp((string) ($r['overdue'] ?? '0'), '0', 4) > 0),
            self::STOCK_LOW, self::STOCK_NEGATIVE => $this->rowsWhere($user, 'inventory.stock_alerts', $filters),
            self::STOCK_DEAD => $this->rowsWhere($user, 'inventory.slow_dead', $filters),
            // ⓘ পাকা হওয়ার দিন পেরিয়েছে, অথচ সময়মতো পুরো আসেনি — রিপোর্টের নিজের `due`/`otif` ঘর
            self::LATE_DELIVERY => $this->rowsWhere($user, 'sales.otif', $filters, fn (array $r) => (int) ($r['due'] ?? 0) === 1 && (int) ($r['otif'] ?? 0) === 0),
            self::FAILED_LOGIN => $user->can('governance.login.view')
                ? app(CompanylessRows::class)->logins($user)->failed()
                    ->whereDate('created_at', '>=', $filters['from'])
                    ->whereDate('created_at', '<=', $filters['to'])
                    ->count()
                : null,
            self::BACKDATED => $this->rowsWhere($user, 'governance.backdated', $filters),
        };
    }

    /**
     * রিপোর্টের সারি গোনা — শর্ত না দিলে রিপোর্টের নিজের মোট সারি।
     *
     * @param  array<string, mixed>  $filters
     */
    private function rowsWhere(User $user, string $report, array $filters, ?callable $keep = null): ?int
    {
        $definition = $this->reports->get($report);

        if (! $definition->allows($user)) {
            return null;
        }

        try {
            $result = $this->reports->run($report, $filters, 1, $keep === null ? 1 : self::MOST_ROWS);
        } catch (Throwable $e) {
            // ⚠️ একটা রিপোর্ট ভাঙলে গোটা পাতা ৫০০ নয় — ঘরটা "—", আর ভুলের খাতায় সারি
            report($e);

            return null;
        }

        if ($keep === null) {
            return $result->fullRowCount ?? $result->totalRows;
        }

        return count(array_filter($result->rows, $keep));
    }
}
