<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\Backup\BackupFreshness;
use App\Core\Services\Backup\PdoDumper;
use App\Core\Services\Backup\PdoLoader;
use App\Core\Services\Backup\ShellAvailability;
use App\Core\Services\BackupService;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ব্যাকআপ ছয় দিন ধরে "DONE" বলেছে, অথচ একটাও নেয়নি।
 *
 * ── কী ঘটেছিল, ১৫ সেপ্টেম্বর ২০২৬ ────────────────────────────────────
 * লাইভে মেপে দেখা গেছে `abos:backup` **কোনোদিন চলেনি**: শেয়ার্ড
 * হোস্টিংয়ে `proc_open` বন্ধ, আর `Symfony\Process` ওটা ছাড়া চলে না।
 *
 * ⛔ দুইটা আলাদা রোগ, আর দ্বিতীয়টা বেশি খারাপ:
 *   ১. ব্যাকআপ নেওয়া যেত না      → পরিবেশের সীমা, সারানো যায়
 *   ২. ব্যর্থতাটা কেউ জানত না     → **এটাই ছয় দিন লুকিয়ে রেখেছিল**
 *
 * ── ⭐ তাই এই ফাইলের প্রতিটা দাবি "ঘটনা" মাপে, "দাবি" নয় ─────────────
 * ⚠️ `assertFileExists()` এখানে কিছুই প্রমাণ করে না — একটা ফাইল থাকতে
 * পারে, আকার ঘোষণা করতে পারে, আর ভিতরে এক বাইটও না থাকতে পারে। ⓘ ছয়
 * দিনের ঐ "DONE"-ও একই শ্রেণির: ফল ঘোষিত হয়েছে, কাজটা হয়নি।
 *
 * ⭐ তাই প্রমাণটা একটাই আকারের: **ডাম্প নাও → ফিরিয়ে আনো → গুনে দেখো**।
 */
class TheBackupSaidDoneForSixDaysTest extends TestCase
{
    use RefreshDatabase;

    private function scratch(string $name): string
    {
        $dir = storage_path('framework/testing/backup');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir.DIRECTORY_SEPARATOR.$name;
    }

    /**
     * ⛔ সেটআপের দাবি, আর এটা সবার আগে।
     *
     * ⓘ নিচের দাবিগুলো ধরে নেয় ডাটাবেজে সত্যিই টেবিল ও সারি আছে। না
     * থাকলে "ফিরিয়ে এনে গোনা মিলেছে" কথাটা শূন্যের উপর সত্য হত।
     */
    public function test_the_ground_this_file_stands_on_is_really_there(): void
    {
        $tables = DB::select(
            'SELECT COUNT(*) AS n FROM information_schema.tables '
            .'WHERE table_schema = ? AND table_type = ?',
            [DB::connection()->getDatabaseName(), 'BASE TABLE'],
        );

        $this->assertGreaterThan(50, (int) $tables[0]->n,
            'টেবিলই নেই — তাহলে ডাম্পের দাবিগুলো কিছুই মাপে না।');
    }

