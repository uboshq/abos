<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ব্যাংক মিলকরণ — খাতা আর ব্যাংকের কাগজের তফাতটার ব্যাখ্যা।
 *
 * ── কেন এটা লাগে ────────────────────────────────────────────────────
 * খাতার ব্যাংক-জের আর ব্যাংকের কাগজের জের প্রায় কখনোই সমান হয় না, আর
 * সেটাই স্বাভাবিক: চেক লিখে দিয়েছি কিন্তু ভাঙানো হয়নি, জমা দিয়েছি
 * কিন্তু পাশ হয়নি, ব্যাংক চার্জ কেটেছে যেটা আমরা এখনো লিখিনি।
 *
 * তফাতটা থাকা দোষ নয়। তফাতটার **ব্যাখ্যা না থাকাই** দোষ — কারণ তখন
 * একটা ভুল এন্ট্রি বা একটা চুরি ব্যাংকের কাগজে ধরা পড়ত, অথচ কেউ কাগজটা
 * খাতার সাথে মেলায় না বলে মাসের পর মাস বসে থাকে।
 *
 * ── টিক পড়ে ভাউচার লাইনে, চেকে নয় ───────────────────────────────────
 * লোভনীয় ভুলটা হলো চেকের তালিকা ধরে মেলানো, কারণ চেকই তো দেরি করে।
 * কিন্তু ব্যাংক হিসাবের ভেতর দিয়ে চেক ছাড়াও অনেক কিছু যায়: ব্যাংক
 * চার্জ, সুদ, MFS-এ পাঠানো, এক হিসাব থেকে আরেকটায় সরানো, সরাসরি জমা।
 * চেক ধরে মেলালে ওগুলো কোনোদিন টিক পড়ত না, আর মিলকরণ চিরকাল এমন তফাত
 * দেখাত যার কোনো ব্যাখ্যা নেই।
 *
 * ভাউচার লাইন ধরলে চেকও এমনিতেই ঢুকে যায়, কারণ ChequeService::clear()
 * পাশ হওয়ার তারিখে ভাউচার বসায়। একটা তালিকা, সব রকম লেনদেন।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('bank_account_id')->constrained('accounts')->cascadeOnDelete();

            /* ব্যাংকের কাগজের শেষ তারিখ, আর ওই কাগজে লেখা জের। */
            $table->date('statement_date');
            $table->decimal('statement_balance', 18, 4);

            $table->string('status', 16)->default('draft');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            $table->string('narration', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            /*
             * একই হিসাবের একই তারিখে দুইটা মিলকরণ থাকতে পারে না।
             *
             * থাকলে দুইটা আলাদা ব্যাখ্যা তৈরি হত একই সত্যের, আর কোনটা
             * সত্যি সেটা বলার কোনো উপায় থাকত না।
             */
            $table->unique(['company_id', 'bank_account_id', 'statement_date'], 'acc_bank_recon_unique');
            $table->index(['company_id', 'bank_account_id', 'status']);
        });

        /*
         * টিক-চিহ্নটা — nullable, তাই পুরনো কোনো সারি ভাঙে না।
         *
         * null মানে ব্যাংক এখনো ওটা দেখেনি। ভরা থাকলে কোন মিলকরণে টিক
         * পড়েছে সেটাও জানা যায়।
         *
         * শুধু একটা `reconciled_on` তারিখ রাখলে গত মাসের টিক আর এই মাসের
         * টিক আলাদা করা যেত না, আর মিলকরণ খুললে কোন টিকগুলো তুলতে হবে
         * সেটা বলা যেত না।
         */
        Schema::table('voucher_lines', function (Blueprint $table) {
            $table->foreignId('reconciliation_id')->nullable()->after('cost_center_id')
                ->constrained('acc_bank_reconciliations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('voucher_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reconciliation_id');
        });

        Schema::dropIfExists('acc_bank_reconciliations');
    }
};
