<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * বাংলা নামটা বাধ্যতামূলক ছিল, অথচ এই বাড়ির নিয়মে ওটা ঐচ্ছিক।
 *
 * ── ⓘ এই বাড়ির নিয়ম ─────────────────────────────────────────────────
 * প্রতিটা মাস্টার তালিকায় `name_bn` ফর্মে ঐচ্ছিক, কারণ বাস্তবে প্রায়ই
 * খালি থাকে — *"bKash"*-এর বাংলা নামও *"bKash"*। ⭐ তাই যে টেবিলেই
 * `name_bn` আছে, সেখানে ওটা `nullable` — পাহারা:
 * [[TheFormSaidOptionalTheColumnSaidRequiredTest]]।
 *
 * ── ⛔ প্রথম খসড়ায় যা ছিল ──────────────────────────────────────────
 * ⓘ `promotions.name_bn` টেবিলে বাধ্যতামূলক ছিল। ⚠️ একজন সহকর্মীর পুরো
 * Architecture রানে ধরা পড়েছে — আর সেটা অন্যদের রানও লাল করছিল।
 *
 * ── ⚠️ কেন নতুন মাইগ্রেশন, পুরনোটায় সম্পাদনা নয় ─────────────────────
 * ⓘ `2026_12_20` শেয়ার করা ডেভ DB-তে ইতিমধ্যে চলে গেছে। ⛔ ঐ ফাইল বদলালে
 * সেখানে বদলটা কোনোদিন পৌঁছাত না, আর কোথাও কিছু লাল হত না — কোঅর্ডিনেটরের
 * নিয়ম: *"কলাম বদলাতে হলে নতুন মাইগ্রেশন লিখুন"*।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->string('name_bn')->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * ⚠️ ফেরার পথে খালি ঘরগুলো আগে ইংরেজি নামে ভরা হয়।
         *
         * ⛔ নাহলে `NOT NULL`-এ ফেরার সময় যে সারির বাংলা নাম খালি, সেখানে
         * `down()` নিজেই ভাঙত — আর রোলব্যাক মানেই ঝামেলার সময়।
         */
        \Illuminate\Support\Facades\DB::table('promotions')
            ->whereNull('name_bn')
            ->update(['name_bn' => \Illuminate\Support\Facades\DB::raw('name_en')]);

        Schema::table('promotions', function (Blueprint $table) {
            $table->string('name_bn')->nullable(false)->change();
        });
    }
};
