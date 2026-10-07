<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ⭐ সরবরাহকারীর ধরন: "Vendor" এখন "Principal / প্রিন্সিপাল", আর সাধারণ সরবরাহকারীর নতুন ধরন — মালিক, ৬ অক্টোবর ২০২৬।
 *
 * *"প্রিন্সিপাল শুধু ম্যানুফ্যাকচারারের জন্য, ডিপোর জন্য; সরবরাহকারী রাখো, সার্ভিস প্রোভাইডারে নিয়ে যাও"*।
 *   · কোড VENDOR থাকে — মূল সরবরাহকারী তালিকা এই কোড ধরে চেনে ([[Supplier::VENDOR_CODE]]); তাই আজকের সব প্রিন্সিপাল
 *     (Star Line, Akij …) যেখানে ছিলেন সেখানেই, কেবল নাম বদলায়।
 *   · নতুন GENERAL "Supplier / সরবরাহকারী" — কোড VENDOR নয় বলে সেবাদাতার তালিকায় বসে (প্যাকেট, স্টেশনারি …)।
 * ⓘ কেউ নিজে নাম বদলে থাকলে ছোঁয়া হয় না — কেবল বীজের নাম ("Vendor")।
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('mdm_party_types')
            ->where('code', 'VENDOR')
            ->where('name_en', 'Vendor')
            ->update(['name_en' => 'Principal', 'name_bn' => 'প্রিন্সিপাল', 'updated_at' => now()]);

        $companies = DB::table('mdm_party_types')->where('code', 'VENDOR')->distinct()->pluck('company_id');

        foreach ($companies as $companyId) {
            $exists = DB::table('mdm_party_types')->where('company_id', $companyId)->where('code', 'GENERAL')->exists();

            if (! $exists) {
                DB::table('mdm_party_types')->insert([
                    'company_id' => $companyId,
                    'code' => 'GENERAL',
                    'name_en' => 'Supplier',
                    'name_bn' => 'সরবরাহকারী',
                    'applies_to' => 'supplier',
                    'is_default' => false,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                    'public_id' => (string) Str::uuid7(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('mdm_party_types')->where('code', 'VENDOR')->where('name_en', 'Principal')
            ->update(['name_en' => 'Vendor', 'name_bn' => 'সরবরাহকারী', 'updated_at' => now()]);
        DB::table('mdm_party_types')->where('code', 'GENERAL')->where('name_en', 'Supplier')
            ->whereNotExists(fn ($q) => $q->from('suppliers')->whereColumn('suppliers.party_type_id', 'mdm_party_types.id'))
            ->delete();
    }
};
