<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * কাউন্টারে নেওয়া চেকের টাকা আদায়ের কাগজ পোস্ট করে, চেক নিজে নয়।
 *
 * ── দুইটা ভূমিকা, একটা ঘটনা নয় দুইটা পথ ─────────────────────────────
 * চেক এই কোডবেসে **আদায়ের একটা বৈশিষ্ট্য** — আদায়ের কাগজ নিজেই
 * instrument · instrument_no · instrument_date বহন করে। তাই কাউন্টারে
 * চেক নিলে:
 *
 *     টাকা এল   →  আদায়ের কাগজ পোস্ট করে   (Dr ১১০৪ হাতে চেক / Cr গ্রাহক
 *                  + CollectionLine, যা থেকে বিলের বকেয়া গোনা হয়)
 *     কাগজের জীবন →  acc_cheques রেজিস্টার রাখে  (পাশ · ফেরত · unique · PDC রিপোর্ট)
 *
 * ⚠️ ক্রয়ের চেক আলাদা — **ওখানে কোনো আদায়ের কাগজ নেই** (টাকা যাচ্ছে,
 * আসছে না), তাই সেখানে `ChequeService` নিজেই পোস্ট করে। পার্থক্যটা
 * খামখেয়ালি নয়, ঘটনাটাই আলাদা — কেউ যেন ছয় মাস পরে "দুইটা পথ" দেখে
 * এক করতে না যান।
 *
 * ── এই কলামটাই সেই চিহ্ন ───────────────────────────────────────────
 * `collection_id` থাকা মানে **টাকাটা আদায়ের কাগজ পোস্ট করেছে**, চেক
 * নিজে নয়। `ChequeService::bounce()` এই চিহ্ন দেখে নিজের পোস্টিং
 * **এড়িয়ে যায়** — নইলে ফেরত এলে টাকা দ্বিগুণ কাটত (একবার আদায়-বাতিলে,
 * একবার চেক-বাউন্সে)। শর্তটা ডেটায়, মনে রাখার উপর নয়।
 *
 * আর ফেরতের সময় ঠিক ওই আদায়ের কাগজটাই বাতিল করতে হবে — কোনটা, সেটাও
 * এই লিংক বলে দেয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_cheques', function (Blueprint $table): void {
            /*
             * শক্ত FK নয় — ইচ্ছাকৃত। acc_cheques-এর `party_id`-ও FK-হীন,
             * কারণ Accounts নিচের স্তর, Sales তার উপরে; Accounts→Sales একটা
             * শক্ত FK স্তরের ক্রম উল্টে দিত। collection soft-delete/বাতিল হয়
             * (NoHardDeleteGuard), হার্ড-ডিলিট নয় — তাই dangling id হয় না।
             */
            $table->unsignedBigInteger('collection_id')->nullable()->after('party_id');
            $table->index(['company_id', 'collection_id']);
        });
    }

    public function down(): void
    {
        Schema::table('acc_cheques', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'collection_id']);
            $table->dropColumn('collection_id');
        });
    }
};
