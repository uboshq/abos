<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Support\Actor;
use App\Core\Support\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * ⭐ যৌথ রোল — কয়েকটা রোল মিলিয়ে একটা (মালিকের অনুমোদিত রোলের পাতার নমুনা, ২০ নভেম্বর ২০২৬ "ok")।
 *
 * ── ⓘ কীভাবে কাজ করে ─────────────────────────────────────────────────
 * `sys_role_includes`-এ লেখা থাকে কোন রোলের ভেতরে কোন রোল। যৌথ রোলের অনুমতি = ভেতরের রোলগুলোর (তাদের ভেতরেরও) অনুমতির যোগফল,
 * আর সেই যোগফলটা **যৌথ রোলেই বসানো** হয় (`syncPermissions`)। ⭐ তাই যৌথ রোলের সদস্যের প্রতিটা যাচাই — `can()`, মেনু, নীতি,
 * অনুমোদন — spatie-র নিজের পথেই চলে; অনুমতি পড়ার একটা লাইনও বদলাতে হয়নি।
 *
 * ⚠️ দামটা: ভেতরের রোল বদলালে যৌথটাও নতুন করে বসাতে হয় — [[refreshAbove()]], রোলের পাতার প্রতিটা সংরক্ষণে ডাকা হয়।
 * ⛔ চক্র নিষেধ: ক-এর ভেতরে খ আর খ-এর ভেতরে ক হলে যোগফলের শেষ নেই — [[assertNoCycle()]] সংরক্ষণের আগে দেখে।
 * ⛔ মালিকের রোল (`super_admin`) কারও ভেতরে বসে না (তাহলে যৌথটা মালিক-সমান), আর নিজেও যৌথ হয় না (ওটা সংজ্ঞায় সব পারে)।
 */
final class CompositeRoles
{
    public const TABLE = 'sys_role_includes';

    /** @var array<int, bool> টেবিল আছে কি না — কোম্পানি ধরে নয়, একবারই দেখা */
    private static array $ready = [];

    /** @var array<int, array<int, list<int>>> কোম্পানি → রোল → ভেতরের রোল, এক অনুরোধে একবার পড়া */
    private array $maps = [];

    public function __construct(private readonly AuditEngine $audit) {}

    /** ⓘ মাইগ্রেশন চলার আগে (ডেপ্লয়ের মাঝখানে) পর্দা যেন না ভাঙে — টেবিল না থাকলে কোনো রোলই যৌথ নয়। */
    public function available(): bool
    {
        return self::$ready[0] ??= Schema::hasTable(self::TABLE);
    }

    /**
     * চলতি কোম্পানির সব যৌথ রোল: রোল → সরাসরি ভেতরের রোলগুলো।
     *
     * @return array<int, list<int>>
     */
    public function map(?int $companyId = null): array
    {
        $companyId ??= (int) CompanyContext::id();

        if (! $this->available()) {
            return [];
        }

        if (! isset($this->maps[$companyId])) {
            $map = [];

            foreach (DB::table(self::TABLE)->where('company_id', $companyId)->orderBy('id')->get(['role_id', 'included_role_id']) as $row) {
                $map[(int) $row->role_id][] = (int) $row->included_role_id;
            }

            $this->maps[$companyId] = $map;
        }

        return $this->maps[$companyId];
    }

    public function isComposite(int $roleId): bool
    {
        return ($this->map()[$roleId] ?? []) !== [];
    }

    /** @return list<int> সরাসরি ভেতরের রোল */
    public function includedIds(int $roleId): array
    {
        return $this->map()[$roleId] ?? [];
    }

