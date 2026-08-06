<?php

declare(strict_types=1);

use App\Core\Support\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * সরবরাহকারীকে পরিশোধ — আদায়ের আয়না।
 *
 * ── কেন এটা এতদিন ছিল না, আর না থাকায় কী ভাঙত ────────────────────────
 * বিক্রয়ে আদায় ছিল: টাকা আসত, আর কোন বিলের বিপরীতে কত বসল তা লেখা
 * থাকত। ক্রয়ে তার উল্টো দিকটা ছিল না। সরবরাহকারীকে টাকা দিতে হলে
 * জার্নাল ভাউচার কাটতে হত, আর তাতে খতিয়ানে প্রদেয় কমত ঠিকই — কিন্তু
 * *কোন বিলটা* শোধ হলো তা কোথাও লেখা থাকত না।
 *
 * ফল: "কোন বিলগুলো এখনো বাকি" প্রশ্নের উত্তর কখনো মিলত না। মোট প্রদেয়
 * জানা যেত, কিন্তু সরবরাহকারীর সাথে বসে মেলানোর সময় কোন চালানটা বাকি
 * তা দুই পক্ষই আন্দাজ করত।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pur_payments', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('financial_year_id')->nullable()
                ->constrained('financial_years')->nullOnDelete();

            $table->string('document_no', 64);
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();

            // টাকাটা কোথা থেকে গেল — কারও টিল থেকে, নাকি ব্যাংক থেকে
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();

            $table->date('trx_date');
            $table->decimal('amount', 18, 4);

            /*
             * চেক বা MFS-এর তথ্য।
             *
             * নগদে খালি থাকে। চেকে নম্বর ও তারিখ দুইটাই দরকার — সরবরাহকারী
             * ফোন করে "চেকটা কবে দিয়েছিলেন" জিজ্ঞেস করলে খুঁজে বের করতে
             * হয়, আর সেটা ব্যাংকের কাগজে নয়, এখানেই থাকা উচিত।
             */
            $table->string('instrument', 32)->nullable();
            $table->string('instrument_no', 64)->nullable();
            $table->date('instrument_date')->nullable();

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

        /*
         * পরিশোধের লাইন — কোন বিলের বিপরীতে কত।
         *
         * এক পরিশোধ কয়েকটা বিলে ভাগ হতে পারে, আর ডিপোতে সেটাই স্বাভাবিক:
         * মাস শেষে এক চেকে সাত-আটটা চালানের টাকা যায়।
         */
        Schema::create('pur_payment_lines', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('pur_payments')->cascadeOnDelete();
            $table->foreignId('purchase_bill_id')->constrained('pur_bills')->restrictOnDelete();

            $table->decimal('amount', 18, 4);
            $table->unsignedSmallInteger('line_no');
            $table->timestamps();

            $table->index(['payment_id', 'line_no']);
            $table->index('purchase_bill_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pur_payment_lines');
        Schema::dropIfExists('pur_payments');
    }
};
