<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Support\CompanyContext;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Permission answers remembered for one read-only page (speed work, 23 Nov 2026).
 *
 * ── Why ──────────────────────────────────────────────────────────────────
 * Measured on live as the owner: one `can()` costs ~0.67 ms on the shared host (spatie walks its whole permission
 * list to find the name), and a page asks 650–1,030 times — the side menu, the dashboard gates and every row menu
 * ask the same ~165 keys again and again. That alone was 0.4–0.7 s of every page.
 *
 * ── ⛔ Why it can never answer differently from the gate ───────────────────
 *   • Only inside a [[ReadWindow]] (a GET/HEAD request being built) — a read does not grant or take keys. Writes,
 *     console, queue and test code outside a request ask the gate every time, exactly as before.
 *   • Only abilities without arguments (permission keys). A policy with a record is never remembered by the gate itself;
 *     only a list may ask once, and only a policy that declares it never looks at the row ([[ofTheList()]]).
 *   • The key holds the user object, the company, the branch, the spatie team, and the identity of spatie's loaded
 *     permission list and of the user's loaded roles/permissions relations: when spatie forgets its cache (any grant
 *     or revoke) or the relations are reloaded, the old answers no longer match and the gate is asked again.
 *   • The answer stored is the gate's own final answer (written by a `Gate::after` hook), so the dealer wall
 *     ([[DealerScope]]) and the overrides ([[PermissionOverrides]]) are inside it, in their original order.
 */
final class GateMemo
{
    /** @var array<string, mixed> */
    private array $answers = [];

    /** @var array<class-string, bool> */
    private array $rowFree = [];

    private ?int $generation = null;

    /** @var array<int, object> objects whose ids are part of a key — held so an id is never reused while remembered */
    private array $pins = [];

    private ?\ReflectionProperty $registrarList = null;

    public function __construct(private readonly ReadWindow $window) {}

    /** @param  array<int, mixed>  $arguments */
    public function recall(mixed $user, string $ability, array $arguments): mixed
    {
        $key = $this->key($user, $ability, $arguments);

        return $key === null ? null : ($this->answers[$key] ?? null);
    }

    /** @param  array<int, mixed>  $arguments */
    public function remember(mixed $user, string $ability, mixed $result, array $arguments): void
    {
        $key = $this->key($user, $ability, $arguments);

        if ($key !== null) {
            // ⓘ null (nobody said yes) is a "no" at the gate; false gives the same deny, while null would mean "ask again"
            $this->answers[$key] = $result ?? false;
        }
    }

    /**
     * One answer for a whole list — only where the policy declares that it never looks at the row ([[RowFreePolicy]]) and only
     * inside the same read window and key as everything else here. ⓘ The gate itself is asked the first time, with a real
     * row, so the dealer wall, the overrides and spatie all speak; any other policy is asked for every row, as before.
     */
    public function ofTheList(mixed $user, string $ability, Model $row): bool
    {
        if (! $user instanceof Model || ! $user instanceof Authorizable) {
            return false;
        }

        $key = $this->isRowFree($row) ? $this->key($user, $ability.'@'.$row::class, []) : null;

        if ($key === null) {
            return (bool) $user->can($ability, $row);
        }

        if (array_key_exists($key, $this->answers)) {
            return (bool) $this->answers[$key];
        }

        $answer = (bool) $user->can($ability, $row);

        // ⓘ asking may load the person's roles or keys, and those are part of the key — kept under the key as it now stands
        $key = $this->key($user, $ability.'@'.$row::class, []);

        if ($key !== null) {
            $this->answers[$key] = $answer;
        }

        return $answer;
    }

    private function isRowFree(Model $row): bool
    {
        return $this->rowFree[$row::class] ??= \Illuminate\Support\Facades\Gate::getPolicyFor($row) instanceof \App\Core\Contracts\RowFreePolicy;
    }

    /** @param  array<int, mixed>  $arguments */
    private function key(mixed $user, string $ability, array $arguments): ?string
    {
        if ($arguments !== [] || ! $user instanceof Model || ! $user instanceof Authorizable) {
            return null;
        }

        $generation = $this->window->generation();

        if ($generation === null) {
            return null;
        }

        if ($generation !== $this->generation) {
            $this->generation = $generation;
            $this->answers = [];
            $this->pins = [];
        }

        $list = $this->registrarPermissions();

        if ($list === null) {
            return null;
        }

        $roles = $user->relationLoaded('roles') ? $user->getRelation('roles') : null;
        $perms = $user->relationLoaded('permissions') ? $user->getRelation('permissions') : null;

        foreach ([$user, $list, $roles, $perms] as $object) {
            if (is_object($object)) {
                $this->pins[spl_object_id($object)] = $object;
            }
        }

        return implode('|', [
            $ability,
            $user::class, (string) $user->getKey(), spl_object_id($user),
            (string) CompanyContext::id(), (string) CompanyContext::branchId(),
            (string) app(PermissionRegistrar::class)->getPermissionsTeamId(),
            spl_object_id($list), $roles === null ? '-' : spl_object_id($roles), $perms === null ? '-' : spl_object_id($perms),
        ]);
    }

    /** spatie's loaded permission list (a new object after every forget) — null while not loaded */
    private function registrarPermissions(): ?object
    {
        try {
            $this->registrarList ??= new \ReflectionProperty(PermissionRegistrar::class, 'permissions');
            $list = $this->registrarList->getValue(app(PermissionRegistrar::class));
        } catch (\Throwable) {
            return null;
        }

        return is_object($list) ? $list : null;
    }
}
