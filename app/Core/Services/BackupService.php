<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Services\Backup\PdoDumper;
use App\Core\Services\Backup\PdoLoader;
use App\Core\Services\Backup\ShellAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * ব্যাকআপ নেওয়া, যাচাই করা ও ফিরিয়ে আনা।
 *
 * এটা কোনো ঐচ্ছিক ফিচার নয়। ABOS চলে অফিসের একটা মেশিনে — ক্লাউডে নয়,
 * ক্লাস্টারে নয়। একটা ডিস্ক ফেল করলে প্রতিষ্ঠানের পুরো হিসাব শেষ: কার
 * কাছে কত পাওনা, কাকে কত দিতে হবে, কোন চালান কার কাছে গেছে — কিছুই আর
 * বলা যাবে না। কাগজের খাতা পুড়ে গেলে যা হত, ঠিক তাই।
 *
 * সবচেয়ে জরুরি অংশটা ব্যাকআপ নেওয়া নয়, verify(): যে ডাম্প কখনো ফিরিয়ে
 * এনে দেখা হয়নি সেটা ব্যাকআপ নয়, আশা। ভাঙা ডাম্প নীরবে জমতে থাকে আর
 * সেটা জানা যায় ঠিক সেই দিন যেদিন দরকার পড়ে।
 */
final class BackupService
{
    /** ডাম্প ফাইলের নামের ছক — তারিখ সহ, তাই ক্রম দেখেই বোঝা যায়। */
    private const NAME = 'abos-%s.sql.gz';

    /**
     * একটা ডাম্প নেওয়া।
     *
     * @return array{file: string, bytes: int, mirrored: ?string}
     */
    public function run(Carbon $at): array
    {
        $directory = $this->directory();
        $file = $directory.DIRECTORY_SEPARATOR.sprintf(self::NAME, $at->format('Y-m-d-His'));

        $this->dump($file);

        if (! is_file($file) || filesize($file) === 0) {
            throw new RuntimeException(
                "ব্যাকআপ ফাইলটা তৈরি হয়নি বা খালি: {$file}"
            );
        }

        $mirrored = $this->mirror($file);

        $this->rememberMirror($mirrored, $at);

        return [
            'file' => $file,
            'bytes' => (int) filesize($file),
            'mirrored' => $mirrored,
        ];
    }

