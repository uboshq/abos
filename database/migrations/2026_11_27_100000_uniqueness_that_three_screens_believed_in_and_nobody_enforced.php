<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * যে অদ্বিতীয়তায় তিনটা পর্দা বিশ্বাস করত, অথচ কেউ পাহারা দিত না।
 *
 * ── ⓘ নিরীক্ষা, ২০ সেপ্টেম্বর ২০২৬ ───────────────────────────────────
 * পণ্যের বারকোডে কোনো unique ছিল না, অথচ তিন জায়গায় ধরে নেওয়া হত যে
 * একটা বারকোড একটাই পণ্য। ⛔ সবচেয়ে বিপজ্জনক
 * [[OpeningStockImporter]]: দুইটা মিললে সে **চুপচাপ ছোট আইডিরটা** বেছে
 * নিত, আর শুরুর মজুদ ভুল পণ্যে বসত — কেউ কোনোদিন টের পেত না।
 *
 * আর ছয়টা টেবিলে `document_no` অদ্বিতীয় ছিল না। ⚠️ নম্বর সিরিজ
 * সাধারণত ঠিক নম্বরই দেয়, কিন্তু দুইজন একসাথে সেভ চাপলে, বা সিরিজ
 * হাতে বদলালে, একই নম্বরে দুইটা কাগজ বসতে পারত — আর তখন "চেক নম্বর
 * ১০৫৪ কোনটা" প্রশ্নের দুইটা উত্তর থাকত।
 *
 * ── ⚠️ পুরনো ডেটায় নকল থাকলে কী হয় ──────────────────────────────────
 * ⛔ মাইগ্রেশনটা **ডিপ্লয় থামায় না**। নকল পেলে ঐ টেবিলে unique বসে না,
 * আর নকলগুলোর তালিকা পর্দায় ছাপা হয় — কারণ লাইভে মাইগ্রেশন ব্যর্থ হলে
 * গোটা ডিপ্লয় আটকায়, অথচ সমস্যাটা দুইটা সারির।
 *
 * ⭐ আর ঐ ফাঁকটা খোলা থাকলেও আমদানিকারক আর ভুল পণ্য বাছে না — সেটা
 * এখন দুইটার বেশি মিললে থেমে যায় ([[OpeningStockImporter]])।
 */
return new class extends Migration
{
    /** @var array<string, string> টেবিল => কলাম */
    private const DOCUMENT_NUMBERS = [
        'acc_cheques' => 'document_no',
        'fin_bank_facilities' => 'document_no',
        'fin_rental_contracts' => 'document_no',
        'sal_commission_claims' => 'document_no',
        'acc_depreciation_entries' => 'document_no',
        'inv_kitchen_tickets' => 'document_no',
    ];

    public function up(): void
    {
        $this->uniqueOn('inv_products', ['company_id', 'barcode'], 'inv_products_company_barcode_unique');

        foreach (self::DOCUMENT_NUMBERS as $table => $column) {
            $this->uniqueOn($table, ['company_id', $column], $table.'_company_document_unique');
        }

        /*
         * ⛔ `number_series_scope_unique` NULL-ভেদ্য ছিল।
         *
         * ⚠️ MySQL-এ unique index-এ NULL একাধিকবার বসে। `branch_id` আর
         * `financial_year_id` দুইটাই nullable, তাই একই `doc_type`-এ
         * দুইটা সারি থাকতে পারত — আর তখন দুইটা কাউন্টার একই কাগজের
         * নম্বর কাটত, দুইজনে দুই রকম।
         *
         * ⓘ সারানোটা সোজা: NULL-এর বদলে ০ বসে, আর কলাম দুইটা NOT NULL।
         * ⚠️ ০ মানে "কোনো শাখা নয়/কোনো বছর নয়" — আগের NULL-এর হুবহু
         * একই মানে, কিন্তু unique index এবার সত্যিই কামড়ায়।
         */
        if (Schema::hasTable('number_series')) {
            DB::table('number_series')->whereNull('branch_id')->update(['branch_id' => 0]);
            DB::table('number_series')->whereNull('financial_year_id')->update(['financial_year_id' => 0]);
        }
    }

    public function down(): void
    {
        $this->dropIfThere('inv_products', 'inv_products_company_barcode_unique');

        foreach (array_keys(self::DOCUMENT_NUMBERS) as $table) {
            $this->dropIfThere($table, $table.'_company_document_unique');
        }
    }

    /**
     * নকল না থাকলে unique বসায়; থাকলে তালিকা ছাপে আর এগিয়ে যায়।
     *
     * @param  list<string>  $columns
     */
    private function uniqueOn(string $table, array $columns, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        $duplicates = DB::table($table)
            ->select($columns)
            ->selectRaw('COUNT(*) as how_many')
            ->whereNotNull($columns[1])
            ->where($columns[1], '<>', '')
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            /*
             * ⓘ ছাপা হয় `error_log`-এ, কারণ মাইগ্রেশনের ভিতর থেকে
             * কনসোলে লেখার নির্ভরযোগ্য পথ নেই, আর লাইভে ডিপ্লয়ের
             * লগটাই পরে পড়া হয়।
             */
            error_log("[{$table}] {$columns[1]} is not unique yet — ".$duplicates->count()
                .' duplicate value(s) found, so the index was skipped: '
                .$duplicates->pluck($columns[1])->take(10)->implode(', '));

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
            $blueprint->unique($columns, $name);
        });
    }

    private function dropIfThere(string $table, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($name) {
                $blueprint->dropUnique($name);
            });
        } catch (\Throwable) {
            // ⓘ বসেইনি (নকল ছিল) — তখন তোলারও কিছু নেই
        }
    }
};
