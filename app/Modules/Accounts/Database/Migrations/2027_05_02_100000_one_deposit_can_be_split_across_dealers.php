<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ একটা ব্যাংক জমা কয়েকজন ডিলারের নামে ভাগ হতে পারে — মালিক, ২১ নভেম্বর ২০২৬।
 *
 * ── কী ঘটল ──────────────────────────────────────────────────────────
 * মালিক ৳১,০০,০০০ জমা দিলেন, লেনদেন নম্বর 175300556 — দুই ডিলারের ৳৫০,০০০ করে। RCV-0537-এর সই পড়ল, অথচ পোস্ট থামল:
 * "লেনদেন নম্বর 175300556 আগেই বসেছে — ভাউচার RCV-0283" (২৫ অক্টোবরের অন্য তারিখের অন্য রসিদ)। দ্বিতীয় ডিলারের RCV-0538-এ
 * নম্বরটা "0556" লিখে পাহারা পেরোতে হল — ঠিক যে ফাঁকিটা ২৯ আগস্টের পাহারা ঠেকাতে চেয়েছিল।
 *
 * ── নতুন নিয়ম (আন্তর্জাতিক) ─────────────────────────────────────────
 * একই টাকা কেবল একই নম্বর + একই খাত/মাধ্যম + একই মূল্য-তারিখ + একই পক্ষ, আর কেবল পাকা কাগজে
 * ([[VoucherService::sameDeposit()]])। ⓘ অন্য পক্ষের একই নম্বর জমার ভাগ — বৈধ।
 *
 * ── তাই `vouchers_bank_reference_unique` যায় ─────────────────────────
 * `(কোম্পানি, খাত, নম্বর)` অনন্য থাকলে জমার ভাগ ডেটাবেসেই ভাঙত, আর বাতিল রসিদও নম্বরটা ধরে রাখত। ⓘ নকল-পাহারা এখন পোস্টের
 * লেনদেনে, টাকার খাতের সারিতে তালা নিয়ে ([[VoucherService::assertBankReferenceIsFree()]]) — একই মুহূর্তে দুই হাত এলেও দ্বিতীয়জন
 * প্রথমটাকে দেখে থামেন। ⓘ খোঁজার পথ থাকে — একই তিন কলামে সাধারণ সূচি।
 *
 * ⚠️ ক্রম: আগে নতুন সূচি, তারপর পুরনোটা তোলা। MySQL `company_id`-এর বিদেশি চাবির জন্য পুরনো অনন্য সূচিটা ব্যবহার করে থাকতে
 * পারে; আগে তুললে "needed in a foreign key constraint" ভুলে থামত।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('vouchers', 'vouchers_bank_reference_index')) {
            Schema::table('vouchers', function (Blueprint $table): void {
                $table->index(['company_id', 'money_account_id', 'instrument_no'], 'vouchers_bank_reference_index');
            });
        }

        if (Schema::hasIndex('vouchers', 'vouchers_bank_reference_unique')) {
            Schema::table('vouchers', function (Blueprint $table): void {
                $table->dropUnique('vouchers_bank_reference_unique');
            });
        }
    }

    public function down(): void
    {
        /*
         * ⚠️ জমার ভাগ বসে গেলে অনন্য সূচি আর ফেরানো যায় না (একই খাত আর নম্বরে দুই রসিদ) — তখন ডেটাবেস নিজেই থামায়, আর সেটাই ঠিক:
         * ভাগগুলো না সরিয়ে পুরনো নিয়মে ফেরা মানে পাকা কাগজ ভাঙা।
         */
        if (! Schema::hasIndex('vouchers', 'vouchers_bank_reference_unique')) {
            Schema::table('vouchers', function (Blueprint $table): void {
                $table->unique(['company_id', 'money_account_id', 'instrument_no'], 'vouchers_bank_reference_unique');
            });
        }

        if (Schema::hasIndex('vouchers', 'vouchers_bank_reference_index')) {
            Schema::table('vouchers', function (Blueprint $table): void {
                $table->dropIndex('vouchers_bank_reference_index');
            });
        }
    }
};
