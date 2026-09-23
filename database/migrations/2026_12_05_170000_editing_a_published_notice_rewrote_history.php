<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * প্রকাশিত নোটিশ সম্পাদনা করলে ইতিহাসটাই বদলে যেত।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬, ধারা ২৫ ────────────────────
 * *"Notice publish হওয়ার পর edit করলে নতুন version তৈরি হবে"* — আর
 * প্রতিটা সংস্করণের লেখক, তারিখ, কী বদলাল আর অনুমোদন সংরক্ষণ করতে হবে।
 *
 * ── ⚠️ কেন এটা অডিট-খাতা দিয়ে হয় না ─────────────────────────────────
 * ⓘ [[IsAudited]] প্রতিটা ঘরের বদল ধরে রাখে, আর প্রশ্নটা *"কে কী
 * বদলেছে"* হলে ওটাই যথেষ্ট। ⛔ কিন্তু এখানে প্রশ্নটা আলাদা: *"যিনি
 * মঙ্গলবার পড়েছিলেন তিনি কোন লেখাটা পড়েছিলেন"*।
 *
 * ⚠️ অডিট-খাতা ঘর ধরে লেখে, সংস্করণ ধরে নয়। ⓘ পাঁচটা ছোট সম্পাদনার
 * পর ওখান থেকে *"সেদিনের পুরো লেখাটা"* জোড়া লাগানো যায়, কিন্তু
 * কেউ ওটা করবে না — আর রিকলের দিনে সময়ও থাকবে না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notice_versions', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('notice_id')->constrained('notices')->cascadeOnDelete();

            /* ⓘ ১, ২, ৩ — সারিতে লেখা, গোনা নয়; একটা সারি মুছলেও ক্রম ঠিক থাকে */
            $table->unsignedInteger('revision');

            $table->string('title', 255);
            $table->string('summary', 300)->nullable();
            $table->text('body');
            $table->string('priority', 16)->nullable();

            /* কী বদলাল — মানুষের ভাষায়, ঘরের নামে নয় */
            $table->string('change_note', 300)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['notice_id', 'revision'], 'nver_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_versions');
    }
};
