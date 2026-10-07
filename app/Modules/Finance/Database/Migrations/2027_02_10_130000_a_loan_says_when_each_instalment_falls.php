<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ ব্যাংক ঋণ — অর্থ-মডিউলের পরিকল্পনা ৩, ৬ অক্টোবর ২০২৬ (মালিকের অনুমোদিত ক্রম, সমন্বয়কের মারফত)।
 *
 * ⓘ `first_instalment_on` — প্রথম কিস্তির দিন; বাকিগুলো মাসে মাসে ([[BankFacilityService::datedSchedule()]])। না থাকলে
 * মঞ্জুরির পরের মাসের একই দিন। ঐচ্ছিক, পুরনো সারি অক্ষত।
 *
 * ⓘ `fin_facility_statements` — ব্যাংক একটা তারিখে কত বলে (ঋণের বিবরণী), আর পাশে খাতার জের; ফাঁক থাকলে খোঁজার জায়গা।
 * একই ঋণে একই দিনে একটাই। টাকা নড়ে না — কেবল লেখা।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('fin_bank_facilities', 'first_instalment_on')) {
            Schema::table('fin_bank_facilities', function (Blueprint $table): void {
                $table->date('first_instalment_on')->nullable()->after('instalment_amount');
            });
        }

        if (! Schema::hasTable('fin_facility_statements')) {
            Schema::create('fin_facility_statements', function (Blueprint $table): void {
                $table->id();
                $table->publicId();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('bank_facility_id')->constrained('fin_bank_facilities')->cascadeOnDelete();
                $table->date('statement_on');
                $table->decimal('bank_balance', 18, 4);
                $table->string('note', 191)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['bank_facility_id', 'statement_on'], 'fin_facility_statement_day');
                $table->index(['company_id', 'statement_on']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_facility_statements');

        if (Schema::hasColumn('fin_bank_facilities', 'first_instalment_on')) {
            Schema::table('fin_bank_facilities', fn (Blueprint $table) => $table->dropColumn('first_instalment_on'));
        }
    }
};
