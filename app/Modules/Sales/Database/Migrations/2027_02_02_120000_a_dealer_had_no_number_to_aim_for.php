<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * প্রতি ডিলারের মাসিক আদায়ের লক্ষ্য — মালিক, ৩ অক্টোবর ২০২৬ (বিলের "টার্গেট রিমাইন্ডার")।
 *
 * ⓘ লক্ষ্য আগে ছিল কেবল বিক্রয়কর্মীর (`sal_targets`) আর রুটের (`sal_route_targets`) — ডিলারের নিজের কোনো সংখ্যা
 * ছিল না, তাই বিলে "এ মাসে কত দেওয়ার কথা, কত দিয়েছেন" বলার উপায় ছিল না। প্রতি ডিলার প্রতি মাসে এক সারি:
 * লক্ষ্যের টাকা আর শেষ তারিখ (যেমন ২৫ তারিখ)। অর্জন সারিতে লেখা হয় না — প্রতিবার খাতা থেকে গোনা
 * ([[CustomerTargetService::reminderFor()]]), যাতে রসিদ বাতিল হলে সংখ্যাটা নিজেই ঠিক থাকে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sal_customer_targets', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies', 'id', 'sal_ct_company_fk')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers', 'id', 'sal_ct_customer_fk')->cascadeOnDelete();
            $table->date('month');
            $table->decimal('amount', 18, 4);
            $table->date('closes_on');
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'sal_ct_user_fk')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'customer_id', 'month'], 'sal_ct_unique');
            $table->index(['company_id', 'month'], 'sal_ct_month_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sal_customer_targets');
    }
};
