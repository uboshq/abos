<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * টেন্যান্সির ভিত্তি — কোম্পানি ও শাখা।
 *
 * প্ল্যান সেকশন ৫: প্রতিটা টেবিলে company_id, প্রযোজ্য হলে branch_id, আর সেটা
 * global scope দিয়ে আপনাআপনি ফিল্টার হবে। সেই স্কোপ যে কলামের উপর দাঁড়াবে,
 * সেটা এখানেই তৈরি হয় — তাই এটাই প্রথম মাইগ্রেশন।
 *
 * নাম দুই কলামে (name_en / name_bn) কারণ কোম্পানি ও শাখার নাম ইনভয়েসের
 * মাথায় ছাপা হয়, আর বাংলা ইনভয়েসে ইংরেজি নাম ছাপলে সেটা গ্রাহকের কাগজ হয় না
 * — সেকশন ১৮.৩।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();

            // লগইনে কোম্পানি চেনার জন্য — সাবডোমেইন বা কোড, কখনো ড্রপডাউনে
            // সবার নাম দেখিয়ে নয় (সেকশন ১৬.৩, Zero Trust)।
            $table->string('code', 32)->unique();

            $table->string('name_en', 191);
            $table->string('name_bn', 191)->nullable();
            $table->string('legal_name', 191)->nullable();

            $table->string('address_en', 500)->nullable();
            $table->string('address_bn', 500)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('website', 191)->nullable();

            // বাংলাদেশে ইনভয়েসে ছাপাতে হয়
            $table->string('bin', 32)->nullable();
            $table->string('tin', 32)->nullable();

            $table->string('logo_path', 255)->nullable();
            $table->char('currency', 3)->default('BDT');
            $table->string('locale', 5)->default('bn');
            $table->string('timezone', 64)->default('Asia/Dhaka');

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 32);
            $table->string('name_en', 191);
            $table->string('name_bn', 191)->nullable();

            $table->string('address_en', 500)->nullable();
            $table->string('address_bn', 500)->nullable();
            $table->string('phone', 64)->nullable();

            // একটা ডিপোর তিনটা শাখা থাকলে ডকুমেন্ট কোনটার নামে হবে, সেটা
            // এখান থেকেই ঠিক হয়; DMS-এ এটা না থাকায় সব এন্ট্রি একটাতেই যেত।
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('financial_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name', 32);            // "2026-2027"
            $table->date('starts_on');
            $table->date('ends_on');

            // বন্ধ বছরে কোনো এন্ট্রি নয় — সেকশন ৫। বন্ধ করার পর খোলা যায়,
            // কিন্তু সেটা অনুমোদন-সাপেক্ষ কাজ, তাই কে কখন খুলল তা রাখা হয়।
            $table->boolean('is_closed')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_current')->default(false);

            $table->timestamps();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_years');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('companies');
    }
};
