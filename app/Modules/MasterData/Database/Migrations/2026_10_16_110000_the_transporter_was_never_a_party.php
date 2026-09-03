<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * পরিবহনকারী কোনোদিন পক্ষ ছিলেন না — চলমান কোম্পানিগুলোতেও চারটা ধরন।
 *
 * ── কী ছিল না ───────────────────────────────────────────────────────
 * চালানে `carrier_name` একটা **মুক্ত লেখা**। তাই *"এই পরিবহনকারীকে এই
 * মাসে মোট কত দিলাম"* প্রশ্নের উত্তর ছিল না — বানানভেদে একজন মানুষ
 * দশজন হয়ে যেতেন।
 *
 * মালিকের কথা (৩ সেপ্টেম্বর ২০২৬): *"পরিবহন business partner-এ থাকার
 * কথা — vendor বা transporter হিসেবে, তার আলাদা ledger হবে।"*
 *
 * ── কেন নতুন মডিউল নয়, কেবল চারটা সারি ───────────────────────────────
 * চারজনই সরবরাহকারী: আমরা তাঁদের টাকা দিই, আর হিসাব **চলতি** — মাসে
 * একবার মেটে। তাই খতিয়ান, বকেয়ার বয়স আর drill সবই সরবরাহকারীর যন্ত্রেই
 * চলে। ⭐ কেবল শ্রেণিটা আলাদা, আর `suppliers.party_type_id` ধরে ছাঁকার
 * ব্যবস্থা [[PartyReports]]-এ আগে থেকেই আছে।
 *
 * ⚠️ **এটা আগে ভুল বোঝা হয়েছিল।** ভাবা হয়েছিল খতিয়ানের `party_type`
 * ঘরটাই এই শ্রেণি রাখে — রাখে না, ওখানে বসে `'customer'`/`'supplier'`,
 * অর্থাৎ মডিউল-স্তরের ধরন। দুইটা আলাদা জিনিস, আর একটাকে অন্যটা ভাবলে
 * পুরো নকশাটাই ভুল দিকে যেত।
 *
 * ── কেন মাইগ্রেশন, কেবল seed নয় ──────────────────────────────────────
 * `MasterListService`-এর seed **নতুন কোম্পানি** বসানোর সময় চলে। চলমান
 * কোম্পানিগুলো পায় কেবল যদি কেউ "ডিফল্ট বসান" চাপেন — আর ততক্ষণ
 * পরিবহনকারীকে শ্রেণি দেওয়াই যেত না।
 */
return new class extends Migration
{
    /** কোড → [ইংরেজি, বাংলা] — চারটাই সরবরাহকারীর দিকে। */
    private const TYPES = [
        'TRANSPORT' => ['Transport Vendor', 'পরিবহনকারী'],
        'RENTAL' => ['Rental Vehicle', 'ভাড়ার গাড়ি'],
        'LABOUR' => ['Labour Contractor', 'হাম্মালি ঠিকাদার'],
        'BROKER' => ['Broker', 'দালাল'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach (self::TYPES as $code => [$en, $bn]) {
                $exists = DB::table('mdm_party_types')
                    ->where('company_id', $companyId)
                    ->where('code', $code)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('mdm_party_types')->insert([
                    'public_id' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'code' => $code,
                    'name_en' => $en,
                    'name_bn' => $bn,
                    'applies_to' => 'supplier',
                    'is_default' => false,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * ফেরত — কেবল যে সারিগুলো কেউ ব্যবহার করেননি।
     *
     * ⚠️ একটা ধরন কোনো সরবরাহকারীর গায়ে বসে গেলে সেটা মুছে ফেলা যায় না:
     * `suppliers.party_type_id` তখন এমন কিছুর দিকে দেখাত যা নেই, আর
     * সরবরাহকারীর পাতা ও রিপোর্টের ছাঁকনি দুইটাই ভাঙত।
     *
     * ⓘ FK-টা `nullOnDelete`, তাই ডাটাবেস নিজে ভাঙত না — **শ্রেণিটা
     * নীরবে খালি হয়ে যেত**, আর সেটা আরও খারাপ: কেউ টের পেত না যে
     * তথ্যটা হারিয়েছে।
     */
    public function down(): void
    {
        foreach (array_keys(self::TYPES) as $code) {
            $ids = DB::table('mdm_party_types')->where('code', $code)->pluck('id');

            foreach ($ids as $id) {
                $used = DB::table('suppliers')->where('party_type_id', $id)->exists();

                if (! $used) {
                    DB::table('mdm_party_types')->where('id', $id)->delete();
                }
            }
        }
    }
};
