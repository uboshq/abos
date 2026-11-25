<?php

declare(strict_types=1);

namespace App\Core\Security;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * spatie-র অনুমতি-তালিকা, একই উত্তর, সস্তায় পড়া (২৪ নভেম্বর ২০২৬)।
 *
 * ── কেন ─────────────────────────────────────────────────────────────────
 * [[CompanyContext::set()]] প্রতিবার তালিকার ক্যাশ ছাড়ে — **ইচ্ছাকৃত, আর এখানে বদলায়নি**: না ছাড়লে এক কোম্পানির চাবি অন্যটায়
 * বা অন্য প্রসেসে বদলানো রোলের পুরনো উত্তর থেকে যেতে পারে। কিন্তু তারপর নতুন করে পড়া ৩৬২ অনুমতি × ৩০১৩ জোড়ার জন্য ৩০১৩টা
 * Role আর ৩০১৩টা Pivot মডেল বানাত — ডেমোতে প্রতি পাতায় ~৫০০ms।
 * ⭐ এখানে প্রতিটা রোল একবারই তোলা, জোড়াগুলো সাদা সারি। ⛔ কোন তথ্য, কার জন্য, কতক্ষণ — কিছুই বদলায় না; তালিকাটা মূলেরও কোম্পানি
 *   চেনে না (কার কোন রোল, সেটা মানুষের নিজের সম্পর্কে)।
 */
final class CheapPermissionRegistrar extends PermissionRegistrar
{
    private ?object $indexOf = null;

    /** @var array<string, array<string, object>> গার্ড => নাম => অনুমতি */
    private array $byName = [];

    /**
     * ⭐ নাম ধরে একটা অনুমতি — তালিকা ঘেঁটে নয়, সূচি থেকে (২৫ নভেম্বর ২০২৬)। মেনু প্রতি পাতায় ~৩০০ নাম খোঁজে, প্রতিটায় মূল কোড ৩৬২
     * সারি হাঁটত — ছোট পাতার প্রায় তৃতীয়াংশ সময়।
     *   ⛔ সূচি বাঁধা তালিকার বস্তুর সাথে: ক্যাশ ছাড়লে তালিকা নতুন বস্তু, সূচিও নতুন। অন্য যেকোনো প্রশ্ন (আইডি, একাধিক, সংখ্যার মতো
     *   নাম) মূলের পথে — উত্তর হুবহু এক।
     */
    public function getPermissions(array $params = [], bool $onlyOne = false): Collection
    {
        $name = $params['name'] ?? null;
        $guard = $params['guard_name'] ?? null;

        if (! $onlyOne || count($params) !== 2 || ! is_string($name) || ! is_string($guard) || is_numeric($name)) {
            return parent::getPermissions($params, $onlyOne);
        }

        // ⓘ মূলের `loadPermissions()` private — তালিকা না থাকলে মূলের পথেই তোলা
        if (! is_object($this->permissions)) {
            return parent::getPermissions($params, true);
        }

        if ($this->indexOf !== $this->permissions) {
            $this->indexOf = $this->permissions;
            $this->byName = [];

            foreach ($this->permissions as $permission) {
                // ⓘ একই নাম দুইবার হলে প্রথমটা — মূলের `first()`
                $this->byName[(string) $permission->getAttribute('guard_name')][(string) $permission->getAttribute('name')] ??= $permission;
            }
        }

        $one = $this->byName[$guard][$name] ?? null;

        return new Collection($one === null ? [] : [$one]);
    }

    protected function getPermissionsWithRoles(): Collection
    {
        $roles = $this->getRoleClass()::query()->get()->keyBy(fn ($role) => $role->getKey());
        $of = [];

        foreach (DB::table('role_has_permissions')->get([$this->pivotPermission.' as permission', $this->pivotRole.' as role']) as $pair) {
            if ($roles->has($pair->role)) {
                $of[$pair->permission][] = $roles->get($pair->role);
            }
        }

        return $this->getPermissionClass()::select()->get()
            ->each(fn ($permission) => $permission->setRelation('roles', new Collection($of[$permission->getKey()] ?? [])));
    }
}
