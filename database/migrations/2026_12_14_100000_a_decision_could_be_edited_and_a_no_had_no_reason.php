<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * একটা সিদ্ধান্ত বদলানো যেত, আর একটা "না"-র কোনো কারণ থাকত না।
 *
 * ── ⛔ দুইটা আলাদা ফাঁক, একই জায়গায় ────────────────────────────────
 * ১ · `approval_decisions` আজ কেবল `create()` দিয়ে লেখা হয় — কোনো
 * `update`/`delete` পথ নেই। ⚠️ কিন্তু সেটা **দুর্ঘটনাক্রমে**, নকশায়
 * নয়। ⓘ আগামীকাল কেউ একটা সম্পাদনার পর্দা লিখলে কিছুই আটকাত না, আর
 * নিরীক্ষার গোটা ভিত্তিটাই নড়ে যেত।
 *
 * ২ · বাতিল করার সময় কারণ **বাধ্যতামূলক নয়**। ⚠️ ফল: অনুরোধকারী জানেন
 * না কী শুধরাতে হবে, আর রিপোর্টে *"কেন বাতিল হয়"* প্রশ্নের উত্তর নেই।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_decisions', function (Blueprint $table) {
            /*
             * ⭐ বাতিলের কারণ — বাছাই করা একটা কোড।
             *
             * ⓘ মুক্ত লেখাও থাকে (`remarks`), কিন্তু **গোনার** জন্য কোড
             * লাগে। ⚠️ কেবল মুক্ত লেখা রাখলে *"দাম ভুল"* দশ বানানে
             * লেখা হত, আর কোনো রিপোর্ট ওগুলোকে এক করতে পারত না।
             */
            $table->string('reason_code', 32)->nullable()->after('decision');
        });
    }

    public function down(): void
    {
        Schema::table('approval_decisions', function (Blueprint $table) {
            $table->dropColumn('reason_code');
        });
    }
};
