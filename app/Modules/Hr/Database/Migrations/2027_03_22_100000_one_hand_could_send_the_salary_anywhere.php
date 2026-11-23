<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⛔ একজনই কর্মীর বেতনের ঠিকানা বদলাতে পারতেন — আন্তর্জাতিক মানের ফাঁক #৮ (গুরুতর), ২০ অক্টোবর ২০২৬ (fe; প্রমাণ
 * gaps-core-hr.md #৯: `EmployeeController.php:393, 402-405` কেবল চাবি দেখত)।
 *
 * ⓘ বেতন জালিয়াতির সবচেয়ে চেনা পথ: `hr.identity.view` থাকা একজন কর্মীর ব্যাংক নম্বর নিজের নম্বরে বদলান, আর পরের ব্যাংক-ফাইল
 * বেতন সেখানেই পাঠায় (SOX/ITGC maker-checker; ISO 27001 A.5.3 দায়িত্ব ভাগ)।
 *
 * ── কেন আলাদা সারি ──────────────────────────────────────────────────
 * বদলটা দ্বিতীয় সইয়ের আগে কর্মীর সারিতে বসে না, তাই প্রস্তাবিত মানটা কোথাও থাকতে হয় — আর সই পড়লে আগেরটাও, যাতে "কোন
 * হিসাব থেকে কোন হিসাবে, কে চাইলেন, কে সই দিলেন" প্রশ্নের উত্তর থাকে। ⓘ `approvals.payload` নয়: ওটা সই-ছকের সারি, ছক
 * বন্ধ কোম্পানিতে (UB) সারিটাই হয় না, অথচ এই সই ছক বন্ধ থাকলেও লাগে; আর ওটা ঢাকা নয়।
 *
 * ⚠️ `proposed` আর `previous` ঢাকা (Laravel `encrypted:array`) — ব্যাংক নম্বর, রাউটিং আর MFS নম্বর কর্মীর সারির মতোই ডাম্পে
 * পড়া যায় না। ⓘ মোছে না: না হওয়া আর ফিরিয়ে নেওয়া বদলও থাকে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payment_changes', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();

            // ⓘ পথ (cash · bank · mfs) আর ব্যাংকের ঘরগুলো — ঢাকা JSON
            $table->text('proposed');
            $table->text('previous')->nullable();

            $table->string('status', 16)->default('pending');   // pending · applied · rejected · cancelled
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('note', 500)->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status'], 'hr_pay_change_queue');
            $table->index(['employee_id', 'status'], 'hr_pay_change_emp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payment_changes');
    }
};
