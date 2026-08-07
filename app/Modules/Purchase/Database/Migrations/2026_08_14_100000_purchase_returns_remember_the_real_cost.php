<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ফেরত যাওয়া মালটা আসলে কত টাকায় ঢুকেছিল।
 *
 * ── কেন এটা বিলের দর থেকে আলাদা ─────────────────────────────────────
 * সরবরাহকারীকে মাল ফেরত দিলে প্রদেয় কমে **বিলের দরে** — ওটাই তিনি ফেরত
 * দেবেন। কিন্তু মজুদ থেকে বেরোয় মালটা যে দামে গুদামে ঢুকেছিল, সেই দামে।
 *
 * দুইটা সচরাচর এক, কারণ ফেরত যাওয়া মাল সাধারণত ওই বিলেরই। কিন্তু FIFO-তে
 * তাক থেকে বেরোয় পুরনো চালানের মাল, আর ওই বিলের মাল ততক্ষণে বিক্রি হয়ে
 * গিয়ে থাকতে পারে। তখন দুইটা আলাদা, আর পার্থক্যটা মূল্য-পার্থক্য খাতে যায়।
 *
 * সংখ্যাটা জমা রাখা হয়, প্রতিবার নতুন করে গোনা হয় না — কারণ স্তরগুলো
 * পরে বদলায়, আর তখন গত মাসের ফেরতের অঙ্ক আজ অন্যরকম দেখাত। বিক্রয় বিলেও
 * ঠিক একই সিদ্ধান্ত, একই কারণে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pur_returns', function (Blueprint $table) {
            $table->decimal('cost_of_goods', 18, 4)->default(0)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('pur_returns', function (Blueprint $table) {
            $table->dropColumn('cost_of_goods');
        });
    }
};
