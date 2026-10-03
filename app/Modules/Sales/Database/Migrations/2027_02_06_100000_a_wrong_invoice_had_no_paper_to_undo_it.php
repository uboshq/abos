<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * বাতিল-ইনভয়েস (Cancellation Invoice) — মালিক, ৪ অক্টোবর ২০২৬: আন্তর্জাতিক মান।
 *
 * ⓘ ভুল পাকা ইনভয়েস মোছা হয় না ([[SalesInvoiceService::assertNotPosted()]] — ২ অক্টোবরের নিয়ম); তার বদলে নিজের নম্বরের
 * (CXL-xxxx) একটা পুরো উল্টো কাগজ। আসল ইনভয়েস থাকে, পাশে "বাতিল-ইনভয়েস: CXL-…" লেখা। এক ইনভয়েসের একটাই বাতিল
 * (`sales_invoice_id` একক) — বাতিলের বাতিল নেই, ভুল হলে নতুন ইনভয়েস।
 *
 * ⓘ অঙ্কগুলো ইনভয়েসেরই — বাতিলের মুহূর্তের ছবি (ছাপায় বিয়োগ ছাড়া, "বাতিল" শিরোনামে); সারি আলাদা নেই, ছাপা ইনভয়েসের
 * সারি থেকে আঁকে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sal_invoice_cancellations', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies', 'id', 'sal_cxl_company_fk')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches', 'id', 'sal_cxl_branch_fk')->nullOnDelete();
            $table->foreignId('sales_invoice_id')->constrained('sal_invoices', 'id', 'sal_cxl_invoice_fk')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers', 'id', 'sal_cxl_customer_fk')->nullOnDelete();

            $table->string('document_no', 64);
            $table->date('trx_date');
            $table->string('reason', 500);

            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('tax', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);

            // ⓘ খসড়া → সইয়ের অপেক্ষা → পাকা; পাকা হওয়ার মুহূর্তেই খাতা আর মজুদ উল্টায়
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'sal_cxl_creator_fk')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users', 'id', 'sal_cxl_confirmer_fk')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'document_no'], 'sal_cxl_number_once');
            $table->unique(['sales_invoice_id'], 'sal_cxl_one_per_invoice');
            $table->index(['company_id', 'status', 'trx_date'], 'sal_cxl_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sal_invoice_cancellations');
    }
};
