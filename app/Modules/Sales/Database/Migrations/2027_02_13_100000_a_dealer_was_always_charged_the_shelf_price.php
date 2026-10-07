<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * একজন ডিলার সবসময় তাকের দামই দিতেন — দর তালিকার পণ্যপ্রতি দর (মালিক, ৫ অক্টোবর ২০২৬: আন্তর্জাতিক মান, SAP-এর ধাঁচ)।
 *
 * ⓘ `mdm_price_lists` এতদিন কেবল নাম ছিল; প্রতিটা বিক্রির পর্দা পণ্যের একটা `sale_price`-ই নিত।
 * ⭐ এখন একটা তালিকা ঠিক একজনের জন্য — একজন গ্রাহক (`customer_id`), এক ধরনের গ্রাহক (আগের `party_type_id`),
 * এক এলাকা (`location_id`, তার নিচের সব দোকানসহ), নাহলে সবার — আর তার সারিতে পণ্য (+ ঐচ্ছিক একক/প্যাক) → দর,
 * মেয়াদসহ। কোনটা খাটবে তা [[SalesPrice]] বলে।
 *
 * ⚠️ সারির টেবিল বিক্রয়ের (`sal_`), MasterData-র নয়: MasterData কেবল accounts চেনে, আর সারি পণ্য ও গ্রাহক চেনে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mdm_price_lists', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->after('party_type_id')
                ->constrained('customers', 'id', 'mdm_pl_customer_fk')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->after('customer_id')
                ->constrained('mdm_locations', 'id', 'mdm_pl_location_fk')->nullOnDelete();
        });

        Schema::create('sal_price_list_items', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies', 'id', 'sal_pli_company_fk')->cascadeOnDelete();
            $table->foreignId('price_list_id')->constrained('mdm_price_lists', 'id', 'sal_pli_list_fk')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products', 'id', 'sal_pli_product_fk')->cascadeOnDelete();

            // ⓘ খালি মানে পণ্যের নিজের একক; বসানো থাকলে দরটা ঐ এককের (বাক্স, কার্টন)
            $table->foreignId('unit_id')->nullable()->constrained('mdm_units', 'id', 'sal_pli_unit_fk')->nullOnDelete();

            $table->decimal('price', 18, 4);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'sal_pli_user_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'product_id', 'valid_from'], 'sal_pli_product_idx');
            $table->index(['price_list_id', 'product_id'], 'sal_pli_list_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sal_price_list_items');

        Schema::table('mdm_price_lists', function (Blueprint $table): void {
            $table->dropForeign('mdm_pl_customer_fk');
            $table->dropForeign('mdm_pl_location_fk');
            $table->dropColumn(['customer_id', 'location_id']);
        });
    }
};
