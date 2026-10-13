<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⛔ লম্বা টানার মাঝে বদলানো সারি ফোনে কোনোদিন পৌঁছাত না (Api/Sync নিরীক্ষা, ১৪ অক্টোবর ২০২৬; [[SyncService::recordSuccessfulPull()]])।
 *
 * ⚠️ জলচিহ্ন বসত "পুরোটা পেয়েছি"-র মুহূর্তে — অথচ প্রথম ধরনের পাতা হয়তো তিন মিনিট আগে পড়া; মাঝে সেই ধরনের যা বদলাল, পরের টানা
 * (জলচিহ্ন − ৬০ সেকেন্ড) থেকে শুরু হওয়ায় চিরতরে বাদ। ⭐ এখন পালার প্রথম পাতার সময় রাখা হয়, আর জলচিহ্ন সেটাই।
 * ⓘ খালি ঘর — পুরনো সারির জন্য আগের আচরণ ("এখন"), পরের পালা থেকে নতুনটা।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sync_states', 'pass_started_at')) {
            return;
        }

        Schema::table('sync_states', function (Blueprint $table): void {
            $table->timestamp('pass_started_at')->nullable()->after('last_synced_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('sync_states', 'pass_started_at')) {
            Schema::table('sync_states', fn (Blueprint $table) => $table->dropColumn('pass_started_at'));
        }
    }
};
