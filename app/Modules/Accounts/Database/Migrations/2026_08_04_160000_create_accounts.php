<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * হিসাবের খাত — Chart of Accounts।
 *
 * ledger_entries.account_id এখানে দেখায়, কিন্তু ফরেন কি নেই: কোর মডিউলের
 * টেবিলের উপর নির্ভর করে না (সেকশন ১৯.৭)। সম্পর্কটা তবু বাস্তব, আর
 * অখণ্ডতা AccountService ধরে রাখে — খাত মুছতে দেওয়া হয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            /*
             * গাছ, তালিকা নয়। "১১০০ চলতি সম্পদ" একটা মাথা, তার নিচে
             * "১১০১ নগদ", "১১০২ ব্যাংক"। রিপোর্টে যোগফল মাথার নিচে
             * জমা হয়, আর সেটা সমতল তালিকায় করা যায় না।
             */
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->string('code', 32);
            $table->string('name_en', 160);
            $table->string('name_bn', 160)->nullable();

            /*
             * পাঁচটা মূল ধরন। এই একটা কলামই ঠিক করে খাতটা কোন রিপোর্টে
             * যাবে: asset ও liability ও equity → ব্যালেন্স শিট,
             * income ও expense → লাভ-লোকসান।
             */
            $table->string('type', 16);

            /*
             * স্বাভাবিক দিক — debit না credit।
             *
             * ধরন থেকে বের করা যেত, কিন্তু ব্যতিক্রম আছে: "সঞ্চিত অবচয়"
             * সম্পদ হয়েও ক্রেডিট প্রকৃতির। বের করে নিলে ওই খাতগুলোর
             * ব্যালেন্স উল্টো চিহ্নে দেখাত।
             */
            $table->string('nature', 8);

            /*
             * গ্রুপ মানে শুধু মাথা — এতে সরাসরি এন্ট্রি বসে না।
             *
             * এটা না থাকলে কেউ "১১০০ চলতি সম্পদ"-এ সরাসরি টাকা বসাত, আর
             * তখন মাথার যোগফল তার সন্তানদের যোগফলের সমান থাকত না —
             * ব্যালেন্স শিট মিলত, কিন্তু কোন খাতে টাকাটা তা বলা যেত না।
             */
            $table->boolean('is_group')->default(false);

            /*
             * নগদ ও ব্যাংক আলাদা করে চেনা দরকার: ক্যাশ বই, ব্যাংক বই,
             * টাকা হস্তান্তর ও নগদ গণনা — চারটাই "কোন খাতগুলো নগদ"
             * জানতে চায়। নাম দেখে অনুমান করলে "নগদান হিসাব" নামের একটা
             * আয়ের খাতও ধরা পড়ত।
             */
            $table->boolean('is_cash')->default(false);
            $table->boolean('is_bank')->default(false);

            /*
             * সিস্টেমের খাত — মুছাও যায় না, ধরনও বদলানো যায় না।
             *
             * বিক্রয় পোস্ট করার সময় "প্রাপ্য হিসাব" খাতটা কোডে ধরে খোঁজা
             * হয়। কেউ সেটা মুছে দিলে বা আয়ের খাত বানিয়ে দিলে পরদিন
             * থেকে প্রতিটা বিক্রয় ভুল জায়গায় বসত।
             */
            $table->boolean('is_system')->default(false);

            $table->decimal('opening_balance', 18, 4)->default(0);
            $table->date('opening_date')->nullable();

            // ব্যাংক খাতে কাজে লাগে; বাকিতে খালি
            $table->string('account_number', 64)->nullable();
            $table->string('bank_name', 120)->nullable();
            $table->string('branch_name', 120)->nullable();

            $table->string('status', 16)->default('confirmed');
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // কোড কোম্পানির ভেতরে অনন্য — দুই প্রতিষ্ঠানের ছক এক হতে বাধ্য নয়
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'type', 'is_active']);
            $table->index(['company_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
