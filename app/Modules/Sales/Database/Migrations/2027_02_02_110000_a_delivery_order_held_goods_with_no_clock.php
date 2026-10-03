<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ডেলিভারি অর্ডারের মাল আটকানো — কোন DO-র জন্য, কতটা, কতক্ষণ। বিক্রয়ের কাজের ধারা, ধাপ ঘ (৩ অক্টোবর ২০২৬)।
 *
 * ⭐ মালিকের নিয়ম: সুপারভাইজারের অনুমোদনের পরে ২৪ ঘণ্টা কড়া আটকানো (অন্য কেউ বেচতে পারে না), তারপর আরও ২ দিন কেবল
 * দেখানো (বিক্রি চলে), ৭২ ঘণ্টায় টাকা না এলে নিজে ছাড়; হিসাবে অনুমোদিত হলে আবার কড়া, বিল না হওয়া পর্যন্ত।
 *
 * ⓘ কড়া আটকানোর আসল জোর মজুদের খাতায় (`inv_stock_movements.reserved_change`) — এই টেবিল বলে **কার জন্য** আর
 * **কতক্ষণ**: `firm_until` (কড়া কবে নামবে), `expires_at` (কবে ছাড়)। দুইটাই null মানে বিল পর্যন্ত কড়া।
 * সারি মোছে না — ছাড় হলে `released_at` আর কারণ বসে, তাই পরে বলা যায় মাল কেন ফিরল।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sal_do_stock_holds', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies', 'id', 'sal_dosh_company_fk')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches', 'id', 'sal_dosh_branch_fk')->nullOnDelete();
            $table->foreignId('delivery_order_id')->constrained('sal_delivery_orders', 'id', 'sal_dosh_do_fk')->cascadeOnDelete();
            $table->foreignId('delivery_order_line_id')->constrained('sal_delivery_order_lines', 'id', 'sal_dosh_line_fk')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products', 'id', 'sal_dosh_product_fk')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('inv_warehouses', 'id', 'sal_dosh_wh_fk')->restrictOnDelete();

            // ⓘ যত চাওয়া, আর যত পাওয়া গেল — কম হলে সতর্কবার্তা "অর্ডার কমান"
            $table->decimal('wanted_qty', 18, 4);
            $table->decimal('qty', 18, 4);

            $table->string('kind', 8);
            $table->timestamp('held_at');
            $table->timestamp('firm_until')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason', 16)->nullable();
            $table->decimal('consumed_qty', 18, 4)->default(0);
            $table->timestamps();

            $table->index(['company_id', 'delivery_order_id'], 'sal_dosh_do_idx');
            $table->index(['company_id', 'product_id', 'warehouse_id', 'released_at'], 'sal_dosh_stock_idx');
            $table->index(['released_at', 'firm_until', 'expires_at'], 'sal_dosh_clock_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sal_do_stock_holds');
    }
};
