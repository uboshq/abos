<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ ট্রিপে চালকের ফোন আর বাহক — মালিকের বিক্রয় পরিকল্পনা (সংস্করণ ২) ধাপ ৪, ৬ অক্টোবর ২০২৬: *"গাড়ি, চালক, ফোন;
 * কয়েকটা চালান মিলে ট্রিপ"*।
 *
 * ⓘ ফোন ছিল কেবল চালানে — ট্রিপে কয়েকটা চালান, অথচ পথে গাড়ির সাথে কথা বলার নম্বর ট্রিপের কাগজে ছিল না। ভাড়ার গাড়ি
 * হলে কোন পরিবহনের, সেটাও (`carrier_name`, চালানের একই ঘর)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_shipments', function (Blueprint $table): void {
            if (! Schema::hasColumn('sal_shipments', 'driver_phone')) {
                $table->string('driver_phone', 32)->nullable()->after('driver_name');
            }

            if (! Schema::hasColumn('sal_shipments', 'carrier_name')) {
                $table->string('carrier_name', 191)->nullable()->after('helper_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sal_shipments', function (Blueprint $table): void {
            foreach (['driver_phone', 'carrier_name'] as $column) {
                if (Schema::hasColumn('sal_shipments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
