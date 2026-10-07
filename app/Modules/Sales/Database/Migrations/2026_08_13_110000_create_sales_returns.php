<?php

declare(strict_types=1);

use App\Core\Support\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * বিক্রয় ফেরত — মাল গ্রাহকের কাছ থেকে ফিরে এসেছে।
 *
 * ── কেন বিল বাতিল করলেই হত না ───────────────────────────────────────
 * বিলে দশ বস্তা ছিল, তার দুইটা ফেরত এসেছে। বিল বাতিল করলে বাকি আটটার
 * বিক্রিও খাতা থেকে মুছে যেত — অথচ সেগুলো গ্রাহকের কাছেই আছে আর টাকাও
 * পাওনা। ফেরত একটা আলাদা ঘটনা, তাই আলাদা কাগজ।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sal_returns', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('financial_year_id')->nullable()
                ->constrained('financial_years')->nullOnDelete();

            $table->string('document_no', 64);
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('inv_warehouses')->restrictOnDelete();

            /*
             * কোন বিলের বিপরীতে ফেরত — ঐচ্ছিক।
             *
             * বেশিরভাগ ফেরত একটা বিল ধরেই আসে, আর তখন "যত বেচা হয়েছে
             * তার বেশি ফেরত" আটকানো যায়। কিন্তু পুরনো মাল ফেরত এলে
             * বিলটা কেউ খুঁজে পায় না — তখনও ফেরত নিতে হয়, নাহলে মালটা
             * গুদামে ঢুকত কিন্তু খাতায় উঠত না।
             */
            $table->foreignId('sales_invoice_id')->nullable()
                ->constrained('sal_invoices')->nullOnDelete();

            // কেন ফেরত — নষ্ট, মেয়াদ, নাকি বিক্রি হয়নি
            $table->foreignId('reason_code_id')->nullable()
                ->constrained('mdm_reason_codes')->nullOnDelete();

            $table->date('trx_date');

            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('tax', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);

            // ফেরত মালের ব্যয় — বিক্রীত পণ্যের ব্যয় থেকে যতটা ফিরবে
            $table->decimal('cost_of_goods', 18, 4)->default(0);

            $table->string('status', 32)->default(DocumentStatus::DRAFT);
            $table->text('narration')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'document_no']);
            $table->index(['company_id', 'customer_id', 'status']);
            $table->index(['company_id', 'trx_date']);
        });

        Schema::create('sal_return_lines', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('sales_return_id')->constrained('sal_returns')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->restrictOnDelete();

            $table->foreignId('sales_invoice_line_id')->nullable()
                ->constrained('sal_invoice_lines')->nullOnDelete();

            $table->decimal('qty', 18, 4);
            $table->decimal('rate', 18, 4);
            $table->decimal('tax', 18, 4)->default(0);
            $table->decimal('amount', 18, 4);

            /*
             * ফেরত মালটা বিক্রয়যোগ্য কি না।
             *
             * নষ্ট বা মেয়াদ পেরোনো মাল গুদামে ঢোকে ঠিকই, কিন্তু আবার
             * বেচা যায় না — ওটা Hold-এ যায়। এই ঘরটা না থাকলে ফেরত আসা
             * নষ্ট মাল পরদিন আবার কারও কাছে চলে যেত।
             */
            $table->boolean('to_hold')->default(false);

            $table->unsignedSmallInteger('line_no');
            $table->timestamps();

            $table->index(['sales_return_id', 'line_no']);
            $table->index('product_id');
            $table->index('sales_invoice_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sal_return_lines');
        Schema::dropIfExists('sal_returns');
    }
};
