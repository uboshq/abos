<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/**
 * ব্যাকআপ — অলঙ্ঘনীয়, কারণ সার্ভার অফিসের একটা মেশিন।
 *
 * এখানকার আসল পরীক্ষা "ফাইলটা তৈরি হলো কি না" নয়। ওটা সহজ অংশ, আর
 * ওটা পাস করেও ব্যাকআপ অকেজো হতে পারে। আসল প্রশ্ন: **ফাইলটা থেকে
 * সত্যিই ফিরিয়ে আনা যায় কি না।** যে ডাম্প কখনো restore করে দেখা হয়নি
 * সেটা ব্যাকআপ নয়, আশা।
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        // আসল ব্যাকআপ ফোল্ডার ছোঁয়া হয় না — টেস্ট চালালে ওখানকার
        // সত্যিকারের ডাম্পগুলো prune() মুছে ফেলতে পারত
        $this->directory = storage_path('framework/testing/backups');

        File::deleteDirectory($this->directory);
        File::makeDirectory($this->directory, 0775, true);

        config(['abos.backup.path' => $this->directory]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    private function backups(): BackupService
    {
        return app(BackupService::class);
    }

    public function test_a_backup_produces_a_file_that_is_not_empty(): void
    {
        $result = $this->backups()->run(Carbon::parse('2026-08-04 01:30:00'));

        $this->assertFileExists($result['file']);
        $this->assertGreaterThan(0, $result['bytes'], 'খালি ডাম্প মানে ব্যাকআপ নেই।');
        $this->assertStringEndsWith('.sql.gz', $result['file']);
    }

    /**
     * নেওয়া ডাম্পটা সত্যিই ফিরিয়ে আনা যায়।
     *
     * এটাই এই ফাইলের সবচেয়ে গুরুত্বপূর্ণ টেস্ট। verify() ডাম্পটা একটা
     * আলাদা ডাটাবেজে ঢালে, টেবিল গোনে, তারপর সেটা ফেলে দেয় — চলতি
     * ডেটা ছোঁয় না।
     */
    public function test_the_dump_can_actually_be_restored(): void
    {
        $result = $this->backups()->run(Carbon::now());

        $check = $this->backups()->verify($result['file']);

        $this->assertGreaterThan(10, $check['tables'], 'ফিরিয়ে আনার পর টেবিল প্রায় নেই — ডাম্পটা কার্যত খালি।');
    }

    /**
     * যাচাইয়ের ডাটাবেজ পড়ে থাকে না।
     *
     * থাকলে প্রতিটা রাতে একটা করে জমত, আর ছয় মাস পর সার্ভারে ১৮০টা
     * অব্যবহৃত ডাটাবেজ থাকত।
     */
    public function test_verifying_leaves_no_scratch_database_behind(): void
    {
        $result = $this->backups()->run(Carbon::now());
        $check = $this->backups()->verify($result['file']);

        $exists = \DB::selectOne(
            'SELECT COUNT(*) as n FROM information_schema.schemata WHERE schema_name = ?',
            [$check['database']],
        );

        $this->assertSame(0, (int) $exists->n);
    }

    public function test_a_broken_dump_is_reported_not_accepted(): void
    {
        $broken = $this->directory.DIRECTORY_SEPARATOR.'abos-2026-01-01-000000.sql.gz';

        // gzip হিসেবেই অবৈধ — আসল জীবনে এটা হয় অর্ধেক লেখা ফাইল বা
        // ডিস্ক ভরে যাওয়া থেকে
        File::put($broken, 'এটা কোনো gzip ফাইল নয়');

        $this->expectException(RuntimeException::class);

        $this->backups()->verify($broken);
    }

    public function test_a_missing_file_is_reported_rather_than_silently_skipped(): void
    {
        $this->expectException(RuntimeException::class);

        $this->backups()->verify($this->directory.DIRECTORY_SEPARATOR.'নেই.sql.gz');
    }

    /**
     * পুরনো ডাম্প মুছে যায়, নতুনগুলো থাকে।
     *
     * না মুছলে ডিস্ক ভরে যায়, আর ডিস্ক ভরলে নতুন ব্যাকআপ নেওয়াই বন্ধ
     * হয় — অর্থাৎ যত বেশি ব্যাকআপ জমে, ব্যাকআপ থাকার সম্ভাবনা তত কম।
     */
    public function test_old_dumps_are_pruned_and_recent_ones_are_kept(): void
    {
        config(['abos.backup.keep_days' => 7]);

        $old = $this->directory.DIRECTORY_SEPARATOR.'abos-2026-01-01-000000.sql.gz';
        $fresh = $this->directory.DIRECTORY_SEPARATOR.'abos-2026-08-04-000000.sql.gz';

        File::put($old, 'x');
        File::put($fresh, 'x');

        touch($old, Carbon::parse('2026-06-01')->timestamp);
        touch($fresh, Carbon::parse('2026-08-03')->timestamp);

        $removed = $this->backups()->prune(Carbon::parse('2026-08-04'));

        // নাম ধরে মেলানো, পুরো পথ ধরে নয়: উইন্ডোজে storage_path()
        // ফরোয়ার্ড স্ল্যাশ দেয় আর realpath() ব্যাকস্ল্যাশ — দুইটাই একই
        // ফাইল, কিন্তু স্ট্রিং হিসেবে আলাদা
        $this->assertSame([basename($old)], array_map('basename', $removed));
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($fresh);
    }

    public function test_pruning_is_off_when_keep_days_is_zero(): void
    {
        config(['abos.backup.keep_days' => 0]);

        $ancient = $this->directory.DIRECTORY_SEPARATOR.'abos-2020-01-01-000000.sql.gz';
        File::put($ancient, 'x');
        touch($ancient, Carbon::parse('2020-01-01')->timestamp);

        $this->assertSame([], $this->backups()->prune(Carbon::parse('2026-08-04')));
        $this->assertFileExists($ancient);
    }

    public function test_latest_returns_the_newest_dump(): void
    {
        foreach (['abos-2026-08-01-000000.sql.gz', 'abos-2026-08-04-000000.sql.gz'] as $name) {
            File::put($this->directory.DIRECTORY_SEPARATOR.$name, 'x');
        }

        $this->assertStringEndsWith('abos-2026-08-04-000000.sql.gz', (string) $this->backups()->latest());
    }

    public function test_with_no_backups_at_all_latest_is_null(): void
    {
        $this->assertNull($this->backups()->latest());
    }

    /**
     * দ্বিতীয় গন্তব্যে কপি হয়।
     *
     * একই ডিস্কে রাখা ব্যাকআপ ডিস্ক ফেল করলে ব্যাকআপও নিয়ে যায় —
     * অর্থাৎ যেই একটা ক্ষেত্রে ব্যাকআপ সবচেয়ে বেশি দরকার, ঠিক সেখানেই
     * সেটা নেই।
     */
    public function test_the_dump_is_copied_to_the_second_destination(): void
    {
        $mirror = storage_path('framework/testing/backup-mirror');

        File::deleteDirectory($mirror);
        config(['abos.backup.mirror' => $mirror]);

        try {
            $result = $this->backups()->run(Carbon::now());

            $this->assertNotNull($result['mirrored']);
            $this->assertFileExists($result['mirrored']);
            $this->assertSame(
                filesize($result['file']),
                filesize($result['mirrored']),
                'দুই কপির আকার আলাদা — কপিটা সম্পূর্ণ নয়।',
            );
        } finally {
            File::deleteDirectory($mirror);
        }
    }

    public function test_the_command_runs_and_verifies_in_one_go(): void
    {
        $this->artisan('abos:backup')
            ->assertSuccessful();

        $this->assertNotNull($this->backups()->latest());
    }

    /**
     * ব্যর্থ ব্যাকআপ ব্যর্থ exit code দেয়।
     *
     * নীরবে ব্যর্থ হওয়া ব্যাকআপ সবচেয়ে বিপজ্জনক জিনিস: সবাই ভাবে
     * ব্যাকআপ আছে, অথচ নেই। exit code ছাড়া scheduler-এর নজরদারি
     * কিছুই ধরতে পারত না।
     */
    public function test_a_failing_backup_reports_failure_to_the_scheduler(): void
    {
        config(['abos.backup.mysqldump' => 'এই-নামে-কোনো-প্রোগ্রাম-নেই']);

        $this->artisan('abos:backup')->assertFailed();
    }
}
