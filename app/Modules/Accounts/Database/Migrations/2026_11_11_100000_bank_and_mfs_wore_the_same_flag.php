<?php

declare(strict_types=1);

use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ব্যাংক আর MFS এক পতাকাতেই বসে ছিল, আর পতাকাটা কেউ বসাতই না।
 *
 * ── কী ধরা পড়ল, ১৩ সেপ্টেম্বর ২০২৬ ──────────────────────────────────
 * মালিক পর্দায় দেখলেন একটাই টিক — "Bank or MFS account" — আর প্রশ্ন
 * করলেন MFS আলাদা কেন নয়। খুঁজতে গিয়ে বেরোলো সমস্যাটা লেবেলের নয়,
 * সংজ্ঞার: **এই সিস্টেমে "ব্যাংক" মানে দুই রকম**, আর দুইটা একমত নয়।
 *
 *   পতাকা ধরে  → `is_bank` — ১২ জায়গায় পড়া হয়
 *   গাছ ধরে    → [[StandardChart::MONEY_PARENTS]] (১১০১ নগদ · ১১০২
 *                 ব্যাংক · ১১০৫ MFS) — Finance-এর প্রতিটা পর্দা
 *
 * ⛔ আর সবচেয়ে খারাপ দিকটা: প্রমিত ছকের একটা সারিও `'bank' => true`
 * পাঠায় না। অর্থাৎ নতুন কোম্পানিতে `is_bank` **সর্বত্র false**, আর
 * পতাকাটা ওঠে একমাত্র মানুষের হাতে, CoA ফর্মের ওই টিক থেকে।
 *
 * তাই মূলধনের টাকা "ব্যাংকে এসেছে" বলার সময় পর্দাটা খাত বাছত **গাছ
 * ধরে**, আর পোস্ট করার পাহারাটা লেনদেন নম্বর চাইত **পতাকা ধরে** —
 * দুইটা আলাদা প্রশ্ন, একটাই নাম। ব্যবহারকারী এমন একটা নম্বর দিতে
 * বাধ্য হতেন যেটা ফর্ম কখনো জিজ্ঞেস করেনি, বা উল্টোটা।
 *
 * ── সিদ্ধান্ত: গাছটাই সত্য ─────────────────────────────────────────
 * ⭐ কাঠামোটা আগে থেকেই ঠিক ছিল — তিনটা আলাদা মা, আর ৩০ আগস্টের
 * মন্তব্যে কারণও লেখা (*"বিকাশ ক্যাশ-আউটে চার্জ কাটে, ব্যাংক কাটে না"*)।
 * ভুল ছিল কেবল পাশে বসানো পতাকা দুইটা, যারা একই প্রশ্নের দ্বিতীয়
 * উত্তর দিত।
 *
 * তাই `is_cash` ও `is_bank` তুলে দেওয়া হলো, আর তাদের জায়গায়
 * একটাই ঘর — `money_kind` — যেটা **হাতে লেখা হয় না**, বাবার খাত
 * থেকে বসে ([[AccountService::moneyKindFor()]])। তিন পতাকার আটটা
 * সম্ভাব্য অবস্থার ছয়টা অবৈধ ছিল; এখন অবৈধ অবস্থাটা জন্মায়ই না।
 *
 * ── বাড়তি দুইটা ঘর ────────────────────────────────────────────────
 * `account_title` ও `routing_no` — মালিক ধরেছেন ব্যাংকের তথ্যে
 * হিসাবের নাম আর রাউটিং নম্বর নেই। রাউটিং ছাড়া EFT/RTGS ফাইল বানানো
 * যায় না, আর হিসাবের নাম ছাড়া জমা স্লিপে কার নাম লেখা হবে তা জানা
 * যায় না। ⓘ MFS-এ এই দুইটা লাগে না, আর পর্দাটা সেটা জানে।
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ⛔ ক্রম ভুল হলে জোরে থামে, নীরবে নয়।
         *
         * এই রিপোর মাইগ্রেশনের তারিখ বাস্তব দিনের চেয়ে এগিয়ে (আজ ১৩
         * সেপ্টেম্বর, সবচেয়ে পরের মাইগ্রেশন নভেম্বর)। আজকের তারিখ দিলে
         * ফাইলটা `accounts` জন্মানোর আগে চলত, `migrate` সবুজ দেখাত, আর
         * একটা কলামও বসত না — আর দ্বিতীয়বার চালালেও কিছু হত না, কারণ
         * Laravel ততক্ষণে ওটাকে "চলে গেছে" ধরে নিয়েছে। আজ একজনের সাথে
         * ঠিক সেটাই হয়েছে।
         */
        if (! Schema::hasTable('accounts')) {
            throw new RuntimeException(
                'accounts টেবিল নেই — মাইগ্রেশনের ক্রম দেখুন, এই ফাইলটা তার পরে চলতে হবে।'
            );
        }

        Schema::table('accounts', function (Blueprint $table) {
            /*
             * cash · bank · mfs · NULL।
             *
             * NULL মানে "টাকার খাত নয়" — false নয়, কারণ প্রশ্নটা
             * হ্যাঁ/না নয়, "কোনটা"। ইনডেক্স আছে কারণ প্রতিটা টাকার
             * খাতের picker এই কলামেই ছাঁকে।
             */
            $table->string('money_kind', 8)->nullable()->index()->after('is_group');

            // ব্যাংকের ঘর — MFS-এ এগুলো চাওয়া হয় না
            $table->string('account_title', 160)->nullable()->after('branch_name');
            $table->string('routing_no', 32)->nullable()->after('account_title');
        });

        $marked = $this->backfill();

        /*
         * ⛔ পতাকা দুইটা ফেলে দেওয়ার আগে গুনে দেখা — কারণ ফেলে দিলে
         * আর ফিরে দেখা যায় না।
         *
         * আজ একজনের মাইগ্রেশন "DONE" লিখে একটা কলামও বসায়নি, আর তার
         * নিজের যাচাইটাও পাস করেছিল কারণ গোনার মতো কিছুই ছিল না। শূন্যের
         * উপর দাবি শূন্যেই সত্য। তাই এখানে প্রশ্নটা উল্টো করে করা হয়:
         * আগে যতগুলো সারিতে টাকার পতাকা উঠেছিল, ঠিক ততগুলোতে ধরন বসেছে
         * কি না।
         */
        $lost = (int) DB::table('accounts')
            ->whereNull('money_kind')
            ->where(fn ($q) => $q->where('is_cash', true)->orWhere('is_bank', true))
            ->count();

        if ($lost > 0) {
            throw new RuntimeException(
                "{$lost}টা খাতে টাকার পতাকা আছে অথচ ধরন বসেনি — ওগুলো টাকার খাত "
                .'হিসেবে হারিয়ে যেত, আর সেটা নগদ বই ও ব্যাংক বই থেকে নীরবে মুছে ফেলত। পতাকা ফেলা হলো না।'
            );
        }

        // ⓘ সংখ্যাটা লগে — যাতে পরে জানা যায় মাইগ্রেশনটা কী ছুঁয়েছিল
        logger()->info("money_kind: {$marked}টা খাতে ধরন বসানো হলো।");

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['is_cash', 'is_bank']);
        });
    }

    /**
     * পুরনো সারিগুলোর ধরন বসানো — গাছ আগে, পতাকা পরে।
     *
     * ── কেন দুইটা পাস, আর কেন এই ক্রমে ────────────────────────────────
     * গাছটা কর্তৃপক্ষ, তাই সেটা আগে চলে। পতাকাটা কেবল সেই খাতগুলোর
     * জন্য যেগুলো তিন মায়ের বাইরে বসে অথচ কেউ হাতে টিক দিয়েছিলেন —
     * ওগুলো হারিয়ে গেলে টিলের নগদ খাত নগদ বই থেকে উধাও হত।
     *
     * ⚠️ পতাকা থেকে আসা `bank` আসলে "ব্যাংক অথবা MFS" — কোনটা তা জানার
     * উপায় নেই, কারণ তথ্যটা কোনোদিন রাখাই হয়নি। তাই ওগুলো `bank` ধরা
     * হলো, আর নামের ভেতর MFS-এর চিহ্ন থাকলে `mfs`। ⓘ একই অনুমানটা
     * [[SplitBankAndMobileMoney]] কমান্ড আগে থেকেই করে আসছে; এটা তার
     * শেষবার, কারণ এরপর থেকে ধরনটা বাবার খাত থেকে আসে, নাম থেকে নয়।
     */
    private function backfill(): int
    {
        $marked = 0;

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach ([
                StandardChart::CASH_IN_HAND => 'cash',
                StandardChart::BANK => 'bank',
                StandardChart::MOBILE_MONEY => 'mfs',
            ] as $code => $kind) {
                $root = DB::table('accounts')
                    ->where('company_id', $companyId)
                    ->where('code', $code)
                    ->value('id');

                if ($root === null) {
                    continue;
                }

                $marked += DB::table('accounts')
                    ->where('company_id', $companyId)
                    ->whereIn('id', $this->descendantsOf($companyId, (int) $root))
                    // ⚠️ গ্রুপও পায় — সন্তানরা ধরনটা বাবার কাছ থেকে নেয়,
                    // তাই শিকড় খালি থাকলে শিকল ভেঙে যেত। বাছাইয়ের
                    // তালিকা থেকে ওরা বাদ পড়ে [[Account::scopeMoney()]]-এ।
                    ->update(['money_kind' => $kind]);
            }
        }

        // তিন মায়ের বাইরে, হাতে টিক দেওয়া খাতগুলো
        $marked += DB::table('accounts')->whereNull('money_kind')
            ->where('is_cash', true)->update(['money_kind' => 'cash']);

        $names = ['bkash', 'bKash', 'বিকাশ', 'nagad', 'নগদ', 'rocket', 'রকেট', 'upay', 'উপায়', 'mfs', 'MFS'];

        $marked += DB::table('accounts')->whereNull('money_kind')->where('is_bank', true)
            ->where(function ($q) use ($names) {
                foreach ($names as $name) {
                    $q->orWhere('name_en', 'like', '%'.$name.'%')
                        ->orWhere('name_bn', 'like', '%'.$name.'%');
                }
            })
            ->update(['money_kind' => 'mfs']);

        $marked += DB::table('accounts')->whereNull('money_kind')
            ->where('is_bank', true)->update(['money_kind' => 'bank']);

        return $marked;
    }

    /**
     * একটা মাথার নিচের সব বংশধরের id — শিকড় সহ।
     *
     * ⓘ পুনরাবৃত্ত কোয়েরির বদলে স্তরে স্তরে, কারণ ছকের গভীরতা তিন-চার
     * স্তরের বেশি হয় না আর `WITH RECURSIVE` MySQL ৮ ছাড়া চলে না।
     *
     * @return list<int>
     */
    private function descendantsOf(int $companyId, int $root): array
    {
        $all = [$root];
        $frontier = [$root];

        while ($frontier !== []) {
            $next = DB::table('accounts')
                ->where('company_id', $companyId)
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $frontier = array_values(array_diff($next, $all));
            $all = array_merge($all, $frontier);
        }

        return $all;
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('is_cash')->default(false)->after('is_group');
            $table->boolean('is_bank')->default(false)->after('is_cash');
        });

        // ⚠️ MFS আর ব্যাংকের পার্থক্যটা এখানে হারায় — ফেরার পথে দুইটাই
        // `is_bank`, কারণ পুরনো স্কিমায় ওটা রাখার কোনো ঘর ছিল না
        DB::table('accounts')->where('money_kind', 'cash')->update(['is_cash' => true]);
        DB::table('accounts')->whereIn('money_kind', ['bank', 'mfs'])->update(['is_bank' => true]);

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['money_kind', 'account_title', 'routing_no']);
        });
    }
};
