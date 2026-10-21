<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\Backup\PdoDumper;
use App\Core\Services\Backup\PdoLoader;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * ⛔ অ্যাপের নিজের ব্যাকআপ (শেল ছাড়া — লাইভের রাতের ড্রাইভ ব্যাকআপ আর abos:backup) নেওয়া যেত, ফেরানো যেত না (ec, ২১ অক্টোবর ২০২৬):
 * `number_series`-এর `branch_key` আর `financial_year_key` VIRTUAL generated ঘর (2026_11_27), [[PdoDumper]] `SELECT *` দিয়ে
 * তাদের মানও INSERT-এ লিখত, আর ফেরানোয় প্রথম নম্বর-সিরিজের সারিতে MySQL থামত (3105)। আগের ব্যাকআপ-পরীক্ষার ডাটাবেসে নম্বর-সিরিজের
 * সারিই ছিল না, তাই ধরা পড়েনি। এখন গণনা-করা ঘর ডাম্পে যায় না; ফেরানোর পরে MySQL নিজেই গোনে।
 */
final class ABackupCouldBeTakenButNotBroughtBackTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_seeded_database_dumps_and_comes_back_with_every_number_series_row(): void
    {
        $this->seed(DemoSeeder::class);
        $series = (int) DB::table('number_series')->count();
        $this->assertGreaterThan(0, $series, 'প্রস্তুতিটাই ভুল — নম্বর-সিরিজ নেই, তাহলে পরীক্ষাটা কিছুই মাপে না');

        $file = tempnam(sys_get_temp_dir(), 'dump-back-').'.sql';
        app(PdoDumper::class)->dump($file);
        $insert = collect(file($file, FILE_IGNORE_NEW_LINES) ?: [])->first(fn (string $l) => str_starts_with($l, 'INSERT INTO `number_series`'));
        $this->assertNotNull($insert, '⛔ ডাম্পে নম্বর-সিরিজের সারি নেই');
        $this->assertStringNotContainsString('`branch_key`', (string) $insert, '⛔ গণনা-করা ঘরের মান ডাম্পে লেখা হলো — ফেরানো থামবে');

        // ⓘ ফেরানো আলাদা সংযোগে — CREATE DATABASE নিজের সংযোগের লেনদেন চুপচাপ কমিট করে, পরীক্ষার লেনদেন ছোঁয়া হয় না
        $c = config('database.connections.'.DB::getDefaultConnection());
        $scratch = DB::connection()->getDatabaseName().'_dumpback';
        $pdo = new PDO('mysql:host='.$c['host'].';port='.$c['port'].';charset=utf8mb4', (string) $c['username'], (string) $c['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("DROP DATABASE IF EXISTS `{$scratch}`");
        $pdo->exec("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        try {
            $pdo->exec("USE `{$scratch}`");
            try {
                app(PdoLoader::class)->load($pdo, $file);
            } catch (\RuntimeException $e) {
                $this->fail('⛔ ডাম্পটা ফেরানো গেল না — ব্যাকআপ আছে, ফেরানো নেই: '.mb_substr($e->getMessage(), 0, 300));
            }

            $this->assertSame($series, (int) $pdo->query('SELECT COUNT(*) FROM number_series')->fetchColumn(), '⛔ ফেরানো ডাটাবেসে নম্বর-সিরিজ কম');
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM number_series WHERE branch_key <> COALESCE(branch_id, 0)')->fetchColumn(),
                '⛔ ফেরানোর পরে গণনা-করা ঘর নিজের সূত্রে নেই');
        } finally {
            $pdo->exec("DROP DATABASE IF EXISTS `{$scratch}`");
            @unlink($file);
        }
    }
}
