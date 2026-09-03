<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * বাহক কেবল একটা নাম ছিল — এখন একজন পক্ষ।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * `carrier_name` মুক্ত লেখা। তাই *"এই পরিবহনকারীকে এই মাসে মোট কত
 * দিলাম"* প্রশ্নের উত্তর ছিল না — একই মানুষ "করিম ট্রান্সপোর্ট",
 * "করিম ট্রান্স." আর "Karim Transport" হয়ে তিনজন হয়ে যেতেন।
 *
 * ── পুরনো ঘরটা থাকছে, আর সেটা ইচ্ছাকৃত ───────────────────────────────
 * যে চালানগুলো এই মাইগ্রেশনের আগে লেখা, তাদের `carrier_id` নেই কিন্তু
 * নামটা আছে। ঘরটা মুছে দিলে **ওই তথ্যটা চিরতরে যেত**, আর পুরনো চালান
 * ছাপলে বাহকের ঘর খালি দেখাত।
 *
 * পড়ার নিয়ম তাই: **সংযুক্ত পক্ষ, না থাকলে পুরনো লেখা** — হুবহু যেভাবে
 * পণ্যে `brand_id` ও `brand` দুইটাই থাকে।
 *
 * ── কেন `nullOnDelete` ───────────────────────────────────────────────
 * কেউ একজন পরিবহনকারীকে মুছে ফেললে চালানটা টিকে থাকবে, কেবল জোড়াটা
 * খুলে যাবে — আর নামটা তখনো ঘরে আছে বলে **কাগজটা তবু পড়া যায়**।
 * ⚠️ চালান মুছে যাওয়া অনেক বেশি ক্ষতি: ওটার সাথে মজুদের চলাচল ও
 * খতিয়ানের সারি বাঁধা।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_challans', function (Blueprint $table) {
            $table->foreignId('carrier_id')
                ->nullable()
                ->after('carrier_name')
                ->constrained('suppliers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sal_challans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('carrier_id');
        });
    }
};
