<?php

declare(strict_types=1);

use App\Core\Support\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * বেতনের রান — এক মাস, একগুচ্ছ বেতনশিট।
 *
 * ── কেন অঙ্কগুলো শিটে কপি হয়ে বসে ───────────────────────────────────
 * কাঠামো থেকে প্রতিবার নতুন করে হিসাব করলে সহজ মনে হয়। কিন্তু বেতন
 * একবার দেওয়া হয়ে গেলে সেটা ইতিহাস — পরে কেউ কাঠামো শুধরালে গত মাসের
 * শিটটাও বদলে যেত, অথচ ব্যাংকে অন্য টাকা গেছে। তাই রান বানানোর দিনে
 * প্রতিটা অঙ্ক শিটে লেখা হয়ে যায়, আর সেটাই কাগজ।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('financial_year_id')->nullable()
                ->constrained('financial_years')->nullOnDelete();

            $table->string('document_no', 64);

            /*
             * মাসটা তারিখ হিসেবে — মাসের প্রথম দিন।
             *
             * বছর ও মাস আলাদা দুইটা সংখ্যায় রাখলে "এপ্রিল থেকে জুন" ধরনের
             * প্রশ্নে দুই কলাম মিলিয়ে শর্ত লিখতে হত, আর একদিন কেউ বছরটা
             * ভুলে যেত।
             */
            $table->date('month');

            // বেতন কোন দিনের খরচ — সাধারণত মাসের শেষ দিন
            $table->date('trx_date');

            $table->decimal('gross_total', 18, 4)->default(0);
            $table->decimal('deduction_total', 18, 4)->default(0);
            $table->decimal('net_total', 18, 4)->default(0);
            $table->unsignedInteger('employee_count')->default(0);

            $table->string('status', 32)->default(DocumentStatus::DRAFT);
            $table->text('narration')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'document_no']);

            /*
             * এক মাসে এক শাখার একটাই রান।
             *
             * দুইটা থাকলে একই মাসের বেতন দুইবার খরচে বসত, আর কেউ খেয়াল
             * না করলে ব্যাংকেও দুইবার টাকা যেত। বাতিল করা রানগুলো এই
             * শর্তের বাইরে — নাহলে ভুল রান বাতিল করে আর নতুন বানানো যেত না।
             */
            $table->index(['company_id', 'month', 'status']);
        });

        /*
         * একজন কর্মীর এক মাসের শিট।
         *
         * ব্যাংকের ঘরগুলোও এখানে কপি হয়: বেতনের দিনে যে হিসাব নম্বরে
         * টাকা গেছে সেটাই কাগজে থাকা দরকার। কর্মীর সারি থেকে পড়লে আজ
         * নম্বর বদলালে গত মাসের ফাইলটাও বদলে যেত।
         */
        Schema::create('hr_payslips', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();

            $table->decimal('gross', 18, 4)->default(0);
            $table->decimal('deductions', 18, 4)->default(0);
            $table->decimal('net', 18, 4)->default(0);

            // বেতনের দিনের কপি — পরে কর্মীর সারি বদলালেও কাগজ বদলায় না
            $table->string('payment_method', 16)->default('cash');
            $table->string('bank_name', 120)->nullable();
            $table->string('bank_account_name', 120)->nullable();
            $table->string('bank_account_no', 64)->nullable();
            $table->string('bank_routing_no', 32)->nullable();
            $table->string('mfs_number', 32)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // একই রানে একজন কর্মীর দুইটা শিট মানে দুইবার বেতন
            $table->unique(['payroll_run_id', 'employee_id']);
            $table->index(['company_id', 'employee_id']);
        });

        /*
         * শিটের একটা সারি — কোন খাতে কত।
         *
         * খাতের নামটাও কপি হয়, শুধু id নয়: খাতের নাম বদলালে বা খাতটা
         * নিষ্ক্রিয় হলে পুরনো শিট ছাপার সময় সারিটা নামহীন হয়ে যেত।
         */
        Schema::create('hr_payslip_lines', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('payslip_id')->constrained('hr_payslips')->cascadeOnDelete();
            $table->foreignId('salary_head_id')->nullable()
                ->constrained('hr_salary_heads')->nullOnDelete();

            $table->string('head_code', 32);
            $table->string('head_name_en', 120);
            $table->string('head_name_bn', 120)->nullable();

            // earning · deduction
            $table->string('kind', 16);

            $table->decimal('amount', 18, 4)->default(0);
            $table->integer('sort_order')->default(0);

            // কোন হিসাব খাতে বসেছিল — পরে খাত বদলালেও ইতিহাস অক্ষত
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'payslip_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payslip_lines');
        Schema::dropIfExists('hr_payslips');
        Schema::dropIfExists('hr_payroll_runs');
    }
};
