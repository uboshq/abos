<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ ভাঙা মাল — মালিকের বিক্রয় পরিকল্পনা (সংস্করণ ২) ধাপ ৭, ৬ অক্টোবর ২০২৬: *"কম বা ভাঙা মাল → ফেরত বা দাবি"*।
 *
 * ⓘ আংশিক পৌঁছানোর প্রতিটা লাইনে এখন দুইটা পরিমাণ: যতটা ভালো অবস্থায় নিলেন (`delivered_qty`, আগের মতো) আর যতটা ভাঙা
 * পৌঁছাল (`damaged_qty`)। বাকিটা "কম" — পৌঁছায়ইনি। ভাঙা অংশ একই লেনদেনে ফেরত, আটকে রাখা মজুদে ([[ShortDeliveryReturn]])।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sal_delivery_event_lines', 'damaged_qty')) {
            return;
        }

        Schema::table('sal_delivery_event_lines', function (Blueprint $table): void {
            $table->decimal('damaged_qty', 18, 4)->default(0)->after('delivered_qty');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('sal_delivery_event_lines', 'damaged_qty')) {
            Schema::table('sal_delivery_event_lines', fn (Blueprint $table) => $table->dropColumn('damaged_qty'));
        }
    }
};
