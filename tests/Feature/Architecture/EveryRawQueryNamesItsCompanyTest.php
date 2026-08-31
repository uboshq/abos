<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * প্রতিটা কাঁচা কোয়েরি নিজের কোম্পানির নাম বলে।
 *
 * ── কেন এই পাহারাটা লাগল, ৩১ আগস্ট ২০২৬ ──────────────────────────────
 * টেন্যান্ট আলাদা রাখার পুরো ব্যবস্থাটা [[BelongsToCompany]]-র গ্লোবাল
 * স্কোপের উপর দাঁড়ানো, আর [[TenantIsolationTest]] সেটাই পরীক্ষা করে।
 * কিন্তু `DB::table()` Eloquent-এর পাশ দিয়ে চলে যায় — কোনো মডেল নেই,
 * তাই কোনো স্কোপও নেই।
 *
 * ফল: ড্যাশবোর্ডের দুইটা ফাংশন (`SalesWidgets::againstLastWeek()` ও
 * `lastSevenDays()`) ছাঁকনি ছাড়া চলত, আর এক কোম্পানির মালিক **সব
 * কোম্পানির** বিক্রি একসাথে দেখতেন। অ্যাপের বাকি আশিটা কাঁচা কোয়েরি
 * ঠিকই ছাঁকত — অর্থাৎ নিয়মটা সবাই জানত, শুধু দুই জায়গায় ভুলে গিয়েছিল।
 *
 * টেন্যান্ট ফাঁস এমন একটা ভুল যা কেউ রিপোর্ট করে না: সংখ্যাটা বড় দেখায়,
 * আর বড় সংখ্যা কেউ সন্দেহ করে না। তাই পাহারাটা কোড পড়েই ধরে।
 *
 * ── কী পড়া হয় ───────────────────────────────────────────────────────
 * প্রতিটা `DB::table(...)` থেকে ওই বাক্যটার শেষ পর্যন্ত (`;`) পড়ে দেখা
 * হয় `company_id` শব্দটা আছে কি না। বাক্য ধরে পড়া হয়, কয়েক লাইন ধরে
 * নয় — লম্বা রিপোর্ট-কোয়েরিতে ছাঁকনিটা কুড়ি লাইন নিচেও থাকতে পারে,
 * আর লাইন গুনলে ওগুলো মিথ্যা অভিযোগ হয়ে আসত।
 */
class EveryRawQueryNamesItsCompanyTest extends TestCase
{
    /**
     * যেসব টেবিলে কোম্পানি বলে কিছু নেই — আর কেন।
     *
     * তালিকাটা ছোট রাখা ইচ্ছাকৃত। প্রতিটা সারি একটা সিদ্ধান্ত, আর
     * সিদ্ধান্তের কারণ পাশেই লেখা।
     */
    private const NO_COMPANY_COLUMN = [
        'sessions' => 'লগইন সেশন — ব্যবহারকারীর, কোম্পানির নয়; ছাঁকনি user_id',
        'users' => 'একজন ব্যবহারকারী একাধিক কোম্পানিতে থাকতে পারেন',
        'companies' => 'কোম্পানির তালিকা নিজেই',
        'migrations' => 'ফ্রেমওয়ার্কের নিজের খাতা',
        'cache' => 'ফ্রেমওয়ার্ক',
        'cache_locks' => 'ফ্রেমওয়ার্ক',
        'jobs' => 'ফ্রেমওয়ার্ক',
        'job_batches' => 'ফ্রেমওয়ার্ক',
        'failed_jobs' => 'ফ্রেমওয়ার্ক',
        'password_reset_tokens' => 'ফ্রেমওয়ার্ক',
        'permissions' => 'অনুমতির নাম সব কোম্পানিতে এক',
        'roles' => 'রোলের নাম সব কোম্পানিতে এক',
        'model_has_roles' => 'জোড়ার টেবিল',
        'model_has_permissions' => 'জোড়ার টেবিল',
        'role_has_permissions' => 'জোড়ার টেবিল',

        /*
         * লাইন-টেবিলে company_id নেই, আর থাকার কথাও নয় — লাইনটা কার,
         * সেটা তার ডকুমেন্ট জানে। এখানে কলামটা বসালে একই সত্য দুই
         * জায়গায় থাকত, আর একদিন দুইটা আলাদা উত্তর দিত।
         *
         * দুইটাই বইয়ের যাচাইয়ে সাব-কোয়েরি হিসেবে ব্যবহার হয়, আর
         * বাইরের কোয়েরিটা ডকুমেন্টের company_id ধরেই ছাঁকে।
         */
        'sal_invoice_lines' => 'লাইন টেবিল — কোম্পানি বিলের সারিতে',
        'pur_bill_lines' => 'লাইন টেবিল — কোম্পানি বিলের সারিতে',
    ];

