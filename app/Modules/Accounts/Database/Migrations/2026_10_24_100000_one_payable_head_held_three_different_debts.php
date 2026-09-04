<?php

declare(strict_types=1);

use App\Modules\Accounts\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * এক প্রদেয় খাতে তিন রকম দেনা — চলমান কোম্পানিগুলোতেও।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * `2110` = "প্রদেয় হিসাব" — মিলের বিল, ট্রাকের ভাড়া আর হাম্মালির টাকা
 * **একই ঘরে**। তাই *"এই মাসে পরিবহনে কত দিতে বাকি"* প্রশ্নের উত্তর বের
 * করাই যেত না, অথচ ডিপোতে ওটা রোজকার প্রশ্ন — আর তিনটা দেনা তিনজন
 * আলাদা মানুষের কাছে, আলাদা নিষ্পত্তির চক্রে।
 *
 * ── এখন যা দাঁড়ায় ───────────────────────────────────────────────────
 *     ২১১০  প্রদেয় হিসাব          দল
 *       ২১১১  মালের সরবরাহকারী    ← `StandardChart::PAYABLE`-এর গন্তব্য
 *       ২১১৬  পরিবহন              ← [[DeliveryChallanService]]
 *       ২১১৭  হাম্মালি              ⓘ আজ কোনো পথ এটা ব্যবহার করে না
 *       ২১১৮  ভেন্ডর ও সেবা        কুরিয়ার · সার্ভিস · বাকিরা
 *
 * ── ⚠️ কেন এখানে পুরনো দাখিলা সরানো হয়, অথচ `5204`-এ হয়নি ───────────
 * `fuel_and_hire_were_the_same_expense` (১৬ অক্টোবর) একটা নিয়ম লিখে
 * গেছে: **পুরনো সারি ছোঁয়া হয় না, কারণ খাত বদলানো মানে ইতিহাস বদলানো।**
 * ওখানে সেটা ঠিক ছিল, আর এখানে উল্টোটা — কারণটা গঠনগত:
 *
 *   `5204` **পোস্টিং খাতই থেকে যায়**, কেবল নিষ্ক্রিয় হয়। পুরনো দাখিলা
 *   ওখানেই বসে থাকতে পারে, আর গত বছরের রিপোর্ট অবিকল থাকে।
 *
 *   `2110` **দল হয়ে যায়**, আর দলে দাখিলা বসে না। একটা সারিও পিছিয়ে
 *   থাকলে সেটা এমন একটা খাতে ঝুলে থাকত যেখানে থাকার কথা নয় — আর পরের
 *   পোস্টিং ভাঙত।
 *
 * ⭐ আর সরানোটা এখানে **ইতিহাস বদলায় না**, কারণ খাতের যোগফল গোটা গাছ
 * হাঁটে: ২১১০-এর মোট আগের যা, পরেও তা-ই। স্থিতিপত্রে সারিটা একই নামে
 * একই সংখ্যা দেখায়। বদলায় কেবল ভেতরের ভাগটা — আর ওটাই তো চাওয়া।
 *
 * ⚠️ **সব-বা-কিছুই-না**: অর্ধেক সরানো দাখিলা অর্ধেক বসা জেরের চেয়েও
 * খারাপ — খতিয়ান মিলত, স্থিতিপত্র মিলত না, আর কেউ টের পেত না। তাই
 * প্রতিটা কোম্পানির কাজ একটা লেনদেনে, আর যোগফল মিলিয়ে দেখে।
 *
 * ── কেন `Account` মডেল নয় ───────────────────────────────────────────
 * `Account`-এ কোম্পানির গ্লোবাল স্কোপ, আর মাইগ্রেশনে কোনো কোম্পানি-প্রসঙ্গ
 * থাকে না — একই কারণে `fuel_and_hire...`-ও কোয়েরি বিল্ডার ব্যবহার করে।
 */
