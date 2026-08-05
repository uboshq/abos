<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * প্রতিষ্ঠানের গড়ন — বিভাগ, পদবি, নিয়োগের ধরন।
 *
 * ── কেন তিনটাই সারি, আর কেন মাস্টার ডাটায় ────────────────────────────
 * "বিক্রয় বিভাগ", "সহকারী ব্যবস্থাপক", "চুক্তিভিত্তিক" — তিনটাই এমন
 * তালিকা যা প্রতিষ্ঠানভেদে আলাদা আর সময়ে বাড়ে। enum লিখলে প্রতিটা নতুন
 * পদবির জন্য একটা রিলিজ লাগত।
 *
 * HR-এর ভেতরে না রেখে মাস্টার ডাটায়, কারণ বিভাগ কেবল বেতনের জিনিস নয় —
 * খরচ কোন বিভাগের, কোন বিভাগে কয়টা গাড়ি, এসবও একই তালিকা ধরে চলে।
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['mdm_departments', 'mdm_designations', 'mdm_employment_types'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->publicId();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

                $table->string('code', 32);
                $table->string('name_en', 120);
                $table->string('name_bn', 120)->nullable();

                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['company_id', 'code']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mdm_employment_types');
        Schema::dropIfExists('mdm_designations');
        Schema::dropIfExists('mdm_departments');
    }
};
