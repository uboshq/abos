<?php

declare(strict_types=1);

use App\Modules\Accounts\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * জ্বালানি আর ভাড়া একই খরচ ছিল — চলমান কোম্পানিগুলোতেও ভাঙা।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * `5204` = "জ্বালানি ও পরিবহন" — **দুইটা আলাদা খরচ এক ঘরে**। তাই
 * *"এই রুটে পরিবহন খরচ কত"* প্রশ্নের উত্তর বের করাই যেত না: তেলের বিল
 * আর গাড়ির ভাড়া একই সংখ্যায় মিশে থাকত।
 *
 * ⚠️ আর ওটাই ডেলিভারি কস্টিং রিপোর্টের ভিত্তি — ডিপো ব্যবসার কেন্দ্রীয়
 * প্রশ্ন (মালিক, ৩ ও ৪ সেপ্টেম্বর ২০২৬)।
 *
 * ── কেন মাইগ্রেশন, কেবল ছক নয় ───────────────────────────────────────
 * `StandardChart` -এ সারি যোগ করলে **নতুন কোম্পানি** পায়। চলমান
 * কোম্পানিগুলো পায় কেবল যখন কেউ "মানসম্মত ছক বসান" বোতামটা চাপেন —
 * আর যতক্ষণ না চাপা হচ্ছে, খাতগুলো নেই। ⓘ একই কারণে
 * `interest_is_not_a_bank_charge` (২৮ আগস্ট) মাইগ্রেশন লিখতে হয়েছিল।
 *
 * ── কেন `Account` মডেল নয়, কোয়েরি বিল্ডার ────────────────────────────
 * `Account`-এ কোম্পানির গ্লোবাল স্কোপ বসানো, আর মাইগ্রেশনে কোনো
 * কোম্পানি-প্রসঙ্গ থাকে না। মডেল দিয়ে লিখতে গেলে হয় স্কোপ বন্ধ করতে
 * হত, নয় প্রতিটা কোম্পানির প্রসঙ্গ বসিয়ে ঘুরতে হত — দুইটাই মাইগ্রেশনে
 * ভঙ্গুর।
 *
 * ── ⚠️ পুরনো সারিগুলো ছোঁয়া হয় না, আর সেটাই সবচেয়ে জরুরি সিদ্ধান্ত ──
 * `5204`-এ আজ পর্যন্ত যা বসেছে সব ওখানেই থাকে। **খাত বদলানো মানে
 * ইতিহাস বদলানো** — গত বছরের লাভ-লোকসান আজ বদলে গেলে কেউ আর কোনো
 * ছাপা কাগজে বিশ্বাস করতে পারত না, আর নিরীক্ষার সূত্রটাও ছিঁড়ত।
 *
 * খাতটা কেবল **নিষ্ক্রিয়** হয়: পুরনো দাখিলা ও রিপোর্ট আগের মতোই, শুধু
 * নতুন এন্ট্রিতে আর বাছা যায় না (ভাউচারের তালিকা `->active()` ছাঁকে)।
 * নামটাও বদলায়, নাহলে তালিকায় দুইটা "জ্বালানি" থাকত আর মানুষ ভুলটায়
 * ক্লিক করতেন। ⓘ নাম বদলানো নিরাপদ — পোস্টিং খাত খোঁজে **কোড ধরে**
 * (`StandardChart::find($code)`), নাম ধরে নয়।
 */
return new class extends Migration
{
    /** কোড → [ইংরেজি, বাংলা] — বাবা সবার `5200`। */
    private const NEW_HEADS = [
        '5216' => ['Fuel', 'জ্বালানি'],
        '5217' => ['Vehicle Hire', 'গাড়ির ভাড়া'],
        '5218' => ['Loading', 'লোডিং'],
        '5219' => ['Unloading', 'আনলোডিং'],
        '5220' => ['Labour (Hammali)', 'হাম্মালি'],
    ];

    private const RETIRED = '5204';

    private const PARENT = '5200';

    public function up(): void
    {
        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            /*
             * ৫২০০ = পরিচালন ব্যয়ের গ্রুপ। কোম্পানি সেটা মুছে ফেললে
             * খাতগুলো ব্যয়ের গোড়ায় (৫০০০) বসে — না বসানোর চেয়ে ভালো।
             */
            $parentId = DB::table('accounts')
                ->where('company_id', $companyId)
                ->where('code', self::PARENT)
                ->value('id')
                ?? DB::table('accounts')
                    ->where('company_id', $companyId)
                    ->where('code', '5000')
                    ->value('id');

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

            /*
             * পুরনো খাতটা অবসরে — সারি নয়, কেবল অবস্থা ও নাম।
             *
             * ⚠️ ইচ্ছাকৃতভাবে `deleted_at` বসানো হয় না: soft delete হলে
             * পুরনো দাখিলাগুলোর খাতটা তালিকা থেকে হারিয়ে যেত, আর গত
             * বছরের রিপোর্টে "খাত নেই" দেখাত।
             */
            DB::table('accounts')
                ->where('company_id', $companyId)
                ->where('code', self::RETIRED)
                ->update([
                    'name_en' => 'Transport (legacy)',
                    'name_bn' => 'পরিবহন (পুরনো)',
                    'is_active' => false,
                    'updated_at' => $now,
                ]);
        }
    }

    /**
     * ফেরত — খাতগুলো মোছা হয় না, কেবল পুরনোটা আবার সচল হয়।
     *
     * ⚠️ ততক্ষণে নতুন খাতগুলোয় খরচ বসে গেছে থাকতে পারে, আর খাত মুছলে
     * ওই সারিগুলো এমন কিছুর দিকে দেখাত যা নেই — খতিয়ানে ছিদ্র।
     *
     * **মাইগ্রেশন ফেরানো মানে কোডটা ফেরানো, খাতাটা নয়** — একই সিদ্ধান্ত
     * `interest_is_not_a_bank_charge`-এও লেখা আছে, আর কারণটা এক।
     */
    public function down(): void
    {
        DB::table('accounts')
            ->where('code', self::RETIRED)
            ->update([
                'name_en' => 'Fuel & Transport',
                'name_bn' => 'জ্বালানি ও পরিবহন',
                'is_active' => true,
            ]);
    }
};
