<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * হোমের সাজ — প্রত্যেক ব্যবহারকারীর নিজের ক্রম আর লুকানো অংশ (মালিক, ৪ অক্টোবর ২০২৬: "লেআউট সাজান")।
 * ⓘ খালি মানে আগের মতো — সব দেখা, আগের ক্রমে ([[HomeLayout]])।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'home_layout')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->json('home_layout')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'home_layout')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('home_layout');
            });
        }
    }
};
