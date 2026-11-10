<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ গুদাম কতটা ধরে — মজুদের ড্যাশবোর্ডের "গুদামের জায়গা কতটা ভরা" (মালিকের চূড়ান্ত নমুনা, 10 নভেম্বর 2026 রাত)।
 *
 * ⓘ ধারণক্ষমতা পণ্যের মূল এককে (যেমন কার্টন) — গুদামের পাতা থেকে বসানো; খালি থাকলে ঘরে "বসানো নেই", আন্দাজে শতাংশ নয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_warehouses', function (Blueprint $table) {
            $table->decimal('capacity_qty', 18, 4)->nullable()->after('address_bn');
        });
    }

    public function down(): void
    {
        Schema::table('inv_warehouses', function (Blueprint $table) {
            $table->dropColumn('capacity_qty');
        });
    }
};
