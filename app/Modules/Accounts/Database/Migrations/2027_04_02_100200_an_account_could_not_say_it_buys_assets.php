<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * খাতের চিহ্ন "এখানে সম্পদ কেনা হতে পারে" — IAS 16-এর সতর্কতার জন্য (খরচের ভাউচার ১০০%, ভাগ J — ec; ঘরটা §১-এর এক মাইগ্রেশনের
 * ক্রমে, fe অনুমোদিত ২৪ অক্টোবর ২০২৬)।
 *
 * ⓘ খরচের লাইনের অঙ্ক `accounts.expense.capitalise_warn_over`-এর (ডিফল্ট ৳৫০,০০০) উপরে আর খাতে এই চিহ্ন থাকলে পরখের বাক্সে
 * সতর্কতা, "সম্পদে নিন" লিংকসহ — আটকায় না। ডিফল্ট false; ছকের পর্দা থেকে বসানো যায় (মেরামত, যন্ত্র, আসবাব, কম্পিউটার ধরনের খাত)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->boolean('capex_hint')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('capex_hint');
        });
    }
};
