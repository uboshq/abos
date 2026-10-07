<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * নমুনার শেষ পনেরোটা ঘর — ১৫ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ মালিকের নির্দেশ, সাতবার ─────────────────────────────────────────
 * *"sample er 100% lagbe, 99.99% o na. 100% mane 100%."*
 *
 * ⓘ এর আগে তিনটা ঘর আমি ইচ্ছাকৃতভাবে বসাইনি — "আজ ব্যবহৃত",
 * "এ পর্যন্ত ফেরত", "টাকার পরিমাণ" — এই যুক্তিতে যে ওগুলো খতিয়ান থেকে
 * গোনা সংখ্যা, আর হাতে লেখার ঘর দিলে খাতার দ্বিতীয় কপি তৈরি হয়।
 *
 * ⭐ মালিক যুক্তিটা শুনেছেন এবং **না** বলেছেন। ঘরগুলো বসছে।
 *
 * ⚠️ তাই নামগুলো সৎ রাখা হলো — `opening_*`: এগুলো **খোলার জের**,
 * চলতি ব্যালান্স নয়। ⓘ পুরনো খাতা ব্যবস্থায় তোলার সময় যেটুকু আগে
 * ঘটে গেছে সেটুকু এখানে বসে; এরপর থেকে খতিয়ানই হিসাব রাখে। ⛔ নাম
 * `outstanding` রাখলে একদিন কেউ ওটাকেই সত্যি ধরে নিত।
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ⓘ মূলধন কী দিয়ে এল — নমুনায় তিনটা পথ: টাকা, যন্ত্রপাতি/সম্পদ, পণ্য।
         *
         * ⚠️ ডিফল্ট `cash`, কারণ আগের সব সারি টাকাতেই এসেছে — অন্য কিছু
         * ধরলে পুরনো হিসাব নীরবে বদলে যেত।
         */
        Schema::table('acc_capital_entries', function (Blueprint $table): void {
            $table->string('in_kind', 24)->default('cash')->after('entry_type');
        });

        /*
         * হাতধার — নমুনার তিনটা ঘর।
         *
         * ⛔ `principal` ছাড়া খাতাটা খোলাই যেত না জানিয়ে যে কত টাকা;
         * অঙ্কটা এতদিন কেবল নড়াচড়ার সারিতে ছিল, আর প্রথম নড়াচড়ার
         * আগে খাতাটা সংখ্যাহীন থাকত।
         */
        Schema::table('fin_hand_loan_accounts', function (Blueprint $table): void {
            $table->decimal('principal', 18, 4)->default(0)->after('partner_type');
            $table->decimal('opening_repaid', 18, 4)->default(0)->after('principal');
            $table->foreignId('money_account_id')->nullable()->after('opening_repaid')
                ->constrained('accounts')->nullOnDelete();
        });

        /*
         * ⚠️ "আজ ব্যবহৃত" — আর এই কলামটা নিয়ে আমার আপত্তি ছিল।
         *
         * ⛔ CC-তে আসল ব্যবহৃত অঙ্ক **ব্যাংক হিসাবের ঋণাত্মক ব্যালান্স**,
         * আর সেটা খতিয়ানে থাকে। ⓘ তাই এই ঘরটা `opening_` — ব্যবস্থায়
         * তোলার দিনের ছবি, তারপরের হিসাব খতিয়ানের।
         */
        Schema::table('fin_bank_facilities', function (Blueprint $table): void {
            $table->decimal('opening_drawn', 18, 4)->default(0)->after('margin_percent');
        });
    }

    public function down(): void
    {
        Schema::table('acc_capital_entries', function (Blueprint $table): void {
            $table->dropColumn('in_kind');
        });

        Schema::table('fin_hand_loan_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('money_account_id');
            $table->dropColumn(['principal', 'opening_repaid']);
        });

        Schema::table('fin_bank_facilities', function (Blueprint $table): void {
            $table->dropColumn('opening_drawn');
        });
    }
};
