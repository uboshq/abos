<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ লেখক ≠ পাকাকারী — ভাউচারের আন্তর্জাতিক পরিকল্পনা, অংশ ৩গ (৭ অক্টোবর ২০২৬)।
 *
 * ⓘ `accounts.voucher_maker_checker` ঘোষণায় চালু, কিন্তু **আজ যত কোম্পানি আছে** সবগুলোতে বন্ধ লেখা হয় — আজকের পথ না
 * ভাঙতে; মালিক নিজে চালু করবেন (fe, ৭ অক্টোবর)। ⚠️ কেবল যেখানে সারিটা এখনো নেই। কোম্পানির code ধরে নয়।
 */
return new class extends Migration
{
    private const KEY = 'accounts.voucher_maker_checker';

    public function up(): void
    {
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            if (! DB::table('settings')->where('company_id', $companyId)->where('key', self::KEY)->exists()) {
                DB::table('settings')->insert([
                    'company_id' => $companyId,
                    'module' => 'accounts',
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
