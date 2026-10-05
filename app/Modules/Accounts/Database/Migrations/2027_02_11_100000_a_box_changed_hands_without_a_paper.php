<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ক্যাশবাক্সের দায়িত্ব বদল কাগজ ছাড়া — Accounts-Finance অডিট ম৮, ৪ অক্টোবর ২০২৬ (৬৩-এর সিদ্ধান্ত, আন্তর্জাতিক ধারা: শিফট
 * হ্যান্ডওভারে জের গুনে সই)।
 *
 * ⛔ আগে বাক্সের সম্পাদনায় হেফাজতকারী চুপচাপ বদলাত: কার হাত থেকে কার হাতে কত টাকা গেল তার কোনো কাগজ থাকত না, আর আগের
 * জনের সময়ের ঘাটতি নতুন জনের ঘাড়ে পড়ত। ⓘ এখন প্রতিটা বদল একটা কাগজ — খাতার জের, গোনা টাকা (দিলে), পার্থক্য আর তার
 * নগদ গণনার কাগজ, দুজনের নাম, আর সই।
 *
 * ⓘ MariaDB-নিরাপদ: নতুন টেবিল; প্রতিটা সূচক আর FK-র নাম হাতে দেওয়া, ৬৪ অক্ষরের অনেক নিচে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_till_handovers', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies', indexName: 'till_handovers_company_fk')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches', indexName: 'till_handovers_branch_fk')->nullOnDelete();
            $table->string('document_no', 64);
            $table->date('trx_date');
            $table->foreignId('cash_till_id')->constrained('cash_tills', indexName: 'till_handovers_till_fk')->restrictOnDelete();
            $table->foreignId('from_holder_id')->nullable()->constrained('users', indexName: 'till_handovers_from_fk')->nullOnDelete();
            $table->foreignId('to_holder_id')->constrained('users', indexName: 'till_handovers_to_fk')->restrictOnDelete();

            // ⓘ খাতার জের হস্তান্তরের মুহূর্তে; গোনা টাকা দিলে তার অঙ্ক আর পার্থক্য — পার্থক্য খাতায় যায় নগদ গণনার পথে
            $table->decimal('book_balance', 18, 4);
            $table->decimal('counted_amount', 18, 4)->nullable();
            $table->decimal('difference', 18, 4)->default(0);
            $table->foreignId('cash_count_id')->nullable()->constrained('cash_counts', indexName: 'till_handovers_count_fk')->nullOnDelete();

            $table->string('narration', 500)->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'till_handovers_creator_fk')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users', indexName: 'till_handovers_confirmer_fk')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'document_no'], 'till_handovers_number_unique');
            $table->index(['company_id', 'cash_till_id', 'status'], 'till_handovers_till_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_till_handovers');
    }
};
