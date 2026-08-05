<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * পক্ষ — যাদের সাথে ব্যবসা হয়।
 *
 * একটাই টেবিল, ক্রেতা-সরবরাহকারী-পরিবহন সবার জন্য, আর ধরনটা কলামে। কারণ
 * একই দোকান একই সাথে ক্রেতা ও সরবরাহকারী হতে পারে — কেউ মাল কেনে, আবার
 * খালি বোতল ফেরত বেচে। আলাদা টেবিল হলে সেই দোকানের দুটো রেকর্ড, দুটো
 * ঠিকানা, দুটো ফোন নম্বর, আর দুটো হিসাব — যার একটা সবসময় পুরনো।
 *
 * ── DMS-এর মাইগ্রেশন থেকে যা শেখা হয়েছে ────────────────────────────────
 * এই কলামগুলো অনুমান নয়। পুরোনো সিস্টেম থেকে ৩১৮ জন ক্রেতা আনার সময় ঠিক
 * এগুলোই লেগেছিল, আর যেগুলো ছিল না সেগুলোর অভাবেই সারি হারিয়েছিল:
 *
 *   • মোবাইল নম্বর কোম্পানিভেদে অনন্য — এটাই একই দোকান দুবার ঢোকা ধরেছিল
 *     (তিনটে ক্ষেত্রে)। তবে <b>দুটো দোকান একটা হাতসেটও ভাগ করে</b>, তাই
 *     নিয়মটা কড়া হলে আসল দোকান বাদ পড়ে — সেজন্য nullable।
 *   • মালিকের নাম আর যোগাযোগের নাম আলাদা: "M/S Apon Enterprise"-এর মালিক
 *     একজন, ফোন ধরেন আরেকজন।
 *   • ঠিকানা বাংলায় থাকে — ডেলিভারি ম্যান ওটাই পড়ে।
 *   • বাকির শর্ত তিনটে কলাম লাগে, একটা নয়: ধরন, দিনের সংখ্যা, আর মাসের
 *     কোন তারিখ। "৩০ দিন" আর "প্রতি মাসের ২৫ তারিখ" এক সংখ্যায় বলা যায় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            // বাইরের জগতের কী — প্রতিটা ব্যবসায়িক টেবিলে বাধ্যতামূলক;
            // PublicIdTest রেজিস্ট্রি ঘুরে দেখে, তাই বাদ পড়লে ধরা পড়ে
            $table->publicId();

            // টেন্যান্সি — সেকশন ৫। শাখা: যে শাখা রেকর্ডটা তৈরি করেছে।
            // "কে কে এই দোকানে যায়" আলাদা প্রশ্ন, আর তার উত্তর party_branch-এ।
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            // কোম্পানির নিজের সিরিজ থেকে — নম্বর টাইপ করা হয় না, ইস্যু হয়।
            $table->string('code', 32);

            $table->string('type', 24)->default('customer');

            // দোকানের নাম, দুই ভাষায়। বাংলা ইনভয়েসে ইংরেজি নাম ছাপলে সেটা
            // গ্রাহকের কাগজ হয় না — সেকশন ১৮.৩।
            $table->string('name_en', 191);
            $table->string('name_bn', 191)->nullable();

            // মালিক আর যিনি ফোন ধরেন — এক নন।
            $table->string('proprietor', 191)->nullable();
            $table->string('contact_person', 191)->nullable();

            // একটা ঘর, একটা নম্বর। পুরোনো সিস্টেমে দুটো নম্বর এক ঘরে
            // লেখা থাকত ("01724465380 01833190901"), আর সেটাই আনার সময়
            // চারটে সারি ভাঙিয়েছিল — নম্বরটা আর নামটা একসাথে পড়া হচ্ছিল।
            $table->string('mobile', 32)->nullable();
            $table->string('mobile_alt', 32)->nullable();
            $table->string('email', 191)->nullable();

            $table->string('address_bn', 500)->nullable();
            $table->string('address_en', 500)->nullable();

            // বাংলাদেশে ইনভয়েসে ছাপাতে হয়
            $table->string('trade_licence', 64)->nullable();
            $table->string('bin', 32)->nullable();
            $table->string('nid', 32)->nullable();

            // ── বাকি ও তার শর্ত ────────────────────────────────────────
            // শূন্য মানে সীমা নেই, null নয় — "সীমা দেওয়া হয়নি" আর "যত
            // খুশি" এক কথা নয়, আর প্রথমটা একটা প্রশ্ন।
            $table->decimal('credit_limit', 14, 2)->default(0);

            // days | end_of_month | day_of_month | last_banking_day
            $table->string('credit_term', 32)->default('days');
            // days আর end_of_month-এর জন্য দিনের সংখ্যা; null মানে শর্ত নেই
            $table->unsignedSmallInteger('credit_days')->nullable();
            // day_of_month-এর জন্য মাসের তারিখ (১–৩১)। আলাদা কলাম, কারণ
            // এক কলামে "৩০ দিন পরে" আর "৩০ তারিখে" রাখলে সেটা একবারই ভুল
            // পড়া হয় — যে রিপোর্ট পরে লিখবে তার হাতে।
            $table->unsignedTinyInteger('credit_due_day')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // কোড কোম্পানিভেদে অনন্য — দুই কোম্পানির দুটো C-0001 থাকতেই পারে।
            $table->unique(['company_id', 'code']);
            // নামও: একই নামে দুটো দোকান থাকলে কাউন্টারে যে কোনো একটা বেছে
            // নেওয়া হয়, আর একটার বিক্রি দুটোর বিক্রি হয়ে যায়।
            $table->unique(['company_id', 'type', 'name_en']);
            $table->index(['company_id', 'type', 'is_active']);
            $table->index(['company_id', 'mobile']);
        });

        /*
         * কোন কোন শাখা এই দোকানে যায়।
         *
         * parties.branch_id বলে কে রেকর্ডটা বানিয়েছে — সেটা অন্য প্রশ্ন।
         * DMS-এ এই দুটো এক ধরে নেওয়ায় আটটা দোকান একটা করে শাখার চোখের
         * আড়ালে চলে গিয়েছিল: এক কোম্পানির তিনটে ব্যবসা একই দোকানে মাল
         * দেয়, দোকানটার সারি একটাই, আর সেটা যে ব্যবসা আগে ঢুকিয়েছে তার
         * শাখা বহন করে। একটা দোকান একটা শাখার — এই ধারণাটাই ভুল।
         */
        Schema::create('party_branch', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['party_id', 'branch_id']);
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_branch');
        Schema::dropIfExists('parties');
    }
};
