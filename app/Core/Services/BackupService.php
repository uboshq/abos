<?php

declare(strict_types=1);

namespace App\Core\Services;

use Illuminate\Support\Carbon;
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

        return [
            'file' => $file,
            'bytes' => (int) filesize($file),
            'mirrored' => $this->mirror($file),
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

        $scratch = config('database.connections.mysql.database').'_verify';

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
            if (Carbon::createFromTimestamp(filemtime($file))->lt($cutoff)) {
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
     * @return list<string>
     */
    public function all(): array
    {
        $directory = $this->directory();

        $files = glob($directory.DIRECTORY_SEPARATOR.'abos-*.sql.gz') ?: [];

        sort($files);

        return array_values($files);
    }

    private function directory(): string
    {
        $path = (string) config('abos.backup.path');

        if (! is_dir($path) && ! @mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException("ব্যাকআপের ফোল্ডারটা তৈরি করা গেল না: {$path}");
        }

        return realpath($path) ?: $path;
    }

    private function dump(string $target): void
    {
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
