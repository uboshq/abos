<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ ব্যাংক ঋণের মাসিক সুদ জমা — অর্থ-মডিউলের পরিকল্পনা ৩.৩, ৬ অক্টোবর ২০২৬ (সমন্বয়কের অনুমোদিত নকশা ক)।
 *
 * ⓘ প্রতিটা ঋণে প্রতিটা মাসের একটা সারি — কত দিনের, কোন জেরের উপর, কত সুদ, কোন ভাউচারে বসল আর কোন ভাউচারে উল্টাল
 * ([[InterestAccrualService]])। ⛔ এক ঋণে এক মাস একবারই — অনন্য সূচক।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fin_interest_accruals')) {
            return;
        }

        Schema::create('fin_interest_accruals', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('bank_facility_id')->constrained('fin_bank_facilities')->cascadeOnDelete();
            $table->date('for_month');
            $table->unsignedSmallInteger('days');
            $table->decimal('base', 18, 4);
            $table->decimal('rate', 8, 4);
            $table->decimal('amount', 18, 4);
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignId('reversal_voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['bank_facility_id', 'for_month'], 'fin_interest_accrual_month');
            $table->index(['company_id', 'for_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_interest_accruals');
    }
};
