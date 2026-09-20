<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ভাড়াটা যেত হাতে লেখা একটা নামে, হাতে লেখা একটা জায়গার জন্য।
 *
 * ── ⓘ মালিকের প্রশ্ন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"কার সাথে * কীসের জন্য etar list kothay pabo?"* — কোথাও ছিল না।
 * দুইটাই মুক্ত-লেখা ঘর।
 *
 * ── ⛔ কেন মুক্ত লেখা এখানে বিপজ্জনক ──────────────────────────────────
 * ⚠️ "Al Amin", "Al-Amin" আর "আল আমিন" তিনজন বাড়িওয়ালা হয়ে যেতেন, আর
 * একজনকে দেওয়া ভাড়া তিনটা খাতায় ছড়িয়ে পড়ত। ⓘ টাকার পর্দাগুলোয় ঠিক এই
 * ফাঁদটা আগেই সারানো হয়েছে — ভাড়া বাকি ছিল।
 *
 * ── ⭐ আর যেটা আসল লাভ: উল্টো দিকটা ──────────────────────────────────
 * মালিকের কথা: গুদাম, হেড অফিস, গাড়ি — যেটা ভাড়া নেওয়া, চুক্তিটা তার
 * সাথেই জোড়া থাকা উচিত। ⓘ তাতে গুদামের পাতা খুলে দেখা যায় ভাড়া কত,
 * জামানত কত, চুক্তি কবে শেষ। ⛔ যে ভাড়ার সংখ্যাটা কোনো জায়গার সাথে
 * মেলানো যায় না, সেটাই ডিপোকে ছেড়ে আসা গুদামের ভাড়া দিতে থাকায়।
 *
 * ── ⚠️ পুরনো সারিগুলো ─────────────────────────────────────────────────
 * ⓘ টাইপ করা নাম আর বিষয় দুইটাই কলামেই থাকছে (`counterparty`,
 * `subject`)। ⛔ মুছে দিলে পুরনো চুক্তিগুলো নাম হারাত, আর কোনো জোড়া
 * নেই বলে ফেরানোর উপায়ও থাকত না। নতুন ঘর দুইটা তাই **ঐচ্ছিক**।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_rental_contracts', function (Blueprint $table) {
            /*
             * ⓘ পক্ষটা morph নয়, দুইটা সাধারণ ঘর — হাতধারের
             * (`partner_type`/`partner_id`) হুবহু একই ছক, আর কারণটাও এক:
             * ⚠️ পক্ষের ধরনগুলো মডিউল ঘোষণা করে ([[PartyRegistry]]), তাই
             * ক্লাসের নাম ডাটাবেজে বসালে মডিউল সরানোর দিন সারিগুলো
             * অনাথ হত।
             */
            $table->string('party_type', 24)->nullable()->after('counterparty_phone');
            $table->unsignedBigInteger('party_id')->nullable()->after('party_type');

            /* ⓘ কী ভাড়া নেওয়া — গুদাম, শাখা, গাড়ি, স্থায়ী সম্পদ */
            $table->string('subject_type', 24)->nullable()->after('subject');
            $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');

            /*
             * ⭐ উল্টো দিকের প্রশ্নটার জন্য: "এই গুদামের চুক্তি কোনটা"।
             * ⚠️ নামটা ছোট রাখা হয়েছে ইচ্ছাকৃতভাবে — ৬৪ অক্ষরের বেশি
             * হলে MySQL-এ `migrate:fresh` সবার মেশিনে ভাঙে।
             */
            $table->index(['subject_type', 'subject_id'], 'fin_rental_subject_idx');
            $table->index(['party_type', 'party_id'], 'fin_rental_party_idx');
        });
    }

    public function down(): void
    {
        Schema::table('fin_rental_contracts', function (Blueprint $table) {
            $table->dropIndex('fin_rental_subject_idx');
            $table->dropIndex('fin_rental_party_idx');
            $table->dropColumn(['party_type', 'party_id', 'subject_type', 'subject_id']);
        });
    }
};
