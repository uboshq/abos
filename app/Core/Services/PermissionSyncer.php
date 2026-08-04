<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Module\ModuleRegistry;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
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
    /**
     * যে রোলটা সংজ্ঞা অনুযায়ীই সব পারে।
     *
     * এটা সুবিধা নয়, প্রয়োজন: নতুন মডিউলের অনুমতিগুলো কোনো রোলে না
     * গেলে মডিউলটা কেউ খুলতেই পারে না — মালিকও না। আর তখন উপায় থাকে
     * শুধু হাতে ডাটাবেজে গিয়ে সারি বসানো, যা কেউ মনে রাখে না।
     */
    public const OWNER_ROLE = 'owner';

    public function __construct(private readonly ModuleRegistry $registry) {}

    /**
     * @return array{created: list<string>, existing: int, granted: int}
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

        $granted = $this->keepOwnerComplete($guard);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ['created' => $missing, 'existing' => count($existing), 'granted' => $granted];
    }

    /**
     * মালিকের রোলে সব অনুমতি আছে কি না তা নিশ্চিত করা।
     *
     * নতুন অনুমতি তৈরি করাই যথেষ্ট নয়। প্রথমবার এটা ধরা পড়ে
     * সরবরাহকারী মডিউল যোগ করার পর: ছয়টা নতুন অনুমতি তৈরি হলো, কিন্তু
     * কোনো রোলে গেল না, তাই মালিক লগইন করে প্রতিটা সরবরাহকারী পর্দায়
     * ৪০৩ পেলেন। কোনো ত্রুটি বার্তা ছিল না — শুধু দরজা বন্ধ।
     *
     * বাকি রোলগুলো ছোঁয়া হয় না ইচ্ছাকৃতভাবে: হিসাবরক্ষক বা বিক্রয়কর্মী
     * নতুন মডিউলে কী পারবে সেটা ব্যবসার সিদ্ধান্ত, আর সেটা নীরবে
     * নিয়ে নেওয়ার চেয়ে খারাপ কিছু নেই।
     *
     * @return int কয়টা নতুন অনুমতি মালিকের রোলে বসল
     */
    private function keepOwnerComplete(string $guard): int
    {
        $owner = Role::query()
            ->where('name', self::OWNER_ROLE)
            ->where('guard_name', $guard)
            ->first();

        // রোলটা এখনো তৈরি হয়নি (একদম নতুন ইনস্টল) — সিডার বসাবে
        if ($owner === null) {
            return 0;
        }

        $all = Permission::query()->where('guard_name', $guard)->pluck('name');
        $has = $owner->permissions->pluck('name');

        $missing = $all->diff($has);

        if ($missing->isEmpty()) {
            return 0;
        }

        $owner->givePermissionTo($missing->all());

        return $missing->count();
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