return new class extends Migration
{
    /** কোড → [ইংরেজি, বাংলা] — সবার বাবা ২১১০। */
    private const NEW_HEADS = [
        '2111' => ['Trade Suppliers', 'মালের সরবরাহকারী'],
        '2116' => ['Transport Payable', 'প্রদেয় পরিবহন'],
        '2117' => ['Labour Payable', 'প্রদেয় হাম্মালি'],
        '2118' => ['Vendors & Services Payable', 'প্রদেয় ভেন্ডর ও সেবা'],
    ];

    private const GROUP = '2110';

    /** পুরনো দাখিলাগুলো যেখানে যাবে — ডিফল্ট গন্তব্য। */
    private const DEFAULT_HOME = '2111';

    public function up(): void
    {
        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $group = DB::table('accounts')
                ->where('company_id', $companyId)
                ->where('code', self::GROUP)
                ->first(['id', 'is_group']);

            /*
             * কোম্পানি খাতটা মুছে ফেললে এখানে কিছুই করার নেই।
             *
             * ⓘ নতুন ঘরগুলো বাবা ছাড়া বসালে ওরা গাছের বাইরে ঝুলত, আর
             * স্থিতিপত্রে দেখাই যেত না — না বসানোর চেয়ে খারাপ।
             */
            if ($group === null) {
                continue;
            }

            DB::transaction(function () use ($companyId, $group, $now): void {
                $before = $this->groupTotal($companyId, (int) $group->id);

                foreach (self::NEW_HEADS as $code => [$en, $bn]) {
                    $exists = DB::table('accounts')
                        ->where('company_id', $companyId)
                        ->where('code', $code)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('accounts')->insert([
                        'public_id' => (string) Str::uuid(),
                        'company_id' => $companyId,
                        'code' => $code,
                        'name_en' => $en,
                        'name_bn' => $bn,
                        'type' => Account::LIABILITY,
                        'parent_id' => $group->id,
                        'is_group' => false,
                        'is_cash' => false,
                        'is_bank' => false,
                        'nature' => Account::defaultNatureFor(Account::LIABILITY),
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $home = DB::table('accounts')
                    ->where('company_id', $companyId)
                    ->where('code', self::DEFAULT_HOME)
                    ->value('id');

                /*
                 * পুরনো দাখিলাগুলো ডিফল্ট ঘরে।
                 *
                 * ⚠️ ধরন ধরে ভাগ করা হয় না, আর সেটা মাপা সিদ্ধান্ত: লাইভে
                 * ২১১০-এর দাখিলার একটা বড় অংশের সরবরাহকারীর কোনো ধরনই
                 * বসানো নেই। ধরন ধরে ভাগ করলে ওগুলো নীরবে "বাকিরা"-র ঘরে
                 * পড়ত, অথচ ওদের বেশিরভাগই আসল মালের সরবরাহকারী।
                 *
                 * ⓘ সবাইকে ডিফল্ট ঘরে পাঠানো **হারায় না, কেবল ভাগ করে
                 * না** — আর কোম্পানি চাইলে পরে সারিটা ভাউচারে সরাতে পারে।
                 * ভুল ঘরে পাঠানোর চেয়ে ভাগ না করা সৎ।
                 */
                DB::table('ledger_entries')
                    ->where('company_id', $companyId)
                    ->where('account_id', $group->id)
                    ->update(['account_id' => $home, 'updated_at' => $now]);

                DB::table('accounts')
                    ->where('id', $group->id)
                    ->update(['is_group' => true, 'updated_at' => $now]);

                $after = $this->groupTotal($companyId, (int) $group->id);

                /*
                 * ⛔ যোগফল না মিললে গোটা কোম্পানির কাজ ফিরে যায়।
                 *
                 * এটাই একমাত্র প্রমাণ যে টাকা এদিক-ওদিক হয়নি। ⚠️ আর
                 * পরীক্ষাটা **কোম্পানিপ্রতি** — চারটার মোট একসাথে মেলালে
                 * একটার টাকা আরেকটার সাথে কাটাকাটি হয়ে ভুলটা ঢেকে যেত।
                 */
                if (bccomp($before, $after, 4) !== 0) {
                    throw new RuntimeException(
                        "Company {$companyId}: payable total was {$before} before the split and "
                        ."{$after} after. Nothing has been moved for this company."
                    );
                }
            });
        }
    }

    /**
     * ২১১০-এর নিচে গোটা গাছের নিট দেনা।
     *
     * দলটার নিজের সারিও গোনা হয় — সরানোর আগে দাখিলাগুলো ওখানেই বসে,
     * আর পরে ঘরগুলোয়। **দুই অবস্থাতেই একই সংখ্যা আসতে হবে**, আর সেটাই
     * পরীক্ষার পুরো কথা।
     */
    private function groupTotal(int|string $companyId, int $groupId): string
    {
        $ids = DB::table('accounts')
            ->where('company_id', $companyId)
            ->where(fn ($q) => $q->where('id', $groupId)->orWhere('parent_id', $groupId))
            ->pluck('id');

        $row = DB::table('ledger_entries')
            ->where('company_id', $companyId)
            ->whereIn('account_id', $ids)
            ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS net')
            ->first();

        return (string) ($row->net ?? '0');
    }

    /**
     * ফেরত — দাখিলা ফিরিয়ে আনা হয়, খাতগুলো মোছা হয় না।
     *
     * ⚠️ দলটাকে আবার পোস্টিং খাত বানাতে হলে ওর নিচের সারিগুলোকে ফেরাতেই
     * হবে, নাহলে ওরা এমন ঘরে ঝুলত যাদের বাবা আর দল নয়। কিন্তু **ঘরগুলো
     * মোছা হয় না** — ততক্ষণে পরিবহনের নিজের দাখিলা বসে গিয়ে থাকতে পারে,
     * আর খাত মুছলে ওই সারিগুলো এমন কিছুর দিকে দেখাত যা নেই।
     *
     * ⓘ একই সিদ্ধান্ত `fuel_and_hire...`-এও: **মাইগ্রেশন ফেরানো মানে
     * কোডটা ফেরানো, খাতাটা নয়।**
     */
    public function down(): void
    {
        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $group = DB::table('accounts')
                ->where('company_id', $companyId)
                ->where('code', self::GROUP)
                ->value('id');

            $home = DB::table('accounts')
                ->where('company_id', $companyId)
                ->where('code', self::DEFAULT_HOME)
                ->value('id');

            if ($group === null || $home === null) {
                continue;
            }

            DB::table('ledger_entries')
                ->where('company_id', $companyId)
                ->where('account_id', $home)
                ->update(['account_id' => $group, 'updated_at' => $now]);

            DB::table('accounts')->where('id', $group)->update(['is_group' => false, 'updated_at' => $now]);
        }
    }
};
