<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ মেয়াদ পেরোনো বাকির দেয়ালের আলাদা চালু/বন্ধ — বাকি নিয়ন্ত্রণ ধাপ ২.১ (মালিকের উত্তর ১, ১১ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কী বলা যেত না ─────────────────────────────────────────────────
 * `customer.overdue_block_days` একাই দুই কাজ করত: ০ = দেয়াল বন্ধ, X = মেয়াদের X দিন পরে দেয়াল। তাই মালিকের উত্তর —
 * *"মেয়াদ পেরোলেই নতুন বাকি বন্ধ"* — বসানোই যেত না: "মেয়াদ পেরোলেই" মানে ০ দিন, আর ০ মানে বন্ধ।
 *
 * ── ⭐ এখন ───────────────────────────────────────────────────────────
 * নতুন সুইচ `customer.overdue_stop_enabled` (ডিফল্ট চালু), আর পুরনো সংখ্যাটা কেবল ছাড়ের দিন (০ = মেয়াদ পেরোলেই)।
 *
 * ── ⛔ চলতি কোম্পানির আচরণ এক চুলও বদলায় না ───────────────────────────
 * ডিফল্ট চালু, তাই কিছু না লিখলে চালু হওয়ার দিনেই প্রতিটা লাইভ কোম্পানিতে দেয়াল উঠত — যাদের দিন ০ (= বন্ধ) ছিল, তাদের
 * প্রতিটা মেয়াদ পেরোনো গ্রাহক সকালে আটকাতেন। ⭐ তাই আজ যে কোম্পানি আছে, তার জন্য আগের আচরণটাই সারি হয়ে বসে:
 *   · দিন ০ বা বসানো নেই → সুইচ বন্ধ (আগেও বন্ধ ছিল)
 *   · দিন X > ০ → সুইচ চালু, ছাড় X (আগেও X দিনে দেয়াল ছিল)
 * ⛔ `customer.overdue_block_days`-এর কোনো সারি ছোঁয়া হয় না — মান যা ছিল তা-ই। মালিক নিশ্চিত করলে সমন্বয়কারী লাইভ
 * কোম্পানিগুলোর সুইচ চালু করবেন। ⓘ কোনো কোম্পানির আগে থেকেই সুইচের সারি থাকলে (আবার চালানো) সেটাও ছোঁয়া হয় না।
 */
return new class extends Migration
{
    private const SWITCH = 'customer.overdue_stop_enabled';

    private const DAYS = 'customer.overdue_block_days';

    public function up(): void
    {
        if (! Schema::hasTable('settings') || ! Schema::hasTable('companies')) {
            return;
        }

        $days = DB::table('settings')->where('key', self::DAYS)->whereNotNull('company_id')
            ->pluck('value', 'company_id');

        $already = DB::table('settings')->where('key', self::SWITCH)->whereNotNull('company_id')
            ->pluck('company_id')->map(fn ($id) => (int) $id)->all();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            if (in_array((int) $companyId, $already, true)) {
                continue;
            }

            DB::table('settings')->insert([
                'company_id' => $companyId,
                'module' => 'customer',
                'key' => self::SWITCH,
                'type' => 'boolean',
                'value' => (int) ($days[$companyId] ?? 0) > 0 ? '1' : '0',
                'group' => 'entry',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * ⓘ সুইচের সারিগুলো তোলা — পুরনো কোড সুইচটা চেনে না, আর দিনের সংখ্যা তো ছোঁয়াই হয়নি, তাই আচরণ হুবহু আগের মতো।
     */
    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')->where('key', self::SWITCH)->delete();
    }
};
