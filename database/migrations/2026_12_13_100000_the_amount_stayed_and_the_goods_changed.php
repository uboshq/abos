<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * অঙ্কটা ঠিক রইল, পণ্যটা বদলে গেল, আর সইটা দাঁড়িয়ে রইল।
 *
 * ── ⛔ নিরাপত্তার ফাঁক, সুবিধা নয় ───────────────────────────────────
 * আজ কেবল **টাকার অঙ্ক** বদলালে সই বাতিল হয় ([[Approval::covers()]])।
 * ⚠️ অঙ্ক ঠিক রেখে পণ্য বদলে দিলে, পরিমাণ বদলে দিলে, ক্রেতা বদলে দিলে
 * — **পুরনো সইটাই চলে**, আর কাগজটা অনুমোদিত দেখায়।
 *
 * ⓘ পর্দায় *"গত সপ্তাহের সংখ্যা"* সতর্কতা আছে, কিন্তু ওটা কেবল
 * **দেখায়** — আটকায় না।
 *
 * ── ⭐ মালিকের সিদ্ধান্ত, ২৪ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * *"যেকোনো ঘর বদলালেই"* — সবচেয়ে কড়া দিকটা তিনি নিজে বেছেছেন।
 *
 * ⓘ তাই ঘরের তালিকা নয়, **কাগজের গোটা অবস্থার একটা ছাপ**। ⚠️ কয়েকটা
 * ঘর ইচ্ছাকৃতভাবে বাদ (`updated_at` জাতীয়) — নাহলে প্রতিটা সংরক্ষণেই
 * নতুন অনুমোদন লাগত, আর ব্যবস্থাটা কেউ ব্যবহার করত না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            /*
             * ⓘ sha-256 = ৬৪ অক্ষর। ⚠️ `null` মানে পুরনো একটা অনুরোধ
             * যার কোনো ছাপ নেওয়া হয়নি — ওগুলোর আচরণ আগের মতোই
             * (কেবল অঙ্ক দেখা), কারণ ছাপ ছাড়া তুলনা করার কিছু নেই।
             */
            $table->char('state_hash', 64)->nullable()->after('payload');
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropColumn('state_hash');
        });
    }
};
