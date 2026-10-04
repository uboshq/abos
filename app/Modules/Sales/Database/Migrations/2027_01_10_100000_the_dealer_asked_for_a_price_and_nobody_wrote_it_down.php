<?php

declare(strict_types=1);

use App\Core\Support\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ডিলার দর জানতে চাইলেন, আর দরটা কোথাও লেখা থাকল না।
 *
 * ── ⭐ NEXUS §৮ — বিক্রয় উদ্ধৃতি (কোটেশন) ───────────────────────────────
 * দর বলা হত ফোনে বা কাগজের টুকরোয়। ⚠️ ডিলার রাজি হলে আদেশটা আবার হাতে
 * লিখতে হত, আর দুই কাগজের দর মিলত কি না কেউ দেখত না — ভুলটা ধরা পড়ত
 * বিলের দিন, ডিলারের সামনে।
 *
 * ⭐ এখন দরটা নিজের কাগজ: মেয়াদসহ (`valid_until`), আর রাজি হলে এক চাপে
 * হুবহু একই সারি ও দরে আদেশ হয়।
 *
 * ── ⓘ আদেশের দিকে একটা ঘর — `sales_quotation_id` ──────────────────────
 * আদেশ জানে সে কোন উদ্ধৃতি থেকে এসেছে। ⛔ **ইউনিক**: একই উদ্ধৃতি থেকে
 * দুইটা আদেশ ডাটাবেসই আটকায় — সেবার তালা ([[SalesQuotationService::convert()]])
 * ফসকালেও দ্বিতীয় সারিটা বসবে না। NULL-এর জন্য ইউনিক খাটে না, তাই সাধারণ
 * আদেশগুলো আগের মতোই।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sal_quotations', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('financial_year_id')->nullable()
                ->constrained('financial_years')->nullOnDelete();

            $table->string('document_no', 64);

            // ⛔ কেবল গ্রাহক — সম্ভাব্য ক্রেতা (prospect) CRM-এর কাজ, পরে
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();

            $table->date('trx_date');
            $table->date('valid_until');

            // ⓘ দর তালিকা কেবল নাম — পণ্যপ্রতি দর এখনো তালিকায় নেই (mdm_price_lists)
            $table->foreignId('price_list_id')->nullable()
                ->constrained('mdm_price_lists')->nullOnDelete();
            $table->foreignId('payment_term_id')->nullable()
                ->constrained('mdm_payment_terms')->nullOnDelete();
            $table->string('delivery_terms', 500)->nullable();

            $table->decimal('subtotal', 18, 4)->default(0);

            // ⓘ মোট ছাড় — সারির ছাড় + পুরো কাগজের ছাড়; দ্বিতীয়টা আলাদা করেও রাখা
            $table->decimal('discount', 18, 4)->default(0);
            $table->decimal('header_discount', 18, 4)->default(0);
            $table->decimal('tax', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);

            $table->string('status', 32)->default(DocumentStatus::DRAFT);
            $table->text('narration')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->string('answer_note', 500)->nullable();

            $table->foreignId('sales_order_id')->nullable()
                ->constrained('sal_orders')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'document_no'], 'sal_quotations_company_no_unique');
            $table->index(['company_id', 'customer_id', 'status'], 'sal_quotations_customer_status_idx');
            $table->index(['company_id', 'valid_until'], 'sal_quotations_valid_until_idx');
        });

        Schema::create('sal_quotation_lines', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('sales_quotation_id')->constrained('sal_quotations')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->restrictOnDelete();

            $table->decimal('qty', 18, 4);
            $table->decimal('entered_qty', 18, 4)->nullable();
            $table->foreignId('entered_unit_id')->nullable()->constrained('mdm_units')->nullOnDelete();

            $table->decimal('rate', 18, 4);
            $table->decimal('discount', 18, 4)->default(0);

            // ⓘ পুরো কাগজের ছাড়ের এই সারির ভাগ — আদেশে গিয়ে সারির ছাড়ে যোগ হয়
            $table->decimal('header_share', 18, 4)->default(0);
            $table->decimal('tax', 18, 4)->default(0);
            $table->decimal('tax_variance', 18, 4)->nullable();
            $table->decimal('amount', 18, 4);

            $table->unsignedSmallInteger('line_no');
            $table->text('narration')->nullable();
            $table->timestamps();

            $table->index(['sales_quotation_id', 'line_no'], 'sal_quotation_lines_doc_line_idx');
            $table->index('product_id', 'sal_quotation_lines_product_idx');
        });

        Schema::table('sal_orders', function (Blueprint $table): void {
            $table->foreignId('sales_quotation_id')->nullable()->after('customer_id')
                ->constrained('sal_quotations')->nullOnDelete();
            $table->unique('sales_quotation_id', 'sal_orders_quotation_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sal_orders', function (Blueprint $table): void {
            $table->dropForeign(['sales_quotation_id']);
            $table->dropUnique('sal_orders_quotation_unique');
            $table->dropColumn('sales_quotation_id');
        });

        Schema::dropIfExists('sal_quotation_lines');
        Schema::dropIfExists('sal_quotations');
    }
};
