<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * জাবেদা নিজেকে উল্টে দেওয়ার কথা বলত, তারিখ লেখার ঘর ছিল না।
 *
 * ── ⭐ উল্টো দাখিলা কী, আর কেন তারিখটা আগেই লেখা হয় ─────────────────
 * মাসের শেষে কিছু হিসাব **সাময়িক**: বিদ্যুৎ বিল এসে পৌঁছায়নি, কিন্তু
 * খরচটা এই মাসেরই। তাই একটা জাবেদা বসে, আর পরের মাসের ১ তারিখে সেটা
 * **উল্টে** যায় — নাহলে আসল বিল এলে খরচটা দুইবার বসত।
 *
 * ⚠️ তারিখটা ভাউচারের সাথেই লেখা থাকে, মনে রাখার উপর নয়। ⓘ মনে রাখার
 * উপর ছাড়লে কেউ একদিন ভুলে যেত, আর দুইবার বসা খরচটা ধরা পড়ত বছরের
 * শেষে — যখন আর কেউ মনে করতে পারে না কেন।
 *
 * ── ⓘ কেন `nullable` ────────────────────────────────────────────────
 * বেশিরভাগ জাবেদা উল্টানোর নয় (সংশোধন, সমন্বয়, খোলার জের)। ⛔ বাধ্যতামূলক
 * করলে মানুষ একটা যেকোনো তারিখ বসিয়ে দিতেন, আর তখন ঘরটা মিথ্যা বলত।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->date('reverse_on')->nullable()->after('lands_on');

            /*
             * ⓘ সূচকটা তারিখ ধরে, কারণ প্রশ্নটা সবসময় একই আকারের:
             * "আজ কোন কোন জাবেদা উল্টে যাওয়ার কথা?"
             */
            $table->index(['company_id', 'reverse_on'], 'vouchers_reverse_on_index');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->dropIndex('vouchers_reverse_on_index');
            $table->dropColumn('reverse_on');
        });
    }
};
