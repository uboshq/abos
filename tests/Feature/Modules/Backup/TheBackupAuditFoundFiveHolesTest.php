<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Backup;

use App\Core\Services\StatusNotices;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notification as Bell;
use App\Models\User;
use App\Modules\Backup\Models\BackupDestination;
use App\Modules\Backup\Models\BackupRun;
use App\Modules\Backup\Services\BackupRunner;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

/**
 * ব্যাকআপের পাঁচটা ফাঁক — bb-র নিরীক্ষা, ১ অক্টোবর ২০২৬।
 *
 *   ১. ফর্ম `sftp`/`s3` দিত, অথচ চালকও নেই, হোস্ট/চাবির ঘরও নেই — রাতে কপি ব্যর্থ, আর একমাত্র
 *      গন্তব্য হলে রানটা `local_only` বলে কাউকে কিছু বলত না।
 *   ২. গন্তব্যের পথ কেবল অক্ষর মিলিয়ে আটকানো — আপেক্ষিক `public` বা `..` দিয়ে ওয়েবের ফোল্ডারে ঢোকা যেত।
 *   ৩. mysqldump-এর কাঁচা ভুল (সার্ভারের পথসহ) `backup.view`-এর সবার কাছে যেত।
 *   ৪. নামানো আর গন্তব্যের HTTP পথে কোনো দাবি ছিল না।
 *   ৫. লাল "ব্যাকআপ নেই" ফিতা বিক্রয়কর্মীসহ সবার পর্দায়।
 */
final class TheBackupAuditFoundFiveHolesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $watcher;

    private User $salesman;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $people = User::query()->whereKeyNot($this->owner->id)
            ->whereHas('companies', fn ($q) => $q->whereKey($this->company->id))
            ->orderBy('id')->get()
            ->reject(fn (User $u) => $u->can('backup.view'))
            ->values();

        $this->assertGreaterThanOrEqual(2, $people->count(), 'প্রস্তুতিটাই ভুল — ব্যাকআপ না-দেখা দুজন মানুষ নেই।');

        [$this->watcher, $this->salesman] = [$people[0], $people[1]];
        $this->watcher->givePermissionTo('backup.view');
        $this->watcher->forgetCachedPermissions();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'abos-audit-'.uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_only_a_destination_that_has_a_driver_can_be_saved(): void
    {
        $this->actingAs($this->owner);

        foreach (['sftp', 's3'] as $driver) {
            $this->post(route('backup.destination.store'), ['name' => 'x', 'driver' => $driver, 'kind' => 'offsite'])
                ->assertSessionHasErrors('driver');
        }

        $this->assertSame(0, BackupDestination::query()->whereIn('driver', ['sftp', 's3'])->count(), '⛔ চালক নেই এমন গন্তব্য বসে গেল।');
    }

    public function test_a_destination_path_must_really_lie_outside_the_app_and_the_web_root(): void
    {
        $this->actingAs($this->owner);
        $base = str_replace('\\', '/', base_path());

        foreach (['public', 'storage/app/public', $base.'/public', $base.'/storage/backups', '/tmp/..'.(preg_match('#^[A-Za-z]:#', $base) ? substr($base, 2) : $base).'/public'] as $path) {
            $this->post(route('backup.destination.store'), ['name' => 'x', 'driver' => 'local', 'kind' => 'offline', 'path' => $path])
                ->assertSessionHasErrors('path', "⛔ {$path} গ্রহণ হলো — ডাম্প অ্যাপ বা ওয়েবের ফোল্ডারে বসত।");
        }

        $this->post(route('backup.destination.store'), ['name' => 'ঠিক', 'driver' => 'local', 'kind' => 'offline', 'path' => $this->dir])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, BackupDestination::query()->where('driver', 'local')->where('name', 'ঠিক')->count(), 'অ্যাপের বাইরের সাধারণ ফোল্ডারও আটকাল — দাবিটা ভুল কারণে লাল হচ্ছিল।');
    }

    public function test_a_night_whose_every_copy_failed_tells_somebody(): void
    {
        // ⓘ আগের দিনের রেখে যাওয়া সারি — চালক নেই
        BackupDestination::create([
            'company_id' => $this->company->id, 'name' => 'পুরনো s3', 'driver' => 's3', 'kind' => 'offsite',
            'config' => ['bucket' => 'x'], 'is_active' => true,
        ]);

        $fake = tempnam(sys_get_temp_dir(), 'abos-fake-').'.sql.gz';
        file_put_contents($fake, 'not a real dump, but a real file');

        app(BackupRunner::class)->recordAndCopy(['file' => $fake, 'bytes' => filesize($fake), 'mirrored' => null]);
        @unlink($fake);

        $run = BackupRun::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('triggered_by', 'schedule')->latest('id')->firstOrFail();

        $this->assertSame('partial', $run->status, '⛔ একমাত্র গন্তব্যে কপি যায়নি, অথচ রানটা "'.$run->status.'" — চুপচাপ।');
        $this->assertGreaterThan(0, Bell::query()->withoutGlobalScope('company')->where('type', 'backup.failed')->count(), '⛔ কেউ খবর পায়নি।');
    }

    public function test_the_raw_dump_error_reaches_only_whole_database_people(): void
    {
        app(BackupRunner::class)->recordFailure(null, new RuntimeException('mysqldump: ZQSECRET /home/x/.my.cnf'), whileVerifying: true);

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $bell = fn (User $u) => (string) Bell::query()->withoutGlobalScope('company')
            ->where('type', 'backup.failed')->where('user_id', $u->id)->value('body');

        $this->assertStringContainsString('ZQSECRET', $bell($this->owner), 'মালিকের খবরে কারণটাই নেই।');
        $this->assertNotSame('', $bell($this->watcher), '⛔ ব্যাকআপ-দেখার মানুষ ব্যর্থতার খবরই পেলেন না।');
        $this->assertStringNotContainsString('ZQSECRET', $bell($this->watcher), '⛔ কাঁচা mysqldump-এর ভুল ব্যাকআপ-দেখার সাধারণ মানুষের খবরে।');

        $this->actingAs($this->watcher)->get(route('backup.verification.index'))->assertOk()->assertDontSee('ZQSECRET');
        $this->actingAs($this->owner)->get(route('backup.verification.index'))->assertOk()->assertSee('ZQSECRET');
    }

    public function test_the_whole_database_downloads_by_its_own_name_only(): void
    {
        @mkdir($this->dir, 0775, true);
        config(['abos.backup.path' => $this->dir]);
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'abos-zq-test.sql.gz', 'ZQDUMP');

        $this->actingAs($this->owner)->get(route('backup.download', 'abos-zq-test.sql.gz'))->assertOk();
        $this->actingAs($this->owner)->get(route('backup.download', 'nope.sql.gz'))->assertNotFound();
        $this->actingAs($this->watcher)->get(route('backup.download', 'abos-zq-test.sql.gz'))->assertForbidden();
    }

    public function test_the_red_backup_strip_shows_only_to_people_who_look_after_backups(): void
    {
        config(['abos.backup.path' => $this->dir]); // ⓘ ফাঁকা — ব্যাকআপ নেই, ফিতাটা জ্বলার কথা

        $texts = function (User $u): array {
            Cache::flush();
            $this->actingAs($u);

            return array_column(app(StatusNotices::class)->all(), 'text');
        };

        $this->assertContains(__('core.notice.backup_stale'), $texts($this->watcher), 'ব্যাকআপ-দেখার মানুষও ফিতাটা দেখলেন না — দাবিটা কিছুই মাপছে না।');
        $this->assertNotContains(__('core.notice.backup_stale'), $texts($this->salesman), '⛔ বিক্রয়কর্মীর পর্দায় লাল ব্যাকআপ-ফিতা।');
        $this->assertNotContains(__('core.notice.backup_no_mirror'), $texts($this->salesman), '⛔ বিক্রয়কর্মীর পর্দায় "এক ডিস্কে" ফিতা।');
    }
}
