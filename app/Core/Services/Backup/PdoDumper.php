<?php

declare(strict_types=1);

namespace App\Core\Services\Backup;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * বাইরের কোনো প্রোগ্রাম ছাড়া ডাটাবেজের ডাম্প — শুধু PDO দিয়ে।
 *
 * ── ⛔ কেন এটা লাগল, ১৫ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * শেয়ার্ড হোস্টিংয়ে `proc_open` বন্ধ, তাই `mysqldump` চালানো যায় না
 * ([[ShellAvailability]]-তে পুরো কারণ)। ⚠️ ফল: **ব্যাকআপ কোনোদিন চলেনি**,
 * আর ছয় দিন সেটা কেউ জানল না।
 *
 * ── ⭐ কেন ফাইলটা কড়াভাবে লাইন-ভিত্তিক ───────────────────────────────
 * প্রতিটা লাইন **একটা সম্পূর্ণ SQL বিবৃতি**, আর লাইনের শেষে `;`।
 *
 * ⓘ এটা রূপের সিদ্ধান্ত নয়, **ফিরিয়ে আনার** সিদ্ধান্ত: তখন লোড করতে
 * কোনো SQL পার্সার লাগে না — লাইন পড়ো, চালাও। ⛔ পার্সার লিখলে ডেটার
 * ভিতরের `;` বা `--` একদিন তাকে বোকা বানাত, আর সেই ভুলটা ধরা পড়ত ঠিক
 * যেদিন ব্যাকআপটা সত্যিই দরকার — অর্থাৎ সবচেয়ে খারাপ দিনে।
 *
 * ⚠️ তাই [[PdoLoader]] আর এই ক্লাসটা **জোড়া**; একটা বদলালে অন্যটাও।
 *
 * ── ⓘ যা এই ডাম্পার ধরে না, আর কেন সেটা আজ নিরাপদ ───────────────────
 * সঞ্চিত রুটিন, ট্রিগার, ইভেন্ট ও ভিউ — কিছুই নয়। মেপে দেখা হয়েছে
 * (১৫ সেপ্টেম্বর ২০২৬): এই স্কিমায় চারটাই **শূন্য**, ১৬২টা টেবিলের
 * একটাও ওগুলো ব্যবহার করে না।
 *
 * ⛔ কেউ ভবিষ্যতে একটা ট্রিগার বসালে এই ডাম্প সেটা নীরবে বাদ দিত। তাই
 * [[assertNothingExoticExists()]] প্রতিবার গুনে দেখে, আর পেলে **থামে** —
 * নীরবে অসম্পূর্ণ ব্যাকআপ নেওয়ার চেয়ে জোরে থামা ভালো।
 */
final class PdoDumper
{
    /** এক দফায় কত সারি — বেশি দিলে স্মৃতি, কম দিলে গতি। */
    private const CHUNK = 500;

    /**
     * ডাম্পটা লিখে ফেলে, আর কত টেবিল লেখা হলো সেটা ফেরত দেয়।
     */
    public function dump(string $target): int
    {
        $pdo = DB::connection()->getPdo();
        $database = (string) DB::connection()->getDatabaseName();

        $this->assertNothingExoticExists($pdo, $database);

        $out = @fopen($target, 'wb');

        if ($out === false) {
            throw new RuntimeException("ডাম্প ফাইলটা লেখা গেল না: {$target}");
        }

        try {
            $this->writeHeader($out, $database);

            $tables = $this->tables($pdo, $database);

            foreach ($tables as $table) {
                $this->writeTable($out, $pdo, $table);
            }

            $this->writeFooter($out);

            return count($tables);
        } finally {
            fclose($out);
        }
    }

    /**
     * ⛔ যা এই ডাম্পার ধরে না, তা যেন নীরবে বাদ না পড়ে।
     *
     * ⚠️ এটাই এই ফাইলের সবচেয়ে দরকারি পদ্ধতি। একটা ট্রিগার বা রুটিন
     * যোগ হওয়ার দিন ব্যাকআপটা **অসম্পূর্ণ হয়ে যাবে**, আর সেটা জানা
     * যেত কেবল পুনরুদ্ধারের দিন।
     */
    private function assertNothingExoticExists(PDO $pdo, string $database): void
    {
        $counts = [
            'ভিউ' => 'SELECT COUNT(*) FROM information_schema.views WHERE table_schema = ?',
            'রুটিন' => 'SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = ?',
            'ট্রিগার' => 'SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = ?',
            'ইভেন্ট' => 'SELECT COUNT(*) FROM information_schema.events WHERE event_schema = ?',
        ];

        $found = [];

        foreach ($counts as $label => $sql) {
            $statement = $pdo->prepare($sql);
            $statement->execute([$database]);
            $n = (int) $statement->fetchColumn();

            if ($n > 0) {
                $found[] = "{$label}: {$n}";
            }
        }

        if ($found !== []) {
            throw new RuntimeException(__('backup::error.dumper_too_simple', [
                'found' => implode(' · ', $found),
            ]));
        }
    }

