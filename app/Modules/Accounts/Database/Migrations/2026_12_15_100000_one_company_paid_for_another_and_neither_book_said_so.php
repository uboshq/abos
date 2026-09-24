<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * এক কোম্পানি আরেকজনের খরচ দিল, আর কোনো খাতাই সেটা বলল না।
 *
 * ── ⭐ মালিকের প্রশ্ন, ২৫ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"গ্রুপের অ্যাকাউন্ট থেকে টাকা নিলে?"* — ADI-র অ্যাকাউন্ট থেকে TCL-এর
 * খরচ দেওয়া রোজকার ঘটনা, কিন্তু আজ ওটা লেখার কোনো পথ নেই। ⓘ ফলে হয়
 * ADI-তে একটা খরচ বসে যায় যেটা আসলে TCL-এর, নাহলে কিছুই বসে না আর
 * টাকাটা হাওয়া হয়ে যায়।
 *
 * ── ⚠️ কেন আলাদা টেবিল, কেবল দুইটা ভাউচার নয় ────────────────────────
 * দুই কোম্পানিতে দুইটা ভাউচার বসালেই হিসাব মিলে যায়, কিন্তু **তারা
 * একসাথে যে এক লেনদেন, সেটা কোথাও লেখা থাকে না**। ⛔ তখন একটা বাতিল
 * হলে অন্যটা একা দাঁড়িয়ে থাকত, আর মেলানোর কোনো উপায় থাকত না।
 *
 * ⓘ এই সারিটাই জোড়াটার একমাত্র সাক্ষী — কে দিল, কে পেল, কত, আর দুই
 * পাশের ভাউচার কোনগুলো।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_inter_company', function (Blueprint $table) {
            $table->id();

            /*
             * ⓘ প্রতিটা ব্যবসায়িক টেবিলেই এটা লাগে — পাহারা দেয়
             * [[PublicIdTest::test_every_business_table_carries_a_public_id]]।
             * ⚠️ ভুলে গেলে পাহারাটা লাল হত, আর কারণটা এই ফাইলে নয়,
             * ওখানে গিয়ে বুঝতে হত।
             */
            $table->uuid('public_id')->unique();

            /*
             * ⓘ `company_id` — যে কোম্পানি **টাকা দিল**।
             * ⚠️ [[BelongsToCompany]] এই ঘরটাই ছাঁকে, তাই তালিকা খুললে
             * নিজের কোম্পানির দেওয়া লেনদেনগুলোই দেখা যায়।
             */
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            /*
             * ⛔ যে কোম্পানি **পেল** — আর এটাই দেয়াল পেরোনোর একমাত্র ঘর।
             *
             * ⚠️ তাই এখানে যেকোনো কোম্পানি বসানো চলবে না: সেবা স্তরে
             * যাচাই হয় যে **দুইটা কোম্পানিতেই ব্যবহারকারীর সদস্যপদ আছে**
             * (`company_user` পিভট)। ⓘ নাহলে এক ক্রেতা আরেক ক্রেতার
             * খাতায় দাখিলা লিখে ফেলতে পারত।
             */
            $table->foreignId('counter_company_id')->constrained('companies')->cascadeOnDelete();

            $table->date('trx_date');
            $table->decimal('amount', 18, 4);

            /*
             * ⓘ কেন দেওয়া হলো — মানুষের ভাষায়।
             *
             * ⚠️ ঘরটা বাধ্যতামূলক, আর সেটা ইচ্ছাকৃত: ছয় মাস পরে
             * "ADI → TCL ৫০,০০০" সারিটা দেখে কেউ বলতে পারবে না কেন,
             * আর তখন দুই পক্ষের হিসাবরক্ষক দুইরকম ব্যাখ্যা দেন।
             */
            $table->string('purpose', 500);

            /*
             * ⓘ দুই পাশের ভাউচার। খসড়া অবস্থায় দুইটাই `null`।
             * ⚠️ `nullOnDelete` নয় — `restrictOnDelete`: একটা ভাউচার
             * মুছে গেলে জোড়াটা আধখানা হয়ে যেত, আর সারিটা মিথ্যা বলত।
             */
            $table->foreignId('out_voucher_id')->nullable()->constrained('vouchers')->restrictOnDelete();
            $table->foreignId('in_voucher_id')->nullable()->constrained('vouchers')->restrictOnDelete();

            $table->string('status', 16)->default('draft');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /*
             * ⚠️ নামগুলো হাতে ছোট করে দেওয়া।
             *
             * ⛔ Laravel নিজে নাম বানালে হত
             * `acc_inter_company_company_id_counter_company_id_status_index` —
             * ৬৪ অক্ষরের সীমা ছাড়িয়ে, আর তাতে **সবার** `migrate:fresh`
             * ভাঙত, কেবল আমার নয় ([[long-index-names-break-every-session]])।
             */
            $table->index(['company_id', 'status'], 'acc_ic_co_status');
            $table->index(['counter_company_id', 'status'], 'acc_ic_counter_status');
            $table->index('trx_date', 'acc_ic_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_inter_company');
    }
};
