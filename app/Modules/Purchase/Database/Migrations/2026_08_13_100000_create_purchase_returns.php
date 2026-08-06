<?php

declare(strict_types=1);

use App\Core\Support\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ক্রয় ফেরত — মাল সরবরাহকারীর কাছে ফিরে যাচ্ছে।
 *
 * ── কেন এটা ছাড়া চলছিল না ────────────────────────────────────────────
 * ডিপোর ব্যবসায় মাল ফেরত যায় রোজ: নষ্ট, মেয়াদ পেরোনো, বা ভুল মাল
 * এসেছে। এতদিন ফেরতের কোনো ডকুমেন্ট ছিল না, তাই হয় কেউ পুরো বিলটাই
 * বাতিল করত (অথচ বাকি মালটা গুদামেই আছে আর তার টাকাও দিতে হবে), নয়
 * স্টক আর খাতা হাতে ঠিক করত — আর হাতে ঠিক করা মানে কোথাও একটা না মেলা।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pur_returns', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('financial_year_id')->nullable()
                ->constrained('financial_years')->nullOnDelete();

            $table->string('document_no', 64);
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('inv_warehouses')->restrictOnDelete();

            /*
             * কোন বিলের বিপরীতে ফেরত — ঐচ্ছিক।
             *
             * বেশিরভাগ ফেরত একটা বিল ধরেই যায়, আর তখন "যত কেনা হয়েছে
             * তার বেশি ফেরত" আটকানো যায়। কিন্তু পুরনো মাল ফেরত দিতে
             * হলে বিলটা কেউ খুঁজে পায় না — তখনও ফেরত পাঠাতে হয়, নাহলে
             * মালটা গুদাম ছাড়ত কিন্তু খাতায় থেকে যেত।
             */
            $table->foreignId('purchase_bill_id')->nullable()
                ->constrained('pur_bills')->nullOnDelete();

            // কেন ফেরত — নষ্ট, মেয়াদ, নাকি ভুল মাল
            $table->foreignId('reason_code_id')->nullable()
                ->constrained('mdm_reason_codes')->nullOnDelete();

            $table->date('trx_date');

            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('tax', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);

            $table->string('status', 32)->default(DocumentStatus::DRAFT);
            $table->text('narration')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'document_no']);
            $table->index(['company_id', 'supplier_id', 'status']);
            $table->index(['company_id', 'trx_date']);
        });

        Schema::create('pur_return_lines', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('purchase_return_id')->constrained('pur_returns')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->restrictOnDelete();

            // কোন লাইনের বিপরীতে — থাকলে "যত কেনা তার বেশি নয়" আটকানো যায়
            $table->foreignId('purchase_bill_line_id')->nullable()
                ->constrained('pur_bill_lines')->nullOnDelete();

            $table->decimal('qty', 18, 4);
            $table->decimal('rate', 18, 4);
            $table->decimal('tax', 18, 4)->default(0);
            $table->decimal('amount', 18, 4);

            $table->unsignedSmallInteger('line_no');
            $table->timestamps();

            $table->index(['purchase_return_id', 'line_no']);
            $table->index('product_id');
            $table->index('purchase_bill_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pur_return_lines');
        Schema::dropIfExists('pur_returns');
    }
};
