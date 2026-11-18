<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ নতুন "ABOS" (`rose` চাবি) প্রথম রূপ — মালিকের অনুমোদিত নমুনা, ১৯ নভেম্বর ২০২৬ (claude.ai artifact AAjAfjZ8TnCfnjtt3Kthjz v11)।
 *
 * ── কারা সরেন, কারা নন ────────────────────────────────────────────────
 * ঘরের ডিফল্ট এতদিন `blue` ([[2027_04_19_100000_the_owners_blue_becomes_the_first_look]]), তাই যিনি কোনোদিন চেহারার পাতা
 * খোলেননি তাঁর ঘরেও `blue` লেখা — "নিজে বেছেছেন" আর "কিছু বাছেননি" ডাটায় আলাদা করা যায় না। ⓘ তাই `blue`-তে বসা সবাই
 * নতুন "ABOS"-এ ওঠেন — রং একই (ABOS Blue-র), বদলায় খোলস; যিনি আগেরটা চান, চেহারার পাতায় এক ক্লিকে "ABOS Blue"-তে ফেরেন।
 *
 * ⛔ যিনি নিজে অন্য রূপ বেছেছেন (ABOS Navy, Odoo, SAP …), বা কোম্পানির নিজের স্কিন — কেউ ছোঁয়া হয় না।
 * ⓘ ফেরানো (`down`): ডিফল্ট আবার `blue`, আর `rose`-এ বসা সবাই `blue`-তে — আগের Rose নিজে বেছে নেওয়া মানুষ আর নতুন
 *   ডিফল্ট পাওয়া মানুষ ডাটায় আলাদা নন, তাই ফেরার পথে দুজনেই ডিফল্টে নামেন।
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('ui', 'blue')->update(['ui' => 'rose']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('ui', 64)->default('rose')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ui', 64)->default('blue')->change();
        });

        DB::table('users')->where('ui', 'rose')->update(['ui' => 'blue']);
    }
};
