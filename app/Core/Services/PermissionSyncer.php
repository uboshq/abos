<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Module\ModuleRegistry;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * প্রতিটা মডিউলের ঘোষিত অনুমতি ডাটাবেজে নিবন্ধন করে — সেকশন ১৯.৩।
 *
 * নতুন মডিউল যোগ করার পর হাতে কিছু চালাতে হয় না বলাই লক্ষ্য, তাই এটা
 * মাইগ্রেশন বা সিডারের অংশ নয় — একটা কমান্ড, যা ডিপ্লয়ের সময় চলে।
 *
 * অনুমতি কখনো মুছে ফেলা হয় না, শুধু যোগ হয়। একটা মডিউল সাময়িকভাবে বন্ধ
 * থাকলে বা module.php-তে টাইপো হলে মুছে ফেলার মানে হত রোল থেকে অনুমতি
 * চুপচাপ সরে যাওয়া — আর সেটা ধরা পড়ত কেবল যখন কেউ কাজ করতে গিয়ে আটকাত।
 */
final class PermissionSyncer
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    /**
     * @return array{created: list<string>, existing: int}
     */
    public function sync(string $guard = 'web'): array
    {
        $declared = [];

        foreach ($this->registry->all() as $module) {
            foreach ($module->permissions as $permission) {
                $declared[] = $permission;
            }
        }

        $existing = Permission::query()
            ->where('guard_name', $guard)
            ->pluck('name')
            ->all();

        $missing = array_values(array_diff($declared, $existing));

        foreach ($missing as $name) {
            Permission::create(['name' => $name, 'guard_name' => $guard]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ['created' => $missing, 'existing' => count($existing)];
    }

    /**
     * ঘোষিত হয়েছে কিন্তু ডাটাবেজে নেই — অথবা উল্টোটা।
     *
     * দ্বিতীয়টা বেশি জরুরি: ডাটাবেজে আছে কিন্তু কোনো module.php আর ঘোষণা
     * করে না মানে হয় মডিউলটা সরানো হয়েছে, নয়তো নাম বদলেছে। দুই ক্ষেত্রেই
     * রোলগুলোতে একটা মৃত অনুমতি রয়ে গেছে যা আর কিছু খোলে না।
     *
     * @return array{undeclared: list<string>, unregistered: list<string>}
     */
    public function drift(string $guard = 'web'): array
    {
        $declared = [];

        foreach ($this->registry->all() as $module) {
            $declared = [...$declared, ...$module->permissions];
        }

        $stored = Permission::query()->where('guard_name', $guard)->pluck('name')->all();

        return [
            'undeclared' => array_values(array_diff($stored, $declared)),
            'unregistered' => array_values(array_diff($declared, $stored)),
        ];
    }
}
