<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * হাতধার — কাগজবিহীন ধার, দেওয়া বা নেওয়া।
 *
 * ── কেন এটা `acc_loans`-এই বসে ───────────────────────────────────────
 * টেবিলটার নিজের মন্তব্যে আগে থেকেই লেখা: "কে দিল, কোন খাতে, কত সুদ,
 * কী জামানত — এই সবটা এক। আলাদা হয় কেবল ফেরতের গড়ন, আর সেটা kind
 * দেখে ঠিক হয়।" হাতধার তৃতীয় একটা গড়ন, নতুন কোনো ধারণা নয়।
 *
 * আলাদা টেবিল বানালে সুদ, কিস্তি, খতিয়ানে বসা — সব আবার লিখতে হত, আর
 * "মোট কত ধার" প্রশ্নের উত্তর দুই জায়গা থেকে জোড়া লাগাতে হত।
 *
 * ── কিন্তু একটা জিনিস সত্যিই নতুন: হাতধার দেওয়াও যায় ────────────────
 * ব্যাংক ঋণ সবসময় নেওয়া — তাই টেবিলে ছিল `liability_account_id`, আর
 * নামটা ঠিকই ছিল। হাতধারে দিকটা দুই রকম: মালিক আত্মীয়ের কাছ থেকে
 * নেন, আবার ডিলারকে কাগজ ছাড়াই দেনও।
 *
 * দেওয়া ধার দায় নয়, **সম্পদ** — পাওনা। তাই ঘরটার নাম আর সত্যি থাকে
 * না, আর নাম মিথ্যা বলা শুরু করলে ছয় মাস পরে কেউ ওটাকে দায় ধরে
 * ব্যালেন্স শিটে বসিয়ে দেবে। সেজন্য ঘরটার নাম বদলানো হলো
 * `principal_account_id` — যেটা দুই দিকেই সত্যি।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_loans', function (Blueprint $table) {
            $table->renameColumn('liability_account_id', 'principal_account_id');
        });

        Schema::table('acc_loans', function (Blueprint $table) {
            /*
             * নেওয়া না দেওয়া।
             *
             * ডিফল্ট `taken`, কারণ আজ পর্যন্ত যত সারি আছে সবগুলোই
             * ব্যাংক ঋণ — অর্থাৎ নেওয়া। ডিফল্টটা ইতিহাসকে সত্যি রাখে।
             */
            $table->string('direction', 8)->default('taken')->after('kind');

            /*
             * কবে ফেরত দেওয়ার কথা — তারিখ, সূচি নয়।
             *
             * হাতধারের কিস্তি থাকে না; থাকে একটা কথা: "সামনের ঈদের
             * আগে দিয়ে দেব"। ওই কথাটার কোনো ঘর না থাকলে ধারটা খাতায়
             * বসেই থাকে, আর কেউ মনে করিয়ে দেওয়ার কিছু পায় না।
             */
            $table->date('due_on')->nullable()->after('first_instalment_on');
        });

        /*
         * পুরনো সারিগুলো স্পষ্ট করে চিহ্নিত করা।
         *
         * কলামের ডিফল্ট কেবল নতুন সারিতে বসে; যেগুলো আগে থেকেই আছে
         * তাদের মানটা ডাটাবেস অনুযায়ী null বা খালি হতে পারে। তাই
         * একবার হাতে বসিয়ে দেওয়া — নাহলে পুরনো ঋণগুলোর কোনো দিক
         * থাকত না, আর দিকহীন ঋণ কোন পাশে বসবে তা কেউ জানত না।
         */
        DB::table('acc_loans')->whereNull('direction')->orWhere('direction', '')
            ->update(['direction' => 'taken']);
    }

    public function down(): void
    {
        Schema::table('acc_loans', function (Blueprint $table) {
            $table->dropColumn(['direction', 'due_on']);
        });

        Schema::table('acc_loans', function (Blueprint $table) {
            $table->renameColumn('principal_account_id', 'liability_account_id');
        });
    }
};
