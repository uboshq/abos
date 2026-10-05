<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ ফোনের দোকানে পয়েন্ট — মালিক, ৫ অক্টোবর ২০২৬: *"কাস্টমারের নাম মোবাইল নাম্বার দেয়া আছে এখন সাথে পয়েন্ট আউট করে দাও"*
 * ([[CustomerSync]]-এর `pointName`)।
 *
 * ⓘ ফোন কেবল শেষ টানার পরে বদলানো দোকান পায়; পুরনো দোকানগুলো তাই পয়েন্ট ছাড়াই থেকে যেত। গ্রাহক-মডিউলের "শেষ
 * টানা" একবার মুছে দেওয়া হয় — প্রতিটা ফোন পরের সিঙ্কে সব দোকান আবার টানে, পয়েন্টসহ। ⓘ কেবল এটুকুই: অন্য মডিউল,
 * ফোনের জমা অর্ডার বা অন্য কোনো সারি ছোঁয়া হয় না; আবার টানা মানে ফোনের পুরনো দোকানের সারি নতুনটায় বদলায়।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sync_states')) {
            return;
        }

        DB::table('sync_states')->where('module', 'customer')->update(
            Schema::hasColumn('sync_states', 'page_cursor')
                ? ['last_synced_at' => null, 'page_cursor' => null]
                : ['last_synced_at' => null],
        );
    }

    public function down(): void
    {
        // ⓘ ফেরানোর কিছু নেই — "আবার সব টানো" একবারের কাজ
    }
};
