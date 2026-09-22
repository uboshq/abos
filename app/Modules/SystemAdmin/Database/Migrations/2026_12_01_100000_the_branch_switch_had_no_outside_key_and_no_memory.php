<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * শাখার সুইচ টেবিলটা ঘরের দুইটা নিয়ম মানেনি।
 *
 * ── ⛔ কী বাদ পড়েছিল, আর কীভাবে ধরা পড়ল ──────────────────────────────
 * `branch_modules` বানানোর সময় দুইটা জিনিস বসানো হয়নি: বাইরের কী
 * (`public_id`) আর অডিটের সুতো (`IsAudited`)। ⓘ কোনো পরীক্ষা তখন লাল
 * হয়নি, কারণ আমি কেবল **নিজের** ফাইলগুলো চালিয়েছিলাম — পুরো
 * Architecture সুইট চালানোর দিন `PublicIdTest` আর
 * `EveryChangeableRowRemembersWhoChangedItTest` দুইটাই নাম ধরে ধরেছে।
 *
 * ⚠️ শিক্ষাটা লিখে রাখা দরকার: **নতুন টেবিল বসানোর পর ঘরের পাহারাগুলো
 * চালাতে হয়**, কেবল নিজের লেখা পরীক্ষাগুলো নয়। ⓘ নিজের পরীক্ষা কেবল
 * সেটুকুই মাপে যেটুকু আমি ভেবেছি; ঘরের পাহারা মাপে যা আমি ভাবিনি।
 *
 * ── ⓘ কেন আলাদা মাইগ্রেশন, আগেরটা বদলে নয় ───────────────────────────
 * ⛔ আগের মাইগ্রেশনটা **লাইভে চলে গেছে** (২২ সেপ্টেম্বর ২০২৬)। চলে যাওয়া
 * মাইগ্রেশন বদলালে যে সার্ভারে সেটা আগেই চলেছে সেখানে কিছুই ঘটত না, আর
 * নতুন সার্ভারের সাথে পুরনোটার গঠন আলাদা হয়ে যেত — একই কোড, দুই রকম
 * ডাটাবেস।
 *
 * ── ⓘ অডিটের কলাম এখানে নেই, আর সেটাই ঠিক ───────────────────────────
 * [[IsAudited]] আলাদা `audit_trails` টেবিলে লেখে, সারির গায়ে নয়। ⭐ তাই
 * এখানে কেবল `public_id`, আর অডিটটা মডেলে trait বসালেই চালু।
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ⚠️ শর্তটা ইচ্ছাকৃত। ⓘ টেবিলটা এক সার্ভারে আগের ব্যাচে বসেছে আর
         * অন্যটায় এখনো বসেনি — দুই জায়গাতেই যেন একই কোড চলে।
         */
        if (! Schema::hasTable('branch_modules') || Schema::hasColumn('branch_modules', 'public_id')) {
            return;
        }

        Schema::table('branch_modules', function (Blueprint $table) {
            // ⓘ ম্যাক্রোটা [[AppServiceProvider]]-এ — nullable char(36), unique।
            $table->publicId();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('branch_modules') || ! Schema::hasColumn('branch_modules', 'public_id')) {
            return;
        }

        Schema::table('branch_modules', function (Blueprint $table) {
            /*
             * ⚠️ unique index-টা কলামের সাথেই যায়, কিন্তু MariaDB-তে আগে
             * index ফেলা নিরাপদ — নাহলে কিছু সংস্করণে কলামটা ধরা থাকে।
             */
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
