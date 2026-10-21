<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ আটকে থাকা মজুরি পাওনা, শুধু পথ নেই — সই পড়লে পরের ব্যাংক ফাইলে "বকেয়া মজুরি" হয়ে একবারই যায় (fe, ২১ অক্টোবর ২০২৬; শ্রম আইন
 * ধারা ১২৩; [[HeldWagePayment]], [[PayrollService::arrearsFor()]])।
 *
 * ⛔ স্লিপ প্রতি একটাই সারি (unique `payslip_id`) — একই বকেয়া দুই ফাইলে গেলে ব্যাংক দুইবার টাকা পাঠাত। ⓘ আটকে থাকা স্লিপের রান
 * ছোঁয়া হয় না; এই সারি বলে কোন পরের রানের ফাইল টাকাটা বহন করল।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_held_wage_payments', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('payslip_id')->constrained('hr_payslips')->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
            // ⓘ দুই পথের একটা: পরের রানের ব্যাংক ফাইল (`paid_in_run_id`), বা বকেয়ার নিজের কাগজ — যেকোনো দিন, খসড়া পরিশোধ ভাউচারসহ (`voucher_id`)
            $table->foreignId('paid_in_run_id')->nullable()->constrained('hr_payroll_runs')->cascadeOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->string('channel', 8)->default('bank');   // bank · mfs
            $table->decimal('amount', 18, 4);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('payslip_id', 'hr_held_wage_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_held_wage_payments');
    }
};
