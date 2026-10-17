<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Backup;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Backup\Models\BackupRun;
use App\Modules\Backup\Services\BackupRunner;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ফেরত আনা যায় না এমন ব্যাকআপকে "নেওয়া হয়েছে" বলা হত — ব্যাকআপ লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ [[BackupRunner::runNow()]] যাচাইয়ের ফল কেবল নিজের সারিতে লিখত; রান বলত `success`/`local_only`, পর্দা বলত "নেওয়া হয়েছে",
 * কাউকে জানানো হত না। ⓘ আসল ডাম্প পরীক্ষায় চালানো খুব ভারী আর সেবাটা `final`, তাই দাবি মাপে দুই কব্জা: যাচাই ব্যর্থ হলে
 * `false` ফেরে, আর অবস্থা তখন `failed` — গন্তব্যে কপি পৌঁছালেও।
 */
final class AnUnrestorableBackupWasCalledTakenTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_verification_makes_the_run_failed(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $runner = app(BackupRunner::class);
        $run = BackupRun::create([
            'company_id' => $company->id, 'started_at' => now(), 'status' => 'running',
            'backup_type' => 'full', 'scope' => 'all', 'triggered_by' => 'manual',
        ]);

        $verify = new ReflectionMethod(BackupRunner::class, 'recordVerification');
        $this->assertFalse($verify->invoke($runner, $run, storage_path('no-such-dump.sql.gz')),
            'ফেরত আনা যায়নি এমন ফাইলের যাচাই "পাশ" বলল।');

        $status = new ReflectionMethod(BackupRunner::class, 'statusFor');
        $this->assertSame('failed', $status->invoke($runner, false, ['offsite'], []),
            'যাচাই ব্যর্থ, তবু রান "সফল" — পর্দা বলত "নেওয়া হয়েছে"।');
        $this->assertSame('success', $status->invoke($runner, true, ['offsite'], []), 'ভালো রান আর সফল নয়।');
        $this->assertSame('local_only', $status->invoke($runner, true, [], []));
    }
}