    /**
     * ⭐ আসল দাবি: ডাম্প নাও, ফিরিয়ে আনো, আর **সারি গুনে দেখো**।
     *
     * ⚠️ এটাই সেই পরীক্ষা যেটা থাকলে ছয় দিনের নীরবতা প্রথম রাতেই ধরা
     * পড়ত — কারণ এটা কমান্ডের উত্তর শোনে না, ফাইলের ভিতরে তাকায়।
     */
    public function test_a_php_only_dump_can_be_read_back_row_for_row(): void
    {
        /*
         * ⓘ সারিটা এখানেই বসানো, সিডারের উপর ভরসা নয়।
         *
         * ⚠️ `RefreshDatabase` খালি ডাটাবেজ দেয়, আর এই দাবিটার জন্য
         * দরকার **একটা চেনা মান** — যেটা ডাম্পের ভিতরে খুঁজে পাওয়া
         * যাবে। ⛔ সিডারের কোনো সারি ধরে নিলে সিডার বদলানোর দিন এই
         * পরীক্ষাটা ভাঙত, আর কারণটা ব্যাকআপের সাথে সম্পর্কহীন হত।
         */
        $marker = 'ব্যাকআপ-প্রমাণ-'.uniqid();

        $company = Company::query()->create([
            'code' => 'BKP'.random_int(1000, 9999),
            'name_en' => $marker,
            'name_bn' => $marker,
        ]);

        $this->assertSame($marker, (string) $company->fresh()?->name_en,
            'সারিটাই বসেনি — তাহলে নিচের দাবিগুলো কিছুই মাপে না।');

        $file = $this->scratch('dump.sql');
        $tables = app(PdoDumper::class)->dump($file);

        $this->assertGreaterThan(50, $tables, 'ডাম্পে টেবিলই লেখা হয়নি।');

        /*
         * ⛔ এখানেই সেই ফাঁদটা ধরা পড়ে: ফাইলটা থাকা যথেষ্ট নয়।
         *
         * ⓘ চেনা সারিটা ভিতরে সত্যিই আছে কি না সেটাই একমাত্র প্রমাণ যে
         * ডেটা লেখা হয়েছে, কেবল গড়ন নয়।
         */
        $this->assertStringContainsString(
            $marker,
            (string) file_get_contents($file),
            'ডাম্পে টেবিলের গড়ন আছে কিন্তু সারি নেই — এটাই "খালি ব্যাকআপ"।',
        );

        // ── ফিরিয়ে আনা, একটা আলাদা ডাটাবেজে ────────────────────────
        $scratch = DB::connection()->getDatabaseName().'_dumpcheck';
        $pdo = DB::connection()->getPdo();
        $pdo->exec("DROP DATABASE IF EXISTS `{$scratch}`");
        $pdo->exec("CREATE DATABASE `{$scratch}`");

        try {
            $pdo->exec("USE `{$scratch}`");
            $ran = app(PdoLoader::class)->load($pdo, $file);

            $this->assertGreaterThan($tables, $ran,
                'টেবিলের সংখ্যার চেয়ে কম বিবৃতি চলেছে — সারি ফেরেনি।');

            $back = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$scratch}'"
            )->fetchColumn();

            $this->assertSame($tables, (int) $back,
                'ফিরিয়ে আনার পর টেবিলের সংখ্যা মেলেনি।');

            $name = $pdo->query('SELECT name_en FROM companies LIMIT 1')->fetchColumn();

