<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ কর্মীর লিঙ্গ — মাতৃত্বকালীন ছুটির যোগ্যতার জন্য (মালিকের অনুমোদন, ২১ অক্টোবর ২০২৬; বাংলাদেশ শ্রম আইন ২০০৬ অধ্যায় ৪, ধারা ৪৫–৫০;
 * [[LeaveService::assertEligible()]]; a6, ২১ অক্টোবর ২০২৬)।
 *
 *   · `hr_employees.gender` — female · male · other, খালি চলে (পুরনো কর্মীদের কেউ লেখেনি)।
 *
 * ⛔ পরিচয়ের চাবির (`hr.identity.view`) পেছনে ([[Employee::GUARDED]]) — জন্মতারিখ আর রক্তের গ্রুপের মতো ব্যক্তিগত তথ্য।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('hr_employees', 'gender')) {
            return;
        }

        Schema::table('hr_employees', function (Blueprint $table): void {
            $table->string('gender', 8)->nullable()->after('blood_group');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('hr_employees', 'gender')) {
            return;
        }

        Schema::table('hr_employees', function (Blueprint $table): void {
            $table->dropColumn('gender');
        });
    }
};
