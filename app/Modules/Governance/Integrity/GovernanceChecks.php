<?php

declare(strict_types=1);

namespace App\Modules\Governance\Integrity;

use App\Core\Contracts\ChecksItsOwnBooks;
use App\Core\Integrity\IntegrityCheck;
use App\Core\Integrity\IntegrityFinding;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\PermissionSyncer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * যে অনুমতিগুলো ঘোষিত, সেগুলো সত্যিই আছে কি না।
 *
 * ── কীভাবে ধরা পড়ল ─────────────────────────────────────────────────
 * শিপমেন্টের পর্দা ডিপ্লয় করার পর চালু সাইটে "Forbidden" এল। কারণটা
 * সোজা — `abos:sync-permissions` চালানো হয়নি। কিন্তু চালানোর পর দেখা
 * গেল **ছয়টা** অনুমতি অনুপস্থিত, আর তার দুইটা কয়েক দিন আগের:
 * `sales.cost.view` আর `sales.reprint.override`।
 *
 * অর্থাৎ ওই দুইটা কাজ ডিপ্লয় হয়েছিল, পরীক্ষায় সবুজ ছিল, আর চালু
 * সাইটে **কাজই করেনি** — মালিক ক্রয়মূল্যের কলাম দেখতে পেতেন না, আর
 * সীমা ছাড়ানো ছাপা কেউ ছাড়াতে পারতেন না। কোথাও কোনো লাল দাগ ছিল না;
 * কমান্ডটা ভুলে যাওয়া ছাড়া আর কিছুই ঘটেনি।
 *
 * ── কেন এটা একটা টেস্ট নয় ──────────────────────────────────────────
 * পরীক্ষা চলে ডেভেলপারের মেশিনে, যেখানে সিডার প্রতিবার সব অনুমতি
 * বসিয়ে দেয় — তাই সেখানে এই অবস্থাটা কখনো তৈরিই হয় না। প্রশ্নটা
 * কোড ঠিক কি না তা নয়, **এই সার্ভারের এই ডাটাবেজটা** ঠিক কি না। আর
 * ওটাই সততা যাচাইয়ের কাজ।
 */
final class GovernanceChecks implements ChecksItsOwnBooks
{
    /** @return list<IntegrityCheck> */
    public static function checks(): array
    {
        return [
            self::everyDeclaredPermissionExists(),
        ];
    }

    public static function everyDeclaredPermissionExists(): IntegrityCheck
    {
        return new IntegrityCheck(
            key: 'governance.permissions_are_installed',
            label: __('governance::integrity.permissions'),
            question: __('governance::integrity.permissions_q'),
            whenBroken: __('governance::integrity.permissions_broken'),
            permission: 'governance.audit.view',
            run: function (): array {
                $declared = [];

                foreach (app(ModuleRegistry::class)->all() as $module) {
                    foreach ($module->permissions as $permission) {
                        $declared[$permission] = $module->code;
                    }
                }

                $installed = Permission::query()->where('guard_name', 'web')->pluck('name')->all();

                $owner = Role::query()->where('name', PermissionSyncer::OWNER_ROLE)->first();
                $ownerHas = $owner?->permissions->pluck('name')->all() ?? [];

                $findings = [];

                foreach ($declared as $permission => $moduleCode) {
                    if (! in_array($permission, $installed, true)) {
                        $findings[] = new IntegrityFinding(
                            what: $permission,
                            detail: __('governance::integrity.permission_missing', ['module' => $moduleCode]),
                        );

                        continue;
                    }

                    /*
                     * বসানো আছে অথচ মালিকের রোলে নেই — এটাও একই ফল।
                     *
                     * অনুমতিটা টেবিলে থাকা মানে কেউ ওটা ব্যবহার করতে
                     * পারছেন তা নয়। মালিক সংজ্ঞা অনুযায়ীই সব পারেন,
                     * তাই তাঁর রোলে না থাকা মানে পর্দাটা কার্যত বন্ধ।
                     */
                    if ($owner !== null && ! in_array($permission, $ownerHas, true)) {
                        $findings[] = new IntegrityFinding(
                            what: $permission,
                            detail: __('governance::integrity.permission_not_with_owner'),
                        );
                    }
                }

                return $findings;
            },
        );
    }
}
