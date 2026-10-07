<?php

declare(strict_types=1);

namespace App\Policies;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * একজন ব্যবহারকারীকে কে বদলাতে পারে, আর কাকে কোন ভূমিকা দিতে পারে।
 *
 * ── ⭐ কেন ফাইলটা কোরে, SystemAdmin-এ নয় (২১ সেপ্টেম্বর ২০২৬) ────────
 * ⓘ এটা এতদিন `Modules\SystemAdmin\Policies`-এ ছিল, আর তাতে **কোর
 * একটা মডিউলের নাম জানত** — সীমারেখার শেষ লঙ্ঘনটা ছিল ঠিক এই লাইনটাই।
 *
 * ⚠️ কিন্তু এই শ্রেণি SystemAdmin-এর কিছুই ব্যবহার করে না: চারটা
 * নির্ভরতাই কোর বা vendor ([[PermissionSyncer]], [[User]],
 * [[UserPermissionOverride]], Spatie-র `Role`)। ⓘ মডেলটা কোরের,
 * নিয়মগুলোও কোরের জিনিস দিয়ে লেখা — তাই পলিসিটাও কোরের।
 *
 * ⛔ বিকল্প ছিল `module.php`-তে একটা `policies` ঘর বানানো, আর সেটা
 * **খারাপ** হত: তাতে যেকোনো মডিউল কোরের মডেলের উপর পলিসি দাবি করার
 * দরজা পেত। একটা তীর সারাতে গিয়ে নয়টার সমান একটা গর্ত।
 *
 * ── ⚠️ ফাইলটা এখান থেকে সরালে যা হয় ──────────────────
 * ⓘ `app/Policies` কেবল একটা সুন্দর ঠিকানা নয় — Laravel ঠিক
 * ওখানেই নিজে থেকে খোঁজে (`App\Models\User` →
 * `App\Policies\UserPolicy`)।
 *
 * ⛔ অর্থাৎ নিয়মগুলো দরজায় পৌঁছায় দুই সুতোয়: এই ঠিকানা,
 * আর AppServiceProvider-এর স্পষ্ট `Gate::policy()`। দুইটাই
 * ছিঁড়লে পাহারাটা **নীরবে** উবে যায় — তাই
 * [[EveryPolicyRuleIsActuallyReachedTest]] ফলটা মাপে।
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
 * ⭐ তিনটা নিয়ম:
 *   · মালিকের খাতা কেবল মালিক বদলান
 *   · নিজের চেয়ে বেশি ক্ষমতার কারও খাতা বদলানো যায় না — পাসওয়ার্ড বদলে
 *     তাঁর হয়ে ঢোকা মানে তাঁর ক্ষমতাটাই নিয়ে নেওয়া
 *   · নতুন ভূমিকা দেওয়া যায় কেবল যদি তার প্রতিটা অনুমতি দাতার নিজের আছে
 *     (যা আগে থেকেই আছে সেটা রেখে দেওয়া আটকায় না)
 *
 * ⓘ দ্বিতীয় নিয়মটা মালিকের সিদ্ধান্ত, ১৯ সেপ্টেম্বর ২০২৬: *"bondo koro,
 * sob power super admin er"*। মালিকের জন্য কোনো সীমা নেই — তিনিই ছক বানান।
 */
class UserPolicy
{
    public function update(User $actor, User $target): bool
    {
        if (! $actor->can('system_admin.user.manage')) {
            return false;
        }

        if (! $target->exists) {
            return true;
        }

        /*
         * ⛔ প্রতিটা কোম্পানিতে — কেবল চলতিটায় নয়। পুরো ERP অডিট, ৬ অক্টোবর ২০২৬ (SystemAdmin ⛔২)।
         *
         * ইমেইল, পাসওয়ার্ড আর সচল-অবস্থা একটাই সারিতে, সব কোম্পানির জন্য। আগে যাচাই হত কেবল চলতি কোম্পানিতে: A-র
         * ইউজার-অ্যাডমিন A-র সদস্য কারও পাসওয়ার্ড বদলে তাঁর হয়ে ঢুকতে পারতেন — তিনি B-তে super_admin হলেও; আর
         * A-র মালিকও B-র মালিকের খাতা নিতে পারতেন। ⭐ এখন মানুষটা যত কোম্পানির সদস্য বা যেখানে তাঁর ভূমিকা আছে,
         * প্রতিটাতে আমাকে তাঁকে ঢাকতে হবে — সেখানে আমি super_admin, নয়তো সেখানে তাঁর প্রতিটা ক্ষমতা আমারও।
         */
        foreach ($this->companiesOf($target) as $companyId) {
            if (! $this->covers($actor, $target, $companyId)) {
                return false;
            }
        }

        return true;
    }

