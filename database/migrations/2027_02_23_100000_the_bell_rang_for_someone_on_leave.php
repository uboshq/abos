<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ ছুটিতে থাকলে খবর আরেকজনের কাছেও (মালিকের স্পেক §১৪ "ছুটিতে Delegate"; বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ৩-এর অনুসরণ)।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * কেউ ছুটিতে গেলে তাঁর খবর তাঁর ঘণ্টাতেই জমত — ফেরত আসা কাগজ, মেয়াদ ফুরানো জমা, কেউ দেখত না।
 *
 * ── ⭐ এখন ───────────────────────────────────────────────────────────
 *   · `notification_preferences.delegate_user_id / delegate_from / delegate_until` — নিজের পছন্দে: এই তারিখগুলোতে আমার
 *     খবর অমুকের কাছেও যাক।
 *   · `notifications.on_behalf_of` — কপিটা কার হয়ে এল; ঘণ্টা আর তালিকায় লেখা থাকে।
 * ⓘ কপি এক ধাপই (দায়িত্বপ্রাপ্তের নিজের দায়িত্বপ্রাপ্তে আর নয়), আর শাখার দেয়াল দায়িত্বপ্রাপ্তের নিজের।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->foreignId('delegate_user_id')->nullable()->after('timezone')->constrained('users')->nullOnDelete();
            $table->date('delegate_from')->nullable()->after('delegate_user_id');
            $table->date('delegate_until')->nullable()->after('delegate_from');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('on_behalf_of')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('on_behalf_of');
        });

        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delegate_user_id');
            $table->dropColumn(['delegate_from', 'delegate_until']);
        });
    }
};
