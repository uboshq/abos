<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ছাদ গোটা প্রচারণা গুনত, কিন্তু একটা বিল বা একজন ক্রেতাকে নয় — স্পেক §১৫।
 *
 * ── ⭐ স্পেকের *"Limit Controls"* ───────────────────────────────────
 * Per Invoice · Per Customer · Daily · Monthly — আর আগের চারটা (Campaign,
 * Quantity, Discount, Gift)। ⓘ আগেরগুলো ছিল *"কী গোনা হয়"*; নতুনগুলো
 * *"কোন জানালায় গোনা হয়"*। ⚠️ তাই নতুন ধরন নয়, নতুন একটা ঘর: `per`।
 *
 * ── ⛔ `per` কেন খালি হতে পারে না ────────────────────────────────────
 * ⓘ MySQL আর MariaDB দুইটাই unique সূচিতে `NULL`-কে প্রতিবার আলাদা ধরে।
 * ⚠️ খালি রাখলে *"গোটা অফারের মোট ছাদ"* দুইটা সারি হতে পারত, আর পাহারা
 * ছোটটা মানত, পাতা দেখাত বড়টা। ⭐ তাই শুরুর মান `offer`।
 *
 * ── ⚠️ সূচি বদলের ক্রম ────────────────────────────────────────────────
 * ⓘ `promotion_id`-এর বিদেশি চাবি পুরনো unique সূচিটাকেই নিজের সূচি
 * হিসেবে ব্যবহার করে। ⛔ আগে ফেলে দিলে MySQL আপত্তি করত (*"needed in a
 * foreign key constraint"*)। ⭐ তাই আগে নতুনটা বসে — সেটাও `promotion_id`
 * দিয়ে শুরু — তারপর পুরনোটা যায়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_budgets', function (Blueprint $table) {
            $table->string('per', 12)->default('offer')->after('kind');
        });

        Schema::table('promotion_budgets', function (Blueprint $table) {
            $table->unique(['promotion_id', 'kind', 'per'], 'pbg_kind_per_uq');
        });

        Schema::table('promotion_budgets', function (Blueprint $table) {
            $table->dropUnique('pbg_kind_uq');
        });
    }

    public function down(): void
    {
        /*
         * ⚠️ ফেরার আগে জানালার সারিগুলো মোছা — নাহলে পুরনো unique সূচি
         * (`promotion_id`, `kind`) বসতেই পারত না, একই ধরনে দুইটা সারি থাকায়।
         */
        \App\Modules\Promotion\Models\PromotionBudget::query()
            ->withoutGlobalScopes()
            ->where('per', '!=', 'offer')
            ->delete();

        Schema::table('promotion_budgets', function (Blueprint $table) {
            $table->unique(['promotion_id', 'kind'], 'pbg_kind_uq');
        });

        Schema::table('promotion_budgets', function (Blueprint $table) {
            $table->dropUnique('pbg_kind_per_uq');
            $table->dropColumn('per');
        });
    }
};
