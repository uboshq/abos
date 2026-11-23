<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Services\ReadWindow;
use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use Closure;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Collection;

/**
 * ⭐ The chart's code lookups and its tree, read once per read-only page (speed work, 23 Nov 2026).
 *
 * ── Why ──────────────────────────────────────────────────────────────────
 * Measured on live, owner, /dashboard/finance: [[StandardChart::find()]] ran 483 times (≈20 different codes) and the
 * company's whole chart was loaded 297 times to walk a group's subtree ([[Account::balancePool()]] and
 * [[Account::selfAndDescendants()]]) — ≈800 queries and ≈2 s of the page's 5.5 s, most of it PHP building the
 * same models again.
 *
 * ── ⛔ Why the answer cannot differ ───────────────────────────────────────
 *   • Only inside a [[ReadWindow]] (a GET/HEAD page being built); anywhere else the loader runs every time.
 *   • The key holds the company, the user and the branches the user sees — the two global scopes the query carries
 *     ([[BelongsToCompany]], `viewed-branch-till`) — so a remembered answer is the answer to the same query.
 *   • Any account or till saved or deleted inside the window ([[AppServiceProvider::boot()]]) moves the window to a
 *     fresh number and everything is asked again.
 *   • [[find()]] hands out a copy, so a caller that changes the model it got never changes another caller's.
 */
#[Scoped] // ⓘ once per request (speed work 23 Nov 2026) — was a scoped() line in AppServiceProvider
final class AccountLookups
{
    /** @var array<string, Account|null> */
    private array $byCode = [];

    /** @var array<string, Collection<int|string, mixed>> */
    private array $pools = [];

    /** @var array<string, \Illuminate\Database\Eloquent\Collection<int, Account>> */
    private array $trees = [];

    private ?int $generation = null;

    public function __construct(private readonly ReadWindow $window) {}

    /** @param  Closure(): (Account|null)  $load */
    public function byCode(string $code, Closure $load): ?Account
    {
        $scope = $this->scope();

        if ($scope === null) {
            return $load();
        }

        $key = $scope.'|'.$code;

        if (! array_key_exists($key, $this->byCode)) {
            $this->byCode[$key] = $load();
        }

        $account = $this->byCode[$key];

        return $account === null ? null : clone $account;
    }

    /**
     * The company's chart in a few columns, grouped by parent — the walking pool of a subtree.
     *
     * ⓘ The pool's models are slim (only `$columns`) and are only read for ids, parent ids, group flag and nature.
     *
     * @param  list<string>  $columns
     * @param  Closure(): Collection<int|string, mixed>  $load
     * @return Collection<int|string, mixed>
     */
    public function pool(array $columns, Closure $load): Collection
    {
        $scope = $this->scope();

        if ($scope === null) {
            return $load();
        }

        $key = $scope.'|'.implode(',', $columns);

        return $this->pools[$key] ??= $load();
    }

    private function scope(): ?string
    {
        $generation = $this->window->generation();

        if ($generation === null) {
            return null;
        }

        if ($generation !== $this->generation) {
            $this->generation = $generation;
            $this->byCode = [];
            $this->pools = [];
            $this->trees = [];
        }

        $branches = CashTill::visibleBranchIds();

        return implode('|', [
            (string) CompanyContext::id(),
            (string) (auth()->id() ?? '-'),
            $branches === null ? 'all' : implode(',', $branches),
        ]);
    }

    /** an account or till changed inside the window — everything is asked again */
    public static function forget(): void
    {
        app(ReadWindow::class)->invalidate();
    }

    /**
     * @internal the account model's walking pool — `Account::query()->select($columns)->get()->groupBy('parent_id')`
     *
     * @param  list<string>  $columns
     * @return Collection<int|string, mixed>
     */
    public static function accountPool(array $columns): Collection
    {
        return app(self::class)->pool($columns, fn () => Account::query()->select($columns)->get()->groupBy('parent_id'));
    }

    /**
     * @internal a group's walked subtree (self and all below), remembered per root inside the window — handed out as a
     * new collection each time, so a caller that filters or pushes never changes the remembered one.
     *
     * @param  list<string>  $columns
     * @param  Closure(): \Illuminate\Database\Eloquent\Collection<int, Account>  $walk
     * @return \Illuminate\Database\Eloquent\Collection<int, Account>
     */
    public static function subtree(int $rootId, array $columns, Closure $walk): \Illuminate\Database\Eloquent\Collection
    {
        $self = app(self::class);
        $scope = $self->scope();

        if ($scope === null) {
            return $walk();
        }

        $key = $scope.'|tree:'.$rootId.'|'.implode(',', $columns);
        $tree = $self->trees[$key] ??= $walk();

        return new \Illuminate\Database\Eloquent\Collection($tree->all());
    }
}
