<?php

declare(strict_types=1);

use App\Modules\Accounts\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * সুদ ব্যয়ের খাত — পুরনো কোম্পানিগুলোতেও।
 *
 * ── কেন এটা দরকার ───────────────────────────────────────────────────
 * সুদের খাতটা মানসম্মত ছকে যোগ করা হয়েছে, আর `StandardChart::install()`
 * অনুপস্থিত খাত বসায় — কিন্তু সেটা কাউকে বোতাম চাপতে হয়। যতক্ষণ না
 * চাপা হচ্ছে, ঋণের ফর্মের ডিফল্টটা কিছুই খুঁজে পেত না, আর ব্যবহারকারী
 * আবার তালিকা থেকে "ব্যাংক চার্জ" বেছে নিতেন — ঠিক যে ভুলটা এড়ানোর
 * জন্য খাতটা বানানো।
 *
 * ── কেন Account মডেল দিয়ে নয়, সরাসরি ─────────────────────────────────
 * `Account`-এ কোম্পানির গ্লোবাল স্কোপ বসানো, আর মাইগ্রেশনে কোনো
 * কোম্পানি-প্রসঙ্গ থাকে না। মডেল দিয়ে লিখতে গেলে হয় স্কোপ বন্ধ করতে
 * হত, নয় প্রতিটা কোম্পানির জন্য প্রসঙ্গ বসিয়ে ঘুরতে হত — দুইটাই
 * মাইগ্রেশনে ভঙ্গুর। কোয়েরি বিল্ডারে কোম্পানি ধরে ধরে বসানোই সরল ও
 * পড়ার মতো।
 *
 * ── যা ইচ্ছাকৃতভাবে করা হয় না ────────────────────────────────────────
 * পুরনো ঋণগুলোর খাত বদলানো হয় না। যে সুদ ব্যাংক চার্জে বসে গেছে সেটা
 * ওখানেই থাকে — খাত বদলানো মানে ইতিহাস বদলানো, আর গত বছরের লাভ-লোকসান
 * আজ বদলে গেলে কেউ আর কোনো ছাপা কাগজে বিশ্বাস করতে পারত না। যাঁরা
 * সরাতে চান, তাঁরা ঋণের পাতা থেকে নিজেরাই সরাবেন — আগামী কিস্তি থেকে।
 */
return new class extends Migration
{
    private const CODE = '5310';

    public function up(): void
    {
        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $exists = DB::table('accounts')
                ->where('company_id', $companyId)
                ->where('code', self::CODE)
                ->exists();

            if ($exists) {
                continue;
            }

            // ৫০০০ = ব্যয়ের গোড়া। কোম্পানি সেটা মুছে ফেললে খাতটা
            // গোড়াতেই বসে — না বসানোর চেয়ে ভালো।
            $parentId = DB::table('accounts')
                ->where('company_id', $companyId)
                ->where('code', '5000')
                ->value('id');

            DB::table('accounts')->insert([
                'public_id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'code' => self::CODE,
                'name_en' => 'Interest Expense',
                'name_bn' => 'সুদ ব্যয়',
                'type' => Account::EXPENSE,
                'parent_id' => $parentId,
                'is_group' => false,
                'is_cash' => false,
                'is_bank' => false,
                'nature' => Account::defaultNatureFor(Account::EXPENSE),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        /*
         * খাতটা মোছা হয় না।
         *
         * ততক্ষণে ওতে সুদ বসে গেছে থাকতে পারে, আর খাত মুছলে ওই সারিগুলো
         * এমন একটা খাতের দিকে দেখাত যা নেই — খতিয়ানে ছিদ্র। মাইগ্রেশন
         * ফেরানো মানে কোডটা ফেরানো, খাতাটা নয়।
         */
    }
};
