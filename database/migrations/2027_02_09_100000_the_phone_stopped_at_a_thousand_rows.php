<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⛔ ফোনের সিঙ্ক প্রথম ১,০০০ সারির পরে আর এগোত না — Inventory অডিট গ১৮, ৪ অক্টোবর ২০২৬ ([[SyncService::pull()]])।
 *
 * পুরনো অ্যাপ (০.৪.১০ পর্যন্ত) পরের পাতার কার্সর ফেরত পাঠাতে জানে না, তাই এই যন্ত্রের কার্সর সার্ভারে থাকে — প্রতি টানায়
 * পরের পাতা, পুরোটা পেলে মুছে যায়। ⓘ নতুন অ্যাপ কার্সর নিজে পাঠায়, তার জন্য ঘরটা খালিই থাকে।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sync_states', 'page_cursor')) {
            return;
        }

        Schema::table('sync_states', function (Blueprint $table): void {
            $table->text('page_cursor')->nullable()->after('last_synced_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('sync_states', 'page_cursor')) {
            Schema::table('sync_states', fn (Blueprint $table) => $table->dropColumn('page_cursor'));
        }
    }
};
