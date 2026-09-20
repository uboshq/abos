<?php

declare(strict_types=1);

use App\Modules\Finance\Models\Institution;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ঋণের ব্যাংকটা ছিল কেবল হাতে লেখা একটা নাম।
 *
 * ── কী বদলাল ─────────────────────────────────────────────────────────
 * ব্যাংক সুবিধা (`fin_bank_facilities.bank`) আর আমানত
 * (`fin_deposits.institution`) — দুইটাই মুক্ত-লেখা ঘর ছিল। এখন দুইটাতেই
 * `institution_id`, আর তালিকাটা [[App\Modules\Finance\Models\Institution]]।
 *
 * ── ⚠️ পুরনো ঘরটা মোছা হয় না, ইচ্ছাকৃতভাবে ──────────────────────────
 * ⓘ মানুষ যা টাইপ করেছিলেন সেটাই ঐতিহাসিক সত্য — কোন বানানে লেখা ছিল,
 * সেটা মিলকরণের সময় কাজে লাগে। ⛔ আর মুছে দিলে এই মাইগ্রেশনের কোনো
 * ভুল জোড়া আর ফেরানো যেত না।
 *
 * ── কী মেলানো হয়, আর কী হয় না ───────────────────────────────────────
 * ⭐ একই কোম্পানির ভেতরে **হুবহু এক চাবির** নামগুলো এক প্রতিষ্ঠানে বসে
 * ([[Institution::keyFor]] — ছোট হাতের অক্ষর, `.` `,` বাদ, ফাঁকা এক ঘরে)।
 * ⛔ "IBBL" আর "Islami Bank" আলাদাই থাকে: ওরা এক কি না সেটা মানুষ জানেন,
 * যন্ত্র নয়। ⚠️ জোর করে মেলালে দুইটা আলাদা ব্যাংকের হিসাব এক নামে জমে
 * যেত, আর কেউ ধরত না।
 *
 * ⓘ কোন নামগুলো আলাদা রয়ে গেল, তার তালিকা মাইগ্রেশন নিজেই ছাপে —
 * `php artisan migrate` চালানোর পর্দায়। পরে হাতে মেলানোর কাজটা
 * প্রতিষ্ঠানের পাতা থেকেই হয় (নাম বদলে দিলে দুইটা এক হয়ে যায়)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_bank_facilities', function (Blueprint $table) {
            $table->foreignId('institution_id')->nullable()->after('kind')
                ->constrained('fin_institutions')->nullOnDelete();
        });

        Schema::table('fin_deposits', function (Blueprint $table) {
            $table->foreignId('institution_id')->nullable()->after('kind_id')
                ->constrained('fin_institutions')->nullOnDelete();
        });

        $report = [];

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $made = [];

            // ⓘ ব্যাংক সুবিধা ব্যাংকেরই; আমানত ব্যাংক বা আর্থিক প্রতিষ্ঠানের —
            // দুইটাই `bank` ধরে বসে, আর পরে পর্দা থেকে ধরন বদলানো যায়
            $report = array_merge($report, $this->link($companyId, 'fin_bank_facilities', 'bank', $made));
            $report = array_merge($report, $this->link($companyId, 'fin_deposits', 'institution', $made));
        }

        foreach ($report as $line) {
            echo '  '.$line."\n";
        }
    }

    /**
     * @param  array<string, int>  $made  নামের চাবি → প্রতিষ্ঠানের আইডি (এই কোম্পানিতে)
     * @return list<string> যা মেলানো হলো, তার হিসাব
     */
    private function link(int $companyId, string $table, string $column, array &$made): array
    {
        $names = DB::table($table)
            ->where('company_id', $companyId)
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->pluck($column)
            ->all();

        if ($names === []) {
            return [];
        }

        $spellings = [];

        foreach ($names as $name) {
            $spellings[Institution::keyFor((string) $name)][] = (string) $name;
        }

        $lines = [];

        foreach ($spellings as $key => $written) {
            $id = $made[$key] ?? DB::table('fin_institutions')
                ->where('company_id', $companyId)->where('name_key', $key)->value('id');

            if ($id === null) {
                $id = DB::table('fin_institutions')->insertGetId([
                    'public_id' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'kind' => Institution::BANK,
                    'name_en' => $written[0],
                    'name_key' => $key,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $made[$key] = (int) $id;

            DB::table($table)
                ->where('company_id', $companyId)
                ->whereIn($column, array_unique($written))
                ->update(['institution_id' => $id]);

            /*
             * ⓘ একই চাবির নিচে একাধিক বানান — ওগুলো এক হয়েছে, আর সেটা
             * নিরাপদ (কেবল বড়-ছোট হাতের অক্ষর বা দাঁড়ি-কমার তফাত)।
             */
            $distinct = array_values(array_unique($written));

            if (count($distinct) > 1) {
                $lines[] = $table.': "'.implode('" = "', $distinct).'" — এক প্রতিষ্ঠানে বসল';
            }
        }

        /*
         * ⚠️ আলাদা চাবির নামগুলো আলাদাই থাকল — এটাই জানানোর মতো কথা,
         * কারণ এর মধ্যে "IBBL" আর "Islami Bank Bangladesh" দুইটাই থাকতে পারে।
         */
        if (count($spellings) > 1) {
            $lines[] = $table.' (কোম্পানি '.$companyId.'): '.count($spellings)
                .'টা আলাদা নাম রয়ে গেল — একই প্রতিষ্ঠান হলে পর্দা থেকে মিলিয়ে নিন';
        }

        return $lines;
    }

    public function down(): void
    {
        Schema::table('fin_bank_facilities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('institution_id');
        });

        Schema::table('fin_deposits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('institution_id');
        });
    }
};
