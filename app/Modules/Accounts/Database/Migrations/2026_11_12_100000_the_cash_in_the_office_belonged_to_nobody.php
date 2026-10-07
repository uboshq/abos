<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * অফিসের সিন্দুকের টাকা কারো নামে ছিল না।
 *
 * ── মালিকের প্রশ্ন, ১৩ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * *"এই অ্যাকাউন্টে নিয়ন্ত্রক কে? সেটাই নাই।"*
 *
 * নগদের হেফাজত আগে থেকেই ছিল, কিন্তু **কেবল টিলে** — `cash_tills`-এ
 * `holder_id`, আর তার পাশে কারণও লেখা: *"ডেলিভারি ম্যানের টিল অবশ্যই
 * তার নামে, নাহলে 'কার কাছে কত' প্রশ্নের উত্তরটাই থাকে না।"*
 *
 * ⛔ কিন্তু হিসাবের ছক থেকে ১১০১ হাতে নগদের নিচে একটা খাত বানালে কোনো
 * টিল তৈরি হত না। ফল: খাতটায় সত্যিকারের টাকা বসত, নগদ বইয়ে দেখাত,
 * অথচ **কারো নামে নয়** — আর "টাকা ও হেফাজত" পর্দায় সারিটা আসতই না,
 * কারণ ঐ পর্দা টিল ধরে গোনে। দিনশেষে "কার কাছে কত" প্রশ্নের উত্তরে
 * ঐ টাকাটা কোথাও থাকত না।
 *
 * ── ⛔ প্রথম সারাইটা ভুল ছিল, আর সেটাও লিখে রাখা দরকার ──────────────
 * প্রথমে ছক থেকে নগদ খাত বানানোই বন্ধ করা হয়েছিল, আর সবাইকে টিলের
 * পর্দায় পাঠানো হচ্ছিল। মালিক সাথে সাথে ধরিয়ে দিলেন:
 *
 *     "টিল তো POS-এর জন্য। অফিসে অ্যাকাউন্টসের ক্যাশ কীভাবে হবে?
 *      কাউন্টার আর ক্যাশ অ্যাকাউন্ট এক না — যদিও দুইটাই ক্যাশের নিচে।"
 *
 * ⭐ কথাটা ঠিক, আর ভুলটা ছিল প্রশ্ন আর উত্তর গুলিয়ে ফেলা। প্রশ্নটা
 * **"কার হাতে"**; টিল তার একটা উত্তর, অফিসের সিন্দুক আরেকটা। নিয়মটাকে
 * "টিল হতে হবে" বানালে ব্যবসার গড়নটাই বদলে দেওয়া হত।
 *
 * তাই ঘরটা খাতেই বসছে। ⓘ টিলের নিজের `holder_id` **থেকে যাচ্ছে** —
 * ওটা কাউন্টারের দায়িত্ব, আর টিল বানানোর সময় দুইটা একসাথে বসে।
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ⛔ ক্রম ভুল হলে জোরে থামে, নীরবে নয় — আজকের শেখা।
         */
        if (! Schema::hasTable('accounts')) {
            throw new RuntimeException(
                'accounts টেবিল নেই — মাইগ্রেশনের ক্রম দেখুন, এই ফাইলটা তার পরে চলতে হবে।'
            );
        }

        Schema::table('accounts', function (Blueprint $table) {
            /*
             * nullable, কারণ ব্যাংক, MFS, খরচ, বিক্রয় — কোনোটারই
             * হেফাজতকারী নেই, আর সেটা ফাঁক নয়।
             *
             * ⚠️ ব্যাংকের টাকা কারও ড্রয়ারে থাকে না; ওটা ব্যাংকের কাছে।
             * শর্তটা তাই কলামে নয়, [[AccountService::assertCashHasAKeeper()]]-এ
             * — আর কেবল নগদের জন্য।
             */
            $table->foreignId('held_by')->nullable()->after('money_kind')
                ->constrained('users')->nullOnDelete();

            // "আমার হেফাজতে যা আছে" পর্দাটা ঠিক এই আকৃতিতেই খোঁজে
            $table->index(['company_id', 'held_by'], 'accounts_custody_index');
        });

        $this->adoptTillHolders();
    }

    /**
     * চলতি টিলগুলোর নিয়ন্ত্রক খাতেও তুলে আনা।
     *
     * ⚠️ এটা না করলে চলতি সাইটে প্রতিটা কাউন্টারের খাত "কারো নামে নয়"
     * হয়ে বসত, আর হেফাজতের পর্দা সেগুলোকে ফাঁকা দেখাত — অথচ টিলের
     * সারিতে উত্তরটা আগে থেকেই আছে।
     *
     * ⓘ সংখ্যাটা লগে, কারণ "চলেছে" আর "কাজ করেছে" এক প্রশ্ন নয়।
     */
    private function adoptTillHolders(): void
    {
        if (! Schema::hasTable('cash_tills')) {
            return;
        }

        $moved = 0;

        foreach (DB::table('cash_tills')->whereNotNull('holder_id')->get(['account_id', 'holder_id']) as $till) {
            if ($till->account_id === null) {
                continue;
            }

            $moved += DB::table('accounts')
                ->where('id', $till->account_id)
                ->whereNull('held_by')
                ->update(['held_by' => $till->holder_id]);
        }

        logger()->info("হেফাজত: {$moved}টা নগদ খাত তার টিলের নিয়ন্ত্রক পেল।");
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            /*
             * ⚠️ সূচক আগে, কলাম পরে — MySQL একটা যৌগিক সূচকের কলাম
             * মুছলে সূচকটা **অর্ধেক অবস্থায় রেখে দেয়**, আর সেটা আজ
             * একবার ধরা পড়েছে।
             */
            $table->dropIndex('accounts_custody_index');
            $table->dropConstrainedForeignId('held_by');
        });
    }
};
