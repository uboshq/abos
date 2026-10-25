<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ বাঁ মডিউল-রেলের নিজের সাজ — পিন করা মডিউল আর নিজের ক্রম (মালিক, ২৪ অক্টোবর ২০২৬)।
 *
 * ⓘ যন্ত্রে নয়, মানুষের সারিতে — অন্য যন্ত্রে লগইন করলেও একই রেল। NULL মানে নিজের সাজ নেই: তখন খোলা পাতার অনুপাতে, সমান হলে
 * আগের ক্রমে ([[MenuBuilder::arranged()]])। ⛔ নিরীক্ষায় যায় না ([[User::auditIgnores()]]) — নিজের পছন্দ, ব্যবসার তথ্য নয় (fe)।
 * ⚠️ তারিখটা home_layout-এর (2027_02_08) পরে, আর ঘর থাকলে কিছু করে না — নতুন DB-তে ক্রম ঠিক থাকে, পুরনোয় দুবার চললেও ভাঙে না।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'nav_layout')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->json('nav_layout')->nullable()->default(null);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'nav_layout')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('nav_layout');
        });
    }
};
