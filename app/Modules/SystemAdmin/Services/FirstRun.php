<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Services;

use App\Core\Services\CompanyProvisioner;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CodeFromName;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * একদম নতুন ইনস্টলের প্রথম দরজা — একবারই খোলে।
 *
 * ── কেন এটা লাগল ────────────────────────────────────────────────────
 * `composer setup` আর `migrate` চলে যায়, লগইনের পর্দাও আসে — কিন্তু
 * **একটাও ব্যবহারকারী নেই**, আর ব্যবহারকারী বানানোর পর্দাটা `auth`-এর
 * পিছনে। অর্থাৎ:
 *
 *   লগইন করতে ব্যবহারকারী লাগে · ব্যবহারকারী বানাতে লগইন লাগে
 *
 * আমাদের সার্ভারে বিক্রি হলে ফাঁকটা চোখে পড়ে না, কারণ প্রথম
 * ব্যবহারকারীটা হাতে বসিয়ে দেওয়া হয়। **ক্রেতার নিজের সার্ভারে সেই
 * লোকটাই নেই** — আর ৩ সেপ্টেম্বর ২০২৬ থেকে ABOS দুইভাবেই বিক্রি হয়।
 *
 * ── কেন সিডার দিয়ে হয় না ────────────────────────────────────────────
 * `DatabaseSeeder` → `DemoSeeder`, আর সে ডেমো কোম্পানি, ডেমো পণ্য আর
 * `owner@abos.test` বসায়। প্রোডাকশনে ওটা চালানো যায় না। কিন্তু ঠিক ওই
 * সিডারের ভিতরেই লুকিয়ে আছে তিনটা ধাপ যা **ছাড়া অ্যাপটা চলে না**, আর
 * যা আর কোথাও লেখা নেই:
 *
 *   PermissionSyncer::sync()      module.php-র অনুমতিগুলো টেবিলে বসায়
 *   Role::findOrCreate('owner')   `owner` রোল কোথাও তৈরি হয় না
 *   $owner->syncPermissions(...)  নাহলে মালিক লগইন করে সব পর্দায় ৪০৩
 *
 * ⚠️ তিন নম্বরটা নীরব। `PermissionSyncer::keepOwnerComplete()` রোলটা না
 * পেলে চুপচাপ `0` ফেরত দেয় ("সিডার বসাবে" — কিন্তু প্রোডাকশনে সিডার
 * চলে না)। তাই রোলটা এখানে না বানালে `grantAccess()` একটা
 * `RoleDoesNotExist` ছুঁড়ত, আর ক্রেতা তার প্রথম পর্দাতেই একটা
 * ইংরেজি ব্যতিক্রম দেখতেন।
 *
 * ── পুরোটা এক ট্রানজ্যাকশনে, আর সেটা ইচ্ছাকৃত ───────────────────────
 * মাঝপথে থেমে গেলে যা পড়ে থাকত তা সবচেয়ে খারাপ অবস্থা: একটা
 * ব্যবহারকারী আছে (তাই দরজা আর খুলবে না), কিন্তু কোম্পানি নেই — অর্থাৎ
 * ক্রেতা লগইন করতে পারেন, আর ঢুকে কিছুই করতে পারেন না, ফেরার পথও নেই।
 */
final class FirstRun
{
    public function __construct(
        private readonly CompanyProvisioner $provisioner,
        private readonly PermissionSyncer $permissions,
    ) {}

    /**
     * দরজাটা কি আর খোলা?
     *
     * শর্তটা **"owner একজনই"** নয় — owner একটা ভূমিকা, আর একটা
     * প্রতিষ্ঠানে পরে যত খুশি owner থাকতে পারেন। শর্তটা **"এই দরজা
     * দিয়ে একবারই ঢোকা যায়"**, আর সেটা মাপা হয় সবচেয়ে সরল প্রশ্নে:
     * এই ইনস্টলে কি একটাও ব্যবহারকারী আছেন?
     *
     * ⚠️ এটা কেবল **দেখানোর** সিদ্ধান্ত (পর্দা না ৪০৪)। **লেখার**
     * সিদ্ধান্ত এখানে নয় — সেটা `open()`-এর তালার ভিতরে, কারণ এই
     * উত্তরটা ফেরত দেওয়ার আর ফর্ম জমা পড়ার মাঝে সময় থাকে।
     */
    public function isOpen(): bool
    {
        return ! User::query()->exists();
    }

