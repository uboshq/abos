<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * সইয়ের অপেক্ষায় থাকা বিক্রি কাউন্টারে দেখা যেত না — মালিকের অনুমোদিত নকশা, ২৮
 * সেপ্টেম্বর ২০২৬: Pending-এর সব ভাগ কাউন্টারেই খোলে; "অনুমোদনের অপেক্ষায়" বিক্রি
 * কেবল দেখা যায়, আর যিনি পাঠিয়েছেন তিনি খসড়ায় ফিরিয়ে আনতে পারেন।
 *
 * ⓘ `counter_draft` নয় কেন: ওটা "খসড়া, কাউন্টারে বদলানো যায়"-এর চিহ্ন —
 * [[DirectSaleService::parkedFor()]] আর স্বয়ংক্রিয় শেষ-করা ওটা দেখেই চলে। ⛔ সইয়ের
 * অপেক্ষার বিক্রিতে ওটা বসালে বিক্রিটা সই ছাড়াই বদলানো যেত।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_invoices', function (Blueprint $table): void {
            $table->json('counter_screen')->nullable()->after('draft_paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('sal_invoices', function (Blueprint $table): void {
            $table->dropColumn('counter_screen');
        });
    }
};
