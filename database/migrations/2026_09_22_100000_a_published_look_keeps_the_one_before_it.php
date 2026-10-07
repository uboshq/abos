<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * প্রকাশিত রূপের আগেরটা থেকে যায় — থিম ইঞ্জিনের ধাপ ৩ (অংশ ৮)।
 *
 * ── আজ পর্যন্ত যা ছিল ───────────────────────────────────────────────
 * `look_skins`-এ একটাই সারি, আর সম্পাদনা মানে ওই সারিটাই বদলে ফেলা।
 * ফলে দুইটা জিনিস একসাথে ভাঙত:
 *
 *   · সম্পাদনা শুরু করলেই **সবার পর্দা বদলে যেত** — অর্ধেক লেখা রূপ
 *     নিয়ে গোটা ডিপো কাজ করত
 *   · আগেরটা কী ছিল তা কোথাও থাকত না, তাই ফেরার কোনো পথ নেই
 *
 * ── এখন ভাগটা কোথায় ─────────────────────────────────────────────────
 * `look_skins`-এর সারিটা **খসড়া** — সম্পাদকের কাজের কপি। এই টেবিলের
 * প্রতিটা সারি একটা **প্রকাশিত সংস্করণ**, আর পর্দা সবসময় সর্বশেষ
 * প্রকাশিত সংস্করণটাই পরে।
 *
 * তাই সম্পাদনা নিরাপদ: প্রকাশ না করা পর্যন্ত কারো কিছু বদলায় না।
 *
 * ── ফেরা মানে মুছে ফেলা নয় ──────────────────────────────────────────
 * তিন নম্বর সংস্করণে ফিরতে চাইলে চার নম্বরটা মোছা হয় না — তিনের কপি
 * নিয়ে **পাঁচ নম্বর** বসে। ইতিহাস কেবল বাড়ে।
 *
 * কারণ ফেরাটাও একটা ভুল হতে পারে, আর তখন ফেরার-ফেরাটাও লাগে। মুছে
 * ফেললে দ্বিতীয়বার আর কিছু করার থাকত না, আর অডিটে "কে কখন কী দেখেছে"
 * প্রশ্নের উত্তরও হারাত।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('look_skin_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();

            /*
             * সংস্করণ স্কিনের সাথেই মরে।
             *
             * স্কিনটা সত্যিই মুছে গেলে (সফট-ডিলিট নয়, সারিটাই) তার
             * ইতিহাস রেখে দেওয়ার কোনো মানে নেই — ওগুলো আর কোনো
             * প্রশ্নের উত্তর দেয় না।
             */
            $table->foreignId('look_skin_id')->constrained('look_skins')->cascadeOnDelete();

            /*
             * ১, ২, ৩ — স্কিনের ভিতরে গোনা।
             *
             * বিশ্বব্যাপী একটা ক্রম নয়: মানুষ বলেন "আমাদের রূপের তিন
             * নম্বর", "রূপ-সংস্করণ ৪১৭" নয়।
             */
            $table->unsignedInteger('version');

            $table->string('parent', 64);
            $table->json('tokens');

            /*
             * কেন বদলানো হলো — সম্পাদকের নিজের ভাষায়।
             *
             * নাল হতে পারে: কেউ কিছু না লিখলে প্রকাশ আটকে দেওয়ার মতো
             * বড় কিছু নয়। কিন্তু ঘরটা থাকে, কারণ ছয় মাস পরে "কেন
             * নীলটা বদলেছিল" প্রশ্নের উত্তর আর কোথাও পাওয়া যায় না।
             */
            $table->string('note', 200)->nullable();

            /*
             * কোন সংস্করণ থেকে ফেরা — সাধারণত নাল।
             *
             * ভরা থাকলে সারিটা বলে "এটা পুরনো একটার কপি", আর ইতিহাস
             * পড়ে বোঝা যায় কোনটা নতুন কাজ আর কোনটা পিছিয়ে যাওয়া।
             */
            $table->unsignedInteger('reverted_from')->nullable();

            $table->timestamp('published_at');
            $table->foreignId('published_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['look_skin_id', 'version'], 'look_skin_version_unique');
            $table->index(['look_skin_id', 'published_at'], 'look_skin_version_latest');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('look_skin_versions');
    }
};
