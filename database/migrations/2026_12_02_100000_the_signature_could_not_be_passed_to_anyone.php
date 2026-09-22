<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * সই অন্যের হাতে দেওয়ার কোনো উপায় ছিল না।
 *
 * ── ⛔ কী আটকাত ───────────────────────────────────────────────────────
 * ছকে লেখা থাকে *"ম্যানেজার সই দেবেন"*। ⚠️ ম্যানেজার ছুটিতে গেলে কাগজটা
 * তাঁর ফেরার দিন পর্যন্ত বসে থাকত — আর বাস্তবে তখন মানুষ ছক এড়িয়ে
 * কাজ সারার পথ খোঁজেন, যেটা ছক থাকা আর না থাকার চেয়েও খারাপ।
 *
 * ── ⓘ দুইটা ঘর, দুইটা আলাদা প্রশ্ন ───────────────────────────────────
 * `approvals.assigned_to` → **এখন কার হাতে**। বর্তমান অবস্থা।
 * `approval_decisions.forwarded_to` → **কে কাকে দিয়েছিলেন**। ইতিহাস।
 *
 * ⛔ একটা দিয়ে অন্যটা চালানো যেত না। শুধু `assigned_to` রাখলে *"কে
 * পাঠিয়েছিলেন"* প্রশ্নের উত্তর হারাত; শুধু ইতিহাস রাখলে প্রতিবার
 * সারি গুনে বের করতে হত আজ কার হাতে।
 *
 * ── ⚠️ কেন আলাদা কোনো টেবিল নয় ───────────────────────────────────────
 * ফরওয়ার্ড একটা **সিদ্ধান্ত** — "হ্যাঁ" আর "না"-র পাশে তৃতীয় একটা।
 * ⓘ আলাদা টেবিলে রাখলে *"কে সই করেছিল"* প্রশ্নের উত্তর দুই জায়গা থেকে
 * জোড়া লাগাতে হত, আর ঐ প্রশ্নটার জন্যই গোটা ব্যবস্থাটা আছে।
 *
 * ── ⓘ `null` মানে "কেউ না", আর সেটাই আজকের আচরণ ──────────────────────
 * ⭐ দুইটা ঘরই খালি থাকে যতক্ষণ কেউ ইচ্ছা করে ফরওয়ার্ড না করেন। তাই
 * এই মাইগ্রেশন চালানোর পর **একটা কাগজেরও পথ বদলায় না**।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            /*
             * ⚠️ `nullOnDelete` — মানুষটা মুছে গেলে কাগজটা মুছবে না।
             *
             * ⛔ `cascadeOnDelete` হলে একজন কর্মী বাদ দিলে তাঁর হাতে থাকা
             * প্রতিটা অপেক্ষমাণ অনুমোদন **উধাও** হয়ে যেত, আর কাগজগুলো
             * চিরকাল খসড়া হয়ে বসে থাকত — কেউ বুঝতই না কেন।
             *
             * ⓘ খালি হয়ে গেলে অনুরোধটা ছকের স্বাভাবিক নিয়মে ফিরে যায়।
             */
            $table->foreignId('assigned_to')->nullable()->after('current_level')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('approval_decisions', function (Blueprint $table) {
            // ⓘ ইতিহাসের সারি — কে কার হাতে দিয়েছিলেন।
            $table->foreignId('forwarded_to')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to');
        });

        Schema::table('approval_decisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('forwarded_to');
        });
    }
};
