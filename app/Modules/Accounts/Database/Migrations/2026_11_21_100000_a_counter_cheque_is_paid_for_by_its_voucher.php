<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * কাউন্টারের চেকের টাকা এখন রসিদ ভাউচার পোস্ট করে — আদায়ের কাগজ নয়।
 *
 * ── ⭐ মালিকের সিদ্ধান্ত, ১৯ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * *"কাউন্টারের সব ডিপোজিট সবসময় রসিদ ভাউচার (RCV)… অগ্রিম আলাদাভাবে
 * থাকবে না… ব্যাংক লেজারের মতো Dr Cr হবে — জমা উত্তোলন, দেনা পাওনা।"*
 *
 * ⓘ চেকের জীবন (পাশ · ফেরত · PDC) আগের মতোই `acc_cheques` রাখে। কেবল
 * টাকাটা কে পোস্ট করেছে সেই চিহ্ন বদলাল: `collection_id`-এর পাশে এখন
 * `voucher_id`। ⚠️ ফেরত এলে ঠিক ঐ ভাউচারটাই বাতিল হয় — উল্টো দাখিলায়
 * টাকা আবার গ্রাহকের খাতায় ফেরে ([[ChequeService::bounce()]])।
 *
 * ⛔ পুরনো `collection_id` থাকছে — আগের কাউন্টার-চেকগুলো আদায়ের কাগজে
 * পোস্ট হয়েছিল, আর ওদের ফেরত এখনো Sales-এর দরজায়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_cheques', function (Blueprint $table): void {
            // ⓘ একই মডিউলের টেবিল, তবু শক্ত FK নয় — collection_id-এর মতোই; ভাউচার মোছে না, বাতিল হয়
            $table->unsignedBigInteger('voucher_id')->nullable()->after('collection_id');
            $table->index(['company_id', 'voucher_id']);
        });
    }

    public function down(): void
    {
        Schema::table('acc_cheques', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'voucher_id']);
            $table->dropColumn('voucher_id');
        });
    }
};
