<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * গ্রাহক — মডিউলের নিজের টেবিল, নিজের ফোল্ডারে (সেকশন ১৯.১)।
 *
 * নামের দুইটা কলাম, কারণ গ্রাহকের নাম ইনভয়েসে ছাপা হয় আর বাংলা ইনভয়েসে
 * ইংরেজি নাম ছাপলে গ্রাহক নিজের নামই পড়তে পারে না (সেকশন ১৮.৩)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('code', 32);
            $table->string('name_en', 191);
            $table->string('name_bn', 191)->nullable();

            $table->string('phone', 32)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('address_en', 500)->nullable();
            $table->string('address_bn', 500)->nullable();

            // পক্ষের ধরন খোলা তালিকা (নিয়ম ৮) — খুচরা, পাইকারি, প্রাতিষ্ঠানিক…
            // কোম্পানি নিজের মতো যোগ করতে পারবে, তাই enum নয়।
            $table->string('customer_type', 32)->nullable();

            // ক্রেডিট লিমিট — DECIMAL, কারণ FLOAT-এ সীমার তুলনা করলে
            // ঠিক সীমায় থাকা বিলও কখনো কখনো আটকে যায়।
            $table->decimal('credit_limit', 18, 4)->default(0);
            $table->unsignedSmallInteger('credit_days')->default(0);

            $table->decimal('opening_balance', 18, 4)->default(0);
            $table->date('opening_date')->nullable();

            // হিসাবের খাত — গ্রাহকের পাওনা যে খাতে বসবে। Accounts মডিউলের
            // টেবিলে ফরেন কি দেওয়া হয়নি (সেকশন ১৯.৭): এক মডিউল আরেকটার
            // টেবিলে হাত দিলে পরে একটা সরানো যায় না।
            $table->unsignedBigInteger('receivable_account_id')->nullable();

            // নিয়ম ৫ — সফট ডিলিট ও স্ট্যাটাস
            $table->string('status', 16)->default('confirmed');
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'name_en']);
            $table->index(['company_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
