<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Backup;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Backup\Models\BackupRun;
use App\Modules\Backup\Models\BackupVerification;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ব্যাকআপের চারটা "পরিকল্পিত" পর্দা — নীতি, যাচাই, ফেরানো, দুর্যোগ।
 *
 * ── ⭐ নিরীক্ষার ধাপ ৫.১, ১৯ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * চারটা মেনু-সারি `planned` ছিল — দেখা যেত, খুলত না। ⓘ এখন খোলে, আর
 * চারটাই কেবল দেখার: কেন সম্পাদনা বা বোতাম নেই, [[RecoveryController]]-এ।
 *
 * ⚠️ এখানে যা প্রমাণ হয় তা "পাতা খোলে" নয়, "পাতা সত্যি বলে":
 *   · ব্যর্থ রাতের কারণটা হুবহু, লাল
 *   · একই সার্ভারে থাকা ব্যাকআপকে "সফল" বলা হয় না
 *   · দ্বিতীয় কপি না থাকলে দুর্যোগের পাতায় সেটাই প্রথম কথা
 *   · নীতির পাতায় কোনো ঘর নেই যেটা বদলালে কিছুই বদলায় না
 */
final class TheFourPlannedBackupScreensTellTheTruthTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $this->dir = storage_path('framework/testing/backup-four-screens');
        File::ensureDirectoryExists($this->dir);
        file_put_contents($this->dir.'/abos-2026-09-19-203000.sql.gz', 'checked dump');
        file_put_contents($this->dir.'/abos-2026-09-18-013000.sql.gz', 'unchecked dump');

        config([
            'abos.backup.path' => $this->dir,
            'abos.backup.mirror' => null,
            'abos.backup.daily_at' => '01:30',
        ]);

        $failed = BackupRun::create([
            'company_id' => $company->id,
            'started_at' => '2026-09-19 16:30:00', 'finished_at' => '2026-09-19 16:30:05',
            'status' => 'failed', 'backup_type' => 'full', 'scope' => 'all',
            'error' => "Access denied for user 'univerbd_abos' to database 'univerbd_abos_verify'",
            'triggered_by' => 'schedule',
        ]);
        BackupVerification::create([
            'run_id' => $failed->id, 'kind' => 'test_restore', 'status' => 'failed',
            'detail' => ['error' => 'verify db missing'], 'duration_ms' => 10, 'verified_at' => '2026-09-19 16:30:05',
        ]);

        $good = BackupRun::create([
            'company_id' => $company->id,
            'started_at' => '2026-09-19 20:30:00', 'finished_at' => '2026-09-19 20:46:00',
            'status' => 'local_only', 'backup_type' => 'full', 'scope' => 'all',
            'file' => 'abos-2026-09-19-203000.sql.gz', 'triggered_by' => 'schedule',
        ]);
        BackupVerification::create([
            'run_id' => $good->id, 'kind' => 'test_restore', 'status' => 'passed',
            'detail' => ['tables' => 164], 'duration_ms' => 900, 'verified_at' => '2026-09-19 20:46:00',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_each_screen_opens_and_says_what_is_true(): void
    {
        $this->actingAs($this->owner);

        // ── নীতি — সত্যিকারের মান, আর বদলানোর ঘর নেই ────────────────
        $policy = $this->get(route('backup.policy.index'))->assertOk();
        $policy->assertSee('01:30');
        $policy->assertSee(__('backup::screen.no_second_copy'));
        $this->assertStringNotContainsString('<input', $this->mainOf($policy->getContent()),
            'নীতির পাতায় একটা ঘর বসেছে — রাতের ব্যাকআপ সেটা পড়ে না, তাই বদলালেও কিছু বদলাবে না।');

        // ── যাচাই — ব্যর্থ রাত হুবহু কারণসহ, আর একই সার্ভারের কপি "সফল" নয়
        $verify = $this->get(route('backup.verification.index'))->assertOk();
        // ⓘ পাতা উদ্ধৃতিচিহ্ন HTML-এ এস্কেপ করে (`&#039;`) — assertSee নিজেও তাই খোঁজে
        $verify->assertSee("Access denied for user 'univerbd_abos' to database 'univerbd_abos_verify'");
        $verify->assertSee(__('backup::screen.status_local_only'));
        $verify->assertSee(__('backup::screen.check_passed', ['tables' => 164]));
        $verify->assertSee('data-run-status="failed"', false);

        // ── ফেরানো — যাচাই-করা ফাইল চেনা যায়, কমান্ড হুবহু, বোতাম নেই ─
        $restore = $this->get(route('backup.restore.index'))->assertOk();
        $restore->assertSee('php artisan abos:restore abos-2026-09-19-203000.sql.gz');
        $restore->assertSee(__('backup::screen.checked'));
        $restore->assertSee(__('backup::screen.unchecked'));

        // ── দুর্যোগ — দ্বিতীয় কপি নেই, সেটাই প্রথম কথা ────────────────
        $dr = $this->get(route('backup.dr.index'))->assertOk();
        $dr->assertSee('data-dr-no-mirror', false);
        $dr->assertSee(__('backup::screen.no_mirror_warning'));
    }

    /** ⓘ মেনুতে চারটাই এখন খোলে — আর অনুমতি ছাড়া খোলে না */
    public function test_the_screens_are_shut_without_their_permission(): void
    {
        $plain = User::query()->where('email', '!=', 'owner@abos.test')
            ->get()
            ->first(fn (User $u) => ! $u->can('backup.view') && ! $u->can('backup.restore'));

        $this->assertNotNull($plain, 'ডেমোতে ব্যাকআপের অনুমতিহীন কেউ নেই — পরীক্ষাটা কিছু দেখছে না।');

        $this->actingAs($plain);

        foreach (['backup.policy.index', 'backup.verification.index', 'backup.restore.index', 'backup.dr.index'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
    }

    /** পাতার মূল অংশ — খোলসের খোঁজার ঘর বাদ দিয়ে */
    private function mainOf(string $html): string
    {
        $start = strpos($html, '<main');
        $end = strrpos($html, '</main>');

        return $start !== false && $end !== false ? substr($html, $start, $end - $start) : $html;
    }
}
