<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * খরচের স্তর জানে না মালটা কোন লটের — চূড়ান্ত অডিট, ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ঘটত ──────────────────────────────────────────────────────────
 * বিক্রেতা লট বাছলে গুদাম থেকে সেই লটটাই বেরোয় ([[StockService::issue()]]), কিন্তু খরচ টানা হত পণ্য ধরে
 * FIFO-তে — স্তরে লটের কোনো ঘরই ছিল না। ⓘ মাপা: LOT-NEW (৩,৮০০) বেচা হলো, খাতায় বসল LOT-OLD-এর ৩,০০০।
 * লাভ ভুল, আর তাকে পড়ে থাকা মালের মূল্যও ভুল।
 *
 * ── এখানে দুইটা ঘর ─────────────────────────────────────────────────────
 *   `inv_cost_layers.batch_id`      — স্তরটা কোন লটের। খালি = লটহীন মাল, বা পুরনো স্তর যার লট জানা নেই।
 *   `inv_cost_layer_uses.fallback`  — লট চাওয়া হয়েছিল, কিন্তু তার স্তরে কুলায়নি, তাই FIFO-তে টানা।
 *
 * ⛔ দ্বিতীয়টা ইচ্ছাকৃত: লটের স্তর না থাকলে FIFO-তে পড়া **কখনো নীরব নয়** (সমন্বয়কের শর্ত)। প্রতিটা এমন টান
 * খাতায় চিহ্নিত থাকে, তাই "কতটা বিক্রি লটের দামে হয়নি" প্রশ্নের উত্তর গোনা যায়।
 *
 * ⓘ MariaDB-নিরাপদ: কেবল ADD COLUMN, একটা FK আর একটা সাধারণ সূচক; সূচকের নাম হাতে দেওয়া, ৬৪ অক্ষরের অনেক নিচে।
 * ⚠️ পুরনো স্তরে লট বসানো এখানে নয় — আলাদা কমান্ডে, আগে শুকনো চালিয়ে (সমন্বয়কের ডিপ্লয়ে)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_cost_layers', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('product_id')
                ->constrained('inv_batches', indexName: 'inv_cost_layers_batch_fk')->nullOnDelete();

            // ⓘ লটের খোলা স্তর খোঁজা — "এই পণ্যের এই লটে কী বাকি"
            $table->index(['company_id', 'product_id', 'batch_id'], 'cost_layer_lot');
        });

        Schema::table('inv_cost_layer_uses', function (Blueprint $table) {
            $table->boolean('fallback')->default(false)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('inv_cost_layer_uses', function (Blueprint $table) {
            $table->dropColumn('fallback');
        });

        Schema::table('inv_cost_layers', function (Blueprint $table) {
            $table->dropIndex('cost_layer_lot');
            $table->dropForeign('inv_cost_layers_batch_fk');
            $table->dropColumn('batch_id');
        });
    }
};
