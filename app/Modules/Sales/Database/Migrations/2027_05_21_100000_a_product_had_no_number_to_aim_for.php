<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * প্রতি পণ্যের মাসিক বিক্রির লক্ষ্য — মালিক, ২৩ নভেম্বর ২০২৬ (সমন্বয়কের মারফত: "লক্ষ্য বনাম বিক্রয়, পণ্য ধরে" রিপোর্টের জন্য)।
 *
 * ⓘ লক্ষ্য আগে ছিল বিক্রয়কর্মীর (`sal_targets`), ডিলারের (`sal_customer_targets`) আর রুটের (`sal_route_targets`) — পণ্যের নিজের কোনো
 * সংখ্যা ছিল না, তাই "কোন পণ্য লক্ষ্যের কত পেল" বলার উপায় ছিল না। প্রতি পণ্য প্রতি মাসে এক সারি: লক্ষ্যের পরিমাণ আর লক্ষ্যের টাকা
 * (যেকোনো একটা বা দুটোই)। অর্জন সারিতে লেখা হয় না — প্রতিবার বিলের লাইন থেকে গোনা, যাতে বিল বাতিল বা ফেরত হলে সংখ্যা নিজেই ঠিক থাকে।
 *
 * ⓘ লক্ষ্য গোটা কোম্পানির — শাখা, এলাকা বা জন ধরে ভাগ নেই: ওই ভাগের লক্ষ্য আগের তিন টেবিলেই আছে, আর একই সংখ্যা দুই জায়গায় বসালে
 * কোনটা ঠিক তা নিয়ে তর্ক হত।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sal_product_targets', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies', 'id', 'sal_pt_company_fk')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products', 'id', 'sal_pt_product_fk')->cascadeOnDelete();
            $table->date('month');
            $table->decimal('qty', 18, 4)->default(0);
            $table->decimal('amount', 18, 4)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'sal_pt_user_fk')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'product_id', 'month'], 'sal_pt_unique');
            $table->index(['company_id', 'month'], 'sal_pt_month_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sal_product_targets');
    }
};