    /**
     * সত্যিই ফিরিয়ে আনা যায় কি না।
     *
     * ডাম্পটা একটা অস্থায়ী ডাটাবেজে ঢালা হয়, টেবিল গোনা হয়, তারপর
     * ডাটাবেজটা ফেলে দেওয়া হয়। চলতি ডাটাবেজ ছোঁয়া হয় না — যাচাই করতে
     * গিয়ে আসল ডেটা মুছে ফেলার ঝুঁকি নেওয়ার কোনো মানে নেই।
     *
     * @return array{database: string, tables: int}
     */
    public function verify(string $file): array
    {
        if (! is_file($file)) {
            throw new RuntimeException("ডাম্প ফাইলটা নেই: {$file}");
        }

        $source = (string) config('database.connections.mysql.database');
        $scratch = $source.'_verify';

        /*
         * ⛔ যাচাইয়ের ডাটাবেজ কখনো আসলটা হতে পারে না — ১৯ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ নিচের প্রথম লাইনটাই `DROP DATABASE`। নামটা আসল ডাটাবেজের সমান
         * হলে (ভুল সেটিং, খালি নাম, বা সংযোগ অন্য ডাটাবেজে বসে থাকলে)
         * যাচাই করতে গিয়ে গোটা খাতাটাই মুছে যেত — আর সেটা হত রাতে, কেউ
         * দেখত না। ⓘ তাই কিছু ছোঁয়ার **আগেই** থামা, আর চিৎকার করে।
         */
        $this->refuseToTouchTheBooks($source, $scratch);

        $this->mysql("DROP DATABASE IF EXISTS `{$scratch}`; CREATE DATABASE `{$scratch}`;");

        try {
            $this->load($file, $scratch);

            $count = $this->mysql(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$scratch}'"
            );

            $tables = (int) trim(preg_replace('/\D/', '', $count) ?? '0');

            if ($tables === 0) {
                throw new RuntimeException(
                    "ডাম্পটা ফিরিয়ে আনা গেল, কিন্তু একটাও টেবিল নেই — ফাইলটা কার্যত খালি: {$file}"
                );
            }

            return ['database' => $scratch, 'tables' => $tables];
        } finally {
            // যাচাইয়ের ডাটাবেজ রেখে দিলে প্রতিটা রাতে একটা করে জমত
            $this->mysql("DROP DATABASE IF EXISTS `{$scratch}`;");
        }
    }

    /**
     * যাচাইয়ের লক্ষ্যটা আসল খাতা নয় — নিশ্চিত না হলে থামা।
     *
     * ⓘ তিনটা প্রশ্ন: আসল নামটা খালি কি না (তখন লক্ষ্য হত `_verify` —
     * কোন খাতার, কেউ জানে না), লক্ষ্য আর আসল এক কি না, আর সংযোগটা এই
     * মুহূর্তে যে ডাটাবেজে বসে আছে সেটাই লক্ষ্য কি না।
     */
    private function refuseToTouchTheBooks(string $source, string $target): void
    {
        $live = (string) DB::connection()->getDatabaseName();

        if (trim($source) === '' || $target === $source || $target === $live) {
            throw new RuntimeException(sprintf(
                'CRITICAL: যাচাই থামানো হলো — লক্ষ্য ডাটাবেজ "%s" আসল খাতার ("%s") সাথে মিলে যায় বা নামই নেই। '
                .'এগোলে আসল ডাটাবেজ মুছে যেত।',
                $target,
                $source !== '' ? $source : $live,
            ));
        }
    }

    /**
     * চলতি ডাটাবেজকে একটা ডাম্পের অবস্থায় ফিরিয়ে নেওয়া।
     *
     * ডাটাবেজটা ফেলে দিয়ে নতুন করে বানানো হয়, তারপর ডাম্প ঢালা হয়।
     * শুধু ডাম্প ঢাললে চলত না: ডাম্পের পরে যেসব টেবিল তৈরি হয়েছে
     * সেগুলো থেকে যেত, আর ডাটাবেজটা দুই সময়ের মিশ্রণ হয়ে দাঁড়াত —
     * যা পুরনো অবস্থার চেয়েও খারাপ, কারণ দেখে বোঝা যায় না।
     */
    public function restore(string $file): void
    {
        if (! is_file($file)) {
            throw new RuntimeException("ডাম্প ফাইলটা নেই: {$file}");
        }

        $database = (string) config('database.connections.mysql.database');

        $this->mysql("DROP DATABASE IF EXISTS `{$database}`; CREATE DATABASE `{$database}`;");

        $this->load($file, $database);
    }

    /**
     * পুরনো ডাম্প মুছে ফেলা।
     *
     * না মুছলে ডিস্ক ভরে যায়, আর ডিস্ক ভরলে নতুন ব্যাকআপ নেওয়াই বন্ধ
     * হয়ে যায় — অর্থাৎ যত বেশি ব্যাকআপ জমে, ব্যাকআপ থাকার সম্ভাবনা তত কম।
     *
     * @return list<string> যেগুলো মুছল
     */
    public function prune(Carbon $now): array
    {
        $days = (int) config('abos.backup.keep_days');

        if ($days <= 0) {
            return [];
        }

        $cutoff = $now->copy()->subDays($days);
        $removed = [];

        foreach ($this->all() as $file) {
            /*
             * তুলনাটা মুহূর্ত ধরে, তাই ঘড়ি না বললেও ফল একই। তবু বলা
             * হয়: একই ফাইলে দুই রকম নিয়ম থাকলে পরেরজন ভুলটা কপি করে
             * এমন জায়গায় বসান যেখানে ফলটা দেখানো হয়।
             */
            if (Carbon::createFromTimestamp(filemtime($file), config('app.timezone'))->lt($cutoff)) {
                @unlink($file);
                $removed[] = $file;
            }
        }

        return $removed;
    }

    /**
     * সবচেয়ে নতুন ডাম্পটা।
     */
    public function latest(): ?string
    {
        $files = $this->all();

        return $files === [] ? null : $files[array_key_last($files)];
    }

    /**
     * সব ডাম্প, পুরনো থেকে নতুন।
     *
     * ── ⛔ এই ক্রমটা নামের ছিল, সময়ের নয় — আর লাইভে ক্ষতি করেছে ────────
     *
     * আগে এখানে ছিল শুধু `sort($files)`, অর্থাৎ **বর্ণানুক্রম**। ⓘ যতক্ষণ
     * প্রতিটা নাম `abos-2026-09-08-235307.sql.gz` ছাঁচের, ততক্ষণ
     * বর্ণানুক্রম আর সময়ের ক্রম মিলে যায় — তাই ভুলটা অদৃশ্য ছিল।
     *
     * ⛔ ৭ সেপ্টেম্বর ২০২৬-এ একটা ডাম্প রাখা হয় `abos-BEFORE-WIPE-...`
     * নামে। ⚠️ ASCII-তে অঙ্ক (`2` = 0x32) আসে বড় হাতের অক্ষরের
     * (`B` = 0x42) **আগে** — তাই ওই একটা ফাইল সেদিন থেকে চিরকালের জন্য
     * "সবচেয়ে নতুন" হয়ে বসে ছিল।
     *
     * ── ⚠️ এর দাম ─────────────────────────────────────────────────────
     * `deploy.sh` ব্যর্থ মাইগ্রেশনের পর `abos:restore` ডাকে **কোনো নাম না
     * দিয়ে**, অর্থাৎ এই তালিকার শেষটা। ⛔ ৮ সেপ্টেম্বর রাতে সেটা লাইভকে
     * **একদিন পুরনো** অবস্থায় ফিরিয়ে দিয়েছে — আর যে ব্যবস্থাটা বাঁচানোর
     * জন্য, সেটাই ক্ষতি করেছে।
     *
     * ⭐ তাই ক্রমটা এখন ফাইলের **সময়** থেকে আসে। নামটা যা-ই হোক —
     * `BEFORE-WIPE`, `preteams`, বা মানুষের হাতে লেখা যেকোনো কিছু —
     * "সবচেয়ে নতুন" বলতে সত্যিই সবচেয়ে নতুনটাই বোঝায়।
     *
     * ⓘ সমান সময় হলে নামের ক্রম, যাতে ফলটা এলোমেলো না হয়।
     *
     * @return list<string>
     */
    public function all(): array
    {
        $directory = $this->directory();

        $files = glob($directory.DIRECTORY_SEPARATOR.'abos-*.sql.gz') ?: [];

        usort($files, function (string $a, string $b): int {
            $byTime = (filemtime($a) ?: 0) <=> (filemtime($b) ?: 0);

            return $byTime !== 0 ? $byTime : strcmp($a, $b);
        });

        return array_values($files);
    }

    /**
     * দ্বিতীয় গন্তব্যে শেষ কবে কিছু পৌঁছেছিল — স্থানীয় একটা কাগজে।
     *
     * ── কেন এই কাগজটা লাগে ──────────────────────────────────────────
     * পাহারাটা (`StatusNotices`) জিজ্ঞেস করে "মিররে টাটকা কিছু আছে
     * কি?", আর সহজ উত্তর হত ফোল্ডারটা দেখে নেওয়া। কিন্তু মিরর হতে
     * পারে একটা নেটওয়ার্ক ড্রাইভ, আর মাউন্ট না থাকলে `is_dir()`
     * কয়েক সেকেন্ড ঝুলে থাকে।
     *
     * ওটা বসে **প্রতিটা পাতার ফুটারে**। অর্থাৎ ড্রাইভটা একদিন উধাও
     * হলে গোটা ERP ধীর হয়ে যেত, আর কারণটা কেউ খুঁজে পেত না — কারণ
     * ভুল কিছু ঘটছে না, কেবল অপেক্ষা।
     *
     * তাই দূরের পথটা ছোঁয়া হয় কেবল রাতে, ব্যাকআপ নেওয়ার সময় — আর
     * ফলটা এখানে লেখা থাকে। ওয়েব অনুরোধ কেবল এই ছোট ফাইলটা পড়ে,
     * যেটা সবসময় নিজের ডিস্কে।
     */
    private function rememberMirror(?string $target, Carbon $at): void
    {
        if ($target === null) {
            return;
        }

        @file_put_contents($this->mirrorLedger(), (string) json_encode([
            'at' => $at->toIso8601String(),
            'target' => $target,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * শেষ সফল কপিটা কখন হয়েছিল — জানা না থাকলে null।
     */
    public function mirroredAt(): ?Carbon
    {
        $ledger = $this->mirrorLedger();

        if (! is_file($ledger)) {
            return null;
        }

        $said = json_decode((string) @file_get_contents($ledger), true);

        if (! is_array($said) || ! isset($said['at'])) {
            return null;
        }

        try {
            return Carbon::parse((string) $said['at']);
        } catch (\Throwable) {
            return null;
        }
    }

    private function mirrorLedger(): string
    {
        return storage_path('app'.DIRECTORY_SEPARATOR.'backup-mirror.json');
    }

    /**
     * দ্বিতীয় গন্তব্যটা কোথায় — বসানো না থাকলে null।
     *
     * ── কেন এটা বাইরে থেকে জিজ্ঞেস করা যেতে হয় ─────────────────────
     * `mirror()` private, আর সেটাই ঠিক: কপি করা এই সেবার নিজের কাজ।
     * কিন্তু "দ্বিতীয় গন্তব্য বসানো আছে কি না" প্রশ্নটা কপি করার নয়,
     * **পাহারার** — আর ওই পাহারাটা `StatusNotices` দেয়।
     */
    public function mirrorPath(): ?string
    {
        $mirror = config('abos.backup.mirror');

        return blank($mirror) ? null : (string) $mirror;
    }

    /**
     * দ্বিতীয় গন্তব্যের সবচেয়ে নতুন ডাম্প — নেই বা গন্তব্যই না থাকলে null।
     *
     * ── কেন কেবল "বসানো আছে কি না" যথেষ্ট নয় ───────────────────────
     * গন্তব্যটা বসানো থাকলেও কপি থেমে যেতে পারে — পেনড্রাইভ খুলে
     * নেওয়া হয়েছে, নেটওয়ার্ক ড্রাইভ আর মাউন্ট হয় না, ডিস্ক ভরে গেছে।
     * তিনটাই নীরব: `run()` ব্যতিক্রম ছোঁড়ে, কিন্তু সেটা কেবল ওই
     * রাতের লগে থাকে, আর সকালে কেউ লগ পড়ে না।
     *
     * তাই প্রশ্নটা ফোল্ডার ধরে: **ওখানে টাটকা কিছু আছে কি?**
     */
    public function latestMirror(): ?string
    {
        $mirror = $this->mirrorPath();

        if ($mirror === null || ! is_dir($mirror)) {
            return null;
        }

        $files = glob(rtrim($mirror, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'abos-*.sql.gz') ?: [];

        sort($files);

        return $files === [] ? null : $files[array_key_last($files)];
    }

    private function directory(): string
    {
        $path = (string) config('abos.backup.path');

        if (! is_dir($path) && ! @mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException("ব্যাকআপের ফোল্ডারটা তৈরি করা গেল না: {$path}");
        }

        return realpath($path) ?: $path;
    }

    /**
     * শেলের পথে যাব, নাকি বিশুদ্ধ PHP-র পথে।
     *
     * ── ⛔ কেন প্রশ্নটা `ShellAvailability`-র চেয়ে বড়, ১৫ সেপ্টেম্বর ২০২৬ ──
     * `ShellAvailability::canRunProcesses()` একটা **সত্য** বলে: এই মেশিনে
     * `proc_open` চলে কি না। ⓘ সেটা মিথ্যা বানানোর কোনো উপায় থাকা উচিত
     * নয় — একটা সক্ষমতা-যাচাই মিথ্যা বললে সেটা আর যাচাই নয়।
     *
     * ⭐ কিন্তু "কোন পথে যাব" আলাদা প্রশ্ন, আর ওটার উপর হাত থাকা দরকার।
     * ⚠️ নইলে লাইভের পথটা — যেখানে শেল নেই — এই মেশিনে কোনোদিন চালিয়েই
     * দেখা যেত না, আর "সারানো হয়েছে" কথাটা আবার দাবি হয়ে থাকত, প্রমাণ নয়।
     */
    private function usesShell(): bool
    {
        if (config('abos.backup.force_php') === true) {
            return false;
        }

        return ShellAvailability::canRunProcesses();
    }

    private function dump(string $target): void
    {
        /*
         * ⛔ শেল না থাকলে বিশুদ্ধ PHP পথ — আর সেটাই এখন লাইভের পথ।
         *
         * ── কেন এই শাখাটা লাগল, ১৫ সেপ্টেম্বর ২০২৬ ──────────────────
         * লাইভে মেপে দেখা গেছে `abos:backup` **কোনোদিন চলেনি**:
         * শেয়ার্ড হোস্টিংয়ে `proc_open` বন্ধ, আর `Symfony\Process`
         * ওটা ছাড়া চলে না। ⚠️ আর ছয় দিন সেটা কেউ জানল না।
         *
         * ⓘ নিচের `mysqldump` পথটা রাখা হয়েছে কারণ যেখানে শেল আছে
         * সেখানে ওটা দ্রুত ও বেশি সম্পূর্ণ (রুটিন, ট্রিগার)। কিন্তু
         * পথ বাছার সিদ্ধান্তটা এখন **পরিবেশ দেখে**, আশা দেখে নয়।
         */
        if (! $this->usesShell()) {
            $tables = app(PdoDumper::class)->dump($target.'.sql');

            try {
                $this->compress($target.'.sql', $target);
            } finally {
                @unlink($target.'.sql');
            }

            if ($tables === 0) {
                throw new RuntimeException(__('backup::error.dump_was_empty'));
            }

            return;
        }

        $db = config('database.connections.mysql');

        /*
         * পাসওয়ার্ড কমান্ড লাইনে দেওয়া হয় না।
         *
         * দিলে সেটা প্রসেস তালিকায় দেখা যেত — একই মেশিনের যেকোনো
         * ব্যবহারকারীর কাছে। তাই একটা অস্থায়ী defaults ফাইল, যা কাজ
         * শেষে মুছে যায়।
         */
        $defaults = $this->defaultsFile($db);

        /*
         * ডাম্প আগে, চাপ পরে — `| gzip` দিয়ে নয়।
         *
         * উইন্ডোজে gzip বলে কোনো প্রোগ্রাম নেই, আর পাইপটা cmd চালায়।
         * ফল: অফিসের মেশিনে প্রতিটা রাতের ব্যাকআপ "'gzip' is not
         * recognized" বলে ব্যর্থ হত — অথচ ডাম্পটা ততক্ষণে নেওয়া হয়ে
         * গেছে, শুধু চাপতে গিয়ে হারিয়ে যেত।
         *
         * PHP-র নিজের zlib দুই জায়গাতেই আছে, তাই বাইরের কিছুর উপর আর
         * নির্ভর করতে হয় না।
         */
        $raw = $target.'.sql';

        try {
            $command = sprintf(
                '%s --defaults-extra-file=%s --single-transaction --quick --routines '
                .'--default-character-set=utf8mb4 --result-file=%s %s',
                escapeshellarg((string) config('abos.backup.mysqldump')),
                escapeshellarg($defaults),
                escapeshellarg($raw),
                escapeshellarg((string) $db['database']),
            );

            // --single-transaction: টেবিল লক না করেই সামঞ্জস্যপূর্ণ ডাম্প,
            // তাই ব্যাকআপ চলাকালীন কেউ বিল কাটতে গিয়ে আটকায় না
            $this->shell($command, 'mysqldump চালানো গেল না');

            $this->compress($raw, $target);
        } finally {
            @unlink($raw);
            @unlink($defaults);
        }
    }

    /**
     * একটা ফাইল gzip করা, টুকরো টুকরো করে।
     *
     * পুরোটা মেমরিতে তোলা হয় না — এক বছরের খাতা কয়েকশো মেগাবাইট হয়,
     * আর PHP-র memory_limit ওখানেই থেমে যেত।
     */
    private function compress(string $source, string $target): void
    {
        $in = fopen($source, 'rb');

        if ($in === false) {
            throw new RuntimeException("ডাম্পটা পড়া গেল না: {$source}");
        }

        $out = gzopen($target, 'wb9');

        if ($out === false) {
            fclose($in);
            throw new RuntimeException("ব্যাকআপ ফাইলটা লেখা গেল না: {$target}");
        }

        try {
            while (! feof($in)) {
                $chunk = fread($in, 1024 * 1024);

                if ($chunk === false) {
                    throw new RuntimeException("ডাম্প পড়ার মাঝপথে থেমে গেল: {$source}");
                }

                if ($chunk !== '' && gzwrite($out, $chunk) === false) {
                    throw new RuntimeException("ব্যাকআপ লেখার মাঝপথে থেমে গেল: {$target}");
                }
            }
        } finally {
            fclose($in);
            gzclose($out);
        }
    }

    private function load(string $file, string $database): void
    {
        /*
         * ⓘ ডাম্পের মতোই — শেল না থাকলে PDO দিয়ে ফিরিয়ে আনা।
         *
         * ⚠️ এই শাখাটা না থাকলে যাচাইটা (`verify()`) শেয়ার্ড হোস্টিংয়ে
         * কোনোদিন চলত না, আর তখন "ব্যাকআপ নেওয়া হয়েছে" কথাটা আবার
         * অপ্রমাণিত হয়ে যেত — যে রোগটা সারাতে বসেছি ঠিক সেটাই।
         */
        if (! $this->usesShell()) {
            $raw = $file.'.restore.sql';
            $pdo = DB::connection()->getPdo();

            /*
             * ⛔ যে ডাটাবেজে ছিলাম সেখানে ফিরে যেতেই হবে, ১৫ সেপ্টেম্বর ২০২৬।
             *
             * ⚠️ `USE` সংযোগটাকে সরিয়ে দেয়, আর এটা অ্যাপের **নিজের**
             * সংযোগ। যাচাইয়ের ডাটাবেজটা শেষে ফেলে দেওয়া হয় — তাই ফিরে
             * না গেলে সংযোগটা একটা **মুছে ফেলা** ডাটাবেজের দিকে তাকিয়ে
             * থাকত, আর তারপরের প্রতিটা প্রশ্ন ব্যর্থ হত।
             *
             * ⓘ শেলের পথে এটা ঘটত না (আলাদা প্রসেস), তাই ফাঁকটা এতদিন
             * দেখা যায়নি — লাইভের পথটা চালিয়ে দেখার আগ পর্যন্ত।
             */
            $was = DB::connection()->getDatabaseName();

            try {
                $this->decompress($file, $raw);

                $pdo->exec('USE `'.str_replace('`', '``', $database).'`');

                /*
                 * ⛔ সংযোগটা সত্যিই লক্ষ্যে পৌঁছেছে কি না — ঢালার আগে দেখা।
                 * ⚠️ ডাম্পের প্রথম কাজই `DROP TABLE`; ভুল ডাটাবেজে থাকলে সেটা
                 * আসল খাতার টেবিল ফেলত।
                 */
                $now = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

                if ($now !== $database) {
                    throw new RuntimeException(sprintf(
                        'CRITICAL: ফিরিয়ে আনা থামানো হলো — সংযোগ "%s"-এ, অথচ লক্ষ্য "%s"।',
                        $now,
                        $database,
                    ));
                }

                app(PdoLoader::class)->load($pdo, $raw);
            } finally {
                @unlink($raw);

                $pdo->exec('USE `'.str_replace('`', '``', $was).'`');
            }

            return;
        }

        $db = config('database.connections.mysql');
        $defaults = $this->defaultsFile($db);

        // ফিরিয়ে আনাও একই কারণে দুই ধাপে — gzip নেই, তাই আগে খুলে নেওয়া
        $raw = $file.'.restore.sql';

        try {
            $this->decompress($file, $raw);

            $this->shell(
                sprintf(
                    '%s --defaults-extra-file=%s %s < %s',
                    escapeshellarg((string) config('abos.backup.mysql')),
                    escapeshellarg($defaults),
                    escapeshellarg($database),
                    escapeshellarg($raw),
                ),
                'ডাম্পটা ফিরিয়ে আনা গেল না',
            );
        } finally {
            @unlink($raw);
            @unlink($defaults);
        }
    }

    /** gzip খোলা, একই কারণে টুকরো টুকরো করে। */
    private function decompress(string $source, string $target): void
    {
        $in = gzopen($source, 'rb');

        if ($in === false) {
            throw new RuntimeException("ব্যাকআপ ফাইলটা খোলা গেল না: {$source}");
        }

        $out = fopen($target, 'wb');

        if ($out === false) {
            gzclose($in);
            throw new RuntimeException("খোলা ডাম্পটা লেখা গেল না: {$target}");
        }

        try {
            while (! gzeof($in)) {
                $chunk = gzread($in, 1024 * 1024);

                if ($chunk === false) {
                    throw new RuntimeException("ব্যাকআপ খোলার মাঝপথে থেমে গেল: {$source}");
                }

                if ($chunk !== '' && fwrite($out, $chunk) === false) {
                    throw new RuntimeException("খোলা ডাম্প লেখার মাঝপথে থেমে গেল: {$target}");
                }
            }
        } finally {
            gzclose($in);
            fclose($out);
        }
    }

    /**
     * একটা SQL চালানো।
     *
     * --execute দিয়ে, `echo … | mysql` দিয়ে নয়। উইন্ডোজের cmd
     * escapeshellarg-এর ডাবল কোটগুলো নিজেও ছাপায়, তাই SQL-এর সাথে
     * কোট দুটোও mysql-এ পৌঁছাত আর প্রতিবার সিনট্যাক্স ত্রুটি দিত।
     */
    private function mysql(string $sql): string
    {
        /*
         * ⓘ শেল না থাকলে সরাসরি PDO — ডাটাবেজ বানানো/মোছা ও গোনা,
         * তিনটাই PDO নিজেই পারে।
         *
         * ⚠️ এখানে **একাধিক বিবৃতি** আসে (`DROP …; CREATE …;`), আর
         * `PDO::exec()` সেটা পারে না। তাই `;`-এ ভাগ করা হয় — নিরাপদ,
         * কারণ এই পদ্ধতিতে আসা SQL সবসময় কোডে লেখা, কোনোদিন ব্যবহারকারীর
         * ডেটা নয় (চারটা ডাকার জায়গাই উপরে দেখা যায়)।
         */
        if (! $this->usesShell()) {
            $pdo = DB::connection()->getPdo();
            $last = '';

            foreach (array_filter(array_map('trim', explode(';', $sql))) as $one) {
                $result = $pdo->query($one);

                if ($result !== false) {
                    $value = $result->fetchColumn();
                    $last = $value === false ? $last : (string) $value;
                }
            }

            return $last;
        }

        $db = config('database.connections.mysql');
        $defaults = $this->defaultsFile($db);

        try {
            return $this->shell(
                sprintf(
                    '%s --defaults-extra-file=%s --skip-column-names --batch --execute=%s',
                    escapeshellarg((string) config('abos.backup.mysql')),
                    escapeshellarg($defaults),
                    escapeshellarg($sql),
                ),
                'mysql চালানো গেল না',
            );
        } finally {
            @unlink($defaults);
        }
    }

    /** @param  array<string, mixed>  $db */
    private function defaultsFile(array $db): string
    {
        $path = tempnam(sys_get_temp_dir(), 'abos-my');

        file_put_contents($path, implode("\n", [
            '[client]',
            'host='.$db['host'],
            'port='.$db['port'],
            'user='.$db['username'],
            'password="'.str_replace('"', '\"', (string) $db['password']).'"',
            '',
        ]));

        @chmod($path, 0600);

        return $path;
    }

    private function shell(string $command, string $failure): string
    {
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(1800);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                $failure.': '.trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }

        return $process->getOutput();
    }

    /**
     * দ্বিতীয় গন্তব্যে কপি।
     *
     * একই ডিস্কে রাখা ব্যাকআপ ডিস্ক ফেল করলে ব্যাকআপও নিয়ে যায় — অর্থাৎ
     * যেই একটা ক্ষেত্রে ব্যাকআপ সবচেয়ে বেশি দরকার, ঠিক সেখানেই সেটা নেই।
     */
    private function mirror(string $file): ?string
    {
        $mirror = config('abos.backup.mirror');

        if (blank($mirror)) {
            return null;
        }

        if (! is_dir($mirror) && ! @mkdir($mirror, 0775, true) && ! is_dir($mirror)) {
            throw new RuntimeException("দ্বিতীয় গন্তব্যটা তৈরি করা গেল না: {$mirror}");
        }

        $target = rtrim((string) $mirror, '/\\').DIRECTORY_SEPARATOR.basename($file);

        if (! @copy($file, $target)) {
            throw new RuntimeException("দ্বিতীয় গন্তব্যে কপি করা গেল না: {$target}");
        }

        return $target;
    }
}
