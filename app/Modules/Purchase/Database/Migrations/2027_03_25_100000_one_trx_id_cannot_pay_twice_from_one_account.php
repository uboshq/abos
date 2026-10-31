<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * একই খাত থেকে একই TrxID/রেফারেন্সে দুবার পরিশোধ নয় — ডেটাবেসে (নিরীক্ষা ৮, fe, ২৪ অক্টোবর ২০২৬)।
 *
 * ── কী ঘটত ──────────────────────────────────────────────────────────
 * [[SlipIsNotUsedTwice]] পক্ষ ধরে দেখত — একই বিকাশ TrxID দুই সরবরাহকারীর নামে বসতে পারত, আর কোনো তালা বা ইউনিক ছিল না, তাই
 * একই মুহূর্তে দুই হাতও পেরোত। টাকা গেছে একবার, খাতায় দুবার।
 *
 * ── কেন `live_ref`, সোজা `instrument_no` নয় ──────────────────────────
 * ভাউচারের `vouchers_bank_reference_unique` বাতিল সারিও ধরে; এখানে তা চলবে না — ভুল পরিশোধ বাতিল করে **একই TrxID-তে আবার
 * ঠিকভাবে** লেখা সবচেয়ে স্বাভাবিক কাজ, আর পাহারাও বাতিল গোনে না। ⭐ তাই একটা জমা-থাকা (STORED) গড়া ঘর: চালু সারিতে
 * ছাঁটা-ছোট-হাতের নম্বর, বাতিল বা মোছা হলে বা নম্বর খালি হলে NULL। MySQL আর MariaDB দুটোতেই NULL কখনো NULL-এর সাথে ঠোকে না, তাই
 * নগদের নম্বরহীন সারি আর বাতিল সারি নির্বিঘ্নে বসে।
 *
 * ── দুই ডেটাবেসেই চলে ────────────────────────────────────────────────
 * ⚠️ লাইভ MariaDB, Mac-এ MySQL 8.4। `GENERATED ALWAYS AS (…) STORED` দুটোতেই চলে (MariaDB 10.2 থেকে STORED = PERSISTENT), গড়া
 * ঘরে ইনডেক্সও দুটোতে চলে; প্রকাশে কেবল নির্ধারিত ফাংশন (CASE, TRIM, LOWER)। ⓘ লাইভে আজ কোনো দ্বৈত নেই (fe চালালেন
 * দ্বৈত-রেফারেন্স-খোঁজা-শুধু-পড়া-২৪-অক্টোবর.sql, ২৪ অক্টোবর: payment_duplicates 0), তাই ইনডেক্স নির্বিঘ্নে বসে।
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ⛔ আগাম পরখ — দ্বৈত থাকলে কিছু ছোঁয়ার আগেই থামা (fe, ২৪ অক্টোবর ২০২৬)। ⓘ MySQL/MariaDB-তে DDL লেনদেনে ফেরে না: ঘর যোগ হয়ে
         * ইনডেক্সে থামলে টেবিল অর্ধেক বদলানো থাকত। গড়া ঘরের একই নিয়মে গোনা (চালু, ছাঁটা-ছোট হাত, খালি নয়)।
         */
        $twins = DB::table('pur_payments')
            ->selectRaw('company_id, account_id, LOWER(TRIM(instrument_no)) as ref, COUNT(*) as n, GROUP_CONCAT(document_no) as papers')
            ->whereNull('deleted_at')
            ->where('status', '<>', 'cancelled')
            ->whereNotNull('instrument_no')
            ->whereRaw("TRIM(instrument_no) <> ''")
            ->groupByRaw('company_id, account_id, LOWER(TRIM(instrument_no))')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($twins->isNotEmpty()) {
            throw new RuntimeException('⛔ একই খাতে একই রেফারেন্সে একাধিক চালু পরিশোধ আছে — ইনডেক্স বসানো হলো না, টেবিল অপরিবর্তিত। আগে এগুলো মেলান: '
                .$twins->map(fn ($t) => "কোম্পানি {$t->company_id}, খাত {$t->account_id}, \"{$t->ref}\": {$t->papers}")->implode(' | '));
        }

        Schema::table('pur_payments', function (Blueprint $table): void {
            $table->string('live_ref', 64)->nullable()->storedAs(
                "CASE WHEN status <> 'cancelled' AND deleted_at IS NULL AND TRIM(instrument_no) <> '' THEN LOWER(TRIM(instrument_no)) END"
            );

            $table->unique(['company_id', 'account_id', 'live_ref'], 'pur_payments_live_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::table('pur_payments', function (Blueprint $table): void {
            $table->dropUnique('pur_payments_live_ref_unique');
            $table->dropColumn('live_ref');
        });
    }
};
