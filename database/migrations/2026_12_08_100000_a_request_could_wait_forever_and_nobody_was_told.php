<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * একটা অনুরোধ চিরকাল পড়ে থাকতে পারত, আর কাউকে বলা হত না।
 *
 * ── ⛔ যা ভাঙা ছিল, ২৪ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * অনুমোদনের অনুরোধ বসত, আর তারপর **কিছুই হত না**। ⚠️ কেউ মনে করাত না,
 * দেরি হলে কারও কাছে যেত না, আর কোনটা আটকে আছে তা জানতে হলে কাউকে
 * নিজে গিয়ে রিপোর্ট খুলতে হত।
 *
 * ⓘ রিপোর্টে `waiting_days` দেখা যেত — কিন্তু **দেখা** আর **জানানো** এক
 * নয়। যে সংখ্যাটা কেউ দেখে না, সেটা থাকা আর না থাকা সমান।
 *
 * ── ⭐ মালিকের সিদ্ধান্ত, ২৪ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * *"প্রতিটা ধাপে আলাদা করে বসাব"* — তাই সময়গুলো ধাপের গায়ে, প্রবাহের
 * নয়। ⓘ ছুটির আবেদন আর পাঁচ লাখ টাকার অর্ডার এক সময় পাবে না।
 *
 * *"প্রবাহে ঠিক করা একজন"* — দেরি হলে কাগজটা **পরের ধাপের জনের কাছে
 * যাবে না**, যাবে ধাপে নাম-ধরে বসানো মানুষের কাছে। ⚠️ তাই গন্তব্যটা
 * ধাপের নিজের ঘর, আর সেটা না বসালে কাগজ কোথাও যায় না — ঐ ফাঁকটা
 * `flow/coverage` পর্দায় ধরা পড়ে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_flow_steps', function (Blueprint $table) {
            /*
             * ⓘ `null` মানে *"এই ধাপে কোনো সময়সীমা নেই"* — শূন্য নয়।
             *
             * ⚠️ ডিফল্ট শূন্য দিলে প্রতিটা পুরনো ধাপ **সঙ্গে সঙ্গে দেরি**
             * হয়ে যেত, আর প্রথম দিনেই সব কাগজ উপরে চলে যেত।
             */
            $table->unsignedSmallInteger('sla_hours')->nullable()->after('requires_all');
            $table->unsignedSmallInteger('warn_hours')->nullable()->after('sla_hours');
            $table->unsignedSmallInteger('escalate_hours')->nullable()->after('warn_hours');

            /*
             * ⭐ দেরি হলে কার কাছে — রোল, নাকি নির্দিষ্ট একজন।
             *
             * ⓘ ধাপের অনুমোদনকারীর মতো একই দুইটা রূপ
             * ([[ApprovalFlowStep::BY_ROLE]] · `BY_USER`), তাই পর্দায়
             * একই ছক দুইবার শেখানো লাগে না।
             */
            $table->string('escalate_to_type', 16)->nullable()->after('escalate_hours');
            $table->unsignedBigInteger('escalate_to_id')->nullable()->after('escalate_to_type');
        });

        Schema::table('approvals', function (Blueprint $table) {
            /*
             * ⭐ এই অনুরোধটা কখন দেরি হয়ে যাবে।
             *
             * ⚠️ ধাপ এগোলে নতুন করে বসে — নাহলে তিন ধাপের একটা কাগজে
             * প্রথম ধাপের ঘড়িই শেষ পর্যন্ত চলত, আর দ্বিতীয় ধাপের মানুষ
             * জন্মের মুহূর্তেই দেরি করে ফেলতেন।
             */
            $table->timestamp('due_at')->nullable()->after('requested_at');

            /*
             * ⛔ একবার উপরে পাঠানো হলে দ্বিতীয়বার নয়।
             *
             * ⚠️ প্রতি ঘণ্টায় কমান্ডটা চলে। এই ঘরটা না থাকলে একটা দেরি
             * করা কাগজ **প্রতি ঘণ্টায়** একটা করে বার্তা পাঠাত, আর
             * মানুষ বার্তাগুলো পড়া বন্ধ করে দিতেন — যেটা বার্তা না
             * পাঠানোর চেয়েও খারাপ।
             */
            $table->timestamp('reminded_at')->nullable()->after('due_at');
            $table->timestamp('escalated_at')->nullable()->after('reminded_at');
            $table->unsignedBigInteger('escalated_to')->nullable()->after('escalated_at');

            /*
             * ⓘ কমান্ডটা খোঁজে *"বকেয়া, আর সময় পার"* — তাই দুইটা ঘর
             * একসাথে। ⚠️ নাম ৬৪ অক্ষরের নিচে
             * ([[long-index-names-break-every-session]])।
             */
            $table->index(['status', 'due_at'], 'approval_due_idx');
        });
    }

    public function down(): void
    {
        Schema::table('approval_flow_steps', function (Blueprint $table) {
            $table->dropColumn(['sla_hours', 'warn_hours', 'escalate_hours', 'escalate_to_type', 'escalate_to_id']);
        });

        Schema::table('approvals', function (Blueprint $table) {
            $table->dropIndex('approval_due_idx');
            $table->dropColumn(['due_at', 'reminded_at', 'escalated_at', 'escalated_to']);
        });
    }
};
