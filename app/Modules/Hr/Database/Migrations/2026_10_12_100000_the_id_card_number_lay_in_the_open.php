<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * পরিচয়পত্রের নম্বরটা খোলা পড়ে ছিল।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * ২ সেপ্টেম্বর ২০২৬-এ পর্দায় ঢাকা হয়েছে ([[FieldSecurity]]) — কে দেখতে
 * পাবে তা এখন অনুমতির পেছনে। কিন্তু **ডাটাবেজে সংখ্যাগুলো যেমন ছিল
 * তেমনই**: জাতীয় পরিচয়পত্র, ব্যাংক হিসাব, রাউটিং নম্বর, MFS নম্বর —
 * সাতটা ঘর, সাতটাই সাধারণ লেখা।
 *
 * অর্থাৎ পর্দার তালাটা কেবল **পর্দার** তালা। একটা ব্যাকআপ ফাইল, একটা
 * `mysql` প্রম্পট, বা phpMyAdmin-এ একবার ঢোকা — তিনটার যেকোনোটাই
 * প্রতিটা কর্মীর পরিচয়পত্র পড়ে ফেলার জন্য যথেষ্ট ছিল।
 *
 * ── কেন আজ, আর কেন ছয় মাস পরে নয় ─────────────────────────────────────
 * লাইভে আজ **একজন কর্মী, আর তাঁর NID, ব্যাংক হিসাব ও MFS তিনটাই খালি**।
 * অর্থাৎ রূপান্তরের খরচ আজ শূন্য।
 *
 * ছয় মাস পরে এই কাজটাই হত সবচেয়ে ভয়ের: শত শত সারি পড়ে, এনক্রিপ্ট করে,
 * আবার লিখতে হত — আর মাঝপথে থেমে গেলে ডাটাবেজে অর্ধেক সারি এক রূপে
 * আর অর্ধেক অন্য রূপে পড়ে থাকত। **কাজটা সহজ থাকতেই করা হচ্ছে।**
 *
 * ── কলামগুলো কেন `text` ──────────────────────────────────────────────
 * `varchar(32)`-এ একটা ১৭ অঙ্কের NID আঁটে; কিন্তু এনক্রিপ্ট করার পর
 * সেটা iv + ciphertext + MAC মিলে base64-এ প্রায় ২০০ বাইট। **প্রস্থ না
 * বাড়ালে MySQL চুপচাপ কেটে দিত**, আর কাটা ciphertext কোনোদিন আর খোলা
 * যেত না — মানটা হারাত, কিন্তু কোনো ভুল দেখাত না।
 *
 * ── আর `national_id_hash` কেন ────────────────────────────────────────
 * কর্মী খোঁজার বাক্সটা একটাই, আর সে নাম-কোড-মোবাইলের সাথে NID-ও খোঁজে
 * ([[Employee::scopeSearch()]])। এনক্রিপ্ট করলে সেটা কাজ করত না — একই
 * সংখ্যা প্রতিবার আলাদা ciphertext, তাই `LIKE` মেলানোর কিছুই থাকে না।
 *
 * তাই একটা **অন্ধ ইনডেক্স**: মানটার HMAC, নির্ধারিত, শুধু মেলানোর জন্য।
 * পুরো নম্বর লিখে খোঁজা কাজ করে — বাস্তবে কার্ড দেখে বা কপি করে এভাবেই
 * খোঁজা হয়। **আংশিক খোঁজা (শেষ চার সংখ্যা) আর কাজ করবে না**, আর সেটা
 * এই সিদ্ধান্তের ঘোষিত দাম।
 *
 * ── APP_KEY বদলালে ───────────────────────────────────────────────────
 * ciphertext আর hash — দুইটাই অকেজো হবে। এটা এনক্রিপশনের স্বাভাবিক
 * শর্ত, কিন্তু লিখে রাখা দরকার: **চাবিটা এখন ব্যাকআপের অংশ**, আর
 * চাবি ছাড়া ডাম্পটা অর্ধেক পড়া যায়।
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const COLUMNS = [
        'hr_employees' => ['national_id', 'bank_account_no', 'bank_routing_no', 'mfs_number'],
        'hr_payslips' => ['bank_account_no', 'bank_routing_no', 'mfs_number'],
    ];

    public function up(): void
    {
        // ── ১ · আগে জায়গা, তারপর মান ───────────────────────────────
        // উল্টো ক্রমে করলে প্রথম সারিটাই কাটা পড়ত।
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($columns): void {
                foreach ($columns as $column) {
                    $t->text($column)->nullable()->change();
                }
            });
        }

        if (Schema::hasTable('hr_employees') && ! Schema::hasColumn('hr_employees', 'national_id_hash')) {
            Schema::table('hr_employees', function (Blueprint $t): void {
                /*
                 * ইনডেক্স আছে, unique নেই।
                 *
                 * একই নম্বর দুইবার বসানো একটা ভুল, কিন্তু unique করলে
                 * সেই ভুলটা ধরা পড়ত একটা ৫০০ হয়ে — আর এই মাইগ্রেশনের
                 * কাজ নিরাপত্তা, নতুন কোনো ব্যবসার নিয়ম নয়।
                 */
                $t->char('national_id_hash', 64)->nullable()->after('national_id')->index();
            });
        }

        // ── ২ · যা আছে তা এনক্রিপ্ট ─────────────────────────────────
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table, $columns): void {
                foreach ($rows as $row) {
                    $update = [];

                    foreach ($columns as $column) {
                        $value = $row->{$column} ?? null;

                        if (! is_string($value) || $value === '' || $this->alreadyEncrypted($value)) {
                            continue;
                        }

                        $update[$column] = Crypt::encryptString($value);

                        if ($table === 'hr_employees' && $column === 'national_id') {
                            $update['national_id_hash'] = self::blindIndex($value);
                        }
                    }

                    if ($update !== []) {
                        DB::table($table)->where('id', $row->id)->update($update);
                    }
                }
            });
        }
    }

    /**
     * ইতিমধ্যেই এনক্রিপ্ট করা কি না।
     *
     * ── কেন এটা লাগে ────────────────────────────────────────────────
     * মাইগ্রেশন দুইবার চললে — বা কেউ হাতে চালালে — দ্বিতীয়বার
     * এনক্রিপ্ট হয়ে যেত, আর তখন একবার খুললে ciphertext বেরোত, মান নয়।
     * **ওই ভুলটা নীরব**: কোনো ব্যতিক্রম নেই, শুধু মানটা আর কোনোদিন
     * ঠিক পড়া যায় না।
     */
    private function alreadyEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * খোঁজার জন্য নির্ধারিত ছাপ।
     *
     * ফাঁকা ও ড্যাশ ফেলে দেওয়া হয় — কেউ "১২৩৪ ৫৬৭৮" লিখলে আর
     * "১২৩৪৫৬৭৮" লিখলে একই কর্মী পাওয়া উচিত।
     */
    public static function blindIndex(string $value): string
    {
        $clean = preg_replace('/[\s\-]+/u', '', trim($value)) ?? '';

        return hash_hmac('sha256', $clean, (string) config('app.key'));
    }

    /**
     * ফেরানো — মান খুলে, তারপর কলাম ছোট করে।
     *
     * ছোট করার আগে না খুললে ciphertext কেটে যেত, আর তখন ফেরানোর পরেও
     * মানগুলো আর পড়া যেত না — অর্থাৎ `down()` নিজেই ডেটা হারাত।
     */
    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table, $columns): void {
                foreach ($rows as $row) {
                    $update = [];

                    foreach ($columns as $column) {
                        $value = $row->{$column} ?? null;

                        if (! is_string($value) || $value === '') {
                            continue;
                        }

                        try {
                            $update[$column] = Crypt::decryptString($value);
                        } catch (Throwable) {
                            // আগে থেকেই সাধারণ লেখা — ছোঁয়ার কিছু নেই
                        }
                    }

                    if ($update !== []) {
                        DB::table($table)->where('id', $row->id)->update($update);
                    }
                }
            });
        }

        if (Schema::hasTable('hr_employees') && Schema::hasColumn('hr_employees', 'national_id_hash')) {
            Schema::table('hr_employees', fn (Blueprint $t) => $t->dropColumn('national_id_hash'));
        }

        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($columns): void {
                foreach ($columns as $column) {
                    $width = $column === 'bank_account_no' ? 64 : 32;
                    $t->string($column, $width)->nullable()->change();
                }
            });
        }
    }
};
