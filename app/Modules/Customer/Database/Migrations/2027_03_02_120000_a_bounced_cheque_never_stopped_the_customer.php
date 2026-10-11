<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ "বাকি বন্ধ" কোথা থেকে এল — হাতে, না চেক ফেরতে (বাকি নিয়ন্ত্রণ ধাপ ২.৪, মালিকের উত্তর ৪, ১১ অক্টোবর ২০২৬)।
 *
 * ⓘ মালিক: *"চেক ফেরত গেলে গ্রাহকের বাকি নিজেই বন্ধ, আর খুলবেন কেবল মালিক — টাকা দিলেও না, সময় গেলেও না, অন্য কেউও না"*।
 * হাতে বসানো বন্ধ আগের মতোই সীমার চাবিওয়ালা তুলতে পারেন; চেক ফেরতের বন্ধ কেবল সুপার অ্যাডমিন। তাই কোনটা কী, সারিতেই লেখা।
 *
 * ⓘ নতুন ঘর, খালি = হাতে বসানো (আজ পর্যন্ত সব বন্ধই হাতের) — কোনো চলতি সারির মান বদলায় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers') || Schema::hasColumn('customers', 'credit_block_source')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('credit_block_source', 20)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('customers') || ! Schema::hasColumn('customers', 'credit_block_source')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('credit_block_source');
        });
    }
};
