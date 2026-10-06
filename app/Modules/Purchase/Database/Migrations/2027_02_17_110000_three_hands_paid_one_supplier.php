<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ পরিশোধের প্রস্তাব আর তিন হাত — টাকা আসা-যাওয়ার আন্তর্জাতিক পরিকল্পনা, ধাপ খ ১১–১২ (৭ অক্টোবর ২০২৬)।
 *
 * ⓘ `pur_payments.proposal_no` (nullable) — একটা প্রস্তাবের (PP-…) পরিশোধগুলো তাকে চেনে; আজকের পরিশোধে খালি।
 * ⓘ `purchase.payment_three_hands` ঘোষণায় চালু, কিন্তু **আজ যত কোম্পানি আছে** সবগুলোতে বন্ধ লেখা হয় — আজকের পথ না ভাঙতে;
 * মালিক নিজে চালু করবেন (fe, ৭ অক্টোবর)। ⚠️ কেবল যেখানে সারিটা এখনো নেই।
 */
return new class extends Migration
{
    private const KEY = 'purchase.payment_three_hands';

    public function up(): void
    {
        if (! Schema::hasColumn('pur_payments', 'proposal_no')) {
            Schema::table('pur_payments', function (Blueprint $table): void {
                $table->string('proposal_no', 40)->nullable()->after('document_no');
                $table->index(['company_id', 'proposal_no'], 'pur_payments_proposal_idx');
            });
        }

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            if (! DB::table('settings')->where('company_id', $companyId)->where('key', self::KEY)->exists()) {
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

        if (Schema::hasColumn('pur_payments', 'proposal_no')) {
            Schema::table('pur_payments', function (Blueprint $table): void {
                $table->dropIndex('pur_payments_proposal_idx');
                $table->dropColumn('proposal_no');
            });
        }
    }
};
