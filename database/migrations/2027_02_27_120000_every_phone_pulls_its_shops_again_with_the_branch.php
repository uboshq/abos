<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ ফোনের দোকানে শাখা — মালিক, ১৯ অক্টোবর ২০২৬ (স্থায়ী নিয়ম): *"গ্রাহকের নামের পাশে পয়েন্ট দিতে হবে অবশ্যই, সব জায়গায়,
 * আর শাখার নামও দিতে হবে"* ([[CustomerSync]]-এর `branchName`)।
 *
 * ⓘ ফোন কেবল শেষ টানার পরে বদলানো দোকান পায়; পুরনো দোকানগুলো তাই শাখা ছাড়াই থেকে যেত। গ্রাহক-মডিউলের "শেষ টানা"
 * একবার মুছে দেওয়া হয় — প্রতিটা ফোন পরের সিঙ্কে সব দোকান আবার টানে, শাখাসহ। পয়েন্টের বেলায় যা করা হয়েছিল, ঠিক তাই
 * (`2027_02_10_110000_every_phone_pulls_its_shops_again_with_the_point`); অন্য মডিউল বা ফোনের জমা অর্ডার ছোঁয়া হয় না।
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