    /**
     * ঘোষিত ব্যতিক্রম — যেখানে ছাঁকনিটা অন্য কিছু দিয়ে হয়।
     *
     * ফাইল ও কারণ, দুইটাই লেখা থাকতে হবে। কারণ না লিখে তালিকায় নাম
     * বসানো মানে পাহারাটা নিজেই একটা ফাঁক হয়ে যাওয়া।
     *
     * @var array<string, string>
     */
    private const DECLARED = [
        'app/Modules/MasterData/Services/MasterListService.php' => 'সন্তান-সারি আছে কি না দেখা — অভিভাবকের আইডি দিয়েই, আর অভিভাবক '
            .'ইতিমধ্যেই কোম্পানি-স্কোপে বাছা',

        'app/Modules/Sales/Services/BatchTrace.php' => 'ব্যাচ ধরে খোঁজা — ব্যাচটা নিজেই কোম্পানি-স্কোপে বাছা, আর '
            .'ব্যাচ আইডি পুরো ব্যবস্থায় অদ্বিতীয়',

        'app/Console/Commands/CatchUpNumbers.php' => 'ছাঁকনিটা শর্তসাপেক্ষে বসে (`$where[\'scoped\']`), কারণ নম্বর '
            .'সিরিজের কিছু টেবিল ইচ্ছাকৃতভাবেই কোম্পানি-নিরপেক্ষ; '
            .'বসানোর সময় সিরিজের নিজের company_id ব্যবহার হয়',
    ];

    public function test_no_raw_query_forgets_the_company(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(app_path()) as $file) {
            $relative = $this->relative($file);

            // মাইগ্রেশনে কোনো কোম্পানি-প্রসঙ্গ থাকে না; ওখানে কোম্পানি
            // ধরে ধরে ঘোরাই স্বাভাবিক
            if (str_contains($relative, '/Database/Migrations/')) {
                continue;
            }

            if (array_key_exists($relative, self::DECLARED)) {
                continue;
            }

            foreach ($this->rawQueriesIn(File::get($file)) as $statement) {
                if (str_contains($statement['code'], 'company_id')) {
                    continue;
                }

                if ($statement['table'] !== null
                    && array_key_exists($statement['table'], self::NO_COMPANY_COLUMN)) {
                    continue;
                }

                $offenders[] = $relative.' → '.($statement['table'] ?? '(চলক)');
            }
        }

        sort($offenders);

        $this->assertSame([], $offenders, implode("\n", [
            'এই কাঁচা কোয়েরিগুলো কোন কোম্পানির তা বলে না।',
            'DB::table() গ্লোবাল স্কোপ মানে না — ছাঁকনিটা হাতে বসাতে হয়:',
            "    ->where('company_id', CompanyContext::id())",
            'টেবিলটায় সত্যিই কোম্পানি না থাকলে NO_COMPANY_COLUMN-এ,',
            'আর ছাঁকনিটা অন্যভাবে হলে DECLARED-এ কারণসহ লিখুন।',
        ]));
    }

    /**
     * প্রতিটা DB::table(...) আর তার বাক্যটা।
     *
     * @return list<array{table: ?string, code: string}>
     */
    private function rawQueriesIn(string $source): array
    {
        $found = [];
        $offset = 0;

        while (($at = strpos($source, 'DB::table(', $offset)) !== false) {
            $offset = $at + 10;

            // টেবিলের নাম — উদ্ধৃতিতে লেখা থাকলে। চলক হলে null, আর তখন
            // ছাড় দেওয়ার কোনো উপায় নেই: নামটা না জেনে বলা যায় না
            // টেবিলটায় company_id আছে কি না
            $table = null;

            if (preg_match('/\G\s*[\'"]([a-z0-9_]+)/i', $source, $m, 0, $offset) === 1) {
                $table = $m[1];
            }

            $found[] = ['table' => $table, 'code' => $this->statementFrom($source, $at)];
        }

        return $found;
    }

    /**
     * বাক্যটার শেষ পর্যন্ত — বন্ধনী গুনে, প্রথম `;` পর্যন্ত নয়।
     *
     * ক্লোজারের ভেতরে `;` থাকে, তাই কেবল প্রথম সেমিকোলন ধরলে বাক্যটা
     * মাঝপথে কাটা পড়ত আর নিচের ছাঁকনিটা দেখা যেত না।
     */
    private function statementFrom(string $source, int $at): string
    {
        $depth = 0;
        $length = strlen($source);

        for ($i = $at; $i < $length; $i++) {
            $char = $source[$i];

            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                $depth--;
            } elseif ($char === ';' && $depth <= 0) {
                return substr($source, $at, $i - $at);
            }
        }

        return substr($source, $at);
    }

    /** @return list<string> */
    private function phpFilesIn(string $dir): array
    {
        return array_values(array_filter(
            File::allFiles($dir),
            fn ($file) => $file->getExtension() === 'php',
        ));
    }

    private function relative(mixed $file): string
    {
        return str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', (string) $file));
    }
}
