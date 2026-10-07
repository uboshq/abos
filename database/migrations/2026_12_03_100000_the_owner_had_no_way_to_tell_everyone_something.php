<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * মালিকের নিজের কথা বলার কোনো জায়গা ছিল না।
 *
 * ── ⓘ কী ছিল, আর কী ছিল না ───────────────────────────────────────────
 * নিচের চলন্ত বারটা আগে থেকেই আছে, কিন্তু ওটায় বসে কেবল **যন্ত্রের
 * নিজের কথা** ([[StatusNotices]]) — ব্যাকআপ বাসি, খসড়া পড়ে আছে।
 * ⚠️ *"কাল দোকান বন্ধ থাকবে"* বলার কোনো পথ ছিল না, তাই ওটা হত
 * হোয়াটসঅ্যাপে — আর যিনি ঐ দলে নেই তিনি জানতেন না।
 *
 * ── ⭐ মালিকের বাছাই (২২ সেপ্টেম্বর ২০২৬) ─────────────────────────────
 * **দুইটাই** — একটা বোর্ড যেখানে পুরো নোটিশ পড়া যায়, আর জরুরিগুলো
 * নিচের চলন্ত বারেও। ⓘ আর কে দেখবেন সেটা **ভূমিকা ধরে**।
 *
 * ── ⚠️ কেন ভূমিকা ধরে, ব্যক্তি ধরে নয় ────────────────────────────────
 * মালিকের বাছাই। ⓘ আর ওটাই টেকে: নতুন বিক্রয়কর্মী এলে তাঁকে আলাদা করে
 * কোনো নোটিশে যোগ করতে হয় না — ভূমিকাটা পেলেই পুরনো নোটিশগুলো তিনি
 * দেখতে পান। ⛔ ব্যক্তি ধরে হলে প্রতিটা নতুন লোকের জন্য পুরনো সব নোটিশ
 * হাতে ধরে বসাতে হত, আর কেউ সেটা করত না।
 *
 * ── ⓘ তিনটা টেবিল, তিনটা আলাদা প্রশ্ন ─────────────────────────────────
 *   `notices`        → কী লেখা, কবে থেকে কবে, বারে যাবে কি না
 *   `notice_roles`   → কারা দেখবেন
 *   `notice_reads`   → কে কবে পড়েছেন
 *
 * ⚠️ পড়ার হিসাবটা আলাদা টেবিলে, নোটিশের গায়ে গুনতি নয় — নাহলে *"কে
 * পড়েননি"* প্রশ্নের উত্তর কোনোদিন পাওয়া যেত না, আর ঐ প্রশ্নটাই
 * নোটিশ দেওয়ার আসল কারণ।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->publicId();

            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('title', 160);
            $table->text('body')->nullable();

            /*
             * ⓘ তারিখ দুইটাই ঐচ্ছিক, আর দুইটার মানে আলাদা।
             *
             * `starts_on` খালি → এখনই। `ends_on` খালি → যতদিন না কেউ
             * বন্ধ করেন। ⚠️ শেষ তারিখটা থাকা জরুরি: *"কাল দোকান বন্ধ"*
             * নোটিশটা এক সপ্তাহ পরেও ঘুরতে থাকলে মানুষ **গোটা বারটাই**
             * পড়া বন্ধ করে দেয়।
             */
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            $table->boolean('is_active')->default(true);

            /*
             * ⚠️ বারে যাবে কি না — নোটিশ ধরে, সব নোটিশ নয়।
             *
             * ⛔ সবগুলো বারে পাঠালে জরুরি কথাটা রোজকার কথার ভিড়ে হারাত,
             * আর বারটা আবার সেই "কেউ পড়ে না" অবস্থায় ফিরে যেত।
             */
            $table->boolean('in_ticker')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // ⓘ বারটা প্রতিটা পাতায় আঁকা হয়, তাই চালু-নোটিশের খোঁজটা ইনডেক্সে।
            $table->index(['company_id', 'is_active'], 'notice_live');
        });

        Schema::create('notice_roles', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('notice_id')->constrained('notices')->cascadeOnDelete();

            /*
             * ⚠️ `roles`-এ FK নেই, আর সেটা ইচ্ছাকৃত।
             *
             * ⓘ ভূমিকা কোম্পানি ধরে বসে (teams), আর একই নামের ভূমিকা
             * প্রতিটা কোম্পানিতে আলাদা সারি। ⛔ আইডি ধরে বাঁধলে কোম্পানি
             * বদলের দিন সম্পর্কটা ভুল ভূমিকায় গিয়ে বসত। ⭐ তাই **নাম**
             * ধরে — আর নাম ধরেই [[Notice::visibleTo()]] মেলায়।
             */
            $table->string('role', 125);

            $table->unique(['notice_id', 'role'], 'notice_role_once');
        });

        Schema::create('notice_reads', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('notice_id')->constrained('notices')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at');

            // ⓘ একজন একবারই — দ্বিতীয়বার পড়লে প্রথমবারের সময়টাই থাকে।
            $table->unique(['notice_id', 'user_id'], 'notice_read_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_reads');
        Schema::dropIfExists('notice_roles');
        Schema::dropIfExists('notices');
    }
};
