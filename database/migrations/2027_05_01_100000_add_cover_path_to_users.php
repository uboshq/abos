<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ প্রোফাইলের মলাট (cover) ছবি — প্রোফাইল পাতা, ২০ নভেম্বর ২০২৬।
 *
 * ⓘ ছবির মতোই পথ রাখা হয়, ছবি নয় ([[App\Core\Services\CoverService]]; ফাইল ব্যক্তিগত ডিস্কের `covers/`-এ)।
 * nullable — মলাট না থাকাটাই স্বাভাবিক, তখন পাতা রঙের পটি দেখায়।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'cover_path')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('cover_path')->nullable()->after('avatar_path');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'cover_path')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('cover_path');
        });
    }
};
