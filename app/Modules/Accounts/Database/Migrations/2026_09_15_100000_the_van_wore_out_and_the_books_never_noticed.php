<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * স্থায়ী সম্পদ ও অবচয়।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * হিসাবের ছকে খাতগুলো আগে থেকেই ছিল — ১২০০ স্থায়ী সম্পদ, ১২৯০ সঞ্চিত
 * অবচয়, ৫২১২ অবচয় খরচ। কিন্তু **কোনো খাতা ছিল না**: কোন গাড়ি, কবে
 * কেনা, কত দামে, কত বছরের আয়ু — কিছুই কোথাও লেখা হত না।
 *
 * ফলে অবচয় বসতও না। আর অবচয় না বসলে দুইটা মিথ্যা একসাথে চলে: ডেলিভারি
 * ভ্যানটা কেনার দিনের দামেই খাতায় বসে থাকে যতদিন না বিক্রি হয়, আর
 * বছরের মুনাফা ঠিক ওই ক্ষয়ের পরিমাণ বেশি দেখায়।
 *
 * দ্বিতীয়টা বেশি ক্ষতিকর: বেশি মুনাফা দেখে বেশি টাকা তোলা হয়, আর
 * ভ্যানটা বদলানোর দিন টাকাটা থাকে না।
 *
 * ── দুইটা টেবিল, আর কেন দুইটাই দরকার ────────────────────────────────
 * সম্পদের খাতা বলে জিনিসটা কী। অবচয়ের সারিগুলো বলে কোন মাসে কতটা
 * ক্ষয় ধরা হয়েছে — আর ওই ইতিহাসটা না থাকলে "এই মাসেরটা কি আগেই বসানো
 * হয়েছে" প্রশ্নের উত্তর থাকত না, আর একই মাস দুইবার বসে যেত।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('document_no', 32);
            $table->string('name', 191);
            $table->string('tag_no', 64)->nullable();

            /* কোন খাতে বসেছে — ১২০১ আসবাব, ১২০২ যানবাহন… */
            $table->foreignId('asset_account_id')->constrained('accounts');
            $table->foreignId('accumulated_account_id')->constrained('accounts');
            $table->foreignId('expense_account_id')->constrained('accounts');

            $table->decimal('cost', 18, 4);

            /*
             * বাতিল মূল্য — আয়ু শেষে যেটুকু দামে বিক্রি হবে বলে ধরা।
             *
             * ওটুকুর উপর অবচয় বসে না; বসালে খাতায় জিনিসটার দাম শূন্য
             * হয়ে যেত, অথচ ভাঙারির দোকানে ওটার এখনো দাম আছে।
             */
            $table->decimal('salvage', 18, 4)->default(0);

            $table->date('acquired_on');

            /*
             * পদ্ধতি — সরলরৈখিক, নাকি ক্রমহ্রাসমান।
             *
             * দুইটাই দেশে চলে, আর একই জিনিসে দুইটা খুব আলাদা সংখ্যা
             * দেয়। কোনটা তা কোম্পানির নীতির কথা; আমরা অনুমান করি না।
             */
            $table->string('method', 16);

            /* সরলরৈখিকে আয়ু, ক্রমহ্রাসমানে হার — একটা থাকলে অন্যটা লাগে না। */
            $table->unsignedSmallInteger('life_months')->nullable();
            $table->decimal('rate', 8, 4)->nullable();

            $table->string('status', 16)->default('active');
            $table->date('disposed_on')->nullable();
            $table->decimal('disposal_amount', 18, 4)->nullable();

            $table->string('narration', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'document_no']);
            $table->index(['company_id', 'status', 'acquired_on']);
        });

        Schema::create('acc_depreciation_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained('acc_fixed_assets')->cascadeOnDelete();

            /*
             * কোন মাসের অবচয় — মাসের শেষ দিনটা।
             *
             * মাস ধরে রাখা হয়, দিন ধরে নয়। অবচয় একটা মাসিক হিসাব;
             * দিন ধরে বসালে ফেব্রুয়ারি আর মার্চ আলাদা অঙ্ক দিত শুধু
             * দিনসংখ্যার কারণে, আর কেউ ব্যাখ্যা করতে পারত না।
             */
            $table->date('period_end');
            $table->decimal('amount', 18, 4);

            $table->string('document_no', 40)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /*
             * একই সম্পদের একই মাস দুইবার বসতে পারে না।
             *
             * এটাই এই টেবিলের সবচেয়ে দরকারি লাইন। অবচয় চালানো হয়
             * মাস শেষে, হাতে — আর হাতে চালানো জিনিস একদিন দুইবার চলে।
             * দুইবার বসলে খরচ দ্বিগুণ, মুনাফা কম, আর সম্পদের দাম দ্রুত
             * শূন্যের দিকে নামে। কেউ ধরতে পারে না, কারণ প্রতিটা সারিই
             * দেখতে বৈধ।
             */
            $table->unique(['fixed_asset_id', 'period_end'], 'acc_dep_once_per_month');
            $table->index(['company_id', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_depreciation_entries');
        Schema::dropIfExists('acc_fixed_assets');
    }
};
