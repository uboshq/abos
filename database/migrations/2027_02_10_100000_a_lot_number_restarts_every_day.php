<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ লটের নম্বর রোজ ০১ থেকে — মালিকের আদেশ, ৫ অক্টোবর ২০২৬: *"লটে নিজে থেকে প্রস্তাব দেবে, DDMMYY/XX-LOT"*।
 *
 * ⓘ ঘরটা প্রতিটা সিরিজের নিজের, ডিফল্টে বন্ধ — কেবল LOT-এ চালু হয়ে বসে ([[NumberSeriesProvisioner]])। বাকি সব কাগজের
 * গুনতি আগের মতোই চলে; `{DD}` সেখানে কেবল দেখায় (মালিক, ৫ সেপ্টেম্বর ২০২৬)। গোনার নিয়ম [[NumberSeriesEngine::next()]]-এ।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('number_series', 'reset_daily')) {
            return;
        }

        Schema::table('number_series', function (Blueprint $table): void {
            $table->boolean('reset_daily')->default(false)->after('reset_yearly');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('number_series', 'reset_daily')) {
            Schema::table('number_series', fn (Blueprint $table) => $table->dropColumn('reset_daily'));
        }
    }
};
