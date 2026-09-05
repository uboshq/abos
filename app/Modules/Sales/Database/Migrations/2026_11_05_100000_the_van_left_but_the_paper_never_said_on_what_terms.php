<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ভ্যান বেরিয়ে গেল, কিন্তু কাগজে লেখা রইল না কোন শর্তে।
 *
 * ── মালিকের নির্দেশ (৫ সেপ্টেম্বর ২০২৬) ──────────────────────────────
 * *"r zaza nai sob daw direct sales e"* — ক্রয়ের কাউন্টারে শর্তের যত ধরন
 * আছে, বিক্রয়েও তত।
 *
 * ── ⛔ যা ভাঙা ছিল ───────────────────────────────────────────────────
 * বিক্রয়ের চালানে জমা হত কেবল **দিনের সংখ্যা** (`credit_period_days`)
 * আর **দেয় তারিখ**। ⚠️ কিন্তু সংখ্যাটা ধরনটা বলে না:
 *
 *     নগদ                  → ০ দিন
 *     COD                  → ০ দিন
 *     মাস শেষে (৫ তারিখে)  → ২৫ দিন
 *     ২৫ দিনের বাকি        → ২৫ দিন
 *
 * ⛔ চারটার দুই জোড়া খাতায় **হুবহু একই রকম** দেখাত, অথচ ব্যবসায়িক অর্থ
 * সম্পূর্ণ আলাদা। ⓘ তাই *"এই মাসে COD-তে কত বিক্রি হলো"* — ডিপোর সবচেয়ে
 * সাধারণ প্রশ্নগুলোর একটা — এর উত্তর কোথাও ছিল না, যদিও বিক্রেতা প্রতিটা
 * বিলে ঘরটা ভরেছেন।
 *
 * ⭐ ক্রয়ের কাগজে কলামটা ১ সেপ্টেম্বর থেকেই আছে
 * ([[the_paper_said_when_but_never_why]]); এটা তার যমজ, বিক্রয়ের দিকে।
 *
 * ── ⚠️ কলামটা `enum` নয়, `string` ───────────────────────────────────
 * ধরনগুলো কোডের, কিন্তু দিনসংখ্যাগুলো সারির (`mdm_payment_terms`)। ⓘ
 * `enum` হলে নতুন একটা ধরন যোগ করতে **মাইগ্রেশন** লাগত, আর সেটা ঠিক
 * সেই জিনিস যা মালিক বারবার বারণ করেছেন।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_challans', function (Blueprint $table): void {
            /*
             * ⓘ `null` মানে পুরনো কাগজ — ঘরটা বসার আগে যেগুলো লেখা
             * হয়েছিল। ⚠️ ওগুলোকে জোর করে `cash` বানানো হয়নি: যা কেউ
             * কোনোদিন বলেনি, তা খাতায় লিখে দেওয়ার চেয়ে **খালি থাকা
             * সৎ**।
             */
            $table->string('payment_term', 16)->nullable()->after('credit_period_days');

            /*
             * ⓘ ছাঁকনিটা প্রায় সবসময় তিনটা জিনিস একসাথে ধরে —
             * কোম্পানি, ধরন, তারিখ। ⚠️ তারিখ ছাড়া সূচক দিলে "গত মাসে
             * COD" প্রশ্নে পুরো টেবিল পড়তে হত।
             */
            $table->index(['company_id', 'payment_term', 'trx_date']);
        });
    }

    public function down(): void
    {
        Schema::table('sal_challans', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'payment_term', 'trx_date']);
            $table->dropColumn('payment_term');
        });
    }
};
