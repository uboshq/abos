<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * এক শাখার ব্যবস্থাপক সব শাখার হয়ে সই দিতে পারতেন, যত বড় অঙ্কই হোক।
 *
 * ── ⛔ যা ভাঙা ছিল, ২৪ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * প্রবাহে একটা ধাপে *"বিক্রয় ব্যবস্থাপক"* লেখা থাকলে **যেকোনো** বিক্রয়
 * ব্যবস্থাপক **যেকোনো** অঙ্কে সই দিতে পারতেন। ⚠️ দশ লাখের অর্ডারেও।
 *
 * ⓘ একমাত্র সীমা ছিল `approval.self_limit` — আর ওটা *"নিজের অনুরোধ
 * নিজে"*-র সীমা, অন্যের কাগজে কোনো সীমা নয়।
 *
 * ── ⭐ কেন শাখা ধরে ধরে ─────────────────────────────────────────────
 * ⓘ একই পদ, দুই শাখায় দুই রকম ক্ষমতা — ময়মনসিংহের ব্যবস্থাপক পাঁচ
 * লাখ, উপজেলার শাখায় এক লাখ। ⚠️ একটা সংখ্যা সবার জন্য বসালে হয় বড়
 * শাখা আটকে যেত, নয় ছোট শাখায় সীমাটা অর্থহীন হত।
 *
 * ⭐ `branch_id` খালি মানে **সব শাখা** — অর্থাৎ সাধারণ নিয়ম, আর
 * নির্দিষ্ট শাখার সারি সেটাকে ছাপিয়ে যায়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_limits', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();

            /*
             * ⓘ খালি = সব মডিউল/সব কাজ। ⚠️ *"বিক্রয়ে পাঁচ লাখ"* আর
             * *"সবখানে এক লাখ"* — দুইটাই বলা যায়।
             */
            $table->string('module', 32)->nullable();
            $table->string('action', 64)->nullable();

            /* ⓘ খালি = সব শাখা। নির্দিষ্ট শাখার সারি এটাকে ছাপিয়ে যায়। */
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();

            /*
             * ⛔ `null` মানে **সীমা নেই**, শূন্য নয়।
             *
             * ⚠️ শূন্য বসালে ঐ রোল কিছুই অনুমোদন করতে পারত না, আর
             * পার্থক্যটা কেউ ধরতে পারত না — একটা খালি ঘর আর একটা
             * ইচ্ছাকৃত শূন্য দেখতে এক।
             */
            $table->decimal('max_amount', 18, 4)->nullable();

            $table->timestamps();

            /* ⚠️ নাম ৬৪ অক্ষরের নিচে ([[long-index-names-break-every-session]])। */
            $table->index(['company_id', 'role_id', 'module'], 'appr_limit_role_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_limits');
    }
};
