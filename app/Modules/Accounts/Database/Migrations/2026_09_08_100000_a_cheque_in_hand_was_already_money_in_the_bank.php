<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * হাতে আসা চেক আগেই ব্যাংকের টাকা হয়ে যেত।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * ভাউচারে "চেক" বলে একটা উপায় বাছা যেত, নম্বর ও তারিখও লেখা যেত। কিন্তু
 * **ওই বাছাইটা কোনো দাখিলা বদলাত না** — টাকাটা সাথে সাথেই ব্যাংকে বসত।
 *
 * ফলে দুইটা মিথ্যা: এক, ব্যাংক ব্যালেন্স এমন টাকা দেখাত যা এখনো আসেনি।
 * দুই, চেক ফেরত এলে সেটা ব্যাংকে **কোনোদিন ছিলই না**, অথচ খাতা বলত
 * ছিল — আর ফেরতটা কোথাও লেখারও উপায় ছিল না।
 *
 * বাংলাদেশের পরিবেশনে আদায়ের বড় অংশ চেকে, আর তার একটা অংশ ফেরত আসে।
 * "কোন চেক কবে পাশ হবে, কোনটা ফেরত এসেছে" — এই প্রশ্নটার কোনো উত্তরই
 * ছিল না।
 *
 * ── দুই দিক, এক কাগজ ────────────────────────────────────────────────
 * গৃহীত চেক (ডিলার দিলেন) আর ইস্যু করা চেক (কোম্পানিকে দিলাম) — দুইটার
 * জীবনচক্র একই: হাতে আসা/দেওয়া → জমা → পাশ, নয়তো ফেরত। আলাদা দুইটা
 * টেবিল করলে "আজ কী কী পাশ হওয়ার কথা" প্রশ্নে দুইটা তালিকা মেলাতে হত।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_cheques', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('document_no', 40)->nullable();

            /** received = ডিলার দিলেন · issued = আমরা দিলাম */
            $table->string('direction', 12);

            /*
             * চেকের নিজের তারিখ — যেদিন ওটা ভাঙানো যাবে।
             *
             * এন্ট্রির তারিখ নয়: আগাম তারিখের চেক (PDC) বাংলাদেশে
             * রোজকার, আর ওটাই এই কাগজের প্রাণ। দুইটা এক করে ফেললে
             * "আগামী সপ্তাহে কত টাকার চেক পাশ হবে" প্রশ্নের উত্তর
             * থাকত না।
             */
            $table->date('cheque_date');
            $table->date('received_on');

            $table->string('cheque_no', 40);
            $table->string('bank_name', 120)->nullable();
            $table->decimal('amount', 18, 4);

            // কার চেক — গ্রাহক বা সরবরাহকারী
            $table->string('party_type', 32)->nullable();
            $table->unsignedBigInteger('party_id')->nullable();

            /*
             * কোন ব্যাংক হিসাবে জমা পড়বে বা কোন হিসাব থেকে যাবে।
             *
             * পাশ হওয়ার দিন এই খাতেই টাকা নড়ে। জমা দেওয়ার আগে জানা না
             * থাকলেও চলে, তাই nullable।
             */
            $table->foreignId('bank_account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->string('status', 16)->default('pending');
            $table->date('deposited_on')->nullable();
            $table->date('cleared_on')->nullable();
            $table->string('bounce_reason', 255)->nullable();

            $table->string('narration', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status', 'cheque_date']);
            $table->index(['company_id', 'party_type', 'party_id']);

            /*
             * একই ব্যাংকের একই নম্বরের চেক দুইবার নয়।
             *
             * দুইবার বসলে একই টাকা দুইবার আদায় দেখাত, আর ডিলারের বকেয়া
             * বিনা কারণে কমে যেত। দিক ধরে আলাদা, কারণ গৃহীত ও ইস্যু করা
             * চেকের নম্বর দুইটা আলাদা জগতের।
             */
            $table->unique(['company_id', 'direction', 'bank_name', 'cheque_no'], 'acc_cheques_unique_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_cheques');
    }
};
