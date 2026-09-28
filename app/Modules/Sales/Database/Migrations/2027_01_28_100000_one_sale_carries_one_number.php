<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * একটা বিক্রির একটাই নম্বর — মালিকের সিদ্ধান্ত, ২৯ সেপ্টেম্বর ২০২৬: *"ekta biler ekti id holei
 * valo tate track korte subidha hoy"*।
 *
 * ⓘ DO বা সরাসরি বিক্রিতে S-নম্বর জন্মায় ([[SaleNumber]]); চালান, বিল, গেট পাস, ফেরত সবাই
 * সেটাই পায়। একই বিক্রিতে একই ধরনের দ্বিতীয় কাগজ S-0012/2। ⓘ `sale_no` মূল নম্বর (লেজ
 * ছাড়া) — খোঁজ আর পরের কাগজের নম্বর এখান থেকেই।
 */
return new class extends Migration
{
    private const TABLES = ['sal_challans', 'sal_invoices', 'sal_gate_passes', 'sal_returns'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) use ($table): void {
                $t->string('sale_no', 40)->nullable()->after('document_no');
                $t->index(['company_id', 'sale_no'], $table.'_sale_no_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) use ($table): void {
                $t->dropIndex($table.'_sale_no_idx');
                $t->dropColumn('sale_no');
            });
        }
    }
};
