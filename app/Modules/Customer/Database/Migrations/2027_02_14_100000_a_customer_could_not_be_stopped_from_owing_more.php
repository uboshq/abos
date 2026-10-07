<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ "বাকি বন্ধ" — একজন গ্রাহকের নতুন বাকি হাতে বন্ধ করা, বাকি ও আদায় (SAP Credit Management-এর "credit block"),
 * ৫ অক্টোবর ২০২৬ ([[CustomerService::blockCredit()]], [[CreditExposure::stopsFor()]])।
 *
 * ⓘ পতাকা = `credit_blocked_at` ভরা। কে আর কেন পাশেই — আর প্রতিটা বসানো-তোলা নিরীক্ষার খাতায় ওঠে।
 * ⓘ nullable, পুরনো সারিতে কিছু বসে না: আজ কেউ বন্ধ নন।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->timestamp('credit_blocked_at')->nullable();
            $table->foreignId('credit_blocked_by')->nullable()
                ->constrained('users', 'id', 'customers_credit_blocked_by_fk')->nullOnDelete();
            $table->string('credit_block_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropForeign('customers_credit_blocked_by_fk');
            $table->dropColumn(['credit_blocked_at', 'credit_blocked_by', 'credit_block_reason']);
        });
    }
};
