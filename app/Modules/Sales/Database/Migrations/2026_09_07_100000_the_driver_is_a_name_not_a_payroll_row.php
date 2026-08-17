<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * চালকের ঘরটা কর্মী তালিকার সারি নয়, একটা নাম।
 *
 * ── কেন সরানো হলো ───────────────────────────────────────────────────
 * ট্রিপের কাগজে চালকের জন্য `hr_employees`-এ একটা FK বসানো হয়েছিল,
 * যাতে "এই চালক এ মাসে কয়টা ট্রিপ গেলেন" প্রশ্নের উত্তর থাকে।
 * সীমানার পরীক্ষা সাথে সাথেই ধরল: **বিক্রয় মডিউল Hr-এর ভেতরে হাত
 * দিচ্ছে, অথচ তাকে নির্ভরতা বলে ঘোষণা করেনি।**
 *
 * ঘোষণা করাই সহজ পথ ছিল, কিন্তু সেটা একটা মিথ্যা কথা: বিক্রয় করতে
 * বেতনের খাতা লাগে না। আর মজার ব্যাপার, ওই মাইগ্রেশনের মন্তব্যেই আমি
 * নিজে লিখেছিলাম *"ভাড়ার গাড়ির চালক কর্মী নন, আর তাঁকে কর্মী তালিকায়
 * ঢোকানো মানে বেতনের খাতাও নোংরা করা"* — অথচ ঘরটা ঠিক তাই চাইছিল।
 *
 * এই রিপোর দুই জায়গায় চালককে ইতিমধ্যেই নাম হিসেবেই রাখে —
 * `sal_challans.driver_name` আর `mdm_vehicles.driver_name`। ট্রিপও তাই
 * করে, আর তাতে বহরের বাইরের চালকও লেখা যায়।
 *
 * ── যা এতে হারাল ────────────────────────────────────────────────────
 * চালক ধরে যোগ করা সংখ্যা এখন নামের বানানের উপর ভর করে। সত্যিই যদি
 * চালক ধরে হিসাব চাই, তখন সেটা হবে **নিজের একটা মাস্টার তালিকা**
 * (mdm_drivers), বেতনের খাতা ধার করে নয় — কিন্তু সেটা মালিকের
 * সিদ্ধান্ত, আর সেদিন এই ঘরটা ওই তালিকায় দেখাবে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('sal_shipments', function (Blueprint $table) {
            $table->foreignId('driver_employee_id')->nullable()->after('vehicle_no')
                ->constrained('hr_employees')->nullOnDelete();
        });
    }
};
