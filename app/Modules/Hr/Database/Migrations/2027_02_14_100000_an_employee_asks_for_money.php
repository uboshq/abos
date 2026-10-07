<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ কর্মী টাকা চান — খরচের দাবি আর অগ্রিম অনুরোধ (মালিকের আদেশ, ৭ অক্টোবর ২০২৬: টাকা আসা-যাওয়ার আন্তর্জাতিক পরিকল্পনা,
 * ভাগ ১৩-গ; [[ExpenseClaimService]])।
 *
 *   · `hr_expense_claims` — একটা কাগজ, দুই ধরন: খরচের দাবি (খাত, অঙ্ক, তারিখ, কারণ, রসিদের ছবি) বা অগ্রিম অনুরোধ। সই
 *     অঙ্কের সীমা অনুযায়ী; শেষ সইয়ে খসড়া ভাউচার নিজে থেকে, ক্যাশিয়ার টাকা দিয়ে পাকা করেন।
 *
 * ⓘ অগ্রিম থেকে কাটা অংশ (`from_advance`) আর তার জাবেদা (`settle_voucher_id`) — খরচের দাবি আগে কর্মীর খোলা অগ্রিম থেকে
 * মেটে; বাকিটুকু নগদে (`payment_voucher_id`, ক্যাশিয়ারের খসড়া)।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_expense_claims')) {
            return;
        }

        Schema::create('hr_expense_claims', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('document_no', 40)->nullable();
            $table->string('kind', 16);
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->decimal('from_advance', 18, 2)->default(0);
            $table->date('spent_on')->nullable();
            $table->string('reason', 500);
            $table->string('status', 16)->default('submitted');
            $table->foreignId('settle_voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignId('payment_voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'document_no'], 'hr_claim_no');
            $table->index(['company_id', 'status']);
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_expense_claims');
    }
};