            $this->assertSame($marker, $name,
                'টেবিল ফিরেছে কিন্তু সারির মান বদলে গেছে — ডাম্পটা বিশ্বস্ত নয়।');
        } finally {
            $pdo->exec('USE `'.DB::connection()->getDatabaseName().'`');
            $pdo->exec("DROP DATABASE IF EXISTS `{$scratch}`");
            @unlink($file);
        }
    }

    /**
     * ⛔ যা ডাম্পার ধরে না, তা যেন নীরবে বাদ না পড়ে।
     *
     * ⓘ আজ স্কিমায় ভিউ/রুটিন/ট্রিগার নেই। কেউ একটা বসালে ব্যাকআপটা
     * অসম্পূর্ণ হয়ে যেত — আর অসম্পূর্ণ ব্যাকআপ না থাকার চেয়েও খারাপ,
     * কারণ ওটা মানুষকে নিরাপদ ভাবায়।
     */
    public function test_the_dumper_refuses_rather_than_skipping_what_it_cannot_carry(): void
    {
        DB::statement('CREATE OR REPLACE VIEW abos_dump_probe AS SELECT 1 AS one');

        try {
            $this->expectException(\RuntimeException::class);

            app(PdoDumper::class)->dump($this->scratch('never.sql'));
        } finally {
            DB::statement('DROP VIEW IF EXISTS abos_dump_probe');
        }
    }

    /**
     * ⭐ পাহারাটা **ফাইল দেখে**, কমান্ডের দাবি শোনে না।
     */
    public function test_the_guard_shouts_when_the_newest_backup_is_too_old(): void
    {
        $freshness = app(BackupFreshness::class);

        $this->assertTrue($freshness->isStale(),
            'একটাও ব্যাকআপ নেই, তবু পাহারা চুপ — এটাই ছয় দিনের নীরবতার আকৃতি।');

        $this->assertNotNull($freshness->complaint());
    }

    /**
     * ⓘ পাহারার সীমাটা ঠিক জায়গায় — ৪৭ ঘণ্টা চলে, ৪৯ নয়।
     *
     * ⚠️ সীমানার দুই পাশ দুইটাই দাবি করা হয়, কারণ কেবল একটা করলে
     * একটা সবসময়-সত্য বা সবসময়-মিথ্যা কোডও সবুজ থাকত।
     */
    public function test_the_line_is_where_it_says_it_is(): void
    {
        $dir = storage_path('framework/testing/backup-fresh');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        config(['abos.backup.path' => $dir]);

        $file = $dir.DIRECTORY_SEPARATOR.'abos-probe.sql.gz';
        file_put_contents($file, str_repeat('x', 4096));

        $freshness = app(BackupFreshness::class);

        try {
            touch($file, Carbon::now()->subHours(47)->getTimestamp());
            clearstatcache();
            $this->assertFalse($freshness->isStale(), '৪৭ ঘণ্টায় চিৎকার করার কথা নয়।');

            touch($file, Carbon::now()->subHours(49)->getTimestamp());
            clearstatcache();
            $this->assertTrue($freshness->isStale(), '৪৯ ঘণ্টায় চিৎকার করার কথা।');
        } finally {
            @unlink($file);
        }
    }

    /**
     * ⓘ সক্ষমতা যাচাইটা সৎ — যা আছে তাই বলে।
     */
    public function test_the_shell_check_answers_about_this_machine(): void
    {
        $this->assertSame(
            function_exists('proc_open') && ! in_array(
                'proc_open',
                array_map('trim', explode(',', (string) ini_get('disable_functions'))),
                true,
            ),
            ShellAvailability::canRunProcesses(),
        );
    }

    /**
     * ⭐ পুরো পথটা একসাথে — `BackupService` নিজেই, শেল ছাড়া।
     *
     * ⓘ এটা [[BackupService::run()]] ও [[BackupService::verify()]] দুইটাই
     * চালায়, অর্থাৎ যে পথটা রাতে cron চালাবে ঠিক সেটাই।
     */
    public function test_the_service_takes_and_verifies_a_backup_end_to_end(): void
    {
        config(['abos.backup.path' => storage_path('framework/testing/backup-run')]);

        $backups = app(BackupService::class);
        $result = $backups->run(Carbon::now());

        $this->assertGreaterThan(1024, $result['bytes'],
            'ব্যাকআপ ফাইলটা কার্যত খালি।');

        $check = $backups->verify($result['file']);

        $this->assertGreaterThan(50, $check['tables'],
            'ফিরিয়ে এনে টেবিল পাওয়া যায়নি — ডাম্পটা বিশ্বস্ত নয়।');

        @unlink($result['file']);
    }

    /**
     * ⭐ লাইভ যে পথে হাঁটে ঠিক সেই পথে — শেল ছাড়া, নকল করে।
     *
     * ── ⛔ কেন এই দাবিটা সবচেয়ে জরুরি, ১৫ সেপ্টেম্বর ২০২৬ ───────────────
     * উপরের দাবিগুলো এই মেশিনে **শেলের** পথে চলে, কারণ এখানে `proc_open`
     * খোলা। ⚠️ কিন্তু লাইভে ওটা বন্ধ — অর্থাৎ যে পথটা আসলে ব্যবহার হবে
     * সেটা এতক্ষণ একবারও চলেনি। ⓘ "সারানো হয়েছে" কথাটা তখন আবার একটা
     * দাবি হয়ে থাকত, আর দাবি-বনাম-ঘটনার ফাঁকেই ছয় দিনের নীরবতা ছিল।
     *
     * ── ⭐ পথটা সত্যিই বদলেছে, সেটাও এখানেই প্রমাণ হয় ───────────────────
     * `mysqldump` ও `mysql`-এর নাম দুইটা এমন রাখা হয়েছে যা এই মেশিনে
     * **নেই**। ⓘ তাই কোড ভুল করে শেলের পথে গেলে দাবিটা সবুজ থাকতে পারে
     * না — ব্যর্থ হবেই। ⛔ নইলে `force_php` কাজ না করলেও পরীক্ষাটা পাশ
     * করত, আর সেটা হত আরেকটা নীরব সবুজ।
     */
    public function test_the_road_live_really_walks_without_a_shell(): void
    {
        /*
         * ⓘ নিজের একটা চেনা সারি — আগের পরীক্ষার ফেলে যাওয়া সারির উপর
         * ভরসা নয়। ⛔ আগে এখানে কেবল `Company::count() > 0` দেখা হত, আর
         * এই পরীক্ষা একা চালালে (খালি ডাটাবেজে) সেটা সবসময় ০ দিত —
         * অথচ দেখাত যেন যাচাই আসল খাতা মুছেছে। ⭐ এখন প্রমাণটা সরাসরি:
         * যাচাইয়ের আগে বসানো সারিটা যাচাইয়ের পরেও হুবহু আছে।
         */
        $marker = 'যাচাইয়ে-হাত-পড়েনি-'.uniqid();
        $mine = Company::query()->create(['code' => 'VFY'.random_int(1000, 9999), 'name_en' => $marker]);

        config([
            'abos.backup.path' => storage_path('framework/testing/backup-noshell'),
            'abos.backup.force_php' => true,
            'abos.backup.mysqldump' => 'abos-no-such-binary-'.uniqid(),
            'abos.backup.mysql' => 'abos-no-such-binary-'.uniqid(),
        ]);

        $backups = app(BackupService::class);
        $result = $backups->run(Carbon::now());

        try {
            $this->assertGreaterThan(1024, (int) $result['bytes'],
                'শেল ছাড়া নেওয়া ব্যাকআপটা কার্যত খালি।');

            $check = $backups->verify($result['file']);

            $this->assertGreaterThan(50, (int) $check['tables'],
                'শেল ছাড়া ফিরিয়ে আনা গেল না — লাইভে এটাই ঘটত।');

            /*
             * ⛔ যাচাইয়ের পর সংযোগটা আসল ডাটাবেজেই ফিরেছে কি না।
             *
             * ⚠️ PDO-র পথে `USE` অ্যাপের **নিজের** সংযোগ সরিয়ে দেয়, আর
             * যাচাইয়ের ডাটাবেজটা শেষে মুছে ফেলা হয়। ⓘ ফিরে না এলে
             * এরপরের প্রতিটা প্রশ্ন ব্যর্থ হত — ব্যাকআপ নিতে গিয়ে অ্যাপ
             * বন্ধ হয়ে যেত, যা রোগের চেয়েও খারাপ ওষুধ।
             */
            $this->assertSame(
                DB::connection()->getDatabaseName(),
                (string) DB::connection()->getPdo()->query('SELECT DATABASE()')->fetchColumn(),
                'যাচাইয়ের পর সংযোগটা অন্য ডাটাবেজে পড়ে আছে।',
            );

            $this->assertSame($marker, Company::query()->whereKey($mine->id)->value('name_en'),
                'যাচাইয়ের পর আসল খাতার সারিটা নেই — যাচাই আসল ডাটাবেজে হাত দিয়েছে।');
        } finally {
            @unlink($result['file']);
        }
    }

    /**
     * ⛔ যাচাইয়ের লক্ষ্য আসল খাতা হলে — কিছু ছোঁয়ার আগেই থামে।
     *
     * ⓘ ঠিক সেই অবস্থাটা বানানো হয়: আসল ডাটাবেজের নাম খালি (ভুল বা
     * হারানো সেটিং), তাই লক্ষ্য হত কেবল `_verify` — কোন খাতার, কেউ
     * জানে না। ⭐ CRITICAL, আর আগে বসানো সারিটা অক্ষত।
     */
    public function test_a_verify_aimed_at_the_real_books_stops_before_touching_them(): void
    {
        $marker = 'খাতা-অক্ষত-'.uniqid();
        $mine = Company::query()->create(['code' => 'GRD'.random_int(1000, 9999), 'name_en' => $marker]);

        $file = $this->scratch('guard.sql.gz');
        file_put_contents($file, gzencode('DROP TABLE IF EXISTS `companies`;'));

        $real = (string) config('database.connections.mysql.database');

        try {
            config(['database.connections.mysql.database' => '', 'abos.backup.force_php' => true]);

            try {
                app(BackupService::class)->verify($file);
                $this->fail('আসল ডাটাবেজের নাম খালি, তবু যাচাই থামেনি।');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('CRITICAL', $e->getMessage());
            }
        } finally {
            config(['database.connections.mysql.database' => $real]);
            @unlink($file);
        }

        $this->assertSame($marker, Company::query()->whereKey($mine->id)->value('name_en'),
            'পাহারা থামার আগে আসল খাতার সারি মুছে গেছে।');
    }

    /**
     * ⭐ ব্যর্থ হলে **চিৎকার করে** ব্যর্থ হয় — নীরবে "DONE" নয়।
     *
     * ── ⛔ আজকের আসল রোগটা এটাই ছিল ───────────────────────────────────
     * ব্যাকআপ নেওয়া যাচ্ছিল না, সেটা এক রোগ। ⚠️ কিন্তু ছয় দিন লুকিয়ে
     * রেখেছিল **দ্বিতীয়টা**: ব্যর্থতাটা সফলতার মতো দেখাচ্ছিল।
     *
     * ⓘ তাই এখানে দাবিটা ফাইল নিয়ে নয়, **exit code** নিয়ে — কারণ cron,
     * শিডিউলার আর মানুষ তিনজনেই ঐ সংখ্যাটাই পড়ে।
     */
    public function test_a_backup_that_cannot_be_taken_says_so_out_loud(): void
    {
        /*
         * ⓘ ফোল্ডারের জায়গায় একটা **ফাইল** — তাই ফোল্ডারটা বানানো
         * অসম্ভব, আর অসম্ভবতাটা পরিবেশের, কোডের সাজানো নকল নয়।
         */
        $blocked = storage_path('framework/testing/backup-blocked');

        if (is_dir($blocked)) {
            @rmdir($blocked);
        }

        file_put_contents($blocked, 'আমি ফোল্ডার নই।');

        config([
            'abos.backup.path' => $blocked,
            'abos.backup.force_php' => true,
        ]);

        try {
            $this->artisan('abos:backup')->assertFailed();
        } finally {
            @unlink($blocked);
        }
    }

    /**
     * ⭐ শিডিউলার যে সংখ্যাটা পড়ে, সেই সংখ্যাটা সত্যি বলে কি না।
     *
     * ── ⛔ এখানে আমি একটা ভুল লিখেছিলাম, আর এটা তার সংশোধন ────────────
     * কয়েকটা ফাইলে আমি লিখেছিলাম *"শিডিউলার exit code দেখে `DONE`
     * লাইনটা লেখে না"*। ⛔ **কথাটা মিথ্যা।**
     * [[ScheduleRunCommand::runEvent()]] শেষ করে ঠিক এইভাবে:
     *
     *     return $event->exitCode == 0;
     *
     * ⓘ ঐ উত্তরটাই `DONE` না `FAIL` ঠিক করে। অর্থাৎ যন্ত্রটা সৎ।
     *
     * ── ⚠️ তাহলে ছয় দিন কেউ জানল না কেন ──────────────────────────────
     * কারণ `FAIL` লেখা হচ্ছিল এমন এক ফাইলে **যা কেউ খোলে না**। ⭐ আর
     * না-পড়া সতর্কবার্তা আর না-থাকা সতর্কবার্তার মধ্যে কোনো তফাত নেই।
     *
     * ── ⭐ তাই এই দাবিটা শৃঙ্খলের গিঁটটা মাপে ─────────────────────────
     * `abos:backup` ব্যর্থ হলে `abos:backup-due` সেটা **পাস করে দেয়** কি
     * না — নাকি নিজের সাফল্য ঘোষণা করে বসে। ⛔ ঐ এক জায়গায় কেউ
     * `return self::SUCCESS` লিখে দিলে শিডিউলার চিরকাল `DONE` লিখত, আর
     * ছয় দিনের নীরবতাটা ফিরে আসত — এবার সত্যিই নীরব হয়ে।
     */
    public function test_the_scheduler_is_told_the_truth_about_a_failed_backup(): void
    {
        $blocked = storage_path('framework/testing/backup-blocked-due');

        if (is_dir($blocked)) {
            @rmdir($blocked);
        }

        file_put_contents($blocked, 'আমি ফোল্ডার নই।');

        config([
            'abos.backup.path' => $blocked,
            'abos.backup.force_php' => true,
        ]);

        try {
            /*
             * ⓘ `--force`, কারণ নইলে কমান্ডটা আগে "আজকের ডাম্প আছে কি?"
             * জিজ্ঞেস করত — আর ফোল্ডারই খোলা যাচ্ছে না বলে ঐ প্রশ্নেই
             * থেমে যেত। ⭐ আমরা মাপতে চাই **ব্যাকআপ চেষ্টার** ফলটা।
             */
            $this->artisan('abos:backup-due', ['--force' => true])->assertFailed();
        } finally {
            @unlink($blocked);
        }
    }
}
