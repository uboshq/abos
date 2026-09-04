<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Module\ModuleRegistry;

/**
 * নতুন ইনস্টলে কোন রোল কোন অনুমতি নিয়ে শুরু করবে — সব মডিউল থেকে জোড়া।
 *
 * রোল cross-module: একজন Warehouse-কর্মী মজুদ (Inventory), পণ্য (Inventory)
 * আর নিজের হাজিরা (Hr) ছোঁন। কিন্তু "আমার কোন অনুমতি কোন রোলের" সিদ্ধান্তটা
 * প্রতিটা মডিউলের নিজের ([[ModuleDefinition::$roleTemplates]])। এই রেজিস্ট্রি
 * সেগুলো একত্র করে — ঠিক যেভাবে [[DuplicationRegistry]] duplicates পড়ে।
 * কেন্দ্র কেবল জোড়া লাগায়, কোনো মডিউলের নাম জানে না; মডিউল বন্ধ হলে তার
 * অংশ আপনা থেকে বাদ পড়ে।
 *
 * ⭐ এটা শুরুর সারি, তালা নয় — [[PermissionSyncer]] রোলটা না থাকলে তবেই
 * বসায়; ক্রেতা পরে RoleController-এ বদলাতে/বাড়াতে পারেন।
 */
final class RoleTemplateRegistry
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    /**
     * রোল-নাম => অনুমতির তালিকা (সব মডিউল মিলিয়ে, একটাও দুইবার নয়)।
     *
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        $roles = [];

        foreach ($this->registry->all() as $module) {
            foreach ($module->roleTemplates as $role => $permissions) {
                foreach ($permissions as $permission) {
                    // একই অনুমতি দুই মডিউল থেকে এলেও একবার — set-এর মতো
                    $roles[$role][$permission] = true;
                }
            }
        }

        return array_map(
            static fn (array $set): array => array_keys($set),
            $roles,
        );
    }

    /**
     * যেসব রোল টেমপ্লেটে ঘোষিত — নাম ধরে।
     *
     * @return list<string>
     */
    public function declaredRoles(): array
    {
        return array_keys($this->all());
    }
}
