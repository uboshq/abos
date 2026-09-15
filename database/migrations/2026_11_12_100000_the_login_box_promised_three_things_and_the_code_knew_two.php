<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * লগইন আইডি, আর ইমেইল বদলানোর অপেক্ষমাণ ঘরগুলো।
 *
 * ── ⭐ দুইটা কাজ, একটাই মাইগ্রেশন, আর কারণটা বলে রাখা দরকার ───────────
 * মালিকের সিদ্ধান্ত, ১৪ সেপ্টেম্বর ২০২৬: *"১ আর ৩ — একসাথে"*, অর্থাৎ
 * যাচাই করে ইমেইল বদলানো, আর আলাদা একটা নিজের বাছা লগইন আইডি। ⓘ দুইটাই
 * `users`-এর ঘর, আর দুইটা আলাদা মাইগ্রেশনে ভাগ করলে একটা চলে আর অন্যটা
 * না-চলা অবস্থায় প্রোফাইল পাতা অর্ধেক ভাঙা থাকত।
 *
 * ── ⛔ আর এটা একটা ফাঁকও বন্ধ করে ────────────────────────────────────
 * লগইনের ঘরের লেবেলে আজও লেখা আছে *"ব্যবহারকারীর নাম, ইমেইল বা মোবাইল"*।
 * ⚠️ কিন্তু [[App\Core\Security\CredentialCheck]] মেলাত `email` আর
 * `name` — মোবাইল কখনো নয়, অর্থাৎ **লেবেলটা একটা মিথ্যা প্রতিশ্রুতি**।
 *
 * ⛔ আর `name` মেলানোটা কেবল ভুল নয়, বিপজ্জনক: `users.name`-এ unique
 * সূচক নেই (মেপে দেখা — আছে কেবল `users_email_unique` ও
 * `users_public_id_unique`), আর নিজের নাম প্রোফাইল পাতা থেকে যে কেউ
 * বদলাতে পারেন। ⚠️ তাই একজন নিজের নাম আরেকজনের **ইমেইল** বসিয়ে দিলে
 * ঐ ঠিকানায় দুইটা সারি মিলত, আর কোনটা ফিরবে তার নিয়ম নেই।
 *
 * ⭐ `login_id` আসার পর নামটা আর পরিচয় নয় — নাম আবার শুধু নাম।
 *
 * ── ⓘ কেন `login_id` ঐচ্ছিক, অথচ unique ──────────────────────────────
 * আজকের ব্যবহারকারীদের কেউ নিজের আইডি বাছেননি, তাই `NOT NULL` দিলে এই
 * মাইগ্রেশনটাই চলত না। ⚠️ কিন্তু unique না দিলে আজকের ফাঁকটাই নতুন
 * ঘরে ফিরে আসত — দুইজনের এক আইডি মানে আবার "কোনটা ফিরবে জানি না"।
 * ⓘ MySQL-এ unique সূচক একাধিক NULL মানে — অর্থাৎ যাঁরা বাছেননি তাঁরা
 * সবাই পাশাপাশি থাকতে পারেন।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('login_id', 40)->nullable()->unique()->after('email');

            /*
             * ── ইমেইল বদল — নতুন ঠিকানাটা এখানে অপেক্ষা করে ───────────
             *
             * ⭐ আসল ঘরটা (`email`) **ছোঁয়া হয় না** যতক্ষণ না নতুন
             * ঠিকানার মালিক লিংকে চাপ দেন। ⛔ সাথে সাথে বদলে দিলে কেউ
             * একটা অক্ষর ভুল লিখে নিজের অ্যাকাউন্ট থেকে চিরতরে বেরিয়ে
             * যেতেন — লগইন ঐ ঠিকানায়, রিসেট লিংকও ঐ ঠিকানায়।
             *
             * ⓘ তাই পুরনো ঠিকানাটা শেষ মুহূর্ত পর্যন্ত কাজ করতে থাকে।
             */
            $table->string('pending_email', 191)->nullable()->after('login_id');

            /*
             * ⚠️ টোকেনের **হ্যাশ**, টোকেন নয়।
             *
             * ⛔ সাদা রাখলে ডাটাবেজ পড়তে পারে এমন যে কেউ (ব্যাকআপ ফাইল,
             * একটা SQL ইনজেকশন, একজন কৌতূহলী প্রশাসক) লিংকটা নিজে
             * বানিয়ে অন্যের ইমেইল নিজের নামে বসিয়ে নিতে পারতেন।
             * ⓘ পাসওয়ার্ড রিসেটের টোকেনও ঠিক এভাবেই রাখা হয়।
             */
            $table->string('pending_email_token', 64)->nullable()->after('pending_email');

            /* ⓘ মেয়াদ মাপার জন্য — লিংক চিরকাল বাঁচে না। */
            $table->timestamp('pending_email_at')->nullable()->after('pending_email_token');
        });

        /*
         * ── পুরনো অ্যাকাউন্টগুলোকে একটা আইডি দেওয়া ─────────────────────
         *
         * ⚠️ এটা সাজসজ্জা নয়, **ধারাবাহিকতা**: আজ পর্যন্ত কেউ নিজের
         * নাম লিখে লগইন করতে পারতেন, আর পরের ধাপে সেই পথটা বন্ধ হচ্ছে।
         * ⛔ কাউকে আইডি না দিয়ে পথটা বন্ধ করলে যিনি নাম দিয়ে ঢুকতেন
         * তিনি কাল সকালে আটকে যেতেন।
         *
         * ⓘ উৎস ইমেইলের প্রথম অংশ, কারণ ওটা তিনি এমনিতেই মনে রাখেন।
         * নাম থেকে বানানো হয়নি: নামগুলো বাংলায় ("হিসাবরক্ষক"), আর
         * slug করলে ফাঁকা স্ট্রিং পড়ে থাকত।
         */
        $taken = [];

        foreach (DB::table('users')->select('id', 'email')->orderBy('id')->get() as $row) {
            $base = Str::of((string) $row->email)
                ->before('@')
                ->lower()
                ->replaceMatches('/[^a-z0-9._-]+/', '')
                ->limit(30, '')
                ->toString();

            /* ⚠️ ইমেইলটা পুরোটাই অ-ল্যাটিন হলে উপরের ফল ফাঁকা — তখন
               `id` ধরে একটা বসানো হয়, কারণ ফাঁকা আইডি কোনো আইডি নয়। */
            if (strlen($base) < 3) {
                $base = 'user'.$row->id;
            }

            $candidate = $base;
            $suffix = 2;

            while (isset($taken[$candidate])) {
                $candidate = $base.$suffix++;
            }

            $taken[$candidate] = true;

            DB::table('users')->where('id', $row->id)->update(['login_id' => $candidate]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['login_id']);
            $table->dropColumn(['login_id', 'pending_email', 'pending_email_token', 'pending_email_at']);
        });
    }
};
