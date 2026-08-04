<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * নগদ কাউন্টার — কার হাতে কত টাকা।
 *
 * "নগদ" একটা খাত নয়, অনেকগুলো। একটা ডিপোতে ক্যাশিয়ারের হাতে টাকা
 * থাকে, প্রতিটা ডেলিভারি ম্যানের হাতে টাকা থাকে, মালিকের হাতেও থাকে।
 * সব এক খাতে রাখলে দিনশেষে মোটটা মিলত, কিন্তু কার কাছে কত তা জানার
 * উপায় থাকত না — আর ঠিক ওখানেই টাকা হারায়।
 *
 * প্রতিটা টিলের নিজের হিসাব-খাত থাকে, "১১০১ হাতে নগদ"-এর নিচে। ফলে
 * লেজার নিজেই বলে দেয় টাকাটা কার কাছে, আর হস্তান্তর মানে দুইটা খাতের
 * মধ্যে সাধারণ একটা এন্ট্রি — নতুন কোনো ব্যবস্থা লাগে না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_tills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // এই টিলের হিসাব-খাত — টাকার হিসাব লেজারেই থাকে, এখানে নয়।
            // আলাদা "balance" কলাম রাখলে সেটা একদিন লেজারের সাথে অমিল
            // হত, আর কোনটা সত্যি তা বলার উপায় থাকত না।
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();

            $table->string('code', 32);
            $table->string('name_en', 120);
            $table->string('name_bn', 120)->nullable();

            /*
             * কার হেফাজতে।
             *
             * nullable, কারণ সব টিলের একজন নির্দিষ্ট মালিক থাকে না —
             * "প্রধান ক্যাশ" প্রতিষ্ঠানের, কারও ব্যক্তিগত নয়। কিন্তু
             * ডেলিভারি ম্যানের টিল অবশ্যই তার নামে, নাহলে "কার কাছে কত"
             * প্রশ্নের উত্তরটাই থাকে না।
             */
            $table->foreignId('holder_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * সীমা — এর বেশি হাতে রাখা যাবে না।
             *
             * শূন্য মানে সীমাহীন, বন্ধ নয় (গ্রাহকের ক্রেডিট সীমার মতোই)।
             * সীমা ছাড়ালে জমা দিতে বলা হয়; আটকানো হয় না, কারণ বিকেলে
             * আদায় বেশি হলে সেটা কারও দোষ নয়।
             */
            $table->decimal('limit_amount', 18, 4)->default(0);

            /*
             * একটা প্রতিষ্ঠানে একটাই প্রধান টিল — যেখানে দিনশেষে সবাই
             * জমা দেয়। ডিফল্ট গন্তব্য হিসেবে দরকার।
             */
            $table->boolean('is_primary')->default(false);

            $table->string('status', 16)->default('confirmed');
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'holder_id']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_tills');
    }
};
