<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ⭐ পোস্ট হওয়া কাগজের সংশোধন — আগে আর পরে, দুইটাই (মালিক, ৩ অক্টোবর ২০২৬)।
 *
 * ── ⓘ কী ছিল না ────────────────────────────────────────────────────────
 * মাস বন্ধের আগে সুপার অ্যাডমিন যেকোনো পোস্ট হওয়া কাগজ সম্পাদনা করবেন — নম্বর একই, খাতা উল্টে
 * আবার বসে। ⛔ কিন্তু অডিট কেবল ঘরের বদল লেখে; সারি, খাতার দাখিলা আর মজুদ মিলিয়ে "কাগজটা আগে কী
 * বলত" — তার কোনো এক জায়গা ছিল না। প্রশ্ন উঠলে দেখানোর কিছু থাকত না।
 *
 * ── ⚠️ সারিটা প্রমাণ ────────────────────────────────────────────────────
 * বসে, বদলায় না, মোছে না ([[DocumentRevision]])। ⓘ লেখকের সারি তাই `restrict` — মানুষটা মুছে গেলে
 * "কে বদলেছিলেন" প্রশ্নের উত্তর হারাত।
 *
 * ⓘ সংশোধনের ক্রম কাগজপ্রতি ১, ২, ৩ … — একই কাগজে একই ক্রম দুইবার নয় ([[doc_revision_once]])।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_revisions', function (Blueprint $table) {
            $table->id();

            $table->publicId();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            /* ⓘ মডেলের শ্রেণি (morph) আর তার id — কোর কোনো মডিউলের নাম জানে না */
            $table->string('document_type', 150);
            $table->unsignedBigInteger('document_id');
            $table->string('document_no', 64);

            $table->unsignedInteger('revision_no');

            $table->foreignId('edited_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('edited_at');

            $table->text('reason');

            /* ⓘ মাথা + সারি + খাতার খোলা দাখিলা + মজুদের নিট চলাচল */
            $table->json('before');
            $table->json('after');

            /* ⓘ সংশোধনের আগে কাগজটা বেরিয়েছিল কি না — শেষ ছাপা/পাঠানোর ঘটনা, আর কতবার */
            $table->json('printed_before')->nullable();

            $table->timestamps();

            /* ⚠️ নামগুলো ছোট, হাতে — নিজে বানালে ৬৪ অক্ষর পেরোত */
            $table->unique(['document_type', 'document_id', 'revision_no'], 'doc_revision_once');
            $table->index(['company_id', 'document_type', 'document_id'], 'doc_revision_paper');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_revisions');
    }
};
