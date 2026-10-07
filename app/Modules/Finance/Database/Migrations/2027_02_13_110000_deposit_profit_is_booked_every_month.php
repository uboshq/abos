<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ আমানতের মাসিক অর্জিত মুনাফা — অর্থ-মডিউলের পরিকল্পনা ৪.২ (৬ অক্টোবর ২০২৬, সমন্বয়কের সিদ্ধান্ত প্র১; ব্যাংক ঋণের সুদ
 * জমার একই নিয়ম, [[InterestAccrualService]])।
 *
 * ⓘ এক জমায় এক মাস একটাই সারি: কত দিন, কোন আসলে, কোন হারে, কত; কোন ভাউচারে বসল আর কোনটায় উল্টাল। টাকা নড়ে ভাউচারে —
 * সারিটা কেবল বলে কোন মাস করা হয়েছে, আর একই মাস দুবার বসা আটকায়।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fin_deposit_accruals')) {
            return;
        }

        Schema::create('fin_deposit_accruals', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('deposit_id')->constrained('fin_deposits')->cascadeOnDelete();
            $table->date('for_month');
            $table->decimal('base', 18, 4);
            $table->decimal('rate', 8, 4);
            $table->decimal('amount', 18, 4);
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignId('reversal_voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['deposit_id', 'for_month'], 'fin_deposit_accrual_month');
            $table->index(['company_id', 'for_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_deposit_accruals');
    }
};
