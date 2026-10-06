<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ বিলের দর বনাম আদেশের দর — নতুন সুইচ `purchase.block_order_price_mismatch` (টাকা আসা-যাওয়ার পরিকল্পনা, ধাপ খ ১০, ৭ অক্টোবর ২০২৬)।
 *
 * ⓘ ঘোষণায় ডিফল্ট চালু (নতুন কোম্পানি), কিন্তু **আজ যত কোম্পানি আছে** সবগুলোতে বন্ধ লেখা হয় — আজকের পথ না ভাঙতে; মালিক
 * নিজে Control Panel থেকে চালু করবেন (fe, ৭ অক্টোবর)। ⚠️ কেবল যেখানে সারিটা এখনো নেই — কেউ আগেই বসিয়ে থাকলে ছোঁয়া হয় না।
 * কোম্পানির code ধরে নয়, সবগুলোতে — ডেমোও একই থাকে।
 */
return new class extends Migration
{
    private const KEY = 'purchase.block_order_price_mismatch';

    public function up(): void
    {
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $exists = DB::table('settings')->where('company_id', $companyId)->where('key', self::KEY)->exists();

            if (! $exists) {
                DB::table('settings')->insert([
                    'company_id' => $companyId,
                    'module' => 'purchase',
                    'key' => self::KEY,
                    'type' => 'boolean',
                    'value' => '0',
                    'group' => 'entry',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('key', self::KEY)->where('value', '0')->delete();
    }
};
