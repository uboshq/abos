<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * এক মালিক অন্য সব মালিকের রোল বদলাতে পারতেন।
 *
 * ── ⛔ কী ভাঙা ছিল ───────────────────────────────────────────────────
 * রোল ছিল **বিশ্বজনীন** — একটাই `owner`, একটাই `salesman`, সব কোম্পানির
 * জন্য। ⓘ ফল দুইটা, দুইটাই নীরব:
 *
 *   ⛔ এক ক্রেতার মালিক **সবার ডাটাবেস** নামাতে পারতেন — ব্যাকআপের
 *      অনুমতি তাঁর রোলে আছে, আর সেই রোলটা সবার
 *   ⛔ কেউ "বিক্রয়কর্মী"-র একটা ক্ষমতা তুলে দিলে **প্রতিটা কোম্পানির**
 *      বিক্রয়কর্মী সেটা হারাতেন, আর কেউ বুঝতেই পারত না কেন
 *
 * ⚠️ বহু-টেন্যান্ট পণ্যে এটা সুবিধার প্রশ্ন নয় — CLAUDE.md-র ভাষায়
 * *"টেন্যান্ট বিচ্ছিন্নতা সুবিধা নয়, আইনি বাধ্যবাধকতা"*।
 *
 * ── কেন আজ, পরে নয় ─────────────────────────────────────────────────
 * ⭐ আজ ৭টা রোল আর **৩টা বরাদ্দ**। ⓘ বিশটা ক্রেতা আর কয়েকশো
 * ব্যবহারকারীর পরে এই একই মাইগ্রেশন একটা রাত জাগার কাজ — আর তখন ভুল
 * হলে ফেরানোও কঠিন।
 *
 * ── ⚠️ পারমিশন কেন ছোঁয়া হয়নি ──────────────────────────────────────
 * ১৮৪টা পারমিশন **পণ্যের শব্দভাণ্ডার** — "বিল বসানো যায়", "ছাড় দেওয়া
 * যায়"। ⓘ ওগুলো সব ক্রেতার জন্য এক, আর এক থাকা উচিত।
 *
 * ⭐ বদলায় কেবল **রোল**: *"বিক্রয়কর্মী কী কী পারে"* প্রতিটা ব্যবসার
 * নিজের সিদ্ধান্ত। মালিকের নিয়মও তাই — *"যে তালিকা গ্রাহকভেদে বদলায়,
 * সেটা সেটিংসের সারি, কোডের ধ্রুবক নয়"*।
 *
 * ── ⛔ ব্যাক-ফিলটা এখানেই, আলাদা স্ক্রিপ্টে নয় ───────────────────────
 * `deploy.sh` কেবল মাইগ্রেশন চালায়। ⚠️ স্ক্রিপ্ট লিখলে কেউ একদিন সেটা
 * চালাতে ভুলে যেতেন, আর তখন **প্রতিটা ব্যবহারকারী তাঁর সব রোল হারাতেন**
 * — লগইন হত, কিন্তু কোনো মেনু থাকত না।
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ── ⚠️ স্কিমার কাজটা spatie নিজেই করে, শর্তসাপেক্ষে ────────────────
         *
         * ⓘ `config/permission.php`-এ `teams => true` দেখে spatie-র নিজের
         * মাইগ্রেশন **তিনটা টেবিলেই** কলাম, ইনডেক্স ও ইউনিক কী বসায়।
         *
         * ⛔ প্রথম খসড়ায় আমি ওগুলো আবার বসাতে গিয়েছিলাম, আর নতুন
         * ডাটাবেসে (টেস্ট · `migrate:fresh`) সাথে সাথে থেমেছিল:
         * *"Duplicate column name 'company_id'"*।
         *
         * ── ⭐ তাই ভাগটা এখন পরিষ্কার ────────────────────────────────────
         *     নতুন ডাটাবেস   →  spatie বসায়, এখানে কিছুই করার নেই
         *     চলতি লাইভ      →  টেবিলগুলো teams বন্ধ থাকতে তৈরি, তাই
         *                        কলামগুলো নেই — এখানে বসানো হয়
         *
         * ⚠️ প্রতিটা ধাপ আলাদা করে দেখা হয় (`hasColumn`), কারণ একটা
         * ডাটাবেস অর্ধেক পথেও থাকতে পারে — যেমন এই মাইগ্রেশনটা একবার
         * ব্যর্থ হওয়ার পর।
         */
        if (! Schema::hasColumn('roles', 'company_id')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->nullable()->after('id');
                $table->index('company_id', 'roles_team_foreign_key_index');
            });

            /*
             * ⓘ পুরনো চাবিটা `(name, guard_name)` — অর্থাৎ "owner" নামে
             * **একটাই** রোল। ⛔ প্রতি কোম্পানিতে একটা করে দরকার, তাই
             * চাবিটা বদলাতেই হয়।
             */
            Schema::table('roles', function (Blueprint $table) {
                $table->dropUnique('roles_name_guard_name_unique');
                $table->unique(['company_id', 'name', 'guard_name'], 'roles_company_name_guard_unique');
            });
        }

        foreach (['model_has_roles' => 'role_id', 'model_has_permissions' => 'permission_id'] as $table => $fk) {
            if (Schema::hasColumn($table, 'company_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->unsignedBigInteger('company_id')->nullable()->after('model_id');
                $t->index('company_id', $table.'_team_foreign_key_index');
            });

            DB::statement("ALTER TABLE `{$table}` DROP PRIMARY KEY, ADD PRIMARY KEY (`{$fk}`, `model_id`, `model_type`, `company_id`)");
        }

        $companies = DB::table('companies')->orderBy('id')->pluck('id')->all();

        if ($companies === []) {
            return;
        }

        $first = array_shift($companies);

        DB::table('roles')->whereNull('company_id')->update(['company_id' => $first]);
        DB::table('model_has_roles')->whereNull('company_id')->update(['company_id' => $first]);
        DB::table('model_has_permissions')->whereNull('company_id')->update(['company_id' => $first]);

        $originals = DB::table('roles')->where('company_id', $first)->get();

        foreach ($companies as $companyId) {
            foreach ($originals as $role) {
                $copyId = DB::table('roles')->insertGetId([
                    'company_id' => $companyId,
                    'public_id' => (string) Str::uuid(),
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                /*
                 * ⚠️ পারমিশনগুলোও কপি হয়, নাহলে নতুন কোম্পানির রোলগুলো
                 * **নাম ছাড়া আর কিছুই নয়** — মানুষ লগইন করে খালি মেনু
                 * দেখতেন আর ভাবতেন ব্যবস্থাটা ভাঙা।
                 */
                $permissions = DB::table('role_has_permissions')
                    ->where('role_id', $role->id)->pluck('permission_id');

                foreach ($permissions as $permissionId) {
                    DB::table('role_has_permissions')->insert([
                        'role_id' => $copyId,
                        'permission_id' => $permissionId,
                    ]);
                }

                /*
                 * ⭐ আর যাঁরা ওই কোম্পানিতে আছেন, তাঁদের বরাদ্দও।
                 *
                 * ⓘ মালিক তিন কোম্পানিতেই মালিক ছিলেন; teams-এর আগে
                 * একটা সারিই যথেষ্ট ছিল, এখন প্রতি কোম্পানিতে একটা লাগে।
                 * ⛔ না দিলে তিনি অন্য দুই কোম্পানিতে লগইন করে **কিছুই
                 * করতে পারতেন না**।
                 */
                $holders = DB::table('model_has_roles')
                    ->where('role_id', $role->id)
                    ->where('company_id', $first)
                    ->get();

                foreach ($holders as $holder) {
                    $belongs = DB::table('company_user')
                        ->where('user_id', $holder->model_id)
                        ->where('company_id', $companyId)
                        ->exists();

                    if (! $belongs) {
                        continue;
                    }

                    DB::table('model_has_roles')->insert([
                        'role_id' => $copyId,
                        'model_id' => $holder->model_id,
                        'model_type' => $holder->model_type,
                        'company_id' => $companyId,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        /*
         * ── ⚠️ কেবল ব্যাক-ফিলটাই ফেরানো হয়, স্কিমা নয় ────────────────────
         *
         * ⓘ কলামগুলো spatie-র নিজের মাইগ্রেশনের হতে পারে (নতুন ডাটাবেসে
         * তাই)। ⛔ ওগুলো এখানে মুছলে spatie-র মাইগ্রেশন আর তার নিজের
         * টেবিল চিনত না — অর্থাৎ একটা মাইগ্রেশন আরেকটার কাজ মুছে দিত।
         *
         * ⚠️ ফেরানোটা ক্ষতিহীন নয়: কপিগুলোর সাথে ওই কোম্পানিগুলোর নিজের
         * সাজানো রোলও যায়। ⓘ তবু লেখা, কারণ ডেপ্লয়ের রাতে ফেরার পথ
         * থাকা দরকার — আর কী হারায় সেটা জানা থাকা দরকার তার চেয়েও বেশি।
         */
        $first = DB::table('companies')->orderBy('id')->value('id');

        if ($first === null) {
            return;
        }

        $keep = DB::table('roles')->where('company_id', $first)->pluck('id');

        DB::table('model_has_roles')->whereNotIn('role_id', $keep)->delete();
        DB::table('role_has_permissions')->whereNotIn('role_id', $keep)->delete();
        DB::table('roles')->whereNotIn('id', $keep)->delete();
    }
};
