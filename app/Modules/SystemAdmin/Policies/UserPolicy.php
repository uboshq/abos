<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Policies;

use App\Core\Services\PermissionSyncer;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * একজন ব্যবহারকারীকে কে বদলাতে পারে, আর কাকে কোন ভূমিকা দিতে পারে।
 *
 * ── ⛔ নিরীক্ষার ফলাফল ৩.৪, ১২ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * *"SystemAdmin-এ রেকর্ড-স্তরের পলিসি নেই।"* ⓘ মিডলওয়্যার বলত "আপনি
 * ব্যবহারকারী সামলাতে পারেন" — **কাকে**, আর **কতটা ক্ষমতা দিয়ে**, বলত না।
 *
 * ── ⚠️ তাতে যে দুইটা দরজা খোলা ছিল, ১৯ সেপ্টেম্বর ২০২৬-এ মাপা ─────────
 * ১. "User Admin" ভূমিকার কেউ **মালিকের** সম্পাদনার পাতা খুলে ইমেইল আর
 *    পাসওয়ার্ড বদলাতে পারতেন — অর্থাৎ মালিকের খাতাটাই নিয়ে নেওয়া।
 * ২. তিনি নিজেকে (বা যে কাউকে) এমন ভূমিকা দিতে পারতেন যার ক্ষমতা তাঁর
 *    নিজের নেই — module.php-তে লেখা আছে User Admin *"ক্ষমতার ছক বানান
 *    না"*, অথচ ছকের যেকোনো ঘর তিনি নিজের নামে বসাতে পারতেন।
 *
 * ⭐ দুইটা নিয়ম:
 *   · মালিকের খাতা কেবল মালিক বদলান
 *   · নতুন ভূমিকা দেওয়া যায় কেবল যদি তার প্রতিটা অনুমতি দাতার নিজের আছে
 *     (যা আগে থেকেই আছে সেটা রেখে দেওয়া আটকায় না)
 *
 * ⓘ মালিকের জন্য কোনো সীমা নেই — তিনিই ছক বানান।
 */
class UserPolicy
{
    public function update(User $actor, User $target): bool
    {
        if (! $actor->can('system_admin.user.manage')) {
            return false;
        }

        if ($this->isOwner($actor)) {
            return true;
        }

        return ! ($target->exists && $this->isOwner($target));
    }

    /**
     * এই ভূমিকাটা এই মানুষকে দেওয়া যায় কি না — দাতার নিজের ক্ষমতার ভিতরে।
     */
    public function grantRole(User $actor, User $target, Role $role): bool
    {
        if ($this->isOwner($actor)) {
            return true;
        }

        // ⓘ আগে থেকেই আছে — রেখে দেওয়া নতুন কিছু দেওয়া নয়
        if ($target->exists && $target->hasRole($role->name)) {
            return true;
        }

        if ($role->name === PermissionSyncer::SUPER_ADMIN_ROLE) {
            return false;
        }

        foreach ($role->permissions->pluck('name') as $permission) {
            if (! $actor->can($permission)) {
                return false;
            }
        }

        return true;
    }

    private function isOwner(User $user): bool
    {
        return $user->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE);
    }
}
