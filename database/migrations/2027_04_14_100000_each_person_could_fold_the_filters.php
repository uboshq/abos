<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ তালিকার ছাঁকনি প্যানেল ভাঁজ করে রাখা — মানুষের নিজের পছন্দ, যেকোনো কম্পিউটারে একই (মালিক, ১৪ নভেম্বর ২০২৬: *"ফুল করে
 *   রাখার ব্যবস্থাটা তো রাখার দরকার"*; [[filter-layout]])।
 *
 * `users.list_prefs` — ছোট JSON (`{"folded": true}`); তালিকার নিজের পছন্দ পরে এখানেই যোগ হবে। ⓘ ব্যবসার তথ্য নয় — নিরীক্ষার
 *   খাতায় যায় না ([[User::auditIgnores()]]), থিমের মতো।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'list_prefs')) {
            Schema::table('users', function (Blueprint $table) {
                $table->json('list_prefs')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'list_prefs')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('list_prefs');
            });
        }
    }
};