    /**
     * @return list<string>
     */
    private function tables(PDO $pdo, string $database): array
    {
        $statement = $pdo->prepare(
            'SELECT table_name FROM information_schema.tables '
            .'WHERE table_schema = ? AND table_type = ? ORDER BY table_name'
        );
        $statement->execute([$database, 'BASE TABLE']);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param  resource  $out
     */
    private function writeHeader($out, string $database): void
    {
        /*
         * ⚠️ `FOREIGN_KEY_CHECKS=0` — নাহলে টেবিলের ক্রমই সব ঠিক করত।
         *
         * ⓘ ১৬২টা টেবিলের মধ্যে অনেকগুলো একে অন্যের দিকে তাকায়
         * (`vouchers` → `accounts` → `companies`)। বর্ণানুক্রমে লিখলে
         * সন্তান আগে বসত আর FK ভাঙত। ⛔ ক্রম ঠিক করার চেষ্টা করলে
         * চক্রাকার FK-তে সেটাও ভাঙত, তাই যাচাই বন্ধ রেখে শেষে ফেরানো।
         */
        $this->line($out, 'SET FOREIGN_KEY_CHECKS = 0;');
        $this->line($out, 'SET UNIQUE_CHECKS = 0;');
        $this->line($out, "SET NAMES 'utf8mb4';");
        $this->line($out, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';");
        $this->line($out, '-- ABOS dump of '.$database.' at '.date('c'));
    }

    /**
     * @param  resource  $out
     */
    private function writeFooter($out): void
    {
        $this->line($out, 'SET UNIQUE_CHECKS = 1;');
        $this->line($out, 'SET FOREIGN_KEY_CHECKS = 1;');
    }

    /**
     * @param  resource  $out
     */
    private function writeTable($out, PDO $pdo, string $table): void
    {
        $quoted = '`'.str_replace('`', '``', $table).'`';

        $create = $pdo->query("SHOW CREATE TABLE {$quoted}")->fetch(PDO::FETCH_NUM);

        if ($create === false || ! isset($create[1])) {
            throw new RuntimeException("টেবিলের গড়ন পড়া গেল না: {$table}");
        }

        $this->line($out, "DROP TABLE IF EXISTS {$quoted};");

        /*
         * ⚠️ `SHOW CREATE TABLE` বহু লাইনে আসে, আর আমাদের ফাইল
         * লাইন-ভিত্তিক — তাই নতুন লাইনগুলো ফাঁকা জায়গায় বদলে দেওয়া হয়।
         *
         * ⓘ MySQL এতে কিছু মনে করে না; গড়নটা হুবহু একই থাকে।
         */
        $this->line($out, preg_replace('/\s*\R\s*/', ' ', (string) $create[1]).';');

        $this->writeRows($out, $pdo, $table, $quoted);
    }

    /**
     * @param  resource  $out
     */
    private function writeRows($out, PDO $pdo, string $table, string $quoted): void
    {
        /*
         * ⓘ `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY` ইচ্ছাকৃতভাবে বদলানো হয়
         * না — বড় টেবিল পুরোটা স্মৃতিতে আনলে শেয়ার্ড হোস্টিংয়ের ১২৮MB
         * সীমায় মরত। তাই `LIMIT`/`OFFSET` ধরে ধরে পড়া।
         *
         * ⚠️ OFFSET বড় হলে MySQL ধীর হয়, কিন্তু সারি কম হওয়ায় (সবচেয়ে
         * বড় টেবিলেও হাজারের ঘরে) এটা এখানে সমস্যা নয়। লক্ষ ছাড়ালে
         * প্রাথমিক কী ধরে পড়া লাগবে — তখন এই মন্তব্যটা পড়বেন।
         */
        $offset = 0;

        while (true) {
            $rows = $pdo
                ->query("SELECT * FROM {$quoted} LIMIT ".self::CHUNK.' OFFSET '.$offset)
                ->fetchAll(PDO::FETCH_ASSOC);

            if ($rows === []) {
                return;
            }

            $columns = implode(', ', array_map(
                fn (string $c) => '`'.str_replace('`', '``', $c).'`',
                array_keys($rows[0]),
            ));

            foreach ($rows as $row) {
                $values = implode(', ', array_map(
                    fn ($v) => $this->quote($pdo, $v),
                    array_values($row),
                ));

                $this->line($out, "INSERT INTO {$quoted} ({$columns}) VALUES ({$values});");
            }

            $offset += self::CHUNK;
        }
    }

    /**
     * একটা মান SQL-এ বসানোর মতো করে।
     *
     * ⚠️ `PDO::quote()` ব্যবহার করা হয় — নিজে হাতে escape করলে একদিন
     * একটা বাংলা অক্ষর বা বাইনারি বাইট ফাঁক গলে যেত, আর ডাম্পটা নীরবে
     * ভাঙা হত।
     *
     * ⓘ নতুন লাইন `\n`-এ বদলানো হয়, কারণ ফাইলটা লাইন-ভিত্তিক — একটা
     * সত্যিকারের নতুন লাইন বিবৃতিটাকে দুই ভাগ করে দিত।
     */
    private function quote(PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $quoted = $pdo->quote((string) $value);

        if ($quoted === false) {
            throw new RuntimeException('একটা মান SQL-এ বসানো গেল না।');
        }

        return str_replace(["\r\n", "\n", "\r"], ['\\n', '\\n', '\\n'], $quoted);
    }

    /**
     * @param  resource  $out
     */
    private function line($out, string $sql): void
    {
        if (fwrite($out, $sql."\n") === false) {
            throw new RuntimeException('ডাম্প লেখার মাঝপথে থেমে গেল।');
        }
    }
}
