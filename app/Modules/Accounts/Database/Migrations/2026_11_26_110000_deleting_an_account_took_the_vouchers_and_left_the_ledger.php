<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * খাত মুছলে ভাউচারের সারি যেত, আর খতিয়ানের সারি পড়ে থাকত।
 *
 * ── ⛔ অসামঞ্জস্যটা কোথায় ───────────────────────────────────────────
 * `voucher_lines.account_id`-এ `cascadeOnDelete`, অথচ
 * `ledger_entries.account_id`-এ **কোনো foreign key-ই নেই** — আর সেটা
 * ইচ্ছাকৃত: কোর কোনো মডিউলের টেবিল চেনে না ([[ledger_entries]]-এর
 * মাইগ্রেশনে লেখা)।
 *
 * ⚠️ ফল: একটা খাত হার্ড-ডিলিট হলে ভাউচারের সারিগুলো **নীরবে** চলে যেত,
 * আর খতিয়ানের সারিগুলো অনাথ হয়ে থেকে যেত। ⛔ আর সবচেয়ে খারাপটা —
 * স্থিতিপত্র তখনও **"মিলেছে"** বলত, কারণ অনাথ সারিগুলো `INNER JOIN`-এ
 * বাদ পড়ে যায়।
 *
 * ── কী করা হচ্ছে, আর কেন এটাই সঠিক দিক ──────────────────────────────
 * ⭐ `cascade` → `restrict`. অর্থাৎ যে খাতে একটা ভাউচারের সারিও আছে,
 * সেটা মুছতে গেলে **ডেটাবেস নিজেই থামিয়ে দেবে**।
 *
 * ⓘ খতিয়ানে FK বসানো হয়নি, আর হবেও না — সীমানাটা ঠিক আছে। ⚠️ কিন্তু
 * এই একটা তালা ঐ দুইটাকেই বাঁচায়: ভাউচারের সারি যেখানে আছে, খতিয়ানের
 * সারিও সেখানেই আছে, তাই ভাউচারের দরজা আটকালে খতিয়ানও অনাথ হয় না।
 *
 * ⓘ পর্দা থেকে কেউ খাত মোছেই না — `destroy()` কেবল নিষ্ক্রিয় করে
 * ([[AccountService::deactivate()]])। ⚠️ তাই এই তালাটা পর্দার জন্য নয়,
 * **কাঁচা SQL আর ভবিষ্যতের কোডের** জন্য: নীরব ক্ষতির বদলে জোরালো ভুল।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voucher_lines', function (Blueprint $table) {
            $table->dropForeign(['account_id']);

            /*
             * ⛔ `restrictOnDelete` — মুছতে দেওয়া হয় না, cascade নয়।
             * ⓘ `nullOnDelete` চলত না: খাত ছাড়া ভাউচারের সারি মানে
             * "টাকাটা কোথায় বসল" প্রশ্নের উত্তর হারিয়ে যাওয়া।
             */
            $table->foreign('account_id')
                ->references('id')->on('accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('voucher_lines', function (Blueprint $table) {
            $table->dropForeign(['account_id']);

            $table->foreign('account_id')
                ->references('id')->on('accounts')
                ->cascadeOnDelete();
        });
    }
};
