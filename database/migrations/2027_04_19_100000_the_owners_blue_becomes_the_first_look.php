<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ মালিকের নীল (`blue`, "নীল প্যানেল") প্রথম রূপ — ১৮ নভেম্বর ২০২৬, মুক্তি ১.৩.৫।
 *
 * ── কারা সরেন, কারা নন ────────────────────────────────────────────────
 * ঘরের ডিফল্ট এতদিন `navy` ([[2026_09_20_100000_the_first_thing_they_see_should_be_ours]]), তাই যিনি কোনোদিন
 * চেহারার পাতা খোলেননি তাঁর ঘরেও `navy` লেখা — "নিজে বেছেছেন" আর "কিছু বাছেননি" ডাটায় আলাদা করা যায় না
 * (বাছাইয়ের কোনো খাতা রাখা হয়নি)। ⓘ তাই `navy`-তে বসা সবাই নীলে ওঠেন; নীল navy-র গড়নেই দাঁড়ায়
 * ([[Ui::family()]]), বদলায় কেবল রং আর কোণ — আর যিনি পুরনোটা চান, চেহারার পাতায় এক ক্লিকে ফেরেন।
 *
 * ⛔ যিনি নিজে অন্য রূপ বেছেছেন (Odoo, Fiori, Rose …), বা কোম্পানির নিজের স্কিন — কেউ ছোঁয়া হয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('ui', 'navy')->update(['ui' => 'blue']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('ui', 64)->default('blue')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ui', 64)->default('navy')->change();
        });

        DB::table('users')->where('ui', 'blue')->update(['ui' => 'navy']);
    }
};
