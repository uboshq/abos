<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⛔ বাতিল রানের ব্যাংক ফাইলে যাওয়া বকেয়া মজুরি হয়তো ব্যাংক দিয়েই দিয়েছে — নিজে নিজে ছাড়া হয় না (fe-র সিদ্ধান্ত ২, ২১ অক্টোবর
 * ২০২৬; শ্রম আইন ধারা ১২৩; [[HeldWages::askBankUnpaid()]], [[BankUnpaidLine]])।
 *
 * ⓘ ফাইল নামানো মানে [[HeldWagePayment]]-এর সারি বসা; ফাইলটা ব্যাংকে উঠে গিয়ে থাকলে রান বাতিল হলেও টাকা গেছে। তাই সারিটা থাকে,
 * অবস্থা "ব্যাংকে পাঠানো — নিশ্চিতের অপেক্ষায়"। হিসাবের মানুষ ব্যাংকের বিবরণী মিলিয়ে বলেন "ব্যাংক এই লাইন দেয়নি" — কারণসহ, আর
 * আরেকজনের দ্বিতীয় সইয়ে তবে সারিটা মোছে, মজুরি আবার দেওয়ার তালিকায়, একবারই।
 *
 * ── কেন আলাদা সারি ──────────────────────────────────────────────────
 * সইয়ের অনুরোধ একটা কাগজে বসে; [[HeldWagePayment]]-এর সারি সই পড়লে মোছে, তাই অনুরোধ তার ওপর বসলে "কী ছাড়া হলো, কে চাইলেন, কে
 * সই দিলেন" উত্তর হারাত। এই সারি থাকে — কোন স্লিপ, কোন বাতিল রানের ফাইল, কত, কারণ, কে কবে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_bank_unpaid_lines', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('payslip_id')->constrained('hr_payslips')->cascadeOnDelete();
            // ⓘ যে "দেওয়া হয়েছে" সারির কথা — সই পড়লে সেটা মোছে, তখন null; বাকি ঘরে সব থেকে যায়
            $table->foreignId('held_wage_payment_id')->nullable()->constrained('hr_held_wage_payments')->nullOnDelete();
            // ⓘ বাতিল যে রানের ব্যাংক ফাইল লাইনটা বহন করেছিল
            $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
            $table->decimal('amount', 18, 4);
            $table->string('reason', 500);

            $table->string('status', 16)->default('pending');   // pending · released · rejected · cancelled
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('note', 500)->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status'], 'hr_bank_unpaid_queue');
            $table->index(['payslip_id', 'status'], 'hr_bank_unpaid_slip');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_bank_unpaid_lines');
    }
};
