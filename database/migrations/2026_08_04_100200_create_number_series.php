<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ডকুমেন্ট নম্বরের সিরিজ — Number Series engine-এর টেবিল (সেকশন ২.২)।
 *
 * কোম্পানি × শাখা × ডকুমেন্ট টাইপ × অর্থবছর — এই চারটার প্রতিটা সমন্বয়ের নিজের
 * কাউন্টার। branch_id null রাখলে কোম্পানি-ব্যাপী একটাই সিরিজ, যা কিছু
 * প্রতিষ্ঠান চায়; শাখা দিলে শাখাভিত্তিক।
 *
 * নম্বর ইস্যু হবে DB ট্রানজেকশনের ভেতরে row lock নিয়ে (SELECT ... FOR UPDATE),
 * নাহলে দুইজন একসাথে বিল করলে একই নম্বর দুইবার পড়ে — আর সেটা ধরা পড়ে
 * অনেক পরে, যখন দুইটা ইনভয়েসের নম্বর এক দেখা যায়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->foreignId('financial_year_id')->nullable()->constrained('financial_years')->cascadeOnDelete();

            $table->string('module', 64);
            $table->string('doc_type', 16);        // SI · PI · RV · PV · JV …

            $table->string('prefix', 32)->default('');
            $table->string('suffix', 32)->default('');

            // {FY} · {BRANCH} · {YYYY} · {MM} · {SEQ} — কোন অংশ কোথায় বসবে
            $table->string('format', 64)->default('{PREFIX}-{SEQ}');

            $table->unsignedInteger('padding')->default(4);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedBigInteger('start_number')->default(1);

            // বছর ঘুরলে ১ থেকে শুরু হবে কি না — অনেক প্রতিষ্ঠানের রীতি
            $table->boolean('reset_yearly')->default(true);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['company_id', 'branch_id', 'doc_type', 'financial_year_id'], 'number_series_scope_unique');
            $table->index(['company_id', 'module']);
        });

        // ইস্যু হওয়া প্রতিটা নম্বরের রেকর্ড। কেন দরকার: একটা ভাউচার বাতিল হলে
        // নম্বরটা কী হলো সেই প্রশ্নের উত্তর লাগে — নিরীক্ষায় "৪৭ নম্বর কোথায়"
        // জিজ্ঞেস করা হয়, আর "কাউন্টার বেড়ে গেছে" উত্তরটা যথেষ্ট নয়।
        Schema::create('issued_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('number_series_id')->constrained('number_series')->cascadeOnDelete();

            $table->string('document_no', 64);
            $table->unsignedBigInteger('sequence');

            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->boolean('is_voided')->default(false);
            $table->string('void_reason', 255)->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'document_no']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_numbers');
        Schema::dropIfExists('number_series');
    }
};
