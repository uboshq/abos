<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ জরুরি নোটিশের নিজের টিক — মালিক, ২১ অক্টোবর ২০২৬ (fe-র মাধ্যমে): *"জরুরি সতর্কতা উপরে স্থির, ঘোষণা নিচে চলন্ত"*।
 *
 * ⓘ মানুষের লেখা ঘোষণা নিচের চলন্ত বারে ফিরল ([[StatusNotices::forTicker()]]); যেটায় লেখক "জরুরি" টিক দেন, সেটা উপরের
 * স্থির ফিতায় ([[StatusNotices::forStrip()]]) — একই নোটিশ কখনো দুই জায়গায় নয়।
 *
 * ⛔ অগ্রাধিকার (`priority`) দিয়ে নয়: অগ্রাধিকার আগে থেকেই ঠিক করে বারে ওঠা, সরানো আর সই চাওয়া; ওটাকে জায়গার প্রশ্নও
 * বানালে "অত্যন্ত জরুরি" বাছলে নোটিশটা নিজে থেকেই জায়গা বদলাত, আর লেখক জানতেন না কেন। জায়গা লেখকের নিজের সিদ্ধান্ত।
 *
 * ⓘ আগের সব নোটিশ মিথ্যা (জরুরি নয়) — অর্থাৎ নিচের বারে, মালিকের নিয়মে যেখানে ঘোষণার জায়গা।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notices') || Schema::hasColumn('notices', 'is_urgent')) {
            return;
        }

        Schema::table('notices', function (Blueprint $table) {
            $table->boolean('is_urgent')->default(false)->after('in_ticker');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('notices') && Schema::hasColumn('notices', 'is_urgent')) {
            Schema::table('notices', function (Blueprint $table) {
                $table->dropColumn('is_urgent');
            });
        }
    }
};
