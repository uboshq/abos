<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⛔ বিলের নাম ছাড়া নেওয়া টাকা কোনো বিলের "বাকি" কমাত না — মালিক, ২৫ নভেম্বর ২০২৬: বিলওয়াইজ পরিশোধের বাধ্যবাধকতা নেই, লেজারই হিসাব,
 *   বিলে অটো বসবে ([[AutoDues]])।
 *
 * ⓘ online: কেবল একটা নতুন টেবিল, আর সেটা **গোনা অঙ্ক** — খাতা থেকে যেকোনো সময় আবার বানানো যায়; কোনো রসিদ, বিল বা খাতার সারি ছোঁয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sal_auto_dues', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('sales_invoice_id')->unique('sal_ad_invoice_once')->constrained('sal_invoices', 'id', 'sal_ad_invoice_fk')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies', 'id', 'sal_ad_company_fk')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers', 'id', 'sal_ad_customer_fk')->cascadeOnDelete();
            $table->decimal('settled', 18, 4)->default(0);
            $table->index(['company_id', 'customer_id'], 'sal_ad_customer_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sal_auto_dues');
    }
};
