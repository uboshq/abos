<?php

declare(strict_types=1);

use App\Core\Support\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ক্রয়ের তিনটা ডকুমেন্ট: আদেশ → মাল বুঝে নেওয়া → বিল।
 *
 * ── কেন প্রতিটার নিজের টেবিল ──────────────────────────────────────────
 * একটা টেবিলে status দিয়ে তিনটাই রাখা যেত ('ordered' → 'received' →
 * 'billed')। কিন্তু বাস্তবে সম্পর্কটা এক-থেকে-এক নয়:
 *
 *   এক আদেশের মাল তিন কিস্তিতে আসে (তিনটা GRN)
 *   এক বিলে দুই চালানের মাল থাকে   (এক বিল, দুই GRN)
 *   বিল ছাড়াই মাল আসে             (GRN, কোনো PO নেই)
 *
 * এক সারিতে চাপালে তৃতীয় কিস্তিটা লিখতে গিয়ে হয় আগেরটা মুছতে হত, নয়
 * ভুয়া আদেশ বানাতে হত।
 *
 * ── পরিমাণের কলাম কেন আছে, অথচ স্টকে নেই ─────────────────────────────
 * Inventory-তে কোনো পরিমাণের কলাম নেই — "আছে কত" সবসময় চলাচলের যোগফল।
 * এখানে উল্টো: `ordered_qty` একটা ঘোষণা, যোগফল নয়। "কত আনতে বলেছি" প্রশ্নের
 * উত্তর কোনো চলাচল থেকে গোনা যায় না, কারণ আদেশ দিলে কিছুই নড়ে না।
 *
 * `received_qty` কিন্তু গোনা হয় — GRN-এর লাইনগুলো থেকে — আর সেজন্যই
 * আদেশের লাইনে ওটা জমা রাখা হয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── ক্রয় আদেশ ───────────────────────────────────────────────────
        Schema::create('pur_orders', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('financial_year_id')->nullable()
                ->constrained('financial_years')->nullOnDelete();

            $table->string('document_no', 64);
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()
                ->constrained('inv_warehouses')->nullOnDelete();

            /*
             * লেনদেনের তারিখ ও লেখার তারিখ আলাদা (Global Features নিয়ম ৩)।
             *
             * সোমবারের আদেশ বুধবার লিখলে খাতায় সোমবারই থাকতে হবে, নাহলে
             * পুরনো তারিখের রিপোর্ট প্রতিবার বদলে যেত।
             */
            $table->date('trx_date');
            $table->date('expected_on')->nullable();

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
            $table->index(['company_id', 'supplier_id', 'status']);
            $table->index(['company_id', 'trx_date']);
        });

        Schema::create('pur_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('purchase_order_id')->constrained('pur_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->restrictOnDelete();

            $table->decimal('ordered_qty', 18, 4);
            $table->decimal('rate', 18, 4);
            $table->decimal('discount', 18, 4)->default(0);
            $table->decimal('tax', 18, 4)->default(0);
            $table->decimal('amount', 18, 4);

            $table->unsignedSmallInteger('line_no');
            $table->text('narration')->nullable();
            $table->timestamps();

            $table->index(['purchase_order_id', 'line_no']);
            $table->index('product_id');
        });

        // ── মাল বুঝে নেওয়া ──────────────────────────────────────────────
        Schema::create('pur_receipts', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('financial_year_id')->nullable()
                ->constrained('financial_years')->nullOnDelete();

            $table->string('document_no', 64);
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();

            // গুদাম বাধ্যতামূলক — মাল কোথায় নামল তা না জানলে "কোন গুদামে
            // কত আছে" প্রশ্নের উত্তর থাকে না
            $table->foreignId('warehouse_id')->constrained('inv_warehouses')->restrictOnDelete();

            // আদেশ ছাড়াও মাল আসে, তাই ঐচ্ছিক — সেটিংস দিয়ে বাধ্যতামূলক করা যায়
            $table->foreignId('purchase_order_id')->nullable()
                ->constrained('pur_orders')->nullOnDelete();

            $table->date('trx_date');

            // সরবরাহকারীর নিজের চালান নম্বর — আমাদের নয়, তাই আলাদা ঘর
            $table->string('supplier_challan_no', 64)->nullable();

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
            $table->index('purchase_order_id');
        });

        Schema::create('pur_receipt_lines', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('purchase_receipt_id')->constrained('pur_receipts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->restrictOnDelete();

            // কোন আদেশের লাইনের বিপরীতে — এটাই "আদেশের কত বাকি" গোনার ভিত্তি
            $table->foreignId('purchase_order_line_id')->nullable()
                ->constrained('pur_order_lines')->nullOnDelete();

            $table->decimal('received_qty', 18, 4);
            $table->decimal('rate', 18, 4);
            $table->decimal('amount', 18, 4);

            $table->unsignedSmallInteger('line_no');
            $table->text('narration')->nullable();
            $table->timestamps();

            $table->index(['purchase_receipt_id', 'line_no']);
            $table->index('product_id');
            $table->index('purchase_order_line_id');
        });

        // ── ক্রয় বিল ────────────────────────────────────────────────────
        Schema::create('pur_bills', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('financial_year_id')->nullable()
                ->constrained('financial_years')->nullOnDelete();

            $table->string('document_no', 64);
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();

            $table->date('trx_date');
            $table->date('due_on')->nullable();

            // সরবরাহকারীর নিজের বিল নম্বর — একই সরবরাহকারী একই নম্বর দুইবার
            // পাঠালে সেটা ধরা পড়া দরকার, নাহলে একই বিল দুইবার শোধ হয়
            $table->string('supplier_bill_no', 64)->nullable();

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
            $table->index(['company_id', 'supplier_id', 'status']);
            $table->index(['company_id', 'trx_date']);
            $table->index(['company_id', 'supplier_id', 'supplier_bill_no']);
        });

        Schema::create('pur_bill_lines', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('purchase_bill_id')->constrained('pur_bills')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->restrictOnDelete();

            /*
             * কোন চালানের লাইনের বিপরীতে।
             *
             * এটাই ২১৬০ খাতটা শূন্যে ফিরিয়ে আনার একমাত্র উপায়: মাল নেওয়ার
             * সময় যে টাকাটা ওখানে বসেছিল, বিলের সময় ঠিক সেই টাকাটাই সরাতে
             * হবে। বিলের নিজের দাম ধরে সরালে দাম বদলালে খাতটায় একটা অবশিষ্ট
             * পড়ে থাকত, আর সেটা কারও চোখে পড়ত না।
             */
            $table->foreignId('purchase_receipt_line_id')->nullable()
                ->constrained('pur_receipt_lines')->nullOnDelete();

            $table->decimal('qty', 18, 4);
            $table->decimal('rate', 18, 4);
            $table->decimal('discount', 18, 4)->default(0);
            $table->decimal('tax', 18, 4)->default(0);
            $table->decimal('amount', 18, 4);

            $table->unsignedSmallInteger('line_no');
            $table->text('narration')->nullable();
            $table->timestamps();

            $table->index(['purchase_bill_id', 'line_no']);
            $table->index('product_id');
            $table->index('purchase_receipt_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pur_bill_lines');
        Schema::dropIfExists('pur_bills');
        Schema::dropIfExists('pur_receipt_lines');
        Schema::dropIfExists('pur_receipts');
        Schema::dropIfExists('pur_order_lines');
        Schema::dropIfExists('pur_orders');
    }
};
