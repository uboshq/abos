<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ মেয়াদ পেরোনো বিল নতুন বাকি থামায় — চালু/বন্ধের নিজের সুইচ (মালিকের উত্তর ১, ১১ অক্টোবর ২০২৬; বাকি নিয়ন্ত্রণ ধাপ ২.১)।
 *
 * ⛔ আগে একটাই ঘর ছিল — `customer.overdue_block_days`, আর ০ মানে "বন্ধ"। তাই মালিকের কথা ("মেয়াদ পেরোলেই") বলা যেত না।
 * ⭐ এখন `customer.overdue_stop` চালু/বন্ধ বলে, আর দিনের ঘর কেবল ছাড়ের দিন।
 *
 * ⓘ চলতি কোম্পানির আজকের আচরণ হুবহু থাকে: যার দিন > ০ বসানো ছিল (দেয়াল চালু ছিল), তার সুইচ চালু লেখা হয় — ছাড় সেই
 * দিনই, তাই কাটার তারিখ একই। বাকিদের কিছু লেখা হয় না (ডিফল্ট বন্ধ = আজকের মতোই বন্ধ)।
 * ⛔ `customer.overdue_block_days`-এর মান কোনো কোম্পানিতে ছোঁয়া হয় না — চলতি কোম্পানির সেটিং সমন্বয়কারী মালিকের কথায় বসান।
 * ⚠️ কেবল যেখানে সুইচের সারি এখনো নেই।
 */
return new class extends Migration
{
    private const KEY = 'customer.overdue_stop';

    private const DAYS = 'customer.overdue_block_days';

    public function up(): void
    {
        $withDays = DB::table('settings')
            ->where('key', self::DAYS)
            ->whereNotNull('company_id')
            ->get(['company_id', 'value']);

        foreach ($withDays as $row) {
            if ((int) $row->value <= 0) {
                continue;
            }

            $exists = DB::table('settings')
                ->where('company_id', $row->company_id)
                ->where('key', self::KEY)
                ->exists();

            if (! $exists) {
                DB::table('settings')->insert([
                    'company_id' => $row->company_id,
                    'module' => 'customer',
                    'key' => self::KEY,
                    'type' => 'boolean',
                    'value' => '1',
                    'group' => 'entry',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('key', self::KEY)->delete();
    }
};
