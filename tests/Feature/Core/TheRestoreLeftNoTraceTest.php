<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\Backup\BackupLock;
use App\Core\Services\Backup\RestoreRecord;
use App\Core\Services\BackupService;
use App\Models\AuditTrail;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PDO;
use RuntimeException;
use Tests\TestCase;

/**
 * ফেরানো কোনো দাগ রাখত না, আর ব্যাকআপ-যাচাই-ফেরানো একসাথে চলতে পারত — চূড়ান্ত অডিট, ৩০ সেপ্টেম্বর ২০২৬ (⛔১৯)।
 *
 * ⛔ যাচাইয়ের ডাটাবেজের নাম স্থির, আর লাইভে অনুমতিও কেবল ওই নামে — তাই
 * দুইজন একসাথে চাপলে একজনের DROP অন্যজনের টেবিল ফেলত, আর ভুল "পাস" লেখা হত।
 * ⓘ তালাটা ধরে রাখে **অন্য একটা সংযোগ** — ঠিক যেমন অন্য একটা প্রসেস ধরত।
 * একই সেবা, একই ফাইল; কেবল তালাটা কারও হাতে আছে কি না — সেটাই আলাদা।
 */
final class TheRestoreLeftNoTraceTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private ?PDO $other = null;

    protected function setUp(): void
    {
        parent::setUp();

        /* ⓘ নিজের ফোল্ডার — BackupTest-এর ফোল্ডার অন্য সেশনের রানে মুছে যেতে পারে */
        $this->directory = storage_path('framework/testing/backups-restore-trace-'.getmypid());
        File::deleteDirectory($this->directory);
        File::makeDirectory($this->directory, 0775, true);
        config(['abos.backup.path' => $this->directory]);
    }

    protected function tearDown(): void
    {
        $this->other = null;
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_nothing_is_touched_while_another_backup_holds_the_lock(): void
    {
        $service = app(BackupService::class);
        $dump = $service->run(Carbon::parse('2026-09-30 01:00:00'))['file'];
        $scratch = DB::connection()->getDatabaseName().'_verify';

        $this->holdElsewhere();

        foreach ([
            'verify' => fn () => $service->verify($dump),
            'run' => fn () => $service->run(Carbon::parse('2026-09-30 02:00:00')),
            'restore' => fn () => $service->restore($dump),
        ] as $what => $call) {
            try {
                $call();
                $this->fail("{$what}: অন্য কেউ তালা ধরে থাকলেও চলল।");
            } catch (RuntimeException $e) {
                $this->assertSame(__('core.backup_busy'), $e->getMessage(), "{$what}: অন্য কারণে থামল।");
            }
        }

        $this->assertCount(1, $service->all(), 'তালা থাকা অবস্থায় নতুন ডাম্প নেওয়া হয়েছে।');
        $this->assertSame(0, $this->schemaCount($scratch), 'তালা থাকা অবস্থায় যাচাইয়ের ডাটাবেজ তৈরি হয়েছে।');
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('companies'), 'তালা থাকা অবস্থায় খাতাটা মোছা হয়েছে।');

        /* ⓘ একই সেবা, একই ফাইল — তালা ছাড়লেই যাচাই চলে (বেশি বন্ধ করা হয়নি) */
        $this->releaseElsewhere();
        $this->assertGreaterThan(10, $service->verify($dump)['tables']);
        $this->assertSame(0, $this->schemaCount($scratch));
    }

    public function test_the_lock_is_let_go_after_nested_work_and_after_a_failure(): void
    {
        BackupLock::hold(fn () => BackupLock::hold(fn () => null));
        $this->assertSame(1, $this->tryElsewhere(), 'ভেতরের কাজ শেষে তালাটা হাতে রয়ে গেছে।');
        $this->releaseElsewhere();

        try {
            BackupLock::hold(fn () => throw new RuntimeException('মাঝপথে ভাঙা'));
        } catch (RuntimeException) {
        }
        $this->assertSame(1, $this->tryElsewhere(), 'ব্যর্থ কাজের পর তালাটা হাতে রয়ে গেছে — পরের ব্যাকআপ আর চলত না।');
    }

    public function test_the_restore_command_leaves_a_trace_even_when_refused(): void
    {
        $a = Company::create(['code' => 'RTA', 'name_en' => 'Restore Trace A']);
        $b = Company::create(['code' => 'RTB', 'name_en' => 'Restore Trace B']);
        $file = $this->directory.DIRECTORY_SEPARATOR.'abos-2026-09-29-010000.sql.gz';
        File::put($file, gzencode('-- empty'));

        Log::spy();
        $this->holdElsewhere();

        $this->artisan('abos:restore', ['file' => $file, '--force' => true, '--no-safety' => true])->assertFailed();

        foreach ([$a, $b] as $company) {
            $trail = AuditTrail::query()->withoutGlobalScopes()
                ->where('company_id', $company->id)->where('action', RestoreRecord::FAILED)->first();

            $this->assertNotNull($trail, "{$company->code}: ব্যর্থ ফেরানোর দাগ অডিট খাতায় নেই।");
            $this->assertStringContainsString(basename($file), (string) $trail->reason);
        }

        Log::shouldHaveReceived('critical')->withArgs(fn ($message) => $message === 'abos:restore failed')->once();
    }

    public function test_a_finished_restore_is_written_in_every_companys_book(): void
    {
        $a = Company::create(['code' => 'RTC', 'name_en' => 'Restore Trace C']);
        $b = Company::create(['code' => 'RTD', 'name_en' => 'Restore Trace D']);

        $written = app(RestoreRecord::class)->restored('/x/abos-2026-09-28-010000.sql.gz', '/x/abos-2026-09-30-093000.sql.gz');

        $this->assertGreaterThanOrEqual(2, $written);

        foreach ([$a, $b] as $company) {
            $trail = AuditTrail::query()->withoutGlobalScopes()
                ->where('company_id', $company->id)->where('action', RestoreRecord::RESTORED)->firstOrFail();

            $this->assertStringContainsString('abos-2026-09-28-010000.sql.gz', (string) $trail->reason, 'কোন ফাইল থেকে — লেখা নেই।');
            $this->assertStringContainsString('abos-2026-09-30-093000.sql.gz', (string) $trail->reason, 'আগের অবস্থা কোথায় রাখা — লেখা নেই।');
            $this->assertNotSame('db_restored', AuditTrail::actionInWords($trail->action));
        }
    }

    private function holdElsewhere(): void
    {
        $this->assertSame(1, $this->tryElsewhere(), 'পরীক্ষার তালাটাই নেওয়া গেল না।');
    }

    private function tryElsewhere(): int
    {
        $this->other ??= $this->connectAgain();

        return (int) $this->other->query("SELECT GET_LOCK('".BackupLock::name()."', 0)")->fetchColumn();
    }

    private function releaseElsewhere(): void
    {
        $this->other?->query("SELECT RELEASE_LOCK('".BackupLock::name()."')");
    }

    private function connectAgain(): PDO
    {
        $c = config('database.connections.mysql');

        return new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'], $c['database']),
            (string) $c['username'],
            (string) $c['password'],
        );
    }

    private function schemaCount(string $name): int
    {
        return (int) DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.schemata WHERE schema_name = ?', [$name])->n;
    }
}
