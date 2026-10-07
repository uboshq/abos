<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ব্যবহারকারীর সংকেত ও মন্তব্য — মালিকের নমুনা, ২২ সেপ্টেম্বর ২০২৬।
 *
 * ── ⭐ কেন সংকেত ────────────────────────────────────────────────────
 * মালিকের নমুনায় প্রতিটা সারিতে `USR0001`। ⓘ কাজে লাগে যখন একই নামের
 * দুইজন থাকেন, বা কাগজে-কথায় একজনকে নাম ধরে ডাকতে হয় — *"USR0005
 * মাল বুঝে নিয়েছেন"*।
 *
 * ⚠️ আইডি দিয়ে চলত না: আইডি ডাটাবেজের ভিতরের জিনিস, আর সে কোম্পানি
 * ধরে আলাদা হয় না। ⛔ `public_id` আছে, কিন্তু ওটা UUID — মানুষের
 * বলার মতো নয়।
 *
 * ── ⓘ কেন কোম্পানি ধরে অনন্য, গোটা টেবিলে নয় ───────────────────────
 * একজন মানুষ একাধিক কোম্পানিতে থাকতে পারেন ([[company_user]]), কিন্তু
 * সংকেতটা **কোম্পানির নিজের ক্রম** — TCL-এর `USR0003` আর DEM-এর
 * `USR0003` দুইজন আলাদা মানুষ হতে পারেন, আর সেটাই স্বাভাবিক।
 *
 * ⚠️ তাই অনন্যতা নেই, কেবল সূচক: একই মানুষ দুই কোম্পানিতে দুইটা
 * সংকেত পাবেন না — তিনি একটাই সারি। ⓘ সংকেতটা তাঁর **প্রথম**
 * কোম্পানির ক্রম ধরে বসে, আর সেটাই যথেষ্ট: দুইটা কোম্পানিতে একই
 * সংকেত দেখানো একটা ভুল সমস্যার সমাধান হত।
 *
 * ── ⭐ কেন মন্তব্য ──────────────────────────────────────────────────
 * *"ছুটিতে আছেন"*, *"অক্টোবর থেকে বিক্রয়ে"* — যে কথাগুলো কোনো ঘরে
 * পড়ে না। ⛔ না থাকলে মানুষ ওগুলো নামের ঘরে লেখে, আর তখন নামটাই
 * নষ্ট হয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             * ⓘ nullable — পুরনো সারিগুলোর সংকেত নিচে বসানো হয়, আর
             * নতুন ব্যবহারকারী তৈরির সময় সেবা স্তর বসায়।
             *
             * ⚠️ ১৬ অক্ষর: `USR0001` সাত, কিন্তু কোনো প্রতিষ্ঠান নিজের
             * ছাঁচে লিখতে চাইলে (`TCL-EMP-0042`) জায়গা থাকে।
             */
            $table->string('code', 16)->nullable()->after('login_id');
            $table->string('remarks', 255)->nullable()->after('address');

            /* ⓘ নামটা ছোট — ৬৪ অক্ষরের সীমা সবার migrate:fresh ভাঙে */
            $table->index('code', 'users_code_idx');
        });

        /*
         * ⭐ পুরনো সারিগুলোতে সংকেত — একবার, ক্রমে।
         *
         * ⓘ আইডির ক্রমে, তাই যিনি আগে এসেছেন তাঁর সংকেত ছোট।
         * ⚠️ MySQL-এ `UPDATE` আর `ORDER BY` একসাথে চলে না যেভাবে দরকার,
         * তাই সারি ধরে ধরে — ব্যবহারকারীর সংখ্যা ছোট, একবারের কাজ।
         */
        $rows = DB::table('users')
            ->whereNull('code')->orderBy('id')->pluck('id');

        foreach ($rows as $index => $id) {
            DB::table('users')->where('id', $id)->update([
                'code' => 'USR'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_code_idx');
            $table->dropColumn(['code', 'remarks']);
        });
    }
};
