<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * বিক্রয়টা কোন পথে গেল — কোম্পানির নিজের তালিকা (NEXUS §২৮)।
 *
 * ── কী ছিল না ────────────────────────────────────────────────────────
 * "কাউন্টারে কত, ডিলারের কাছে কত, অনলাইনে কত" — প্রশ্নটার উত্তর দেওয়ার
 * মতো কোনো ঘর কোথাও ছিল না। পক্ষের ধরন বলে ক্রেতা কে, আর অর্ডারের
 * `source` বলে কোন সফটওয়্যার-দরজা দিয়ে কাগজটা ঢুকল; কোনোটাই বিক্রির
 * রাস্তা নয়।
 *
 * ⓘ আকারটা বাকি সরল তালিকার মতোই (ব্র্যান্ড, পক্ষের ধরন) — কোড, দুই
 * ভাষার নাম, ডিফল্ট, সক্রিয়তা — যাতে MasterListController-এর একই পর্দা
 * একে বিনা নতুন কোডে চালায়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdm_sales_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 32);
            $table->string('name_en', 120);
            $table->string('name_bn', 120)->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->uuid('public_id')->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdm_sales_channels');
    }
};
