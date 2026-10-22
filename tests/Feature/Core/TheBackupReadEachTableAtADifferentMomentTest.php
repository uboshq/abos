<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\Backup\PdoDumper;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * শেলহীন ব্যাকআপ প্রতিটা টেবিল আলাদা মুহূর্তে পড়ত — ব্যাকআপ লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ [[PdoDumper]] (লাইভে শেল নেই, তাই রাতের, ডিপ্লয়ের আর ফেরানোর আগের সব ব্যাকআপ) কোনো লেনদেন খুলত না, আর `ORDER BY` ছাড়া
 * `LIMIT/OFFSET`-এ পড়ত: ডাম্পের মাঝে বসা বিলের ভাউচার আর খাতার সারি দুই অবস্থায় আসত, আর দফার মাঝে ক্রম বদলালে সারি বাদ পড়ত।
 * ⓘ দাবি চালায় লেনদেনের বাইরে (RefreshDatabase ছাড়া — ওটা নিজেই লেনদেন খোলে, আর তখন ডাম্পার ইচ্ছা করে নতুন লেনদেন খোলে না),
 * আর আসল PDO-র জায়গায় একটা "চর" বসায় যা ডাম্পারের প্রতিটা কথা লিখে রাখে।
 */
final class TheBackupReadEachTableAtADifferentMomentTest extends TestCase
{
    public function test_the_dump_reads_every_table_from_one_snapshot_in_key_order(): void
    {
        $this->artisan('migrate', ['--force' => true]);

        $c = config('database.connections.'.DB::getDefaultConnection());
        $spy = new class('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['database'].';charset=utf8mb4', $c['username'], $c['password']) extends PDO
        {
            /** @var list<string> */
            public array $said = [];

            public function exec(string $statement): int|false
            {
                $this->said[] = $statement;

                return parent::exec($statement);
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                $this->said[] = $query;

                return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
            }
        };

        $original = DB::connection()->getPdo();
        DB::connection()->setPdo($spy);
        $target = tempnam(sys_get_temp_dir(), 'dumpcheck');

        try {
            $this->assertSame(0, DB::transactionLevel(), 'পূর্বশর্ত: দাবিটা লেনদেনের বাইরে চলছে না।');

            app(PdoDumper::class)->dump($target);
        } finally {
            DB::connection()->setPdo($original);
            @unlink($target);
        }

        $said = implode("\n", $spy->said);

        $this->assertStringContainsString('START TRANSACTION WITH CONSISTENT SNAPSHOT', $said, 'ডাম্প এক মুহূর্তের ছবি থেকে পড়েনি।');
        $this->assertSame('ROLLBACK', end($spy->said) ?: '', 'ছবির লেনদেনটা শেষে ছাড়া হয়নি।');
        // ⓘ রাখা ঘরগুলো নাম ধরে (গণনা-করা ঘর বাদ, ABackupCouldBeTakenButNotBroughtBackTest), আর প্রাথমিক কী-র ক্রমে
        $this->assertMatchesRegularExpression('/SELECT `id`, `migration`, `batch` FROM `migrations` ORDER BY `id` LIMIT/', $said, 'সারি প্রাথমিক কী-র ক্রমে পড়া হয়নি।');
    }
}
