<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * পণ্যের শাখার সারি — বাইরের নাম (`public_id`), ৬ অক্টোবর ২০২৬।
 *
 * ⛔ টেবিলটা `2027_02_01_100000_each_branch_sells_its_own_goods`-এ জন্মেছিল `publicId()` ছাড়া; `PublicIdTest` লাল।
 * ⚠️ ঐ মাইগ্রেশন লাইভে চলে গেছে, তাই নতুন মাইগ্রেশন। ⓘ কেবল ঘর যোগ (MariaDB-নিরাপদ), আর পুরনো সারিগুলোও নাম পায়;
 * নতুন সারি নাম পায় [[ProductBranch]]-এর জন্মে।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inv_product_branches', 'public_id')) {
            Schema::table('inv_product_branches', function (Blueprint $table) {
                $table->publicId();
            });
        }

        DB::table('inv_product_branches')->whereNull('public_id')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('inv_product_branches')->where('id', $row->id)->update(['public_id' => (string) Str::uuid7()]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('inv_product_branches', function (Blueprint $table) {
            $table->dropColumn('public_id');
        });
    }
};
