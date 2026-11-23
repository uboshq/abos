<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ প্রসবের আসল দিন — পরের ৮ সপ্তাহ সেদিন থেকে (fe-র উত্তর ৩, ২১ অক্টোবর ২০২৬; বাংলাদেশ শ্রম আইন ২০০৬ ধারা ৪৫ ও ৪৭;
 * [[MaternityDelivery]]; a6, ২১ অক্টোবর ২০২৬)।
 *
 *   · `hr_leave_applications.actual_delivery_date` — সই পড়ার পরে বসে।
 *   · `hr_maternity_deliveries` — একটা বদলের অনুরোধ: আসল দিন, আগের আর নতুন শেষ দিন, অবস্থা (সইয়ের অপেক্ষায় · অনুমোদিত ·
 *     প্রত্যাখ্যাত), কে চাইলেন, কে সই দিলেন; দেওয়া মাসের টাকার পার্থক্য (`adjustment`) আর কোন রানে সেটা মিটল (`payroll_run_id`)।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hr_leave_applications', 'actual_delivery_date')) {
            Schema::table('hr_leave_applications', function (Blueprint $table): void {
                $table->date('actual_delivery_date')->nullable()->after('expected_delivery_date');
            });
        }

        if (Schema::hasTable('hr_maternity_deliveries')) {
            return;
        }

        Schema::create('hr_maternity_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('leave_application_id')->constrained('hr_leave_applications')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->date('actual_delivery_date');
            $table->date('old_to_date');
            $table->date('new_to_date');
            $table->string('status', 16)->default('pending');
            $table->decimal('adjustment', 18, 2)->default(0);
            $table->foreignId('payroll_run_id')->nullable()->constrained('hr_payroll_runs')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_remarks', 500)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status'], 'hr_delivery_emp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_maternity_deliveries');

        if (Schema::hasColumn('hr_leave_applications', 'actual_delivery_date')) {
            Schema::table('hr_leave_applications', function (Blueprint $table): void {
                $table->dropColumn('actual_delivery_date');
            });
        }
    }
};
