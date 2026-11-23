<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Dashboard\HomeFilter;
use App\Core\Dashboard\HomePeriod;
use App\Core\Support\CompanyContext;
use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ⭐ Dashboard figures remembered for five minutes, per company, branch and user (speed work, 23 Nov 2026).
 *
 * ── Why ──────────────────────────────────────────────────────────────────
 * Measured on live as the owner: the home page spent 1.7 s and 584 queries asking every module's dashboard for its
 * figures, and /dashboard/finance 4.3 s drawing its seven rows. The figures move only when something is posted, yet
 * every page open counted them all again.
 *
 * ── ⛔ What keeps a remembered figure honest ─────────────────────────────
 *   • **Scope** — the key holds the company, the chosen branch, the branches the user may see, the user, the user's
 *     keys (spatie roles and permissions plus the per-user overrides), the user's own row version, the language, the
 *     day, the home period and filter, and the page's query string. Two users never share a figure; a user whose keys
 *     change starts afresh.
 *   • **Keys still gate on display** — callers remember the *computed* figures only; the permission filter
 *     (a widget's key, a stat's mask, a section's key) runs on every page, after the cache, against the user of now.
 *     A figure that needs a key the user lacks is never shown, cached or not.
 *   • **Posting busts it** — every ledger row and stock movement written moves the company's generation number
 *     (after the transaction commits), and the number is part of the key: the next page counts afresh.
 *   • **Only finished, serialisable answers** — a value holding a closure cannot be stored and is simply returned
 *     uncached; nothing about the page changes.
 *   • **Only on a read-only page** ([[ReadWindow]]) — a form post, a console command, a queue job or a test between
 *     two requests always counts afresh.
 *
 * ⓘ Totals still count only final papers — this class never decides what is counted, it only remembers the answer.
 */
final class DashboardFigureCache
{
    public const TTL_SECONDS = 300;

    private const GENERATION = 'dash-figures-gen:';

    /** @var array<int, true> companies whose generation moves when the current transaction commits */
    private static array $pending = [];

    /** @var array<string, string> generation read once per request */
    private array $generations = [];

    private ?string $release = null;

    public function __construct(private readonly ReadWindow $window) {}

    /**
     * The value under `$part` for the current scope — computed by `$compute` when missing, stale or not storable.
     *
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    public function remember(string $part, Closure $compute): mixed
    {
        $key = $this->key($part);

        if ($key === null) {
            return $compute();
        }

        try {
            $hit = Cache::get($key);
        } catch (Throwable) {
            $hit = null;
        }

        /*
         * ⛔ 23 Nov 2026, demo: the home page gave 500 for everyone — "tried to access a property on an incomplete object
         * … App\Core\Dashboard\Widget". The cache refuses to rebuild any object (`cache.serializable_classes = false`,
         * a hardening), so a remembered Widget came back as __PHP_Incomplete_Class. The Mac runs use the array store,
         * which never serialises, so no test saw it.
         * ⓘ Now the answer is stored as its own serialised text with the list of classes inside it, and read back
         *   allowing exactly those classes — and only ones from the allowed families ([[storable()]]). A class outside
         *   them means "not stored"; a hit that would still hold an incomplete object is a miss, never a page.
         */
        $value = self::revive($hit);

        if ($value !== self::MISS) {
            return $value;
        }

        $value = $compute();

        try {
            // ⓘ a closure, a PDO handle or a resource inside the answer → not storable; the page still gets its answer
            $blob = serialize($value);
            $classes = self::classesIn($blob);

            if ($classes !== null) {
                Cache::put($key, ['v' => 2, 'blob' => $blob, 'classes' => $classes], self::TTL_SECONDS);
            }
        } catch (Throwable) {
            // not storable — returned as computed
        }

        return $value;
    }

    /** ⓘ a stand-in for "no usable hit" — `null` and `false` are real answers a provider may give */
    private const MISS = "\0dash-figure-miss";

    /** the families a remembered answer may hold: our own classes, Laravel's collections and models, and dates */
    private const STORABLE = ['App\\', 'Illuminate\\Support\\', 'Illuminate\\Database\\Eloquent\\', 'Carbon\\',
        'DateTime', 'DateTimeImmutable', 'DateTimeZone', 'DateInterval', 'DatePeriod', 'stdClass', 'ArrayObject', 'ArrayIterator'];

    private static function storable(string $class): bool
    {
        foreach (self::STORABLE as $family) {
            if ($class === $family || (str_ends_with($family, '\\') && str_starts_with($class, $family))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The classes inside a serialised answer — or null when any of them is outside the allowed families.
     *
     * ⓘ May list a name that only looked like an object inside a string; that only widens the allow list by a name of
     *   an allowed family, never past it.
     *
     * @return list<string>|null
     */
    private static function classesIn(string $blob): ?array
    {
        preg_match_all('/[OC]:\d+:"([^"]+)":/', $blob, $m);
        $classes = array_values(array_unique($m[1]));

        foreach ($classes as $class) {
            if (! self::storable($class)) {
                return null;
            }
        }

        return $classes;
    }

    private static function revive(mixed $hit): mixed
    {
        if (! is_array($hit) || ($hit['v'] ?? null) !== 2 || ! is_string($hit['blob'] ?? null) || ! is_array($hit['classes'] ?? null)) {
            return self::MISS;
        }

        // ⓘ the allow list is read from the text itself, not trusted from the stored list — every object in it is allowed,
        //   or nothing is revived (⛔ never an incomplete object on a page)
        $allowed = self::classesIn($hit['blob']);

        if ($allowed === null) {
            return self::MISS;
        }

        try {
            $value = unserialize($hit['blob'], ['allowed_classes' => $allowed]);
        } catch (Throwable) {
            return self::MISS;
        }

        return $value === false && $hit['blob'] !== serialize(false) ? self::MISS : $value;
    }

    /** the full cache key of `$part` for the current scope, or null when nothing may be remembered now */
    public function key(string $part): ?string
    {
        if ($this->window->generation() === null) {
            return null;
        }

        $user = auth()->user();
        $company = CompanyContext::id();

        if (! $user instanceof User || $company === null) {
            return null;
        }

        // ⓘ the person's viewing wall, asked of the core itself (the core names no module; BoundariesTest)
        $branches = app(DataScope::class)->viewBranchIds($user);
        $query = request()->query();
        ksort($query);

        return 'dash-figures:'.$company.':'.$user->getKey().':'.sha1(implode('|', [
            $part,
            // ⓘ a new release never reads figures stored by the old code
            $this->release ??= \App\Core\Support\Release::version().'/'.(\App\Core\Support\Release::sha() ?? ''),
            $this->generation($company),
            (string) CompanyContext::branchId(),
            $branches === null ? 'all' : implode(',', $branches),
            $this->keysOf($user),
            (string) ($user->updated_at?->getTimestamp() ?? ''),
            app()->getLocale(),
            Carbon::today()->toDateString(),
            (string) HomePeriod::chosen(),
            json_encode(HomeFilter::current()?->query() ?? []),
            json_encode($query),
        ]));
    }

    /** the user's keys as one fingerprint — spatie roles and permissions in this company plus the per-user overrides */
    private function keysOf(User $user): string
    {
        $memo = CompanyContext::id().'#'.$user->getKey().'#'.spl_object_id($user).'#'.$this->window->generation();

        return $this->keys[$memo] ??= $this->readKeysOf($user);
    }

    /** @var array<string, string> */
    private array $keys = [];

    private function readKeysOf(User $user): string
    {
        try {
            $permissions = $user->getAllPermissions()->pluck('name')->sort()->values()->all();
            $roles = $user->getRoleNames()->sort()->values()->all();
        } catch (Throwable) {
            $permissions = $roles = ['?'.microtime()];
        }

        $overrides = app(PermissionOverrides::class)->allFor($user);
        ksort($overrides);

        return sha1(json_encode([$roles, $permissions, $overrides]) ?: '');
    }

    private function generation(int $company): string
    {
        if (! isset($this->generations[$company])) {
            try {
                $this->generations[$company] = (string) (Cache::get(self::GENERATION.$company) ?? '0');
            } catch (Throwable) {
                $this->generations[$company] = 'x'.microtime();
            }
        }

        return $this->generations[$company];
    }

    /** a rolled-back transaction wrote nothing — the next write registers its bust again */
    public static function rolledBack(): void
    {
        self::$pending = [];
    }

    /**
     * ⭐ Something was posted for the company — every remembered figure of the company is dropped once the current
     * transaction commits (at once when there is none). A rolled-back write moves nothing.
     */
    public static function bust(?int $companyId): void
    {
        if ($companyId === null || isset(self::$pending[$companyId])) {
            return;
        }

        self::$pending[$companyId] = true;

        DB::afterCommit(static function () use ($companyId): void {
            unset(self::$pending[$companyId]);

            try {
                Cache::forever(self::GENERATION.$companyId, bin2hex(random_bytes(6)));
            } catch (Throwable) {
                // ⓘ the five-minute life still bounds a figure
            }

            if (app()->resolved(self::class)) {
                app(self::class)->generations = [];
            }
        });
    }
}
