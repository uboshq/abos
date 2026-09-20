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
 *
 * ── ⛔ এই মাইগ্রেশনটা একবার লাইভে অর্ধেক বসেছিল, ২১ সেপ্টেম্বর ২০২৬ ──
 * প্রথম রূপে `number_series`-এর NULL `branch_id`-তে sentinel ০ বসানো
 * হত। ⚠️ কিন্তু কলামটায় `branches(id)`-এর foreign key আছে, আর **শাখা ০
 * বলে কিছু নেই** — তাই ঐ লাইনেই মাইগ্রেশন ছুঁড়ে থেমে গেল, অথচ তার
 * আগের ধাপে বারকোডের সূচকটা **বসে গিয়েছিল**। ⓘ `migrate` ব্যর্থ হলে
 * Laravel সারিটা `migrations` টেবিলে লেখে না, তাই লাইভে সূচক ছিল আর
 * `migrate:status` বলত Pending — অর্থাৎ পরের রানে "Duplicate key name"।
 *
 * ⭐ তাই এখন **প্রতিটা ধাপ আগে দেখে নেয় জিনিসটা ইতিমধ্যে আছে কি না**।
 * একটা মাইগ্রেশন যেটা দ্বিতীয়বার চালানো যায় না, সেটা লাইভে একবার
 * আটকালে আর কোনোদিন চলে না।
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

        $this->numberSeriesScopeReallyBites();
    }

    public function down(): void
    {
        $this->dropIfThere('inv_products', 'inv_products_company_barcode_unique');

        foreach (array_keys(self::DOCUMENT_NUMBERS) as $table) {
            $this->dropIfThere($table, $table.'_company_document_unique');
        }

        $this->dropIfThere('number_series', 'number_series_scope_bites');

        foreach (['branch_key', 'financial_year_key'] as $column) {
            if (Schema::hasTable('number_series') && Schema::hasColumn('number_series', $column)) {
                Schema::table('number_series', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }

    /**
     * ⛔ `number_series_scope_unique` NULL-ভেদ্য ছিল।
     *
     * ⚠️ MySQL-এ unique index-এ NULL একাধিকবার বসে। `branch_id` আর
     * `financial_year_id` দুইটাই nullable, তাই একই `doc_type`-এ দুইটা
     * সারি থাকতে পারত — আর তখন দুইটা কাউন্টার একই কাগজের নম্বর কাটত,
     * দুইজনে দুই রকম।
     *
     * ── ⛔ যে পথটা বন্ধ ─────────────────────────────────────────────
     * NULL-এর বদলে ০ বসানো যায় না: দুইটা কলামেই foreign key আছে
     * (`branches`, `financial_years`), আর শূন্য আইডির কোনো শাখা বা বছর
     * নেই। ⚠️ লাইভে ঠিক এখানেই মাইগ্রেশন ছুঁড়েছিল।
     *
     * ── ⭐ যে পথটা খোলা ─────────────────────────────────────────────
     * দুইটা **generated** কলাম, `COALESCE(…, 0)` — generated কলামে
     * foreign key থাকে না, তাই ০ বসাতে বাধা নেই। ⓘ `branch_id`
     * NULL-ই থেকে যায়, একটা সারিও বদলায় না; কেবল সূচকটা এবার সত্যিই
     * কামড়ায়।
     *
     * ⓘ পুরনো সূচকটা তোলা হয় না — ওটা ক্ষতি করে না, আর foreign key
     * গুলো কোন সূচকের উপর দাঁড়িয়ে আছে সেটা লাইভে অনুমান করার জিনিস নয়।
     */
    private function numberSeriesScopeReallyBites(): void
    {
        if (! Schema::hasTable('number_series') || DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (['branch_id' => 'branch_key', 'financial_year_id' => 'financial_year_key'] as $from => $key) {
            if (Schema::hasColumn('number_series', $key)) {
                continue;
            }

            /*
             * ⛔ VIRTUAL, STORED নয় — ২১ সেপ্টেম্বর ২০২৬।
             *
             * ⚠️ STORED কলাম বসাতে MySQL গোটা টেবিল নতুন করে লেখে
             * (ALGORITHM=COPY), আর সেই লেখার সময় foreign key গুলো
             * আবার বাঁধতে গিয়ে থেমে যায় — লোকালে হুবহু এই ভুলে
             * (errno 1215) তিনটা পরীক্ষা লাল হয়েছিল।
             *
             * ⓘ VIRTUAL কলাম কেবল সংজ্ঞা — সারি ছোঁয়া হয় না, তাই
             * রিবিল্ডও নেই। ⭐ আর MySQL 5.7+ ভার্চুয়াল কলামেও সূচক
             * বসাতে দেয়, আর অদ্বিতীয়তার পাহারাটা STORED-এর মতোই কাজ করে।
             */
            DB::statement(
                "ALTER TABLE `number_series` ADD COLUMN `{$key}` BIGINT UNSIGNED"
                ." AS (COALESCE(`{$from}`, 0)) VIRTUAL"
            );
        }

        if ($this->indexIsThere('number_series', 'number_series_scope_bites')) {
            return;
        }

        /*
         * ⚠️ নকল থাকলে এখানেও থামা হয় না — উপরের `uniqueOn()`-এর একই
         * নিয়ম। ⓘ কিন্তু নকলটা গোনা হয় generated কলাম ধরে, কারণ
         * প্রশ্নটা ঠিক ওটাই: "০ ধরে দেখলে কি দুইটা সারি এক হয়ে যায়"।
         */
        $clashes = DB::table('number_series')
            ->selectRaw('company_id, COALESCE(branch_id, 0) as b, doc_type, COALESCE(financial_year_id, 0) as y')
            ->groupBy('company_id', 'b', 'doc_type', 'y')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($clashes > 0) {
            error_log("[number_series] {$clashes} scope(s) already hold more than one counter — "
                .'the unique index was skipped; two counters are cutting numbers for one document type.');

            return;
        }

        Schema::table('number_series', function (Blueprint $blueprint) {
            $blueprint->unique(
                ['company_id', 'branch_key', 'doc_type', 'financial_year_key'],
                'number_series_scope_bites',
            );
        });
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

        /*
         * ⭐ আগে থেকে থাকলে চুপচাপ এগিয়ে যাওয়া — ২১ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ লাইভে এই সূচকটা বসে গিয়েছিল আর মাইগ্রেশনটা পরের ধাপে
         * ছুঁড়েছিল, তাই সারিটা `migrations`-এ লেখা হয়নি। ⛔ এই যাচাইটা
         * না থাকলে পরের রান "Duplicate key name" দিয়ে থামত, আর
         * মাইগ্রেশনটা চিরকালের জন্য আটকে যেত।
         */
        if ($this->indexIsThere($table, $name)) {
            return;
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

    /**
     * এই নামে সূচকটা ইতিমধ্যে আছে কি না।
     *
     * ⓘ `Schema::getIndexes()` ড্রাইভার-নিরপেক্ষ, তাই sqlite-এও চলে —
     * পরীক্ষাগুলো MySQL-এ চললেও কেউ একদিন অন্য ড্রাইভারে চালাতে পারেন।
     */
    private function indexIsThere(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    private function dropIfThere(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! $this->indexIsThere($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name) {
            $blueprint->dropUnique($name);
        });
    }
};
