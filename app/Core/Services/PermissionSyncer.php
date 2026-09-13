<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
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
     *
     * ── নামটা `owner` ছিল, ১৩ সেপ্টেম্বর ২০২৬-এ `super_admin` হলো ──────
     * ⓘ কারণ ERP-র জগতে কেউ "owner" বলে না — Odoo, Tally, NetSuite,
     * QuickBooks সবাই বলে Administrator। "Owner" আসলে SaaS অ্যাপের শব্দ
     * (GitHub, Slack), আর এই ব্যবস্থাটা ERP।
     *
     * ⭐ কিন্তু আসল লাভটা অন্য জায়গায়: অর্থ মডিউলে `finance::who.owner`
     * = "মালিক" বলতে বোঝায় **কার টাকা ব্যবসায় খাটছে** (মূলধনের খাতা)।
     * ⛔ একই শব্দ দুই অর্থে বসে থাকায় বারবার প্রশ্ন উঠত "বিনিয়োগকারীকে
     * কী রোল দেব?" — অথচ যিনি কেবল টাকা দেন তাঁর কোনো অ্যাকাউন্টই লাগে
     * না। নাম আলাদা হওয়ার পর প্রশ্নটাই আর ওঠে না।
     *
     * ⚠️ তাই `finance::who.owner` **ছোঁয়া হয়নি**, আর ছোঁয়া উচিতও নয় —
     * দুইটা একসাথে বদলালে পুরো লাভটাই হারাত।
     *
     * ⓘ ধ্রুবকের **নামটাও** বদলেছে (`SUPER_ADMIN_ROLE` নয়)। মান বদলে নাম রেখে
     * দিলে `SUPER_ADMIN_ROLE === 'super_admin'` পড়ে পরের জন ভাবতেন দুইটা আলাদা
     * জিনিস — আর এই রিপোতে বারবার দেখা গেছে, মানুষ নামটাই বিশ্বাস করেন।
     */
    public const SUPER_ADMIN_ROLE = 'super_admin';

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly RoleTemplateRegistry $templates,
    ) {}

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

        /*
         * ── ⛔ রোলগুলো এখন কোম্পানিভেদে, ৭ সেপ্টেম্বর ২০২৬ ────────────────
         *
         * ⚠️ পারমিশন (উপরে) বিশ্বজনীনই থাকে — ওটা পণ্যের শব্দভাণ্ডার।
         * ⓘ কিন্তু **রোল** এখন প্রতিটা কোম্পানির নিজের, তাই একবার চালিয়ে
         * থেমে গেলে কেবল **একটা** কোম্পানি রোল পেত।
         *
         * ⛔ আর এই কমান্ডটা চলে **কনসোলে, প্রতিটা ডেপ্লয়ে**, যেখানে কোনো
         * কোম্পানি-প্রসঙ্গ নেই। ⚠️ প্রসঙ্গ ছাড়া `Role::create()` করলে
         * `company_id` থাকত `null` — অর্থাৎ রোলটা **কোনো কোম্পানিরই নয়**,
         * আর কেউ সেটা কোনোদিন পেত না। ⓘ লগইন হত, মেনু খালি থাকত, আর
         * কোথাও কোনো ত্রুটি দেখা যেত না।
         *
         * ⭐ তাই প্রতিটা কোম্পানির প্রসঙ্গে ঢুকে আলাদা করে চালানো হয়।
         * [[CompanyContext::forCompany()]] টিমটাও বসায়, তাই এখানে আলাদা
         * করে মনে রাখার কিছু নেই।
         */
        /*
         * ⚠️ `keepOwnerComplete()` একটা **সংখ্যা** ফেরায় (কতগুলো অনুমতি
         * মালিকের রোলে যোগ হলো), আর `applyRoleTemplates()` একটা **তালিকা**
         * (কোন কোন রোল তৈরি হলো)। ⓘ প্রথম খসড়ায় দুইটাকেই তালিকা ধরে
         * unpack করেছিলাম, আর সাথে সাথে থেমেছিল:
         * *"Only arrays and Traversables can be unpacked, int given"*।
         *
         * ⭐ তাই সংখ্যাটা যোগ হয়, আর নামগুলো জমা হয় — দুইটা দুই রকম।
         */
        $granted = 0;
        $rolesCreated = [];

        foreach (Company::query()->orderBy('id')->pluck('id') as $companyId) {
            CompanyContext::forCompany((int) $companyId, function () use ($guard, &$granted, &$rolesCreated) {
                $granted += $this->keepOwnerComplete($guard);
                $rolesCreated = array_values(array_unique([...$rolesCreated, ...$this->applyRoleTemplates($guard)]));
            });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [
            'created' => $missing,
            'existing' => count($existing),
            'granted' => $granted,
            'roles_created' => $rolesCreated,
        ];
    }

    /**
     * ডিফল্ট রোলগুলো — কেবল প্রথমবার, তারপর ক্রেতার হাতে।
     *
     * প্রতিটা টেমপ্লেট-রোল **না থাকলে তবেই** বসে ও তার অনুমতি পায়। থাকলে
     * ছোঁয়া হয় না — নাহলে ক্রেতা রোলটা সম্পাদনা করার পর প্রতিটা sync তাঁর
     * বদল নীরবে ফিরিয়ে দিত, আর টেমপ্লেট তখন তালা হয়ে যেত (শুরুর সারি নয়)।
     *
     * owner এখানে নেই — [[keepOwnerComplete()]] তাকে সবসময় পূর্ণ রাখে, আর
     * তার সেটটা "সব", টেমপ্লেট নয়। কেবল সত্যিই তৈরি হওয়া অনুমতিই দেওয়া হয়:
     * কোনো মডিউল বন্ধ থাকলে তার অনুমতি নেই, তাই সেটা চুপচাপ বাদ পড়ে।
     *
     * @return list<string> এবার যে রোলগুলো তৈরি হলো
     */
    private function applyRoleTemplates(string $guard): array
    {
        $created = [];

        foreach ($this->templates->all() as $roleName => $permissions) {
            if ($roleName === self::SUPER_ADMIN_ROLE) {
                continue;
            }

            /*
             * ⚠️ "আছে কি না" প্রশ্নটা **এই কোম্পানিতে** — ৭ সেপ্টেম্বর ২০২৬।
             *
             * ⛔ কোম্পানি ছাড়া দেখলে প্রথম কোম্পানিতে রোলটা পাওয়া যেত, আর
             * বাকিদের জন্য "আছে" ধরে নিয়ে বাদ দেওয়া হত — ফলে দ্বিতীয়
             * কোম্পানিতে **একটাও টেমপ্লেট-রোল বসত না**।
             *
             * ⓘ ভুলটা নীরব: sync চলত, কিছু বলত না, আর মানুষ লগইন করে
             * খালি মেনু দেখতেন।
             */
            $exists = Role::query()
                ->where('name', $roleName)
                ->where('guard_name', $guard)
                ->where('company_id', CompanyContext::id())
                ->exists();

            if ($exists) {
                continue;
            }

            $role = Role::create(['name' => $roleName, 'guard_name' => $guard]);

            $grantable = Permission::query()
                ->where('guard_name', $guard)
                ->whereIn('name', $permissions)
                ->pluck('name')
                ->all();

            $role->givePermissionTo($grantable);
            $created[] = $roleName;
        }

        return $created;
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
            ->where('name', self::SUPER_ADMIN_ROLE)
            ->where('guard_name', $guard)
            ->where('company_id', CompanyContext::id())
            ->first();

        /*
         * ── ⛔ রোলটা না থাকলে **এখানেই** বসে, ৭ সেপ্টেম্বর ২০২৬ ──────────
         *
         * ⓘ আগে এখানে লেখা ছিল *"সিডার বসাবে"*, আর সেটা সত্যি ছিল যতদিন
         * রোল বিশ্বজনীন ছিল — একটাই `owner`, সিডার একবার বসাত।
         *
         * ⚠️ teams-এর পর **প্রতিটা কোম্পানির নিজের `owner` লাগে**, আর
         * কোম্পানি তৈরি হয় নানা পথে: সিডার, System Management-এর পর্দা,
         * আর প্রথম-চালুর ধাপ। ⛔ প্রতিটাকে মনে করিয়ে দেওয়ার চেয়ে এখানে
         * একবার বসিয়ে দেওয়া নিরাপদ।
         *
         * ⓘ ধরা পড়েছে মেপে: দ্বিতীয় কোম্পানিতে **একটাও রোল ছিল না**, আর
         * সিডার থেমে গিয়েছিল *"There is no role named `owner`"* বলে।
         * ⭐ ওটা ভাগ্য — বার্তাটা না এলে ভুলটা ধরা পড়ত অনেক পরে, যখন
         * কেউ দ্বিতীয় কোম্পানিতে লগইন করে খালি মেনু দেখতেন।
         */
        if ($owner === null) {
            $owner = Role::create(['name' => self::SUPER_ADMIN_ROLE, 'guard_name' => $guard]);
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
