<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * মুনাফার ভাগের সারিগুলোর বাইরের কোনো নাম ছিল না।
 *
 * ── ⛔ কী বাদ পড়েছিল ────────────────────────────────────────────────
 * `acc_profit_shares` বানানোর সময় `publicId()` বসানো হয়নি, আর মডেলে
 * [[HasPublicId]]-ও ছিল না। ⓘ কিছুই লাল হয়নি নিজের পরীক্ষাগুলোয় —
 * ধরেছে ঘরের পাহারা `PublicIdTest`, নাম ধরে।
 *
 * ── ⚠️ কেন আলাদা মাইগ্রেশন, আগেরটা বদলে নয় ──────────────────────────
 * ⛔ আমি প্রথমে মূল create-টাই বদলিয়েছিলাম, কারণ অফিস সার্ভারের
 * `migrate:status`-এ সারিটা ছিল না। ⚠️ কিন্তু **অফিস সার্ভার লাইভ নয়** —
 * লাইভ `erp.adi.com.bd`, আলাদা মেশিন, আর ২২ সেপ্টেম্বর ২০২৬-এর রাতেই
 * সেখানে `migrate --force` চলে গেছে।
 *
 * ⓘ চলে যাওয়া মাইগ্রেশন বদলালে সেটা আর দ্বিতীয়বার চলে না। ফল হত **একই
 * কোড, দুই রকম ডাটাবেস**: নতুন ইনস্টলে কলামটা থাকত, লাইভে কোনোদিন
 * আসত না, আর `PublicIdTest` সব জায়গায় সবুজ থেকে যেত। ⛔ পাহারাটা
 * তখন লাইভের কথা আর বলত না — শুধু আমার মেশিনের কথা বলত।
 *
 * ⭐ শিক্ষাটা এক লাইনে: *এক মেশিনে মাপা উত্তর অন্য মেশিনের উত্তর নয়।*
 *
 * ── ⓘ শর্ত দুই পাশেই ────────────────────────────────────────────────
 * টেবিলটা কোথাও আছে কলামসহ (নতুন ইনস্টল হলে নয়), কোথাও আছে কলাম ছাড়া
 * (লাইভ), কোথাও এখনো নেই। ⚠️ তিন অবস্থাতেই যেন একই কোড নিরাপদে চলে।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('acc_profit_shares')) {
            return;
        }

        if (! Schema::hasColumn('acc_profit_shares', 'public_id')) {
            Schema::table('acc_profit_shares', function (Blueprint $table) {
                // ⓘ ম্যাক্রোটা [[AppServiceProvider]]-এ — nullable char(36), unique।
                $table->publicId();
            });
        }

        /*
         * ⭐ পুরনো সারিগুলোও নাম পাবে।
         *
         * ⚠️ কলামটা nullable, তাই খালি রেখে দিলেও মাইগ্রেশনটা টিকত —
         * আর ঠিক সেটাই ফাঁদ: লাইভের যে ঘোষণাগুলো আগেই বসেছে সেগুলোর
         * দিকে বাইরে থেকে কোনোদিন লিংক যেত না, অথচ পাহারা সবুজ, কারণ
         * পাহারাটা **কলাম** খোঁজে, সারির মান নয়।
         *
         * ⓘ মডেল দিয়ে নয়, `DB` দিয়ে — [[BelongsToCompany]] একটা global
         * scope, আর CLI-তে কোনো কোম্পানি বাছা থাকে না। মডেল দিয়ে চালালে
         * সংগ্রহটা খালি আসত আর ব্যাকফিলটা নীরবে কিছুই করত না।
         *
         * ⭐ ব্যাকফিলটা শর্তের **বাইরে**, কলাম বসানোর ভেতরে নয়। ⓘ দুইটা
         * কারণ: মাইগ্রেশনটা তখন যতবার চালানো হোক নিরাপদ, আর একটা
         * পরীক্ষা এটাকে সত্যি ডেকে প্রমাণ করতে পারে যে ব্যাকফিলটা
         * কামড়ায় — ভেতরে রাখলে দ্বিতীয়বার ডাকলেই আগেভাগে ফিরে যেত,
         * আর দাবিটা কিছুই মাপত না।
         */
        /*
         * ⚠️ আগে সব id তুলে নেওয়া, তারপর একটা একটা করে বসানো।
         *
         * ⛔ `whereNull(...)->each()` লিখলে ভুল হত: প্রতিটা আপডেটের পরে
         * সারিটা আর শর্তে পড়ে না, অথচ chunk পরের পাতাটা **offset** ধরে
         * চায় — ফলে প্রতি পাতায় কিছু সারি লাফিয়ে বাদ পড়ত, আর বাদ পড়া
         * সারিগুলো চুপচাপ `null` থেকে যেত।
         */
        $ids = DB::table('acc_profit_shares')
            ->whereNull('public_id')
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            DB::table('acc_profit_shares')
                ->where('id', $id)
                ->update(['public_id' => (string) Str::uuid7()]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('acc_profit_shares') || ! Schema::hasColumn('acc_profit_shares', 'public_id')) {
            return;
        }

        Schema::table('acc_profit_shares', function (Blueprint $table) {
            /*
             * ⚠️ unique index-টা কলামের সাথেই যায়, কিন্তু MariaDB-তে আগে
             * index ফেলা নিরাপদ — নাহলে কিছু সংস্করণে কলামটা ধরা থাকে।
             * ⓘ লাইভ MariaDB, dev MySQL 8.4 — DDL দুই জায়গায় এক নয়।
             */
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
