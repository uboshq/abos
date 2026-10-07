<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * হিসাব ও স্টকের দুইটা কেন্দ্রীয় টেবিল — Posting engine লেখে, বাকি সবাই পড়ে।
 *
 * প্ল্যান সেকশন ৫ ও ১৯.৪: কোনো মডিউল নিজের আলাদা লেজার বা স্টক টেবিল বানাবে না।
 * বিক্রয়, ক্রয়, বেতন, ঋণ — সবার প্রভাব এখানেই এসে বসে, নিজের source_type ও
 * source_id নিয়ে। সেই জোড়াই ড্রিল-ডাউনের মেরুদণ্ড (নিয়ম ১): একটা সংখ্যা থেকে
 * ক্লিক করে ঠিক কোন ডকুমেন্ট সেটা তৈরি করেছে সেখানে যাওয়া যায়।
 *
 * আলাদা টেবিল বানালে ট্রায়াল ব্যালেন্স লিখতে গিয়ে প্রতিটা মডিউলের টেবিল
 * ইউনিয়ন করতে হয়, আর নতুন মডিউল যোগ হলে সেই ইউনিয়ন আপডেট করতে কেউ ভোলে —
 * তখন হিসাব চুপচাপ কম দেখায়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('financial_year_id')->constrained('financial_years')->cascadeOnDelete();

            // hisab খাত — Chart of Accounts আসবে Accounts মডিউলের নিজের
            // মাইগ্রেশনে, তাই এখানে ফরেন কি নয়, শুধু ইনডেক্স। কোর মডিউলের
            // টেবিলের উপর নির্ভর করলে সেকশন ১৯.৭-এর নিষেধ ভাঙা হয়।
            $table->unsignedBigInteger('account_id');

            // পক্ষ — গ্রাহক/সরবরাহকারী/কর্মচারী, যার খাতায় এটা বসবে
            $table->string('party_type', 32)->nullable();
            $table->unsignedBigInteger('party_id')->nullable();

            // নিয়ম ৩: ব্যবহারকারীর দেওয়া তারিখ আর সিস্টেমের তারিখ আলাদা।
            // রিপোর্ট trx_date-এ চলে, অডিট created_at-এ।
            $table->date('trx_date');

            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);

            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->string('document_no', 64)->nullable();

            $table->string('narration', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // রিপোর্টের তিনটা আসল পথ — Phase 0-এর পরীক্ষায় এই তিনটাই মাপা হয়েছে
            $table->index(['company_id', 'trx_date'], 'ledger_company_date');
            $table->index(['company_id', 'account_id', 'trx_date'], 'ledger_company_account_date');
            $table->index(['company_id', 'party_type', 'party_id', 'trx_date'], 'ledger_party');
            $table->index(['source_type', 'source_id'], 'ledger_source');
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('warehouse_id');

            $table->date('trx_date');

            // ঢোকা ধনাত্মক, বেরোনো ঋণাত্মক — একটাই কলাম, কারণ দুইটা কলাম
            // (in/out) রাখলে যোগফল বের করতে প্রতিবার দুইটা SUM লাগে আর
            // কোথাও না কোথাও একটা বাদ পড়ে।
            $table->decimal('qty', 18, 3);

            // প্রতি এককের খরচ — স্টকের মূল্যায়নে লাগে
            $table->decimal('unit_cost', 18, 4)->default(0);

            // স্টকের অবস্থা: floor · reserved · hold · available
            $table->string('state', 16)->default('available');

            // ফ্রি স্টক আলাদা পুল — DMS-এ এটা নিয়মিত স্টক থেকে কাটা যেত,
            // যা নীরবে হিসাব নষ্ট করত। শুরু থেকেই আলাদা।
            $table->boolean('is_free')->default(false);

            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->string('document_no', 64)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'product_id', 'warehouse_id', 'trx_date'], 'stock_product_wh_date');
            $table->index(['company_id', 'trx_date'], 'stock_company_date');
            $table->index(['source_type', 'source_id'], 'stock_source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('ledger_entries');
    }
};
