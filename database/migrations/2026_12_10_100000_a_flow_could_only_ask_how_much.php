<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * একটা প্রবাহ কেবল জিজ্ঞেস করতে পারত "কত টাকা"।
 *
 * ── ⛔ যা ভাঙা ছিল, ২৪ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * `approval_flows.threshold_amount` — একটাই শর্ত। ⚠️ *"ছাড় ১৫% পার
 * হলে"*, *"ক্রেতার বাকি আটকানো থাকলে"*, *"পিছনের তারিখে হলে"* — এর
 * কোনোটাই বলা যেত না।
 *
 * ⓘ ফল: হয় প্রতিটা কাগজ সইয়ের জন্য আটকাত (আর মানুষ অনুমোদন এড়িয়ে
 * কাজ করতে শিখত), নয়তো কিছুই আটকাত না।
 *
 * ── ⭐ মানটা কোথা থেকে আসে ──────────────────────────────────────────
 * `approvals.payload` কলামটা **আগে থেকেই ছিল**, আর কেউ কোনোদিন ওটা
 * পড়েনি — এই রিপোর চেনা আকৃতি। ⓘ এখন মডিউল কাগজের ঘরগুলো ওখানে
 * পাঠায়, আর শর্ত ঐগুলোর উপর মাপা হয়।
 *
 * ── ⚠️ কেন সারি ধরে ধরে, একটা লেখা নয় ───────────────────────────────
 * ⛔ *"amount > 100000 AND discount > 10"* একটা স্ট্রিং হিসেবে রাখলে
 * সেটা পার্স করতে হত, আর ভুল লেখা শর্ত **নীরবে মিথ্যা** হয়ে যেত —
 * কোনো প্রবাহ ধরত না, আর কেউ জানত না কেন।
 *
 * ⓘ সারি ধরে রাখলে ফর্মে প্রতিটা শর্ত আলাদা, আর ভুল বসানোর সুযোগ কম।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_conditions', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('approval_flow_id')->constrained('approval_flows')->cascadeOnDelete();

            /*
             * কাগজের কোন ঘর — `amount`, `discount_percent`, `is_backdated`…
             *
             * ⓘ নামগুলো মডিউল নিজে ঘোষণা করে (`module.php` → `approval_fields`),
             * তাই এখানে কোনো তালিকা হাতে লেখা নেই।
             */
            $table->string('field', 64);

            /*
             * ⚠️ অপারেটরগুলো ছোট আর নির্দিষ্ট: `>` `>=` `<` `<=` `=` `!=` `in`।
             * ⛔ যা খুশি লিখতে দিলে একদিন কেউ `like` লিখত, আর সেটা কোথাও
             * চলত না — শর্তটা নীরবে মিথ্যা হয়ে যেত।
             */
            $table->string('operator', 8);
            $table->string('value', 255);

            $table->timestamps();

            $table->index(['approval_flow_id'], 'appr_cond_flow_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_conditions');
    }
};