    /**
     * যে যৌথ রোলগুলোর ভেতরে এই রোল আছে — সরাসরি বা ভেতরের ভেতর দিয়ে।
     *
     * @return list<int>
     */
    public function containersOf(int $roleId): array
    {
        $map = $this->map();
        $found = [];
        $queue = [$roleId];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($map as $outer => $inner) {
                if (in_array($current, $inner, true) && ! isset($found[$outer]) && $outer !== $roleId) {
                    $found[$outer] = true;
                    $queue[] = $outer;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * ⛔ চক্র হবে কি না — `$roleId`-এর ভেতরে `$candidates` বসালে কোনোটার ভেতর দিয়ে কি আবার `$roleId`-এ পৌঁছানো যায়?
     *
     * @param  list<int>  $candidates
     */
    public function wouldCycle(int $roleId, array $candidates): bool
    {
        $map = $this->map();
        $seen = [];
        $queue = $candidates;

        while ($queue !== []) {
            $current = (int) array_shift($queue);

            if ($current === $roleId) {
                return true;
            }

            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;
            array_push($queue, ...($map[$current] ?? []));
        }

        return false;
    }

    /**
     * এই রোলের আসল অনুমতি — যৌথ হলে ভেতরের সবার যোগফল, নইলে নিজের।
     *
     * ⓘ যোগফল সবসময় **পাতার রোলগুলো থেকে** গোনা হয়, যৌথের বসানো সেট থেকে নয় — তাই কোন ক্রমে যৌথগুলো নতুন করে বসে, তাতে
     *   ফল বদলায় না। ⓘ `$seen` — চক্র কোনোভাবে ঢুকে গেলেও (হাতে লেখা সারি) লুপ থামে।
     *
     * @param  array<int, bool>  $seen
     * @return list<string>
     */
    public function effectivePermissions(int $roleId, array $seen = []): array
    {
        if (isset($seen[$roleId])) {
            return [];
        }

        $seen[$roleId] = true;
        $inner = $this->includedIds($roleId);

        if ($inner === []) {
            $role = Role::query()->with('permissions:id,name')->find($roleId);

            return $role ? $role->permissions->pluck('name')->values()->all() : [];
        }

        $names = [];

        foreach ($inner as $id) {
            foreach ($this->effectivePermissions($id, $seen) as $name) {
                $names[$name] = true;
            }
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /**
     * ⛔ যৌথ রোলের ভেতরে কী বসতে পারে — সংরক্ষণের আগে।
     *
     * @param  list<int>  $ids
     */
    public function assertMayHold(Role $role, array $ids, string $field = 'includes'): void
    {
        if ($role->name === PermissionSyncer::SUPER_ADMIN_ROLE) {
            throw ValidationException::withMessages([$field => __('system_admin::permission.composite_owner_fixed')]);
        }

        if ($ids === []) {
            throw ValidationException::withMessages([$field => __('system_admin::permission.composite_needs_roles')]);
        }

        $inner = Role::query()->whereIn('id', $ids)->get(['id', 'name', 'company_id']);

        if ($inner->count() !== count($ids)
            || $inner->contains(fn (Role $r) => (int) $r->company_id !== (int) CompanyContext::id())) {
            throw ValidationException::withMessages([$field => __('system_admin::permission.composite_unknown_role')]);
        }

        if ($inner->contains('name', PermissionSyncer::SUPER_ADMIN_ROLE)) {
            throw ValidationException::withMessages([$field => __('system_admin::permission.composite_no_owner_inside')]);
        }

        $this->assertNoCycle($role, $ids, $field);
    }

    /** @param  list<int>  $ids */
    public function assertNoCycle(Role $role, array $ids, string $field = 'includes'): void
    {
        if ($role->exists && (in_array((int) $role->id, $ids, true) || $this->wouldCycle((int) $role->id, $ids))) {
            throw ValidationException::withMessages([$field => __('system_admin::permission.composite_cycle')]);
        }
    }

    /**
     * ভেতরের রোলগুলো বসানো — আগের তালিকা মুছে নতুনটা — আর যৌথটার অনুমতি নতুন করে।
     *
     * ⚠️ ডাকার আগে [[assertMayHold()]] (আর "যা নিজের হাতে নেই তা দেওয়া যায় না" — [[RoleController]])।
     *
     * @param  list<int>  $ids
     * @return array{added: list<int>, removed: list<int>}
     */
    public function setIncludes(Role $role, array $ids, ?string $reason = null): array
    {
        $companyId = (int) CompanyContext::id();
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $before = $this->includedIds((int) $role->id);
        $added = array_values(array_diff($ids, $before));
        $removed = array_values(array_diff($before, $ids));

        if ($added === [] && $removed === []) {
            return ['added' => [], 'removed' => []];
        }

        DB::table(self::TABLE)->where('company_id', $companyId)->where('role_id', $role->id)
            ->whereIn('included_role_id', $removed === [] ? [0] : $removed)->delete();

        $now = now();
        $rows = array_map(fn (int $id): array => [
            'public_id' => (string) Str::uuid7(),
            'role_id' => (int) $role->id,
            'included_role_id' => $id,
            'created_by' => Actor::userId(),
            'created_at' => $now,
            'updated_at' => $now,
        ], $added);

        if ($rows !== []) {
            // ⓘ প্রতিটা সারি এই কোম্পানির — ছাঁকনিটা কোয়েরির গায়েই লেখা ([[EveryRawQueryNamesItsCompanyTest]])
            DB::table(self::TABLE)->insert(array_map(fn (array $row): array => ['company_id' => $companyId] + $row, $rows));
        }

        unset($this->maps[$companyId]);

        $names = Role::query()->whereIn('id', [...$added, ...$removed])->pluck('name', 'id');
        $label = fn (array $list): string => implode(', ', array_map(fn (int $id): string => (string) ($names[$id] ?? $id), $list));

        $this->audit->record($role, 'role_includes_changed', [
            'roles_added' => [null, $label($added)],
            'roles_removed' => [$label($removed), null],
        ], $reason);

        $this->refresh($role, $reason);
        $this->refreshAbove($role, $reason);

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * যৌথ রোলের অনুমতি নতুন করে — ভেতরের যোগফল। যৌথ না হলে কিছুই নয়।
     */
    public function refresh(Role $role, ?string $reason = null): void
    {
        if (! $this->isComposite((int) $role->id)) {
            return;
        }

        $this->syncRecorded($role, $this->effectivePermissions((int) $role->id), $reason);
    }

    /**
     * এই রোল যে যৌথগুলোর ভেতরে, সেগুলোর অনুমতি নতুন করে — রোলের পাতার প্রতিটা সংরক্ষণের পরে।
     */
    public function refreshAbove(Role $role, ?string $reason = null): void
    {
        $outer = $this->containersOf((int) $role->id);

        if ($outer === []) {
            return;
        }

        foreach (Role::query()->whereIn('id', $outer)->get() as $composite) {
            $this->refresh($composite, $reason ?? __('system_admin::permission.composite_followed'));
        }
    }

    /**
     * চাবি বসানো, আর কী যোগ হলো কী গেল তা অডিটের খাতায় (কারণসহ) — [[RoleController]]-এর পুরনো `syncAndRecord()` এখানে এল,
     * কারণ যৌথ রোলও একই খাতায় লেখে।
     *
     * @param  list<string>  $wanted
     * @return array{added: list<string>, removed: list<string>}
     */
    public function syncRecorded(Role $role, array $wanted, ?string $reason = null): array
    {
        $before = $role->permissions()->pluck('name')->all();

        $role->syncPermissions($wanted);

        $added = array_values(array_diff($wanted, $before));
        $removed = array_values(array_diff($before, $wanted));

        if ($added === [] && $removed === []) {
            return ['added' => [], 'removed' => []];
        }

        sort($added);
        sort($removed);

        $this->audit->record($role, 'role_permissions_changed', [
            'permissions_added' => [null, implode(', ', $added)],
            'permissions_removed' => [implode(', ', $removed), null],
        ], $reason);

        return ['added' => $added, 'removed' => $removed];
    }

    /** ⓘ পরীক্ষা আর কনসোলে কোম্পানি বদলালে পুরনো ছবি যেন না থাকে। */
    public function forget(): void
    {
        $this->maps = [];
    }
}
