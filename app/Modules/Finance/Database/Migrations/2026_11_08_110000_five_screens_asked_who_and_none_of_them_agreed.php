<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * "কে" — পাঁচ জায়গায় পাঁচটা মুক্ত-লেখা ঘর, এবার একটা তালিকার দিকে।
 *
 * ── কী ভাঙা ছিল, ১৩ সেপ্টেম্বর ২০২৬ ──────────────────────────────────
 * অর্থ মডিউলের পাঁচ জায়গায় মানুষের নাম টাইপ করা হত:
 *
 *   acc_capital_entries.contributor_name    মূলধন কে দিলেন
 *   fin_withdrawals.contributor_name        কে তুললেন
 *   acc_withdrawal_limits.contributor_name  কার মাসিক সীমা
 *   fin_hand_loan_accounts.person_name      কার সাথে হাতে-ধার
 *   fin_deposits.holder_name                আমানত কার নামে
 *
 * পাঁচটাই আলাদা, তাই একই মানুষ পাঁচ জায়গায় পাঁচ বানানে থাকতে পারতেন।
 * মালিক নিজে প্রশ্নটা করেছেন: *"একই মালিক আবার বিনিয়োগ করলে আবার নাম
 * লিখতে হবে?"*
 *
 * ── ⛔ দাম তিন জায়গায়, আর তিনটাই নীরব ───────────────────────────────
 * ১। **উত্তোলনের সীমা।** `assertWithinCap()` সীমা খুঁজত নামে, আর এই
 *    মাসে কত তোলা হয়েছে সেটাও নামে। বানান না মিললে সীমাটাই পাওয়া যেত
 *    না — `$cap === null`, চুপচাপ `return`, কিছুই আটকাত না। আর সীমা
 *    পাওয়া গেলেও আগের ভিন্ন-বানানের উত্তোলন যোগ হত না, তাই সীমা বসত
 *    ভুল (কম) মোটের উপর। **দ্বিতীয়টা প্রথমটার চেয়ে খারাপ**, কারণ তখন
 *    পর্দা "সীমার ভেতরে আছেন" বলে আর সংখ্যাটা মিথ্যা।
 * ২। **অংশ %।** `CapitalService::positions()` দল বাঁধত নাম ধরে
 *    (`groupBy('contributor_name')`), তাই এক মালিক তিন বানানে তিন সারি —
 *    তিন জনের অংশ। আর ওই সংখ্যাটা সোজা মুনাফা ভাগের হিসাব।
 * ৩। **নিট মূলধন।** কে কত তুলেছেন তা গোনা হত খতিয়ানের **বিবরণে নাম
 *    খুঁজে** (`narration LIKE '%নাম%'`) — অর্থাৎ "রহিম" নামের অংশীদারের
 *    হিসাবে "আব্দুর রহিম"-এর উত্তোলনও যোগ হয়ে যেত।
 *
 * ── কেন এখনই, আর কেন এক মাইগ্রেশনে তিন ধাপ ───────────────────────────
 * লাইভে ডেটা প্রায় শূন্য (মালিক এইমাত্র setup করেছেন), তাই এটাই সবচেয়ে
 * সস্তা মুহূর্ত। ক্রমটা ইচ্ছাকৃত: **আগে nullable ঘর, তারপর ব্যাক-ফিল,
 * তারপর যাচাই, আর সবার শেষে পুরনো কলাম বাদ** — উল্টো ক্রমে পুরনো
 * মুক্ত-লেখা সারিগুলো FK-এ আটকে যেত।
 *
 * ⛔ ব্যাক-ফিলে একই নামের দুই বানান **জোর করে এক করা হয় না** — সেটা
 * মানুষের সিদ্ধান্ত, মাইগ্রেশনের নয়। প্রতিটা আলাদা বানান আলাদা সারি হয়,
 * আর পরে মিলিয়ে দেওয়ার সময় নকল-পাহারা সতর্ক করবে।
 */
