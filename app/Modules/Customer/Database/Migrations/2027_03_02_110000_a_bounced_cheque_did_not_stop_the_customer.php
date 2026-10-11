<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ বাকি বন্ধ কোথা থেকে — হাতে, না চেক ফেরতে (মালিকের উত্তর ৪, ১১ অক্টোবর ২০২৬; বাকি নিয়ন্ত্রণ ধাপ ২.৪)।
 *
 * ⓘ মালিক: *"চেক ফেরত এলে গ্রাহক নিজে থেকেই বন্ধ, আর খোলেন কেবল আমি"*। হাতে বসানো বন্ধ আগের মতোই সীমা বদলানোর চাবিতে
 * খোলে; চেক ফেরতের বন্ধ খোলে কেবল সুপার অ্যাডমিন — তাই কোনটা কোথা থেকে, সারিতেই লেখা থাকা চাই।
 * ⓘ খালি-যোগ্য নতুন ঘর: আজকের বন্ধগুলো খালি থাকে = হাতে বসানো (আজ পর্যন্ত সেটাই একমাত্র পথ ছিল)।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('customers', 'credit_block_kind')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('credit_block_kind', 24)->nullable()->after('credit_block_reason');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('customers', 'credit_block_kind')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('credit_block_kind');
        });
    }
};
