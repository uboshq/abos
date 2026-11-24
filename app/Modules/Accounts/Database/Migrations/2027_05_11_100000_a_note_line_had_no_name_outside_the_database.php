<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * ⭐ নোট আর স্টক ছাড়া ভাউচারের সারির বাইরের কী — ২৪ নভেম্বর ২০২৬।
 *
 * ⓘ আগের মাইগ্রেশনটা ডেমোতে চলে গেছে, তাই কলামটা সেখানে নয়, এখানে যোগ। পুরনো সারি থাকলে তারাও কী পায়।
 */
return new class extends Migration
{
    private const TABLES = ['acc_note_lines', 'acc_trade_voucher_lines'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if (Schema::hasColumn($name, 'public_id')) {
                continue;
            }

            Schema::table($name, fn (Blueprint $table) => $table->publicId());

            DB::table($name)->whereNull('public_id')->orderBy('id')->select('id')->chunkById(500, function ($rows) use ($name): void {
                foreach ($rows as $row) {
                    DB::table($name)->where('id', $row->id)->update(['public_id' => (string) Str::uuid7()]);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (Schema::hasColumn($name, 'public_id')) {
                Schema::table($name, fn (Blueprint $table) => $table->dropColumn('public_id'));
            }
        }
    }
};