return new class extends Migration
{
    /**
     * কোথায় কোন ঘরে নাম ছিল — আর নতুন FK কোথায় বসবে।
     *
     * @return list<array{table: string, name: string, after: string, extra: ?string}>
     */
    private function places(): array
    {
        return [
            ['table' => 'acc_capital_entries', 'name' => 'contributor_name', 'after' => 'document_no', 'extra' => null],
            ['table' => 'fin_withdrawals', 'name' => 'contributor_name', 'after' => 'document_no', 'extra' => null],
            ['table' => 'acc_withdrawal_limits', 'name' => 'contributor_name', 'after' => 'company_id', 'extra' => null],
            // হাতে-ধারে মোবাইলও ছিল — ব্যক্তির সারিতে সেটাও উঠে আসে
            ['table' => 'fin_hand_loan_accounts', 'name' => 'person_name', 'after' => 'branch_id', 'extra' => 'mobile'],
            ['table' => 'fin_deposits', 'name' => 'holder_name', 'after' => 'held_by', 'extra' => null],
        ];
    }

    public function up(): void
    {
        /*
         * ── ০ · টেবিলগুলো আদৌ আছে কি না ──────────────────────────────
         *
         * ⛔ এই পরীক্ষাটা যোগ করা হয়েছে একটা সত্যিকারের ভুলের পর, আর
         * ভুলটা লিখে রাখা দরকার (১৩ সেপ্টেম্বর ২০২৬):
         *
         * ফাইলটার তারিখ প্রথমে ছিল `2026_09_13`, অথচ পাঁচটা টেবিলের
         * চারটাই তৈরি হয় `database/migrations/`-এ **২০২৬-১০-০৪** ও পরে।
         * অর্থাৎ মাইগ্রেশনটা টেবিল জন্মানোর **আগেই** চলত, আর নিচের
         * `hasTable` শর্তে প্রতিটা সারি চুপচাপ `continue` করত।
         *
         * ⚠️ ফল: `migrate` সবুজ, "DONE" লেখা, `migrations` টেবিলে সারি
         * বসে গেছে — **আর একটাও `person_id` কলাম বসেনি**। পরে কেউ আবার
         * চালালেও কিছু হত না, কারণ Laravel ওটা "চলে গেছে" ধরে নিত।
         * ধরা পড়েছে কেবল কলামগুলো সত্যিই আছে কি না গুনে দেখায়।
         *
         * ⭐ শিক্ষাটা আজকের বাকি সবগুলোর মতোই: **একটা পাহারা যেটা কিছু
         * না পেয়ে সবুজ হয়, সেটা পাহারা নয়।** তাই এখন অনুপস্থিত টেবিল
         * নীরবে বাদ পড়ে না — জোরে থামায়।
         */
        $missing = array_values(array_filter(
            array_column($this->places(), 'table'),
            fn (string $table): bool => ! Schema::hasTable($table),
        ));

        if ($missing !== []) {
            throw new RuntimeException(
                'এই টেবিলগুলো নেই, তাই "কে" ঘরটা সরানো যাচ্ছে না — মাইগ্রেশনের '
                .'ক্রম ঠিক আছে কি না দেখুন (এই ফাইলটা ওদের পরে চলতে হবে):
'
                .implode('
', $missing)
            );
        }

        /*
         * ── ১ · ঘরটা বসানো, এখনো nullable ────────────────────────────
         *
         * ⚠️ পাঁচটা ব্লক হাতে লেখা, `places()` ধরে লুপে নয় — আর সেটা
         * ইচ্ছাকৃত (১৩ সেপ্টেম্বর ২০২৬)।
         *
         * প্রথমে লুপেই লেখা হয়েছিল, কম লাইনে। কিন্তু larastan স্কিমাটা
         * **মাইগ্রেশন পড়ে** শেখে (`phpstan.neon`-এ `databaseMigrationsPath`),
         * আর লুপের ভেতরের কলামের নাম সে স্থিরভাবে দেখতে পায় না। ফলে
         * `$entry->person_id` সম্পর্কে সে বলত *"অচেনা প্রপার্টি"* — অর্থাৎ
         * চতুর কোডটা বিশ্লেষককে অন্ধ করে দিত, আর নতুন কলামের উপর তার
         * পাহারা হারাত।
         *
         * ⓘ `restrictOnDelete` — যাঁর নামে টাকার হিসাব আছে তাঁকে
         * হার্ড-ডিলিট করা যাবে না। `cascade` হলে একটা ভুল ক্লিকে মূলধনের
         * সারিগুলোও যেত; `nullOnDelete` হলে কাগজটা থাকত কিন্তু "কে"
         * হারাত — আর নামহীন মূলধন কারো কাজে লাগে না। তালিকায় মোছা হয়
         * সফট-ডিলিটে, তাই বাস্তবে বাধাটা কেবল সত্যিকারের হার্ড-ডিলিটে বাজে।
         */
        if (! Schema::hasColumn('acc_capital_entries', 'person_id')) {
            Schema::table('acc_capital_entries', function (Blueprint $table): void {
                $table->foreignId('person_id')->nullable()->after('document_no')
                    ->constrained('mdm_people')->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('fin_withdrawals', 'person_id')) {
            Schema::table('fin_withdrawals', function (Blueprint $table): void {
                $table->foreignId('person_id')->nullable()->after('document_no')
                    ->constrained('mdm_people')->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('acc_withdrawal_limits', 'person_id')) {
            Schema::table('acc_withdrawal_limits', function (Blueprint $table): void {
                $table->foreignId('person_id')->nullable()->after('company_id')
                    ->constrained('mdm_people')->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('fin_hand_loan_accounts', 'person_id')) {
            Schema::table('fin_hand_loan_accounts', function (Blueprint $table): void {
                $table->foreignId('person_id')->nullable()->after('branch_id')
                    ->constrained('mdm_people')->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('fin_deposits', 'person_id')) {
            Schema::table('fin_deposits', function (Blueprint $table): void {
                $table->foreignId('person_id')->nullable()->after('held_by')
                    ->constrained('mdm_people')->restrictOnDelete();
            });
        }

        // ── ২ · ব্যাক-ফিল — যা লেখা আছে তা তালিকায় তুলে আনা ─────────
        $this->backfill();

        /*
         * ── ৩ · যাচাই, **মোছার আগে** ────────────────────────────────
         *
         * ⚠️ এই ক্রমটাই এখানে মূল সিদ্ধান্ত। কোনো সারির নাম যদি তালিকায়
         * তোলা না যায় (খালি নাম, বা অচেনা কিছু), তবে কলাম মুছে ফেললে
         * সেই সারির "কে" **চিরতরে হারাত**, আর কেউ কোনোদিন জানতেও পারত
         * না — নীরব ডেটা-ক্ষতি, যেটা সবচেয়ে খারাপ ধরন।
         *
         * তাই আগে গুনে দেখা, আর না মিললে **জোরে থামা**। তখন কিছুই মোছা
         * হয়নি, deploy থামে, আর একজন মানুষ তাকিয়ে দেখেন।
         */
        $this->assertNothingWouldBeLost();

        /*
         * ── ৪ · পুরনো মুক্ত-লেখা ঘর বাদ ───────────────────────────────
         *
         * ⓘ এখানেও হাতে লেখা, উপরের একই কারণে: larastan যেন দেখতে পায়
         * কোন কলামটা আর নেই। লুপে লিখলে সে ভাবত ঘরগুলো এখনো আছে, আর
         * পুরনো নামে কেউ কোড লিখলে চুপ করে থাকত।
         */
        if (Schema::hasColumn('acc_capital_entries', 'contributor_name')) {
            Schema::table('acc_capital_entries', function (Blueprint $table): void {
                $table->dropColumn('contributor_name');
            });
        }

        if (Schema::hasColumn('fin_withdrawals', 'contributor_name')) {
            Schema::table('fin_withdrawals', function (Blueprint $table): void {
                $table->dropColumn('contributor_name');
            });
        }

        if (Schema::hasColumn('acc_withdrawal_limits', 'contributor_name')) {
            Schema::table('acc_withdrawal_limits', function (Blueprint $table): void {
                $table->dropColumn('contributor_name');
            });
        }

        if (Schema::hasColumn('fin_hand_loan_accounts', 'person_name')) {
            Schema::table('fin_hand_loan_accounts', function (Blueprint $table): void {
                $table->dropColumn('person_name');
            });
        }

        if (Schema::hasColumn('fin_deposits', 'holder_name')) {
            Schema::table('fin_deposits', function (Blueprint $table): void {
                $table->dropColumn('holder_name');
            });
        }

        /*
         * হাতে-ধারের মোবাইলও যায় — নম্বরটা এখন ব্যক্তির সারিতে।
         *
         * দুই জায়গায় রাখলে একদিন আলাদা হত: কেউ হাতে-ধারের পর্দায় নম্বর
         * বদলাতেন, আর তালিকায় পুরনোটা থেকে যেত — তখন নকল-পাহারা পুরনো
         * নম্বর ধরে কাজ করত।
         */
        if (Schema::hasColumn('fin_hand_loan_accounts', 'mobile')) {
            Schema::table('fin_hand_loan_accounts', function (Blueprint $table): void {
                $table->dropColumn('mobile');
            });
        }

        /*
         * ⚠️ ক্রমটা এখানে উল্টো — **নতুন সূচক আগে, বাসি সূচক পরে**, আর
         * সেটা একটা সত্যিকারের ব্যর্থতার পর (১৩ সেপ্টেম্বর ২০২৬)।
         *
         * প্রথমে উল্টোটা লেখা ছিল, আর MySQL সোজা খারিজ করল:
         *
         *     SQLSTATE[HY000]: 1553 Cannot drop index
         *     'acc_withdrawal_limits_company_id_contributor_name_unique':
         *     needed in a foreign key constraint
         *
         * কারণ ওই বাসি সূচকটাই তখন `company_id`-র বিদেশি চাবিকে সেবা
         * দিচ্ছিল — MySQL-এ প্রতিটা FK-র একটা সূচক লাগে, আর সে শেষটা
         * কেড়ে নিতে দেয় না। নতুন `unique(company_id, person_id)` আগে
         * বসলে FK-র হাতে বিকল্প থাকে, তখন পুরনোটা মোছা যায়।
         *
         * ⓘ ব্যর্থতাটা **জোরে** হয়েছিল, নীরবে নয় — deploy থেমেছে, আর
         * বার্তাটাই কারণ বলে দিয়েছে। এই ফাইলের বাকি সব পাহারা ঠিক সেই
         * জিনিসটাই চায়।
         */

        /*
         * ── ৫ · নতুন সূচকগুলো, এবার `person_id` ধরে ───────────────────
         *
         * পুরনোগুলো যে প্রশ্নের জন্য বসানো হয়েছিল, প্রশ্নটা বদলায়নি —
         * কেবল ঘরটা বদলেছে। বিশেষ করে উত্তোলনের সূচকটা: `assertWithinCap`
         * ঠিক `(company_id, person_id, trx_date)` ধরেই খোঁজে।
         */
        if (! $this->hasIndex('acc_capital_entries', 'acc_capital_entries_company_person_index')) {
            Schema::table('acc_capital_entries', function (Blueprint $table): void {
                $table->index(['company_id', 'person_id'], 'acc_capital_entries_company_person_index');
            });
        }

        if (! $this->hasIndex('fin_withdrawals', 'fin_withdrawals_company_person_date_index')) {
            Schema::table('fin_withdrawals', function (Blueprint $table): void {
                $table->index(['company_id', 'person_id', 'trx_date'], 'fin_withdrawals_company_person_date_index');
            });
        }

        if (! $this->hasIndex('fin_hand_loan_accounts', 'fin_hand_loans_company_status_person_index')) {
            Schema::table('fin_hand_loan_accounts', function (Blueprint $table): void {
                $table->index(['company_id', 'status', 'person_id'], 'fin_hand_loans_company_status_person_index');
            });
        }

        /*
         * একজনের একটাই মাসিক সীমা।
         *
         * আগে অনন্যতাটা নামে ছিল না কোথাও — `setCap()` নিজে
         * `updateOrCreate` দিয়ে সামলাত, আর দুইটা বানান মানে দুইটা সারি,
         * দুইটা সীমা। কোনটা খাটত তা নির্ভর করত উত্তোলনে কোন বানান লেখা
         * হয়েছে তার উপর।
         */
        if (! $this->hasIndex('acc_withdrawal_limits', 'acc_withdrawal_limits_one_cap_per_person')) {
            Schema::table('acc_withdrawal_limits', function (Blueprint $table): void {
                $table->unique(['company_id', 'person_id'], 'acc_withdrawal_limits_one_cap_per_person');
            });
        }
        /*
         * ── ৬ · ⛔ পুরনো কলামের সূচকগুলো — MySQL ওগুলো মোছে না ─────────
         *
         * একটা যৌগিক সূচকের একটা কলাম মুছলে MySQL **সূচকটা রাখে**, কেবল
         * ওই কলামটা বাদ দিয়ে। ফলে `unique(company_id, contributor_name)`
         * হয়ে যায় `unique(company_id)` — অর্থাৎ **এক কোম্পানিতে একটাই
         * সীমা বসতে পারে**, আর দ্বিতীয় মালিকের সীমা বসাতে গেলেই
         * ডুপ্লিকেট-কী ত্রুটি।
         *
         * ⚠️ এটা ধরা পড়েছে মাইগ্রেশনের পর `information_schema.STATISTICS`
         * পড়ে — কলাম গুনে দেখা যথেষ্ট ছিল না, সূচকও গুনতে হয়েছে। তিনটা
         * সাধারণ সূচকও একইভাবে অর্ধেক হয়ে বসে ছিল: নামে পুরনো কলামের নাম,
         * অথচ ভেতরে সে কলাম নেই — পরের জনকে ভুল পথে নিত।
         *
         * ⓘ নামগুলো হাতে লেখা, কারণ `dropIndex(['column'])` ওই কলামের
         * নাম ধরে সূচকের নাম বানায় — আর কলামটা তো আর নেই।
         */
        $stale = [
            'acc_withdrawal_limits' => ['acc_withdrawal_limits_company_id_contributor_name_unique'],
            'acc_capital_entries' => ['acc_capital_entries_company_id_contributor_name_index'],
            'fin_withdrawals' => ['fin_withdrawals_company_id_contributor_name_trx_date_index'],
            'fin_hand_loan_accounts' => ['fin_hand_loan_accounts_company_id_status_person_name_index'],
        ];

        foreach ($stale as $table => $names) {
            foreach ($names as $name) {
                if (! $this->hasIndex($table, $name)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                    $blueprint->dropIndex($name);
                });
            }
        }
    }

    /**
     * যা লেখা আছে তা `mdm_people`-এ তুলে আনা, আর সারিগুলো সেদিকে তাক করা।
     *
     * ── কোডটা এখানে নাম থেকে বানানো হয় না, আর কেন ───────────────────
     * নতুন সারি বসানোর সময় কোড আসে নাম থেকে ([[MasterListService]])।
     * কিন্তু ব্যাক-ফিলে দরকার **নিশ্চিত অনন্যতা**: একই কোড দুইবার বেরোলে
     * মাইগ্রেশনটা মাঝপথে ভাঙত, আর ভাঙা মাইগ্রেশন লাইভে সবচেয়ে খারাপ
     * অবস্থা। তাই ক্রমিক `P0001` — বিরক্তিকর, কিন্তু কোনোদিন সংঘর্ষ করে না।
     */
    private function backfill(): void
    {
        foreach ($this->companies() as $companyId) {
            $next = 0;

            foreach ($this->places() as $place) {
                if (! Schema::hasTable($place['table']) || ! Schema::hasColumn($place['table'], $place['name'])) {
                    continue;
                }

                $rows = DB::table($place['table'])
                    ->where('company_id', $companyId)
                    ->whereNull('person_id')
                    ->whereNotNull($place['name'])
                    ->where($place['name'], '!=', '')
                    ->get(array_values(array_filter(['id', $place['name'], $place['extra']])));

                foreach ($rows as $row) {
                    $name = trim((string) $row->{$place['name']});

                    if ($name === '') {
                        continue;
                    }

                    $personId = $this->findOrMake(
                        $companyId,
                        $name,
                        $place['extra'] === null ? null : $row->{$place['extra']},
                        $next,
                    );

                    DB::table($place['table'])->where('id', $row->id)->update(['person_id' => $personId]);
                }
            }
        }
    }

    /**
     * এই নামে সারি আছে? থাকলে সেটাই, নাহলে নতুন।
     *
     * ⚠️ মিলটা **হুবহু** (trim করা) — `Al Amin` আর `Al-Amin` আলাদা দুইটা
     * সারি হবে, আর সেটাই ইচ্ছাকৃত। মাইগ্রেশন সিদ্ধান্ত নেয় না যে দুইটা
     * বানান একই মানুষ; ওটা মানুষের কাজ, আর তালিকার পর্দা থেকে দুইটাকে
     * এক করা যায়। জোর করে মিলিয়ে দিলে দুইজন সত্যিকারের আলাদা মানুষও
     * এক হয়ে যেতেন — আর টাকার হিসাবে সেটা অপূরণীয়।
     */
    private function findOrMake(int $companyId, string $name, ?string $mobile, int &$next): int
    {
        $existing = DB::table('mdm_people')
            ->where('company_id', $companyId)
            ->where('name_en', $name)
            ->value('id');

        if ($existing !== null) {
            // নম্বরটা আগে না থাকলে এখন বসে — হাতে-ধারে নম্বর ছিল, বাকি
            // চার জায়গায় ছিল না
            if (filled($mobile)) {
                DB::table('mdm_people')
                    ->where('id', $existing)
                    ->whereNull('mobile')
                    ->update(['mobile' => $mobile]);
            }

            return (int) $existing;
        }

        do {
            $next++;
            $code = 'P'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
            $taken = DB::table('mdm_people')->where('company_id', $companyId)->where('code', $code)->exists();
        } while ($taken);

        return (int) DB::table('mdm_people')->insertGetId([
            // UUIDv7 — HasPublicId যেটা বসায়, হুবহু সেটাই (সময়-ক্রমানুসারী)
            'public_id' => (string) Str::uuid7(),
            'company_id' => $companyId,
            'code' => $code,
            'name_en' => $name,
            'mobile' => filled($mobile) ? $mobile : null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * ⛔ মোছার আগে শেষ পাহারা — একটা নামও যেন হারিয়ে না যায়।
     *
     * ব্যাক-ফিলের পর প্রতিটা সারিতে নাম থাকলে `person_id`-ও থাকতে হবে।
     * না থাকলে কলাম মোছা মানে ওই সারির "কে" চিরতরে হারানো, তাই তখন
     * থামা — আর এই মুহূর্তে কিছুই মোছা হয়নি, তাই থামাটা নিরাপদ।
     */
    private function assertNothingWouldBeLost(): void
    {
        $orphans = [];

        foreach ($this->places() as $place) {
            if (! Schema::hasTable($place['table']) || ! Schema::hasColumn($place['table'], $place['name'])) {
                continue;
            }

            $count = DB::table($place['table'])
                ->whereNull('person_id')
                ->whereNotNull($place['name'])
                ->where($place['name'], '!=', '')
                ->count();

            if ($count > 0) {
                $orphans[] = "{$place['table']}.{$place['name']}: {$count}";
            }
        }

        if ($orphans !== []) {
            throw new RuntimeException(
                'ব্যাক-ফিল সম্পূর্ণ হয়নি — নিচের সারিগুলোর নাম ব্যক্তির তালিকায় তোলা যায়নি, '
                ."আর পুরনো কলাম মুছলে ওই নামগুলো হারিয়ে যেত। কিছুই মোছা হয়নি।\n"
                .implode("\n", $orphans)
            );
        }
    }

    /** এই নামে সূচক আছে কি না — নামটা হাতে, কারণ কলামটা আর নেই। */
    private function hasIndex(string $table, string $name): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $name)
            ->exists();
    }

    /** @return list<int> */
    private function companies(): array
    {
        return DB::table('companies')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * ⚠️ ফেরানোর পথ আছে, কিন্তু নামগুলো ফেরে না।
     *
     * কলামগুলো আবার বসে (খালি), আর `person_id` সরে যায়। নাম ফেরাতে হলে
     * `mdm_people` থেকে লিখে দেওয়া যেত, কিন্তু `down()` চলার সময় FK-টা
     * আগেই সরানো দরকার — তাই নামটা ফেরানোর কাজ ইচ্ছাকৃতভাবে করা হয় না।
     *
     * এটা লিখে রাখা হলো যাতে কেউ ধরে না নেন rollback নিরাপদ: **এটা
     * নিরাপদ নয়**, আর লাইভে rollback-এর আগে ব্যাকআপই আসল উত্তর।
     */
    public function down(): void
    {
        if (Schema::hasTable('acc_withdrawal_limits')) {
            Schema::table('acc_withdrawal_limits', function (Blueprint $table): void {
                $table->dropUnique('acc_withdrawal_limits_one_cap_per_person');
            });
        }

        foreach ($this->places() as $place) {
            if (! Schema::hasTable($place['table'])) {
                continue;
            }

            Schema::table($place['table'], function (Blueprint $table) use ($place): void {
                if (Schema::hasColumn($table->getTable(), 'person_id')) {
                    $table->dropConstrainedForeignId('person_id');
                }

                if (! Schema::hasColumn($table->getTable(), $place['name'])) {
                    $table->string($place['name'], 191)->nullable();
                }
            });
        }

        if (! Schema::hasColumn('fin_hand_loan_accounts', 'mobile')) {
            Schema::table('fin_hand_loan_accounts', function (Blueprint $table): void {
                $table->string('mobile', 32)->nullable();
            });
        }
    }
};
