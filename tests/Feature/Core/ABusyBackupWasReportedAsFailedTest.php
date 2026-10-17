<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\Backup\BackupLock;
use App\Models\Company;
use App\Modules\Backup\Models\BackupRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PDO;
use Tests\TestCase;

/**
 * চলতি ব্যাকআপের পাশে ঘণ্টার প্রশ্ন "ব্যাকআপ ব্যর্থ" লিখত — ব্যাকআপ লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ তালা অন্য কারও হাতে থাকলে `abos:backup` ডাম্প না নিয়েই থামে — ঠিক আছে — কিন্তু খাতায় লাল সারি বসাত আর মালিককে
 * সতর্কতা পাঠাত, অথচ ঠিক তখন আরেকটা ব্যাকআপ হচ্ছিল। ⓘ প্রস্থান-কোড ব্যর্থই থাকে (`deploy.sh` এর উপর দাঁড়িয়ে)।
 */
final class ABusyBackupWasReportedAsFailedTest extends TestCase
{
    use RefreshDatabase;

    private ?PDO $other = null;

    public function test_a_backup_that_found_another_running_leaves_no_red_row(): void
    {
        Company::create(['code' => 'BBUSY', 'name_en' => 'Busy Backup Co']);
        $dir = storage_path('framework/testing/backups-busy-'.getmypid());
        File::deleteDirectory($dir);
        File::makeDirectory($dir, 0775, true);
        config(['abos.backup.path' => $dir]);

        $c = config('database.connections.mysql');
        $this->other = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'], $c['database']),
            (string) $c['username'], (string) $c['password']);
        $this->assertSame(1, (int) $this->other->query("SELECT GET_LOCK('".BackupLock::name()."', 0)")->fetchColumn(),
            'পরীক্ষার তালাটাই নেওয়া গেল না — দাবিটা কিছু মাপছে না।');

        try {
            $this->artisan('abos:backup')->assertFailed();

            $this->assertSame(0, BackupRun::query()->withoutGlobalScopes()->where('status', 'failed')->count(),
                'আরেকটা ব্যাকআপ চলার সময় খাতায় "ব্যর্থ" বসল — মালিকের কাছে মিথ্যা সতর্কতা গেল।');
        } finally {
            $this->other->query("SELECT RELEASE_LOCK('".BackupLock::name()."')");
            $this->other = null;
            File::deleteDirectory($dir);
        }
    }
}
