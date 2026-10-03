<?php

declare(strict_types=1);

use App\Modules\Sales\Support\DeliveryOrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ⭐ ডেলিভারি অর্ডার (DO) — নিজের কাগজ, নিজের ক্রম, নিজের অনুমোদন (মালিকের চূড়ান্ত বিক্রয়-ধারা, ২ অক্টোবর ২০২৬;
 * docs/বিক্রয়ের কাজের ধারা — ২ অক্টোবর.md §২)।
 *
 * ⓘ এতদিন "DO = চালান" (২৮ সেপ্টেম্বর) — আলাদা কাগজ ছিল না। এখন DO চালানের আগের ধাপ: ডিলার/SR/উপরের সবাই
 * লেখেন, সুপারভাইজার (কোম্পানির ছক) অনুমোদন দেন, হিসাব নিজে যাচাই করে (abos-86), ডিপো যাচাই করে বিল বানায়
 * (abos-bb)। অবস্থার নাম তিনজনের মিলিয়ে ঠিক করা — [[DeliveryOrderStatus]]।
 *
 * ⓘ সমন্বয়কের অনুমোদন (abos-63): দুই টেবিল, কেবল যোগ; index/FK-এর নাম ছোট (৬৪ অক্ষরের সীমা); abos-86-এর
 * চারটা হিসাবের কলাম এই একই মাইগ্রেশনে — দুই মাইগ্রেশন একই টেবিল ছোঁয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sal_delivery_orders', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('financial_year_id')->nullable()->constrained('financial_years')->nullOnDelete();
            $table->string('document_no', 64);

            // ⓘ অর্ডারের রেফারেন্স — ঐচ্ছিক (মালিক: "অর্ডারের রেফারেন্সসহ বা ছাড়া")
            $table->foreignId('sales_order_id')->nullable()->constrained('sal_orders')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('inv_warehouses')->nullOnDelete();
            $table->date('trx_date');
            $table->date('deliver_on')->nullable();

            $table->string('status', 24)->default(DeliveryOrderStatus::DRAFT);
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->string('narration', 500)->nullable();

            // ⓘ কে লিখলেন — কর্মী, নাকি ডিলার নিজে (পোর্টাল/অ্যাপ)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_customer_id')->nullable()->constrained('customers', 'id', 'sal_do_by_customer_fk')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();

            // ⭐ abos-86-এর হিসাবের যাচাই — কেবল ওঁর সেবা লেখে
            $table->decimal('accounts_short', 18, 4)->nullable();
            $table->timestamp('accounts_held_at')->nullable();
            $table->timestamp('accounts_checked_at')->nullable();
            $table->json('accounts_warnings')->nullable();

            // ⭐ abos-bb বসায় বিলের মুহূর্তে
            $table->foreignId('sales_invoice_id')->nullable()->constrained('sal_invoices')->nullOnDelete();

            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'document_no'], 'sal_do_no_unique');
            $table->index(['company_id', 'status', 'trx_date'], 'sal_do_status_idx');
            $table->index(['company_id', 'customer_id'], 'sal_do_customer_idx');
        });

        Schema::create('sal_delivery_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('delivery_order_id')->constrained('sal_delivery_orders')->cascadeOnDelete();
            $table->foreignId('sales_order_line_id')->nullable()->constrained('sal_order_lines')->nullOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->restrictOnDelete();

            // ⓘ যা চাওয়া হলো; সুপারভাইজার মজুদ দেখে বদলালে approved_qty — চূড়ান্ত = approved_qty ?? qty
            $table->decimal('qty', 18, 4);
            $table->decimal('approved_qty', 18, 4)->nullable();
            $table->decimal('rate', 18, 4)->default(0);
            $table->decimal('discount_percent', 8, 4)->default(0);
            $table->decimal('free_qty', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index('delivery_order_id', 'sal_dol_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sal_delivery_order_lines');
        Schema::dropIfExists('sal_delivery_orders');
    }
};
