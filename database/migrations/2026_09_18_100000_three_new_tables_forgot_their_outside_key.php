<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * তিনটা নতুন টেবিলে `public_id` বসানো।
 *
 * ── কীভাবে ধরা পড়ল ──────────────────────────────────────────────────
 * `PublicIdTest` স্কিমা ধরে দেখে — হাতে লেখা তালিকা ধরে নয়। তাই ২০
 * আগস্টের পূর্ণ সুইটে তিনটা নতুন টেবিল সাথে সাথেই ধরা পড়েছে:
 * `acc_depreciation_entries`, `notifications`, `user_data_scopes`।
 *
 * ── কেন তিনটারই দরকার, একটারও ছাড় নয় ───────────────────────────────
 * ভেতরে bigint, বাইরে UUIDv7 — কারণ ক্রমিক সংখ্যা গোনা যায়।
 *
 * `notifications`-এর বেলায় এটা তাত্ত্বিক নয়: আজকের ঠিকানা
 * `/notifications/{id}` **ক্রমিক সংখ্যাই দেখায়**, তাই যে কেউ নিজের
 * খবরের নম্বর দেখে বলে দিতে পারেন গোটা কোম্পানিতে আজ পর্যন্ত কয়টা
 * বিজ্ঞপ্তি গেছে।
 *
 * `acc_depreciation_entries` সরাসরি খতিয়ানে বসে, তাই ওটা ব্যবসায়িক
 * নথি — API বা রপ্তানিতে একদিন বাইরে যাবেই।
 *
 * `user_data_scopes` কে কোন সারি দেখবেন তা ঠিক করে; নিরাপত্তার কাগজে
 * ক্রমিক নম্বর ফাঁস করার কোনো কারণ নেই।
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = [
        'acc_depreciation_entries',
        'notifications',
        'user_data_scopes',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->publicId();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('public_id');
            });
        }
    }
};
