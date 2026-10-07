<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * বীমার টাকা বাকি সব টাকার চেয়ে মোটা মাপে মাপা হচ্ছিল।
 *
 * ── ⚠️ কী ছিল ───────────────────────────────────────────────────────
 * পুরো ব্যবস্থায় টাকার ঘর `decimal(18, 4)` — খতিয়ান, ভাউচার, বিল,
 * আমানত, সব। ⛔ কেবল বীমার তিনটা ঘর ছিল `(18, 2)`, আর ওটা আমারই
 * লেখা (২২ নভেম্বরের মাইগ্রেশন)।
 *
 * ── কেন এটা কেবল সৌন্দর্যের ব্যাপার নয় ──────────────────────────────
 * ⓘ `bcadd`/`bccomp` সবখানে স্কেল ৪ ধরে চলে ([[Money]])। ⚠️ ঘরটা ২
 * দশমিকে হলে ডেটাবেস নিজে থেকেই **গোল করে ফেলে**, আর তারপর কোড ঐ
 * গোল-করা সংখ্যাটাকে ৪ দশমিকে তুলনা করে — ফল: `10.0050` লিখে `10.0000`
 * ফেরত পাওয়া, আর মিলকরণে এক পয়সার একটা তফাত যার কোনো ব্যাখ্যা নেই।
 *
 * ⛔ আর তফাতটা ছোট বলেই খারাপ: বড় ভুল চোখে পড়ে, এক পয়সা পড়ে না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_insurance_policies', function (Blueprint $table) {
            $table->decimal('sum_insured', 18, 4)->default(0)->change();
            $table->decimal('premium', 18, 4)->default(0)->change();
        });

        Schema::table('fin_insurance_premiums', function (Blueprint $table) {
            $table->decimal('amount', 18, 4)->change();
        });
    }

    public function down(): void
    {
        Schema::table('fin_insurance_policies', function (Blueprint $table) {
            $table->decimal('sum_insured', 18, 2)->default(0)->change();
            $table->decimal('premium', 18, 2)->default(0)->change();
        });

        Schema::table('fin_insurance_premiums', function (Blueprint $table) {
            $table->decimal('amount', 18, 2)->change();
        });
    }
};
