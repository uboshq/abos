<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * কোন ডিলার কাগজটা বসিয়েছেন।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * `sal_orders.created_by` foreign key `users`-এ যায়। **ডিলার `users`-এ
 * নেই** — সে `customers`-এ, নিজের গার্ডে (`portal`), নিজের পাসওয়ার্ডে।
 *
 * তাই ডিলার পোর্টাল থেকে অর্ডার দিলে ওই ঘরটা **খালি থাকা ছাড়া উপায়
 * নেই**, আর তখন *"কে এটা বসিয়েছিল"* প্রশ্নের উত্তর হারিয়ে যায় —
 * ঠিক সেই কাগজে যেখানে উত্তরটা **সবচেয়ে বেশি দরকার**, কারণ বাইরের
 * একজন মানুষ ওটা বসিয়েছেন।
 *
 * ── কেন এই পথটা, polymorphic নয় ─────────────────────────────────────
 * তিনটা পথ ছিল:
 *
 *   ক  আলাদা কলাম + `source`     ← এটা
 *   খ  `created_by` খালি রেখে দেওয়া   "কে" প্রশ্নের উত্তরই থাকত না
 *   গ  polymorphic creator            নমনীয়, কিন্তু **আজ এই রিপোর
 *                                     কোথাও ওই ধাঁচ নেই** — একা এখানে
 *                                     বসালে অসামঞ্জস্য, আর প্রতিটা
 *                                     কোয়েরিতে দুইটা কলাম মেলাতে হত
 *
 * ⓘ দুইটা কলাম পাশাপাশি থাকায় foreign key দুইটাই আসল থাকে — ডাটাবেস
 * নিজেই নিশ্চিত করে যে নামটা সত্যিকারের একজনকে দেখাচ্ছে। polymorphic-এ
 * ওই নিশ্চয়তা থাকত না।
 *
 * ── ⚠️ কোনটা ভরা থাকবে, তা বলে `source` ─────────────────────────────
 * `source = 'portal'`  → `created_by_customer_id` ভরা, `created_by` খালি
 * `source = 'counter'` → উল্টোটা
 *
 * দুইটাই খালি বা দুইটাই ভরা — কোনোটাই স্বাভাবিক নয়। ⓘ এটা ডাটাবেসে
 * বাঁধা যেত (CHECK), কিন্তু MySQL 8-এর আগে ওটা নীরবে উপেক্ষিত হয়, আর
 * নীরব পাহারা কোনো পাহারা নয় — তাই নিয়মটা সার্ভিস ও টেস্টে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_orders', function (Blueprint $table): void {
            /*
             * ⚠️ `nullOnDelete`, `cascade` নয়।
             *
             * cascade দিলে **একজন ডিলার মুছলে তাঁর প্রতিটা অর্ডার মুছে
             * যেত** — বিক্রির ইতিহাস, খতিয়ানের সূত্র, সব। এই রিপোতে
             * অবশ্য কেউ মোছে না (নিষ্ক্রিয় হয়), কিন্তু পাহারাটা
             * ধারণার উপর ছাড়া যায় না।
             *
             * null হলে যা হয়: কাগজটা থাকে, শুধু নামটা আর দেখানো যায় না।
             * ⓘ আর `source` তখনো বলে দেয় ওটা পোর্টাল থেকে এসেছিল, তাই
             * খালি ঘরটা রহস্য থাকে না।
             */
            $table->foreignId('created_by_customer_id')
                ->nullable()
                ->after('created_by')
                ->constrained('customers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sal_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by_customer_id');
        });
    }
};
