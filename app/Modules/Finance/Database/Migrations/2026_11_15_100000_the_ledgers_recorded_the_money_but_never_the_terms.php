<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * খাতাগুলো টাকাটা লিখত, শর্তগুলো লিখত না।
 *
 * ── ⛔ কী মেপে পাওয়া গেল, ১৫ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * অর্থের পাঁচটা খাতার নকশা কোডের সাথে মিলিয়ে দেখতে গিয়ে ধরা পড়ল —
 * সবচেয়ে বড় ফাঁক **ধারে**:
 *
 *     fin_hand_loan_accounts-এ ১২টা কলাম, আর একটাও টাকার নয়
 *     কে · কোন শাখা · কী নোট · অবস্থা — ব্যস
 *
 * ⚠️ অর্থাৎ *"করিম সাহেবের ধারে সুদ কত"*, *"কবে ফেরত দেওয়ার কথা"*,
 * *"পরের কিস্তি কবে"* — একটারও উত্তর খাতায় ছিল না। ⓘ টাকার গতিবিধি
 * `fin_hand_loan_movements`-এ ঠিকই বসত, কিন্তু **শর্ত** কোথাও না।
 *
 * ── ⭐ কেন এটা "সুবিধা" নয়, সত্যিকারের ক্ষতি ─────────────────────────
 * শর্ত না থাকার দাম তিনটা, তিনটাই নীরব:
 *
 *     সুদ লেখা নেই      → বছরশেষে সুদ খরচে ওঠে না, মুনাফা বেশি দেখায়
 *     মেয়াদ লেখা নেই    → কেউ মনে করিয়ে দেয় না, সম্পর্ক তেতো হয়
 *     কাগজ লেখা নেই     → অস্বীকার করলে প্রমাণ কী ছিল তা কেউ জানে না
 *
 * ── ⚠️ আর তিনটা খাতায় উৎসে কর ছিলই না ───────────────────────────────
 * আমানতের মুনাফায় ব্যাংক কর কাটে (e-TIN থাকলে ১০%, না থাকলে ১৫%), আর
 * বাড়িভাড়ায় কর কেটে রাখা **ভাড়াটের আইনি দায়িত্ব**। ⛔ ঘর না থাকলে
 * কাটা টাকাটা হয় ভুলে যাওয়া হত, নয় আমাদের নিজের খরচে বসত — অথচ ওটা
 * সরকারের টাকা, আমাদের নয়।
 *
 * ── ⭐ আর উত্তোলনের ধরন — তিনটা আলাদা জিনিস এক নামে ──────────────────
 *     উত্তোলন       পুঁজি কমায়, মুনাফায় প্রভাব নেই
 *     মালিকের বেতন  খরচ — মুনাফা কমায়, আর করযোগ্য আয়
 *     মুনাফার ভাগ   কেবল অর্জিত মুনাফা থেকে; লোকসানের বছরে এটা আসলে উত্তোলন
 *
 * ⚠️ তিনটাকে এক ঘরে রাখলে বছরশেষে মুনাফার অঙ্কটাই ভুল হত, আর করের
 * হিসাবেও। ⓘ ধরনটা ঘর হিসেবে থাকলে ঐ ভুলটা আর সম্ভব নয়।
 *
 * ── ⛔ যা এই মাইগ্রেশন **রাখে না** ───────────────────────────────────
 * কোনো "বকেয়া" বা "পরিশোধিত" কলাম নেই — ওগুলো গতিবিধির সারি যোগ করে
 * বের হয়। ⚠️ কলাম রাখলে সেটা খতিয়ানের দ্বিতীয় কপি হত, আর দুই কপি
 * একদিন আলাদা হয়ই ([[the_advance_on_the_godown]]-এর একই যুক্তি)।
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ── ধার: শর্তগুলো ────────────────────────────────────────── */
        Schema::table('fin_hand_loan_accounts', function (Blueprint $table) {
            /*
             * ⓘ শূন্য সুদ **স্বাভাবিক**, তাই default 0 — পরিচিত মানুষের
             * ধার প্রায়ই সুদবিহীন। ⚠️ nullable করলে "লেখা হয়নি" আর
             * "সুদ নেই" এক দেখাত, আর হিসাব করতে গিয়ে থামতে হত।
             */
            $table->decimal('interest_rate', 8, 4)->default(0)->after('partner_type');

            /*
             * মেয়াদ — মাসে। ⓘ nullable, কারণ *"যখন পারো ফেরত দিও"*
             * ধরনের ধার সত্যিই আছে, আর ওটাকে শূন্য মাস লেখা মিথ্যা হত।
             */
            $table->unsignedSmallInteger('term_months')->nullable()->after('interest_rate');

            $table->date('due_on')->nullable()->after('term_months');

            /*
             * ফেরতের ছাঁদ। ⚠️ কোডে তালিকাটা বসানো হয়নি — মডেলের ধ্রুবকে,
             * যাতে একদিন নতুন ছাঁদ যোগ করতে মাইগ্রেশন না লাগে।
             */
            $table->string('repayment', 16)->default('lump')->after('due_on');

            /*
             * কী কাগজে দাঁড়িয়ে আছে — মৌখিক · স্ট্যাম্প · জমা চেক।
             *
             * ⛔ এটাই সেই ঘর যেটা না থাকলে ছয় মাস পরে কেউ বলতে পারে না
             * *"কাগজ ছিল কি না"*। ⓘ আর ঐ প্রশ্নটাই ওঠে ঠিক তখন, যখন
             * সম্পর্কটা আর ভালো নেই।
             */
            $table->string('security', 24)->default('verbal')->after('repayment');

            $table->date('next_due_on')->nullable()->after('security');
        });

        /* ── আমানত: উৎসে কর ও নবায়ন ──────────────────────────────── */
        Schema::table('fin_deposits', function (Blueprint $table) {
            /*
             * ⚠️ ১০ না ১৫ — পার্থক্যটা e-TIN আছে কি না। ⓘ ১০ ধরে নেওয়া
             * হয় কারণ ব্যবসার TIN থাকে; না থাকলে ঘরটা বদলানো যায়।
             */
            $table->decimal('tax_rate', 5, 2)->default(10)->after('profit_rate');

            /*
             * মেয়াদ শেষে কী হবে — আর এটা না থাকলে যা ঘটে তা নীরব:
             * এফডিআর **নিজে নিজে নবায়ন হয়ে যায় পুরনো হারে**, আর কেউ
             * জানতেও পারে না যে বাজারে হার বেড়েছিল।
             */
            $table->string('on_maturity', 24)->default('renew_with_profit')->after('matures_on');
        });

        /* ── ভাড়া: অগ্রিম, কর, আর তারিখ ──────────────────────────── */
        Schema::table('fin_rental_contracts', function (Blueprint $table) {
            /*
             * অগ্রিম কত মাসের। ⓘ জামানত (`deposit_amount`) থেকে আলাদা,
             * আর পার্থক্যটা মৌলিক: **অগ্রিম মাসে মাসে ফুরায়, জামানত
             * ফুরায় না** — ওটা চুক্তি শেষে ফেরত আসে।
             *
             * ⛔ দুইটা এক ঘরে রাখলে স্থিতিপত্রে ফেরতযোগ্য টাকা বেশি
             * দেখাত, আর কেউ ফেরত চাইতে মনে রাখত না।
             */
            $table->unsignedTinyInteger('advance_months')->default(0)->after('monthly_adjustment');

            /*
             * ⚠️ বাড়িভাড়ায় উৎসে কর কেটে রাখা ভাড়াটের **আইনি দায়িত্ব**।
             * না কাটলে ঐ টাকাটা পরে আমাদের নিজের পকেট থেকেই যায়, জরিমানাসহ।
             */
            $table->decimal('tax_rate', 5, 2)->default(5)->after('advance_months');

            /*
             * প্রতি মাসের কত তারিখে। ⓘ ৩১ ধরে রাখা হয় না — ছোট মাসে
             * তারিখটা থাকে না, আর তখন "কবে দিতে হবে" প্রশ্নের উত্তর
             * নিজেই ভাঙা হত। সর্বোচ্চ ২৮।
             */
            $table->unsignedTinyInteger('rent_day')->default(5)->after('tax_rate');
        });

        /* ── উত্তোলন: ধরন ─────────────────────────────────────────── */
        Schema::table('fin_withdrawals', function (Blueprint $table) {
            $table->string('kind', 16)->default('drawing')->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('fin_hand_loan_accounts', function (Blueprint $table) {
            $table->dropColumn(['interest_rate', 'term_months', 'due_on', 'repayment', 'security', 'next_due_on']);
        });

        Schema::table('fin_deposits', function (Blueprint $table) {
            $table->dropColumn(['tax_rate', 'on_maturity']);
        });

        Schema::table('fin_rental_contracts', function (Blueprint $table) {
            $table->dropColumn(['advance_months', 'tax_rate', 'rent_day']);
        });

        Schema::table('fin_withdrawals', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
