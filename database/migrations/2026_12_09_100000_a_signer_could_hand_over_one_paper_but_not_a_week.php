<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * সইকারী একটা কাগজ অন্যকে দিতে পারতেন, একটা সপ্তাহ নয়।
 *
 * ── ⛔ যা ভাঙা ছিল, ২৪ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * [[ApprovalEngine::forward()]] দিয়ে **একটা** কাগজ একজনের হাতে দেওয়া
 * যেত। ⚠️ কিন্তু *"আমি ২৫ থেকে ৩০ তারিখ ছুটিতে, এই সময়ের সব বিক্রয়
 * অনুমোদন সহকারী দেখবেন"* — এটা বলার কোনো উপায় ছিল না।
 *
 * ⓘ ফল: ছুটিতে যাওয়ার আগে সইকারী হয় প্রতিটা কাগজ আলাদা করে পাঠাতেন
 * (যেগুলো তখনো আসেইনি, সেগুলো পাঠানো যায় না), নয়তো ফিরে এসে জমে থাকা
 * স্তূপ সামলাতেন।
 *
 * ── ⚠️ কেন তারিখ দুইটা দরকার, একটা সুইচ নয় ──────────────────────────
 * ⛔ *"চালু/বন্ধ"* সুইচ হলে ফিরে এসে সেটা বন্ধ করতে **মনে রাখতে হত**।
 * ⓘ আর ঠিক ওই জিনিসটাই মানুষ ভোলে — তারপর মাসের পর মাস সহকারী
 * অনুমোদন করে যান, আর কেউ টের পায় না।
 *
 * ⭐ তারিখ ধরে হলে মেয়াদ **নিজে থেকেই** শেষ হয়, কোনো কাজ ছাড়াই।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_delegations', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            /* যিনি ভার দিচ্ছেন, আর যিনি নিচ্ছেন। */
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();

            $table->date('starts_on');
            $table->date('ends_on');

            /*
             * ⭐ কোন কাজে — খালি মানে **সব**।
             *
             * ⚠️ দুইটা আলাদা ঘর, কারণ প্রশ্ন দুইটাই আসে: *"বিক্রয়ের
             * সবকিছু"* (মডিউল), আর *"কেবল ছাড়"* (কাজ)। ⓘ একটা ঘরে
             * মিশিয়ে দিলে `sales` আর `sales.discount` এক তালিকায় বসত,
             * আর কোনটা কী তা কোড থেকে বোঝা যেত না।
             */
            $table->json('modules')->nullable();
            $table->json('actions')->nullable();

            $table->string('reason', 255)->nullable();

            /*
             * ⓘ মেয়াদের **আগেই** ফিরে আসলে হাতে বন্ধ করা যায়।
             * ⚠️ সারিটা মোছা হয় না — কে কবে কার হয়ে সই দিয়েছিলেন, সেই
             * প্রশ্নের উত্তর পরে লাগে।
             */
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            /* ⚠️ নাম ৬৪ অক্ষরের নিচে ([[long-index-names-break-every-session]])। */
            $table->index(['company_id', 'to_user_id', 'starts_on'], 'appr_deleg_to_idx');
            $table->index(['company_id', 'from_user_id'], 'appr_deleg_from_idx');
        });

        Schema::table('approval_decisions', function (Blueprint $table) {
            /*
             * ⭐ কার হয়ে সই দেওয়া হলো।
             *
             * ── ⚠️ কেন দুইজনের নামই রাখা হয় ────────────────────────
             * ⓘ সিদ্ধান্তে `user_id` থাকে **যিনি সত্যি চাপ দিলেন**, আর
             * এই ঘরে থাকেন **যাঁর হয়ে দিলেন**। ⛔ কেবল একজনের নাম
             * রাখলে ছয় মাস পরে *"এই অনুমোদনটা কে দিয়েছিল"* প্রশ্নের
             * উত্তর অর্ধেক হত — আর নিরীক্ষায় অর্ধেক উত্তর কোনো উত্তরই নয়।
             */
            $table->foreignId('on_behalf_of')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('approval_decisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('on_behalf_of');
        });

        Schema::dropIfExists('approval_delegations');
    }
};
