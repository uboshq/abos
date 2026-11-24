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
