<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * প্রোফাইলে মানুষটার জায়গা ছিল না — মালিকের অনুমোদিত নকশা, ১ অক্টোবর ২০২৬।
 *
 * ── কী ছিল না ─────────────────────────────────────────────────────────
 * কর্মীর পাতায় নাম, পদবি, বেতন ছিল — কিন্তু তিনি কার অধীনে কাজ করেন,
 * জন্মতারিখ, রক্তের গ্রুপ, ঠিকানা, মায়ের নাম, বিপদে কাকে ডাকতে হবে —
 * কোনোটারই ঘর ছিল না। ⓘ ছবির ঘর এখানে নেই: ছবি তোলার দরজা (আপলোড) না
 * বানিয়ে ঘর রাখলে সেটা চিরকাল খালি থাকত। নকশার "রিপোর্টিং লাইন" আর ব্যক্তিগত
 * তথ্যের কার্ড তাই বানানোই যেত না।
 *
 * ── কেন সব ঘর ঐচ্ছিক ───────────────────────────────────────────────────
 * লাইভে কর্মীরা আগে থেকেই আছেন, আর তাঁদের এই তথ্যগুলো কেউ লেখেনি।
 * বাধ্যতামূলক করলে হয় মাইগ্রেশন আটকাত, নয় বানানো মান বসাতে হত —
 * আর বানানো জন্মতারিখ খালি ঘরের চেয়ে খারাপ।
 *
 * ── রিপোর্টিং লাইন ───────────────────────────────────────────────────
 * `reports_to_employee_id` একই টেবিলে ফেরে। যাঁর অধীনে ছিলেন তিনি মুছে
 * গেলে ঘরটা খালি হয় (SET NULL) — অধীনস্থের সারি মুছে যায় না। একই
 * কোম্পানির কর্মী কি না, সেটা ফর্মের যাচাই আর পরীক্ষায় বাঁধা; ডাটাবেস
 * কোম্পানি চেনে না।
 *
 * ⚠️ ইনডেক্সের নাম ছোট, হাতে দেওয়া — ৬৪ অক্ষরের বেশি হলে MySQL প্রতিটা
 * সেশন ভেঙে দেয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->foreignId('reports_to_employee_id')->nullable()->after('employment_type_id');
            $table->date('date_of_birth')->nullable()->after('father_name');
            $table->string('mother_name', 150)->nullable()->after('date_of_birth');
            $table->string('blood_group', 3)->nullable()->after('mother_name');
            $table->string('present_address', 500)->nullable()->after('email');
            $table->string('permanent_address', 500)->nullable()->after('present_address');
            $table->string('emergency_name', 150)->nullable()->after('permanent_address');
            $table->string('emergency_relation', 60)->nullable()->after('emergency_name');
            $table->string('emergency_mobile', 20)->nullable()->after('emergency_relation');

            $table->foreign('reports_to_employee_id', 'hr_emp_reports_to_fk')
                ->references('id')->on('hr_employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropForeign('hr_emp_reports_to_fk');
            $table->dropColumn([
                'reports_to_employee_id', 'date_of_birth', 'mother_name', 'blood_group',
                'present_address', 'permanent_address', 'emergency_name', 'emergency_relation',
                'emergency_mobile',
            ]);
        });
    }
};
