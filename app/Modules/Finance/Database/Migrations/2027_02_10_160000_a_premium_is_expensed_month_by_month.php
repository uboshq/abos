<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ বীমার প্রিমিয়াম মাসে মাসে খরচ — অর্থ-মডিউলের পরিকল্পনা ৬.৩, ৬ অক্টোবর ২০২৬ (সমন্বয়কের উত্তর প্র২: অগ্রিম বীমা 1136,
 * উল্টো দাখিলায়)।
 *
 * ⓘ দেওয়া প্রিমিয়ামের প্রতিটা কিস্তিতে প্রতিটা মাসের একটা সারি — মাস শেষে মেয়াদের কত দিন বাকি, কত টাকা অগ্রিমে সরল, কোন
 * খরচের খাত থেকে, কোন ভাউচারে বসল আর কোন ভাউচারে উল্টাল ([[InsurancePrepaymentService]])। ⛔ এক কিস্তিতে এক মাস
 * একবারই — অনন্য সূচক।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fin_insurance_prepayments')) {
            return;
        }

        Schema::create('fin_insurance_prepayments', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('policy_id')->constrained('fin_insurance_policies')->cascadeOnDelete();
            $table->foreignId('premium_id')->constrained('fin_insurance_premiums')->cascadeOnDelete();
            $table->date('for_month');
            $table->unsignedSmallInteger('days_left');
            $table->unsignedSmallInteger('days_total');
            $table->decimal('amount', 18, 4);
            $table->foreignId('expense_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignId('reversal_voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['premium_id', 'for_month'], 'fin_insurance_prepay_month');
            $table->index(['company_id', 'for_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_insurance_prepayments');
    }
};
