<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ভাই-কোম্পানির পক্ষ — এক কোম্পানির একজন ক্রেতা বা সরবরাহকারী আসলে গ্রুপেরই আরেকটা কোম্পানি
 * (মালিকের উত্তর, প্রশ্ন ২; IFRS 10)।
 *
 * ── ⛔ কেন লাগল ─────────────────────────────────────────────────────────
 * ট্রেড ডিপো ফ্যামিলি মার্টকে মাল বেচলে দুই কোম্পানির পর্দায় সেটা ঠিকই আছে। কিন্তু গ্রুপের মোটে সেটা
 * "গ্রুপের বিক্রি" হয়ে যায়, আর ঐ বিলের বাকি "গ্রুপের পাওনা" — অথচ গ্রুপ নিজের কাছেই বেচেছে।
 *
 * ── ⭐ কেবল যা মানুষ নিজে জুড়েছেন ──────────────────────────────────────
 * জোড়া একটা সারি: "কোম্পানি A-র এই পক্ষ = কোম্পানি B"। ⛔ নাম মিলিয়ে আন্দাজ করা হয় না — জোড়া না থাকলে
 * কিছুই বাদ যায় না। ⓘ একটা পক্ষ কেবল একটা ভাই-কোম্পানির সাথে জোড়া যায়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executive_sister_links', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // ⓘ 'customer' বা 'supplier' — খাতার `party_type`-এর একই নাম
            $table->string('party_type', 16);
            $table->unsignedBigInteger('party_id');
            $table->foreignId('sister_company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'party_type', 'party_id'], 'exec_sister_party');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('executive_sister_links');
    }
};