    /** ⓘ সদস্যপদ আর ভূমিকা — দুই জায়গা থেকেই, যাতে সদস্যপদ বন্ধ হলেও রয়ে যাওয়া ভূমিকা বাদ না পড়ে */
    private function companiesOf(User $target): array
    {
        return DB::table('company_user')->where('user_id', $target->id)->pluck('company_id')
            ->merge(DB::table('model_has_roles')->where('model_type', $target->getMorphClass())
                ->where('model_id', $target->id)->pluck('company_id'))
            ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** এই কোম্পানিতে আমি কি তাঁকে ঢাকি — আমি মালিক, নয়তো তিনি মালিক নন আর তাঁর প্রতিটা ক্ষমতা আমার */
    private function covers(User $actor, User $target, int $companyId): bool
    {
        return (bool) CompanyContext::forCompany($companyId, function () use ($actor, $target): bool {
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            $target->unsetRelation('roles')->unsetRelation('permissions');

            try {
                if ($this->isOwner($actor)) {
                    return true;
                }

                if ($this->isOwner($target)) {
                    return false;
                }

                /*
                 * ⓘ তাঁর প্রতিটা ক্ষমতা আমারও আছে — তবেই তাঁর খাতায় হাত। ভূমিকার
                 * অনুমতির সাথে তাঁর নিজের নামে দেওয়া ব্যতিক্রমও ([[UserPermissionOverride]]),
                 * নাহলে ব্যতিক্রম দিয়ে ক্ষমতা পাওয়া মানুষটা এই নিয়মের বাইরে থাকতেন।
                 */
                $powers = $target->getAllPermissions()->pluck('name')->merge(
                    UserPermissionOverride::query()
                        ->where('user_id', $target->id)
                        ->where('granted', true)
                        ->pluck('permission'),
                )->unique();

                // ⓘ সেই কোম্পানির সদস্য নই — তাঁর একটা ক্ষমতাও থাকলে ঢাকি না
                if ($powers->isNotEmpty() && ! $actor->canAccessCompany((int) CompanyContext::id())) {
                    return false;
                }

                foreach ($powers as $permission) {
                    if (! $actor->can($permission)) {
                        return false;
                    }
                }

                return $this->seesNoLessThan($actor, $target);
            } finally {
                $actor->unsetRelation('roles')->unsetRelation('permissions');
                $target->unsetRelation('roles')->unsetRelation('permissions');
            }
        });
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

    /**
     * ⛔ আমি শাখা বা গুদামে সীমিত হলে তিনিও সীমিত, আর তাঁর সীমা আমার ভেতরে — নইলে সীমাহীন সহকর্মীর পাসওয়ার্ড বদলে
     * তাঁর হয়ে বেশি দেখা যেত (পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, SystemAdmin ⛔৩)। ⓘ কোম্পানির প্রসঙ্গের ভেতরে ডাকা হয়।
     */
    private function seesNoLessThan(User $actor, User $target): bool
    {
        $scope = app(\App\Core\Services\DataScope::class);

        foreach ([\App\Models\UserDataScope::BRANCH, \App\Models\UserDataScope::WAREHOUSE] as $type) {
            $mine = $scope->idsFor($actor, $type);

            if ($mine === null) {
                continue;
            }

            $theirs = $scope->idsFor($target, $type);

            if ($theirs === null || array_diff($theirs, $mine) !== []) {
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
