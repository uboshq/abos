<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ প্রিমিয়ামের কিস্তি — অর্থ-মডিউলের পরিকল্পনা ৬.২, ৬ অক্টোবর ২০২৬ (সমন্বয়কের উত্তর প্র১: ঘর থাকবে, ডিফল্ট বছরে)।
 *
 * ⓘ বছরে / ছয় মাসে / তিন মাসে / মাসে — মেয়াদটা সেই মাপে ভাগ হয়ে প্রিমিয়ামের সারি বসে ([[InsuranceService]])। পুরনো পলিসি
 * "বছরে", তাই তাদের একটা সারিই থাকে, যেমন ছিল।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('fin_insurance_policies', 'frequency')) {
            Schema::table('fin_insurance_policies', function (Blueprint $table): void {
                $table->string('frequency', 16)->default('yearly')->after('premium');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('fin_insurance_policies', 'frequency')) {
            Schema::table('fin_insurance_policies', fn (Blueprint $table) => $table->dropColumn('frequency'));
        }
    }
};
