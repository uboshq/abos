<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * আগে থেকেই চলতে থাকা ঋণের নামার কোনো জায়গা ছিল না।
 *
 * ── ⓘ মালিকের নির্দেশ, ২০ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"এই ঋণ নতুন, নাকি আগে থেকেই চলছে?"* — ব্যবস্থায় আসার দিন বেশিরভাগ
 * ঋণই পুরনো। ⓘ তখন আজকের বকেয়া আর **কয়টা কিস্তি ইতিমধ্যে দেওয়া**,
 * দুইটাই জানা দরকার।
 *
 * ── ⛔ কেন এটা "কত কিস্তি দেওয়া হয়েছে" কলাম নয় ──────────────────────
 * ⚠️ দেওয়া কিস্তির চলতি সংখ্যা **কখনো সংরক্ষণ করা হয় না** — ওটা খতিয়ান
 * থেকে গোনা হয় ([[BankFacilityService::instalmentStanding]])। সংরক্ষিত
 * গুনতি আর খতিয়ান একদিন আলাদা কথা বলত, আর তখন কোনটা সত্যি সেটা বলার
 * উপায় থাকত না।
 *
 * ⭐ এই ঘরটা কেবল **শুরুর দিনের ছবি**: ব্যবস্থায় তোলার আগে ব্যাংকের
 * কাগজে যত কিস্তি শোধ দেখানো ছিল। ⓘ নামটাও তাই — `opening_`।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_bank_facilities', function (Blueprint $table) {
            $table->unsignedSmallInteger('opening_instalments_paid')
                ->nullable()
                ->after('opening_drawn');
        });
    }

    public function down(): void
    {
        Schema::table('fin_bank_facilities', function (Blueprint $table) {
            $table->dropColumn('opening_instalments_paid');
        });
    }
};
