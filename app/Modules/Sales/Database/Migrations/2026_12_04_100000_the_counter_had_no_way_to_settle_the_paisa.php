<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * কাউন্টারে পয়সা মেলানোর কোনো ঘর ছিল না।
 *
 * ── ⭐ মালিকের সিদ্ধান্ত, ২৩ সেপ্টেম্বর ২০২৬ ──────────────────────────
 * *"রাউন্ডিং শুধু পজ এ"*, আর তারপর: *"পস-এ যোগ করো, সরাসরি বিক্রয়েও
 * থাক"*।
 *
 * ── ⚠️ কেন বিলের নিজের ঘর, চালান থেকে তোলা নয় ────────────────────────
 * ⓘ `rounding_amount` আগে থেকেই আছে — কিন্তু `sal_challans`-এ, আর ওটা
 * সরাসরি বিক্রয়ের পথ। ⛔ কাউন্টার (POS) চালান বানায় না, সে সরাসরি
 * **বিল** বানায় ([[PosService::checkout]] → [[SalesInvoiceService::create]])।
 *
 * ⚠️ তাই চালানের ঘরটা পস-এর কোনো কাজেই আসে না: ওখানে জোড়ার মতো কোনো
 * চালানই নেই। ⓘ আর চালান থেকে "তুলে আনা"ও যেত না — তোলার মতো কিছু
 * থাকে না।
 *
 * ── ⓘ দুইটা ঘর, তবু দুইটা সত্য নয় ────────────────────────────────────
 * প্রতিটা কাগজ নিজের পয়সা নিজে মেলায়, আর কেউ অন্যেরটা পড়ে না। ⛔ যদি
 * বিলের ঘরটা চালানের ঘর থেকে **নকল** করা হত, তবে চালান সংশোধনের পর
 * দুইটা আলাদা হয়ে যেত, আর কোনটা সত্যি তা বলার উপায় থাকত না।
 *
 * ⚠️ ঋণাত্মক মান বৈধ, আর সেটাই একমাত্র ব্যতিক্রম: ছাড় বা খরচ ঋণাত্মক
 * হওয়ার কোনো মানে নেই, কিন্তু রাউন্ডিংয়ের কাজই **দুই দিকে** পয়সা
 * মেলানো — ৯৯.৬০ থেকে ১০০, আবার ১০০.৪০ থেকে ১০০।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sal_invoices', 'rounding_amount')) {
            return;
        }

        Schema::table('sal_invoices', function (Blueprint $table): void {
            $table->decimal('rounding_amount', 18, 4)->default(0)->after('tax');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('sal_invoices', 'rounding_amount')) {
            return;
        }

        Schema::table('sal_invoices', function (Blueprint $table): void {
            $table->dropColumn('rounding_amount');
        });
    }
};
