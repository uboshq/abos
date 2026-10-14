<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Backup;

use App\Console\Commands\BackupDue;
use App\Core\Services\BackupService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Backup\Models\BackupRun;
use App\Modules\Backup\Models\BackupVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ফেরানো-যায়-না এমন ডাম্প "আজকের ব্যাকআপ" হয়ে সারাদিনের চেষ্টা থামাত — ব্যাকআপ লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ যাচাই ভাঙলেও ফাইলটা ফোল্ডারে থাকে, আর [[BackupDue]] কেবল নামের তারিখ দেখত — ঘণ্টায় ঘণ্টায় বলত "আগেই নেওয়া হয়েছে"।
 * ⓘ দাবি ঘণ্টার প্রশ্নটাই ([[BackupDue::takenOn()]]) ডাকে — আসল ডাম্প না নিয়ে; খাতায় না-থাকা ফাইল আগের মতোই গোনে।
 */
final class AnUnrestorableDumpCountedAsTodaysBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_dump_that_failed_its_restore_check_is_not_todays_backup(): void
    {
        $company = Company::query()->firstOrCreate(['code' => 'BDUE'], ['name_en' => 'Backup Due Co']);
        CompanyContext::set((int) $company->id);

        $dir = storage_path('framework/testing/backups-due-'.getmypid());
        File::deleteDirectory($dir);
        File::makeDirectory($dir, 0775, true);
        config(['abos.backup.path' => $dir]);

        $today = Carbon::today();
        $name = 'abos-'.$today->format('Y-m-d').'-010000.sql.gz';
        File::put($dir.DIRECTORY_SEPARATOR.$name, gzencode('-- broken'));

        $takenOn = new ReflectionMethod(BackupDue::class, 'takenOn');

        try {
            $this->assertTrue($takenOn->invoke(app(BackupDue::class), app(BackupService::class), $today),
                'খাতায় না-থাকা আজকের ফাইলও গোনা হল না — দাবিটা কিছু মাপছে না।');

            $run = BackupRun::create([
                'company_id' => $company->id, 'started_at' => now(), 'finished_at' => now(), 'status' => 'failed',
                'backup_type' => 'full', 'scope' => 'all', 'file' => $name, 'bytes' => 20, 'triggered_by' => 'schedule',
            ]);
            BackupVerification::create([
                'run_id' => $run->id, 'kind' => 'test_restore', 'status' => 'failed',
                'detail' => ['error' => 'x'], 'duration_ms' => 1, 'verified_at' => now(),
            ]);

            $this->assertFalse($takenOn->invoke(app(BackupDue::class), app(BackupService::class), $today),
                'ফেরানো-যায়-না ডাম্পকে "আজকের ব্যাকআপ" ধরা হল — সারাদিন আর চেষ্টা হত না।');
        } finally {
            File::deleteDirectory($dir);
            CompanyContext::clear();
        }
    }
}
