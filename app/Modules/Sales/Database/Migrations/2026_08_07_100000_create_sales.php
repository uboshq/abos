<?php

declare(strict_types=1);

use App\Core\Support\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * বিক্রয়ের চারটা ডকুমেন্ট: আদেশ → চালান → বিল → আদায়।
 *
 * ক্রয়ের মতোই প্রতিটার নিজের টেবিল, আর একই কারণে: সম্পর্কগুলো এক-থেকে-এক
 * নয়। এক অর্ডারের মাল তিন গাড়িতে যায়, এক বিলে দুই চালানের মাল থাকে, আর
 * কাউন্টার বিক্রিতে অর্ডার-চালান কিছুই থাকে না — শুধু বিল।
 *
 * আদায়টা আলাদা রকম: একটা টাকা কয়েকটা বিলের বিপরীতে ভাগ হয়ে যেতে পারে,
 * তাই ওর লাইনগুলো পণ্যের নয়, বিলের।
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── বিক্রয় আদেশ ─────────────────────────────────────────────────
        Schema::create('sal_orders', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('financial_year_id')->nullable()
                ->constrained('financial_years')->nullOnDelete();

            $table->string('document_no', 64);
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()
                ->constrained('inv_warehouses')->nullOnDelete();

            $table->date('trx_date');
            $table->date('deliver_on')->nullable();

            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('discount', 18, 4)->default(0);
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
            $table->index(['company_id', 'customer_id', 'status']);
            $table->index(['company_id', 'trx_date']);
        });

        Schema::create('sal_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('sales_order_id')->constrained('sal_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->restrictOnDelete();

            $table->decimal('ordered_qty', 18, 4);
            $table->decimal('rate', 18, 4);
            $table->decimal('discount', 18, 4)->default(0);
            $table->decimal('tax', 18, 4)->default(0);
            $table->decimal('amount', 18, 4);

            $table->unsignedSmallInteger('line_no');
            $table->text('narration')->nullable();
            $table->timestamps();

            $table->index(['sales_order_id', 'line_no']);
            $table->index('product_id');
        });

        // ── ডেলিভারি চালান ──────────────────────────────────────────────
        Schema::create('sal_challans', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('financial_year_id')->nullable()
                ->constrained('financial_years')->nullOnDelete();

            $table->string('document_no', 64);
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('inv_warehouses')->restrictOnDelete();
            $table->foreignId('sales_order_id')->nullable()
                ->constrained('sal_orders')->nullOnDelete();

            $table->date('trx_date');

            // গাড়ি ও চালকের নাম — গেটপাসে ছাপা হয়, আর "মালটা কার সাথে
            // গেল" প্রশ্নের উত্তরও এটাই
            $table->string('vehicle_no', 64)->nullable();
            $table->string('driver_name', 191)->nullable();

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
            $table->index(['company_id', 'customer_id', 'status']);
            $table->index(['company_id', 'trx_date']);
            $table->index('sales_order_id');
        });

        Schema::create('sal_challan_lines', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('delivery_challan_id')->constrained('sal_challans')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->restrictOnDelete();
            $table->foreignId('sales_order_line_id')->nullable()
                ->constrained('sal_order_lines')->nullOnDelete();

            $table->decimal('delivered_qty', 18, 4);
            $table->decimal('rate', 18, 4);
            $table->decimal('amount', 18, 4);

            $table->unsignedSmallInteger('line_no');
            $table->text('narration')->nullable();
            $table->timestamps();

            $table->index(['delivery_challan_id', 'line_no']);
            $table->index('product_id');
            $table->index('sales_order_line_id');
        });

        // ── বিক্রয় বিল ──────────────────────────────────────────────────
        Schema::create('sal_invoices', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('financial_year_id')->nullable()
                ->constrained('financial_years')->nullOnDelete();

            $table->string('document_no', 64);
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()
                ->constrained('inv_warehouses')->nullOnDelete();

            $table->date('trx_date');
            $table->date('due_on')->nullable();

            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('discount', 18, 4)->default(0);
            $table->decimal('tax', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);

            /*
             * বিক্রীত পণ্যের ব্যয় — বিলের সময় হিসাব করে জমা রাখা হয়।
             *
             * এটা ব্যতিক্রম: বাকি সব সংখ্যা লেজার থেকে গোনা হয়। কিন্তু
             * খরচটা নির্ভর করে *ওই মুহূর্তের* ক্রয়মূল্যের উপর, আর সেটা
             * পরে বদলায়। পরে গুনলে গত মাসের মুনাফা আজ অন্যরকম দেখাত।
             */
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

        Schema::create('sal_invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('sales_invoice_id')->constrained('sal_invoices')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->restrictOnDelete();
            $table->foreignId('delivery_challan_line_id')->nullable()
                ->constrained('sal_challan_lines')->nullOnDelete();

            $table->decimal('qty', 18, 4);
            $table->decimal('rate', 18, 4);
            $table->decimal('discount', 18, 4)->default(0);
            $table->decimal('tax', 18, 4)->default(0);
            $table->decimal('amount', 18, 4);

            // এই লাইনের মালের ক্রয়মূল্য — বিলের মোট খরচের ভিত্তি
            $table->decimal('unit_cost', 18, 4)->default(0);

            $table->unsignedSmallInteger('line_no');
            $table->text('narration')->nullable();
            $table->timestamps();

            $table->index(['sales_invoice_id', 'line_no']);
            $table->index('product_id');
            $table->index('delivery_challan_line_id');
        });

        // ── আদায় ────────────────────────────────────────────────────────
        Schema::create('sal_collections', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('financial_year_id')->nullable()
                ->constrained('financial_years')->nullOnDelete();

            $table->string('document_no', 64);
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();

            // টাকাটা কোথায় ঢুকল — কারও টিলে, নাকি ব্যাংকে
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();

            $table->date('trx_date');
            $table->decimal('amount', 18, 4);

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
            $table->index(['company_id', 'customer_id', 'status']);
            $table->index(['company_id', 'trx_date']);
        });

        /*
         * আদায়ের লাইন — কোন বিলের বিপরীতে কত।
         *
         * এক আদায় কয়েকটা বিলে ভাগ হতে পারে, আর সেটাই স্বাভাবিক: গ্রাহক
         * এক লাখ টাকা দিলেন, তাতে তিনটা পুরনো বিল শোধ হলো আর চতুর্থটা
         * আংশিক। ভাগটা না রাখলে "কোন বিলটা এখনো বাকি" প্রশ্নের উত্তর
         * থাকত না — শুধু মোট বকেয়া জানা যেত।
         */
        Schema::create('sal_collection_lines', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('collection_id')->constrained('sal_collections')->cascadeOnDelete();
            $table->foreignId('sales_invoice_id')->constrained('sal_invoices')->restrictOnDelete();

            $table->decimal('amount', 18, 4);
            $table->unsignedSmallInteger('line_no');
            $table->timestamps();

            $table->index(['collection_id', 'line_no']);
            $table->index('sales_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sal_collection_lines');
        Schema::dropIfExists('sal_collections');
        Schema::dropIfExists('sal_invoice_lines');
        Schema::dropIfExists('sal_invoices');
        Schema::dropIfExists('sal_challan_lines');
        Schema::dropIfExists('sal_challans');
        Schema::dropIfExists('sal_order_lines');
        Schema::dropIfExists('sal_orders');
    }
};
