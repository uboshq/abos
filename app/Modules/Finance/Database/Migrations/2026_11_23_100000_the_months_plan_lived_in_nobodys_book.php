<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * মাসের পরিকল্পনা কারো খাতায় ছিল না।
 *
 * ── কেন, ২০ সেপ্টেম্বর ২০২৬ ──────────────────────────────────────────
 * ফিন্যান্সের মানচিত্রে §১৬ (বাজেট পরিকল্পনা, বাজেট বনাম প্রকৃত,
 * বিভাগভিত্তিক বাজেট) পুরো ফাঁকা ছিল — কোনো টেবিলই ছিল না। ⓘ খরচ আর আয়
 * খাতায় ঠিকই বসে; কিন্তু "এই মাসে ভাড়ায় কত যাওয়ার কথা" কোথাও লেখা ছিল
 * না, তাই "বেশি গেল কি না" প্রশ্নের উত্তরও ছিল না।
 *
 * ── এক সারি = এক খাত × এক মাস × (ঐচ্ছিক) এক বিভাগ ────────────────────
 * ⓘ বিভাগ = খরচের কেন্দ্র (`acc_cost_centers`), যেটা খতিয়ানের প্রতিটা
 * সারিতে আগে থেকেই আছে (`ledger_entries.cost_center_id`) — তাই "প্রকৃত"
 * সরাসরি খতিয়ান থেকেই পড়া যায়, আলাদা কোনো হিসাব রাখতে হয় না।
 *
 * ⚠️ অনন্যতা (কোম্পানি, বছর, মাস, খাত, বিভাগ) সার্ভিসে, ডেটাবেসে নয়:
 * MySQL-এ unique ইনডেক্স NULL-কে আলাদা ধরে, তাই "বিভাগ ছাড়া" বাজেট
 * দুইবার বসে যেত আর মোট দ্বিগুণ দেখাত।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_budgets', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('acc_cost_centers')->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'year', 'month']);
            $table->index(['company_id', 'account_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_budgets');
    }
};
