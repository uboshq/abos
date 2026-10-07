<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * বিলটা মিলল না, আর কেউ সেটা মনে রাখল না।
 *
 * ── ⭐ মালিকের নির্দেশ, ২৩–২৪ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * স্পেকে লেখা: *"3-Way Matching mandatory architecture হবে"*, আর ফলটা
 * হবে `MATCHED` · `PARTIAL_MATCH` · `MISMATCH` · `EXCEPTION`।
 *
 * ── ⛔ আজ পর্যন্ত যা হত ──────────────────────────────────────────────
 * তিনটা সুইচ আগে থেকেই আছে আর কাজও করে: আদেশ ছাড়া মাল নেওয়া,
 * আদেশের বেশি নেওয়া, আর দাম না মিললে আটকানো।
 *
 * ⚠️ কিন্তু সুইচটা **বন্ধ** থাকলে বিলটা চুপচাপ পাশ হয়ে যেত, আর
 * পার্থক্যটা কেবল একটা জাবেদা-সারিতে (মূল্য-পার্থক্য খাত) গিয়ে বসত।
 * ⛔ ফল: *"কোন বিলগুলো মেলেনি"* প্রশ্নের কোনো উত্তর ছিল না — উত্তরটা
 * বের করতে হলে হিসাবের খাত ধরে ধরে উল্টোদিকে হাঁটতে হত।
 *
 * ⓘ আর সুইচ **চালু** থাকলে বিলটা আটকে যেত, অর্থাৎ ঘটনাটা কোথাও
 * লেখাই হত না — শুধু একটা ভুল বার্তা।
 *
 * ── ⭐ তাই ফলটা বিলের গায়েই বসে ──────────────────────────────────────
 * দুইটা ঘর: কী ফল, আর কত টাকার পার্থক্য। ⚠️ পার্থক্যটা আলাদা করে রাখা
 * হয়, কারণ *"মেলেনি"* কথাটা একাই কিছু বলে না — দুই টাকার অমিল আর দুই
 * লাখ টাকার অমিল এক জিনিস নয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pur_bills', function (Blueprint $table) {
            /*
             * ⓘ খালি মানে *"এখনো নিশ্চিত হয়নি"* — খসড়া বিলে মিলকরণের
             * প্রশ্নই ওঠে না। ⚠️ ডিফল্টে `matched` বসালে প্রতিটা খসড়া
             * বিল মিলে গেছে বলে দাবি করত, অথচ কেউ মেলায়নি।
             */
            $table->string('match_state', 20)->nullable()->after('status');

            /*
             * ⚠️ `decimal(18,4)`, টাকার বাকি ঘরগুলোর মতোই। ⓘ ঋণাত্মক
             * হতে পারে: সরবরাহকারী কম দরে বিল পাঠালে পার্থক্যটা উল্টো
             * দিকে যায়, আর ওটাও সমান গুরুত্বের খবর।
             */
            $table->decimal('match_difference', 18, 4)->nullable()->after('match_state');
        });

        Schema::table('pur_bills', function (Blueprint $table) {
            /*
             * ⚠️ সূচকের নামটা হাতে দেওয়া।
             *
             * ⓘ Laravel নিজে বানালে হত `pur_bills_company_id_match_state_index`
             * — ৪৩ অক্ষর, সীমার ভিতরেই। ⛔ তবু হাতে দেওয়া হলো যাতে নামটা
             * ছোট থাকে আর ভবিষ্যতে কলাম যোগ হলে ৬৪ ছাড়িয়ে না যায়;
             * ৬৪ ছাড়ালে `migrate:fresh` প্রতিটা সেশনে ভাঙে।
             */
            $table->index(['company_id', 'match_state'], 'pur_bill_match');
        });
    }

    public function down(): void
    {
        Schema::table('pur_bills', function (Blueprint $table) {
            $table->dropIndex('pur_bill_match');
            $table->dropColumn(['match_state', 'match_difference']);
        });
    }
};