    /**
     * প্রথম মালিক, প্রথম কোম্পানি — একসাথে, একবার।
     *
     * @param  array<string, mixed>  $data  যাচাই করা ফর্ম
     *
     * @throws \RuntimeException দরজাটা ইতিমধ্যে ব্যবহার হয়ে গেলে
     */
    public function open(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            /*
             * ⚠️ এই একটা লাইনই দৌড়টা থামায় — আর এটা কোড পড়ে বোঝার
             * জিনিস, চালিয়ে নয়।
             *
             * ── দৌড়টা কী ────────────────────────────────────────────
             * দুইটা অনুরোধ একই মুহূর্তে এলে দুইটাই `users` খালি দেখতে
             * পারে, আর দুইজনেই প্রথম মালিক হয়ে বসেন:
             *
             *   ক: গোনা → ০   খ: গোনা → ০   ক: INSERT   খ: INSERT
             *
             * ── কেন throttle যথেষ্ট নয় ──────────────────────────────
             * `throttle` **হারের সীমা, পারমাণবিকতা নয়**। দশটা ধাক্কা
             * লাগে না — দুইটাই যথেষ্ট, আর ওই দুইটা সীমার ভিতরেই থাকে।
             *
             * ── কেন `settings`-এর unique index দিয়ে হয় না ───────────
             * `settings`-এ `unique(['company_id','key'])` আছে আর
             * `company_id` nullable, তাই মনে হয় একটা প্রোডাক্ট-ডিফল্ট
             * সারি বসালেই ডাটাবেস দ্বিতীয়টা আটকাবে। **আটকায় না** —
             * MySQL-এর unique index দুইটা NULL-কে ডুপ্লিকেট ধরে না,
             * তাই `(NULL, 'key')` যতবার খুশি বসতে পারে।
             *
             * ── এটা কীভাবে থামায় ────────────────────────────────────
             * খালি টেবিলে `FOR UPDATE` InnoDB-কে গোটা পরিসরে একটা
             * gap lock নিতে বাধ্য করে, তাই দ্বিতীয় ট্রানজ্যাকশনের
             * INSERT প্রথমটার commit পর্যন্ত অপেক্ষা করে। তারপর সে
             * এখানে এসে **committed** সারিটা দেখতে পায় (locking read
             * সবসময় সর্বশেষ committed অবস্থা পড়ে, snapshot নয়) আর
             * নিচের শর্তে আটকে যায়।
             */
            $taken = User::query()->lockForUpdate()->exists();

            if ($taken) {
                throw new \RuntimeException('first-run door already used');
            }

            /*
             * অনুমতি ও রোল আগে, ব্যবহারকারীর আগে।
             *
             * ক্রমটা বদলানো যাবে না: `grantAccess()` নামে রোল খোঁজে,
             * আর নাম ধরে খুঁজে না পেলে ছুঁড়ে ফেলে।
             */
            $this->permissions->sync();

            $owner = Role::findOrCreate(PermissionSyncer::OWNER_ROLE);
            $owner->syncPermissions(Permission::all());

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'locale' => $data['locale'] ?? 'bn',
                'is_active' => true,
            ]);

            /*
             * কোম্পানির কোড — খালি রাখলে নাম থেকে, মালিকের নিয়ম
             * (২ সেপ্টেম্বর ২০২৬): `CMP-0001` নয়, `Trade Depot` → `TRA`।
             *
             * ⚠️ `CodeSuggester` নয়, `CodeFromName::forQuery()` —
             * কারণ এখনো কোনো কোম্পানি প্রসঙ্গ নেই, আর কোম্পানির কোড
             * টেন্যান্টের সীমার বাইরে অনন্য হতে হয়। `CompanyController`
             * ঠিক এই কারণেই একই পথ নেয়।
             */
            $companyCode = CodeFromName::forQuery($data['company_name'], Company::query());

            /*
             * ⚠️ কোডটা খালি হতে পারে — আর সেটা নীরবে বসে যেত।
             *
             * `CodeFromName::base()` ইংরেজি অক্ষর ছাড়া সব ফেলে দেয়
             * (তার নিজের মন্তব্যে: "বাংলা নামে কিছুই টেকে না"), তাই
             * পুরো বাংলা নাম দিলে সে **খালি স্ট্রিং** ফেরত দেয়। কোথাও
             * কিছু ভাঙে না; শুধু প্রতিটা ডকুমেন্টের নম্বর একটা হাইফেন
             * দিয়ে শুরু হত, আর ধরা পড়ত ছয় মাস পর।
             *
             * ⓘ কন্ট্রোলারের যাচাই এটা আটকায় (`regex:/[A-Za-z]/`), তাই
             * এখানে পৌঁছানোর কথা নয়। তবু লেখা আছে, কারণ যাচাইটা
             * **পর্দার**, আর সার্ভিসটা পর্দা ছাড়াও ডাকা যায় — সিডার
             * থেকে, কমান্ড থেকে, বা আগামী বছরের অন্য কোনো পথ থেকে।
             * এই রিপোর নিয়মই তাই: পাহারা নিচের স্তরে, উপরের নয়।
             */
            if ($companyCode === '') {
                /*
                 * ⚠️ `LogicException`, `RuntimeException` নয় — আর
                 * পার্থক্যটা ইচ্ছাকৃত।
                 *
                 * কন্ট্রোলার কেবল `RuntimeException` ধরে, আর ধরে ৪০৪
                 * দেয় ("দরজাটা আর নেই")। এখানে ওটা ছুঁড়লে **দুইটা
                 * সম্পূর্ণ আলাদা ঘটনা একই ৪০৪-এ মিশে যেত**: একজন যিনি
                 * পুরনো ট্যাব থেকে জমা দিলেন, আর একজন প্রোগ্রামার যিনি
                 * যাচাই ছাড়া সার্ভিসটা ডাকলেন। প্রথমজনের জন্য ৪০৪ সঠিক;
                 * দ্বিতীয়জনের জন্য ওটা **বাগটা লুকিয়ে ফেলা**।
                 *
                 * ⓘ গার্ডটা ইচ্ছে করে ভেঙে দেখতে গিয়েই এটা ধরা পড়েছে
                 * (৩ সেপ্টেম্বর ২০২৬): যাচাইয়ের নিয়মটা সরানোর পর
                 * অনুরোধটা ত্রুটি না দেখিয়ে চুপচাপ ৪০৪ হয়ে গিয়েছিল।
                 */
                throw new \LogicException('company name yields no code — needs a Latin letter');
            }

            $branchCode = CodeFromName::forQuery(
                $data['branch_name'],
                Branch::query()->withoutGlobalScopes(),
            );

            $company = $this->provisioner->create(
                [
                    'code' => $companyCode,
                    'name_en' => $data['company_name'],
                ],
                [
                    /*
                     * শাখার কোড না বসলে কোম্পানির কোডটাই — একটা সিরিয়াল
                     * নয়।
                     *
                     * প্রথম শাখা একটাই, তাই `TRA` কোম্পানির প্রধান শাখা
                     * `TRA` হলে খাতা পড়ে বোঝা যায় ওটা কোথাকার। পরে
                     * শাখা যোগ হলে তারা নিজেদের নাম থেকে কোড পাবে।
                     */
                    'code' => $branchCode !== '' ? $branchCode : $companyCode,
                    'name_en' => $data['branch_name'],

                    /*
                     * প্রধান শাখা — `is_default`, `is_active` নয়।
                     * `CompanyController` একই ঘরটাই বসায়; এটা না বসালে
                     * প্রথম শাখাটা থাকত কিন্তু কোনটা প্রধান তা কেউ
                     * জানত না।
                     */
                    'is_default' => true,
                ],
                CompanyProvisioner::currentBangladeshiYear(),
            );

            $this->provisioner->grantAccess($company, $user);

            return $user;
        });
    }
}
