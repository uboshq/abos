<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * টাকা হস্তান্তর ও নগদ গণনা।
 *
 * দুইটাই কন্ট্রা ভাউচার দিয়ে করা যেত — হিসাবের দিক থেকে হস্তান্তর মানে
 * এক নগদ খাত থেকে আরেকটায় টাকা যাওয়া, আর গণনার তো কোনো হিসাবই নেই যদি
 * মিলে যায়। তবু আলাদা, কারণ কাগজটা আলাদা:
 *
 * হস্তান্তরে দুইজন মানুষ থাকে — যে দিল আর যে নিল — আর দুইজনের সইওয়ালা
 * একটা স্লিপ ছাপা হয়। কন্ট্রা ভাউচারে ওই দুইটা নাম রাখার জায়গাই নেই,
 * আর নাম না থাকলে টাকা হারালে কার কাছে তা বলা যায় না।
 *
 * গণনায় নোটের হিসাব থাকে — কয়টা ১০০০, কয়টা ৫০০। মিলে গেলে কোনো এন্ট্রি
 * হয় না; না মিললে পার্থক্যটা একটা জাবেদা হয়ে বসে, আর তখন সেটা কার
 * কাউন্টারে কত কম তা স্পষ্ট থাকে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('financial_year_id')->constrained('financial_years')->cascadeOnDelete();

            $table->string('document_no', 64);
            $table->date('trx_date');

            // কোন কাউন্টার থেকে কোন কাউন্টারে — অথবা ব্যাংকে
            $table->foreignId('from_till_id')->nullable()->constrained('cash_tills')->nullOnDelete();
            $table->foreignId('to_till_id')->nullable()->constrained('cash_tills')->nullOnDelete();

            // ব্যাংকে জমা দিলে গন্তব্য একটা খাত, কাউন্টার নয়
            $table->foreignId('to_account_id')->nullable()->constrained('accounts')->nullOnDelete();

            /*
             * দুইজন মানুষ — স্লিপের দুইটা সই।
             *
             * টিলের holder থেকে অনুমান করা যেত, কিন্তু বাস্তবে যিনি দেন
             * তিনি সবসময় টিলের মালিক নন (ছুটির দিনে অন্যজন দেয়)। কে
             * সত্যিই হাতে হাতে দিল সেটাই কাগজে থাকা দরকার।
             */
            $table->foreignId('given_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('amount', 18, 4);
            $table->string('narration', 500)->nullable();

            /*
             * দুই ধাপ: দেওয়া হয়েছে, তারপর নেওয়া হয়েছে।
             *
             * এক ধাপে করলে "আমি দিয়েছি" বললেই টাকা অন্যের হিসাবে চলে যেত,
             * অথচ সে হয়তো এখনো পায়নি। পথে টাকা হারালে তখন দুইজনেই বলত
             * অন্যজনের কাছে। তাই গ্রহণ নিশ্চিত না হওয়া পর্যন্ত টাকাটা
             * দাতার হিসাবেই থাকে।
             */
            $table->string('status', 16)->default('draft');
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'document_no']);
            $table->index(['company_id', 'trx_date']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('cash_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('financial_year_id')->constrained('financial_years')->cascadeOnDelete();

            $table->string('document_no', 64);
            $table->date('trx_date');

            $table->foreignId('cash_till_id')->constrained('cash_tills')->cascadeOnDelete();

            // গুনে যা পাওয়া গেল, আর খাতায় যা থাকার কথা
            $table->decimal('counted_amount', 18, 4)->default(0);
            $table->decimal('expected_amount', 18, 4)->default(0);
            $table->decimal('difference', 18, 4)->default(0);

            /*
             * নোটের হিসাব — {"1000": 12, "500": 8, ...}
             *
             * শুধু মোট টাকাটা রাখলে গণনাটা যাচাই করা যেত না। নোটের ভাঙতি
             * থাকলে পরে মেলানো যায়, আর ক্যাশিয়ার নিজেও গুনতে গুনতে
             * ভুল ধরতে পারে।
             */
            $table->json('denominations')->nullable();

            // পার্থক্য থাকলে যে জাবেদাটা বসেছে
            $table->foreignId('adjustment_voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();

            $table->string('narration', 500)->nullable();
            $table->string('status', 16)->default('draft');

            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'document_no']);
            $table->index(['company_id', 'cash_till_id', 'trx_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_counts');
        Schema::dropIfExists('money_transfers');
    }
};
