<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ অধিকাল (ওভারটাইম) শিট — এক কর্মীর এক মাসের বাড়তি ঘণ্টা, ব্যবস্থাপকের সইসহ (BLA ২০০৬ ধারা ১০৮; মালিকের অনুমোদিত আইনি বেতনের
 * পরিকল্পনা, a6, ২১ অক্টোবর ২০২৬; [[OvertimeService]])।
 *
 *   · `hours` — হাজিরার ঢোকা-বেরোনো থেকে, দিনের কাজের ঘণ্টার বাইরেরটুকু, মাস জুড়ে যোগ ([[OvertimeService::hoursFromAttendance()]])।
 *   · `status` — `waiting` (সইয়ের অপেক্ষায়) → `signed` (অনুমোদিত)। ⛔ কেবল সই হওয়া ঘণ্টা বেতনে যায় ([[PayrollService::buildPayslip()]])।
 *   · সই হলে ঘণ্টা আর বদলায় না — যা সই হলো, ঠিক তা-ই দেওয়া হয়।
 *
 * ⓘ এক কর্মীর এক মাসে একটাই শিট (`hr_ot_emp_month`)।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_overtime_sheets')) {
            return;
        }

        Schema::create('hr_overtime_sheets', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->date('month');
            $table->decimal('hours', 10, 4)->default(0);
            $table->unsignedSmallInteger('days')->default(0);
            $table->string('status', 16)->default('waiting');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'month'], 'hr_ot_emp_month');
            $table->index(['company_id', 'month', 'status'], 'hr_ot_month_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_overtime_sheets');
    }
};
