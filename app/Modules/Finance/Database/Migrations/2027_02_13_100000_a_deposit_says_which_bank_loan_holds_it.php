<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ জমা কোন ব্যাংক ঋণের জামানতে — অর্থ-মডিউলের পরিকল্পনা ৪.৫ (৬ অক্টোবর ২০২৬, সমন্বয়কের সিদ্ধান্ত প্র৪)।
 *
 * ⛔ জমা এতদিন পুরনো ঋণের সারিতে (`acc_loans`) বাঁধা হত, অথচ ব্যাংক ঋণ এখন নিজের খাতায় (`fin_bank_facilities`)। তাই "কোন
 * FDR কোন ব্যাংক ঋণের পিছনে" প্রশ্নের উত্তর ছিল না, আর `BankFacility::LIEN`-এর মন্তব্য ভুল করে বলত সংযোগ আছে।
 *
 * ⓘ ঐচ্ছিক; পুরনো `pledged_to_loan_id` থাকে আর পড়া হয়, যতদিন পুরনো তথ্য আছে। ঋণ মুছে গেলে বাঁধনও যায় (জমা থাকে)।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('fin_deposits', 'pledged_to_facility_id')) {
            Schema::table('fin_deposits', function (Blueprint $table): void {
                $table->foreignId('pledged_to_facility_id')->nullable()->after('pledged_to_loan_id')
                    ->constrained('fin_bank_facilities')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('fin_deposits', 'pledged_to_facility_id')) {
            Schema::table('fin_deposits', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('pledged_to_facility_id');
            });
        }
    }
};
