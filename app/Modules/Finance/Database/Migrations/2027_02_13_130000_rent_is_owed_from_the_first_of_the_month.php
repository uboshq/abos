<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ মাসের ভাড়া মাসের শুরুতেই খরচে — মালিকের সিদ্ধান্ত প্র২, ৬ অক্টোবর ২০২৬ (সমন্বয়কের মারফত): "প্রতি মাসের শুরুতে খরচে বসবে
 * (accrual): Dr ৫২০২ / Cr নতুন প্রদেয় ভাড়া; দেওয়ার দিন প্রদেয় শোধ হবে … আসল প্রদেয় (দেওয়া পর্যন্ত দায় থাকে)। ছকের সই, এক মাস
 * একবার, বন্ধ মাসে নয়।"
 *
 *   · `fin_rental_accruals` — কোন চুক্তির কোন মাসের ভাড়া কত টাকা প্রদেয় হিসেবে বসল, কোন ভাউচারে ([[RentalAccrualService]])
 *
 * ⓘ এক চুক্তিতে এক মাস একবারই — সফট-ডিলিট নেই, তাই অনন্য চাবিটা সত্যিই পাহারা দেয় (`deleted_at` খালি থাকলে MySQL-এ NULL ≠ NULL,
 * [[RentalContractService::assertMonthNotDone()]]-এর শিক্ষা)। "না" হলে সারিটা মুছে যায়, যাতে মাসটা আবার চালানো যায়।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fin_rental_accruals')) {
            return;
        }

        Schema::create('fin_rental_accruals', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('rental_contract_id')->constrained('fin_rental_contracts')->cascadeOnDelete();
            $table->date('for_month');
            $table->decimal('amount', 18, 4);
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['rental_contract_id', 'for_month'], 'fin_rental_accrual_month');
            $table->index(['company_id', 'for_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_rental_accruals');
    }
};
