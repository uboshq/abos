<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * যে কমিশন ডিপো আগে দেয়, আর কোম্পানির কাছে পরে দাবি করে।
 *
 * ── ব্যবসাটা ─────────────────────────────────────────────────────────
 * কিছু ডিলারের সাথে কোম্পানির আলাদা কমিশনের চুক্তি থাকে — কেউ পান
 * নির্দিষ্ট হারে, কেউ SR ধরে। ৫% থেকে ৫০% পর্যন্ত হতে পারে, বা একটা
 * নির্দিষ্ট টাকাও।
 *
 * **টাকাটা ডিপো আগে দিয়ে দেয়** — বিল থেকে কেটে, বা নগদে। তারপর মাস
 * শেষে কোম্পানির লেজারে সেটা সমন্বয় হয়।
 *
 * ── কেন এটা ছাড় নয়, দাবি ────────────────────────────────────────────
 * টাকাটা ডিপোর পকেট থেকে যাচ্ছে না, কোম্পানির পকেট থেকে। ছাড় হিসেবে
 * লিখলে বিক্রয় কমে যেত, আর ৪% মার্জিনে ৫% কমিশন মানে খাতা বলত
 * **লোকসানে বেচছি** — অথচ কোম্পানির কাছে পাওনাটা কোথাও দেখা যেত না।
 *
 * তাই সারিটা একটা **সম্পদ**: Dr কমিশনের দাবি (১১৫০) · Cr ডিলার।
 *
 * ── তিনটা অবস্থা, আর তিনটাই আলাদা ঘটনা ──────────────────────────────
 * `pending`  — দেওয়া হয়ে গেছে, কোম্পানি এখনো মানেনি
 * `settled`  — কোম্পানি মেনেছে, তাদের হিসাবে সমন্বয় হয়েছে
 * `rejected` — কোম্পানি মানেনি, তাই দাবিটা ডিপোর নিজের খরচ (৫২১৫)
 *
 * শেষেরটা না থাকলে অনাদায়ী দাবিগুলো বছরের পর বছর সম্পদ হয়ে বসে
 * থাকত, আর ব্যালেন্স শিট এমন পাওনা দেখাত যা কেউ কোনোদিন দেবে না।
 *
 * ── হার ও টাকা দুইটা ঘরই কেন ────────────────────────────────────────
 * চুক্তি দুই রকম হয়: শতাংশে, নয়তো থোক টাকায়। কেবল শতাংশ রাখলে থোক
 * অঙ্কটা প্রতিবার হাতে গুনে বসাতে হত, আর গোনার ভুল সরাসরি টাকার ভুল।
 * যেটা দিয়ে হিসাব হয়েছে সেটাই জমা থাকে, তাই ছয় মাস পরেও বলা যায়
 * অঙ্কটা কীভাবে এসেছিল।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sal_commission_claims', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('document_no', 40)->nullable();
            $table->date('trx_date');

            // কাকে দেওয়া হলো
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            /*
             * কোন কোম্পানির কাছে দাবি।
             *
             * বাধ্যতামূলক: দাবিটা কার কাছে সেটা না জানলে মাস শেষে
             * কোন লেজারে সমন্বয় হবে তা বলা যেত না, আর সারিটা চিরকাল
             * ঝুলে থাকত।
             */
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();

            /*
             * কোন বিলের বিপরীতে — ঐচ্ছিক।
             *
             * বিল থেকে কেটে দিলে সেটা এখানে বসে। নগদে দিলে কোনো বিল
             * নেই, আর তখন ঘরটা খালি — ওটাও একটা সত্যি ঘটনা, তাই
             * বাধ্যতামূলক করা হয়নি।
             */
            $table->foreignId('sales_invoice_id')->nullable()
                ->constrained('sal_invoices')->nullOnDelete();

            $table->decimal('base_amount', 18, 4)->default(0);
            $table->decimal('rate_percent', 9, 4)->nullable();
            $table->decimal('rate_amount', 18, 4)->nullable();
            $table->decimal('amount', 18, 4);

            $table->string('status', 16)->default('pending');
            $table->string('narration', 500)->nullable();

            // মানা না হলে কেন — অডিটে কে লেখা থাকে, কেন থাকে না
            $table->string('decision_reason', 255)->nullable();
            $table->date('decided_on')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'supplier_id', 'trx_date']);
            $table->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sal_commission_claims');
    }
};
