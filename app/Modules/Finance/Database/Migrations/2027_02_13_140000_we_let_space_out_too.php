<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ আমরাও জায়গা ভাড়া দিই — মালিকের সিদ্ধান্ত প্র৩, ৬ অক্টোবর ২০২৬ (সমন্বয়কের মারফত): ভাড়াটের চুক্তি, ভাড়াটের জামানত (দায়),
 * মাসের শুরুতে ভাড়া আয় (Dr ভাড়া প্রাপ্য / Cr ভাড়া আয়), আর আদায় ও বকেয়ার রিপোর্ট ([[TenancyService]])।
 *
 *   · `fin_tenancies` — ভাড়াটের চুক্তি; ভাড়াটে সবসময় একটা পক্ষ (ব্যক্তি বা গ্রাহক), কারণ ১১২৫ আর ২১৫৫-এর প্রতিটা সারিতে তিনি
 *     পক্ষ হিসেবে বসেন (সমন্বয়কের শর্ত)
 *   · `fin_tenancy_charges` — এক মাসের ভাড়া দাবি; অঙ্কটা বসার দিনের দর, তাই দর বদলালে পুরনো মাস নড়ে না। এক চুক্তিতে এক মাস একবারই
 *   · `fin_tenancy_moves` — টাকার প্রতিটা নড়াচড়া: জামানত নেওয়া, ভাড়া আদায়, জামানত থেকে কাটা, জামানত ফেরত; প্রতিটা একটা ভাউচার
 *
 * ⓘ কোনো "কত বাকি" কলাম নেই — বকেয়া আর জামানত দাবি ও নড়াচড়ার সারি যোগ করে, কেবল খাতায় বসা ভাউচার ধরে
 * ([[Tenancy::outstanding()]], [[Tenancy::depositHeld()]])।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fin_tenancies')) {
            Schema::create('fin_tenancies', function (Blueprint $table): void {
                $table->id();
                $table->publicId();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('document_no', 40)->nullable();
                $table->string('tenant', 191);
                $table->string('tenant_phone', 40)->nullable();
                $table->string('party_type', 32);
                $table->unsignedBigInteger('party_id');
                $table->string('premises', 191)->nullable();
                $table->foreignId('income_account_id')->constrained('accounts');
                $table->decimal('deposit_amount', 18, 4)->default(0);
                $table->decimal('monthly_rent', 18, 4);
                $table->unsignedTinyInteger('rent_day')->default(5);
                $table->date('starts_on');
                $table->unsignedSmallInteger('term_months');
                $table->date('ends_on');
                $table->string('status', 16)->default('active');
                $table->date('closed_on')->nullable();
                $table->string('note', 500)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['company_id', 'document_no'], 'fin_tenancy_no');
                $table->index(['company_id', 'status']);
                $table->index(['company_id', 'party_type', 'party_id'], 'fin_tenancy_party');
            });
        }

        if (! Schema::hasTable('fin_tenancy_charges')) {
            Schema::create('fin_tenancy_charges', function (Blueprint $table): void {
                $table->id();
                $table->publicId();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('tenancy_id')->constrained('fin_tenancies')->cascadeOnDelete();
                $table->date('for_month');
                $table->decimal('amount', 18, 4);
                $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['tenancy_id', 'for_month'], 'fin_tenancy_charge_month');
                $table->index(['company_id', 'for_month']);
            });
        }

        if (! Schema::hasTable('fin_tenancy_moves')) {
            Schema::create('fin_tenancy_moves', function (Blueprint $table): void {
                $table->id();
                $table->publicId();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('tenancy_id')->constrained('fin_tenancies')->cascadeOnDelete();
                $table->string('kind', 24);
                $table->date('moved_on');
                $table->decimal('amount', 18, 4);
                $table->foreignId('money_account_id')->nullable()->constrained('accounts')->nullOnDelete();
                $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
                $table->string('note', 500)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['tenancy_id', 'kind']);
                $table->index(['company_id', 'moved_on']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_tenancy_moves');
        Schema::dropIfExists('fin_tenancy_charges');
        Schema::dropIfExists('fin_tenancies');
    }
};
