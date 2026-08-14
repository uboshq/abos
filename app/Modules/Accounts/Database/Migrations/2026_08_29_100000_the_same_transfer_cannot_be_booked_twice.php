<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * একই ব্যাংক লেনদেন দুইবার খাতায় বসতে পারে না।
 *
 * ── কী ঘটত ──────────────────────────────────────────────────────────
 * হিসাবরক্ষক বিকাশের ৳৫০,০০০ তুললেন। ম্যানেজারও তুললেন, একই টাকার।
 * দুইটা ভাউচার, দুইটা TrxID এক — আর খাতায় ৳১,০০,০০০ পরিশোধ দেখাল।
 * ব্যাংক মেলাতে গিয়ে পার্থক্যটা ধরা পড়ত, কিন্তু ততদিনে মাসের হিসাব
 * বন্ধ হয়ে গেছে।
 *
 * ── কেন `(কোম্পানি, খাত, নম্বর)`, কেবল নম্বর নয় ─────────────────────
 * ব্যাংকের রেফারেন্স নম্বর ছোট ও পুনরাবৃত্ত — `001234` দুই ব্যাংকেই
 * থাকতে পারে। কেবল নম্বর ধরে অনন্য করলে দ্বিতীয় ব্যাংকের বৈধ একটা
 * এন্ট্রি আটকে যেত, আর মানুষ নম্বরের শেষে একটা অক্ষর জুড়ে কাজ চালাত —
 * তখন পাহারাটাই অকেজো।
 *
 * খাতসহ ধরলে কথাটা হয়: **একই অ্যাকাউন্টে একই নম্বর মানে একই টাকা।**
 * বিকাশের TrxID এমনিতেই বিশ্বজনীন, তাই ওখানে কড়াকড়িটা কিছু হারায় না।
 *
 * ── কেন nullable, আর তাতেও কাজ হয় ──────────────────────────────────
 * নগদ পরিশোধে কোনো TrxID নেই। বাধ্যতামূলক করলে প্রতিটা নগদ ভাউচারে
 * একটা বানানো নম্বর বসত — আর বানানো নম্বর কোনো নম্বর না থাকার চেয়ে
 * খারাপ, কারণ ব্যাংক মেলানোর সময় ওটা দেখে সবাই ভাবে মিলে গেছে।
 *
 * MySQL-এ NULL কখনো NULL-এর সাথে ঠোকে না, তাই একাধিক নগদ ভাউচার
 * নির্বিঘ্নে বসে আর ইউনিক ইনডেক্সটা কেবল সত্যিকারের নম্বরগুলো পাহারা
 * দেয়। এই প্রকল্পে একই কৌশল আগেও কাজে লেগেছে (`open_marker`,
 * `idempotency_key`)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            /*
             * কোন টাকার খাতে লেনদেনটা হয়েছে।
             *
             * ভাউচারের লাইনে খাত আছে, মাথায় নেই — আর অনন্যতা মাথায়
             * বসাতে হয়, নাহলে দুই লাইনের ভাউচারে নিয়মটা কোন লাইনের
             * তা বলা যেত না। তাই নিশ্চিত করার সময় টাকার লাইনটা দেখে
             * খাতটা এখানে বসানো হয়।
             */
            $table->foreignId('money_account_id')->nullable()->after('instrument_date')
                ->constrained('accounts')->nullOnDelete();

            $table->unique(['company_id', 'money_account_id', 'instrument_no'], 'vouchers_bank_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->dropUnique('vouchers_bank_reference_unique');
            $table->dropConstrainedForeignId('money_account_id');
        });
    }
};
