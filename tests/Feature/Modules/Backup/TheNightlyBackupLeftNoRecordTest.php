<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Backup;

use App\Models\Company;
use App\Modules\Backup\Models\BackupRun;
use App\Modules\Backup\Models\BackupVerification;
use App\Modules\Backup\Services\BackupRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * রাতের ব্যাকআপ খাতায় কোনো চিহ্ন রাখত না।
 *
 * ── ⛔ কী ঘটত, ১৯ সেপ্টেম্বর ২০২৬-এ ধরা ────────────────────────────────
 * যাচাইয়ের পর্দা বানাতে গিয়ে দেখা গেল দেখানোর মতো কিছুই নেই:
 *   ১. গন্তব্য বসানো না থাকলে রাতের ব্যাকআপ কোনো সারিই লিখত না — আর
 *      লাইভে কোনো গন্তব্য নেই। প্রতিটা রাত ডিস্কে ছিল, খাতায় না।
 *   ২. রাতের যাচাইয়ের ফল কেবল লগে যেত, `bak_verifications`-এ নয়।
 *   ৩. ব্যর্থ রাতের কোনো সারিই হত না — ১৯ তারিখের ১৬:৩০-এর *"Access
 *      denied … to database 'univerbd_abos_verify'"* মালিক জানতে পারতেন
 *      কেবল সার্ভারে ঢুকে লগ পড়ে।
 *
 * ⭐ এখন তিনটাই খাতায় — [[BackupRunner::recordAndCopy()]] আর
 * [[BackupRunner::recordFailure()]]।
 */
final class TheNightlyBackupLeftNoRecordTest extends TestCase
{
    use RefreshDatabase;

    private Company $alpha;

    private Company $beta;

    private string $dump;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = Company::create(['code' => 'NBA', 'name_en' => 'Alpha']);
        $this->beta = Company::create(['code' => 'NBB', 'name_en' => 'Beta']);

        $this->dump = tempnam(sys_get_temp_dir(), 'abos-dump-');
        file_put_contents($this->dump, 'CREATE TABLE t (id int);');
    }

    protected function tearDown(): void
    {
        @unlink($this->dump);

        parent::tearDown();
    }

    private function runs()
    {
        return BackupRun::query()->withoutGlobalScopes();
    }

    /** ⛔ দাগ ১ আর ২ — গন্তব্য ছাড়া রাতও খাতায়, যাচাইয়ের ফলসহ */
    public function test_a_night_with_no_destination_is_still_written_down_with_its_check(): void
    {
        app(BackupRunner::class)->recordAndCopy(
            ['file' => $this->dump, 'bytes' => filesize($this->dump), 'mirrored' => null],
            null,
            ['tables' => 164],
            1200,
        );

        foreach ([$this->alpha, $this->beta] as $company) {
            $run = $this->runs()->where('company_id', $company->id)->first();

            $this->assertNotNull($run, "{$company->code}-এর খাতায় রাতের ব্যাকআপ ওঠেনি — গন্তব্য না থাকলেই বাদ পড়ছে।");

            // ⓘ "ব্যাকআপ আছে, কিন্তু একই সার্ভারে" — ব্যর্থ নয়, সফলও নয়
            $this->assertSame('local_only', $run->status);
            $this->assertSame(basename($this->dump), $run->file);

            $check = BackupVerification::query()->withoutGlobalScopes()->where('run_id', $run->id)->first();

            $this->assertNotNull($check, 'রাতের যাচাইয়ের ফল খাতায় নেই — কেবল লগে গেছে।');
            $this->assertSame('passed', $check->status);
            $this->assertSame(164, $check->detail['tables'] ?? null);
            $this->assertTrue($check->sawSomething());
        }
    }

    /** ⛔ দাগ ৩ — যাচাইয়ে ভাঙা রাত, কারণ হুবহু */
    public function test_a_night_that_failed_its_check_says_why_in_the_books(): void
    {
        $why = "SQLSTATE[HY000] [1044] Access denied for user 'univerbd_abos'@'localhost' to database 'univerbd_abos_verify'";

        app(BackupRunner::class)->recordFailure(
            ['file' => $this->dump, 'bytes' => filesize($this->dump), 'mirrored' => null],
            new RuntimeException($why),
            whileVerifying: true,
        );

        $run = $this->runs()->where('company_id', $this->alpha->id)->firstOrFail();

        $this->assertSame('failed', $run->status);
        $this->assertSame($why, $run->error, 'কারণটা বদলে গেছে বা কাটা পড়েছে — মালিক আসল বার্তাটা দেখবেন না।');
        $this->assertSame(basename($this->dump), $run->file, 'ডাম্পটা তো নেওয়া হয়েছিল — ফাইলের নামটা হারাল।');

        $check = BackupVerification::query()->withoutGlobalScopes()->where('run_id', $run->id)->firstOrFail();

        $this->assertSame('failed', $check->status);
        $this->assertFalse($check->sawSomething());
    }

    /** ⛔ ডাম্পই নেওয়া যায়নি — তবু খাতায়, আর কোনো যাচাইয়ের সারি নেই */
    public function test_a_night_that_could_not_even_dump_is_written_down(): void
    {
        app(BackupRunner::class)->recordFailure(null, new RuntimeException('mysqldump: not found'), whileVerifying: false);

        $run = $this->runs()->where('company_id', $this->beta->id)->firstOrFail();

        $this->assertSame('failed', $run->status);
        $this->assertNull($run->file);
        $this->assertSame('mysqldump: not found', $run->error);
        $this->assertSame(0, BackupVerification::query()->withoutGlobalScopes()->where('run_id', $run->id)->count());
    }

    /**
     * ⭐ আসল পথ ধরে — `abos:backup` কমান্ড, যেটা cron চালায়।
     *
     * ⓘ রানার আলাদা করে ঠিক থাকলেও কমান্ড যদি ওটা না ডাকে, তাহলে লাভ
     * নেই — ঠিক আগের ফাঁকটাই ছিল কমান্ডে।
     *
     * ⚠️ ব্যর্থতাটা বানানো হয় সত্যিকারের পথে: ডাম্প রাখার জায়গাটা একটা
     * **ফাইলের ভিতরে** — সেখানে ফোল্ডার বানানো যায় না, তাই ডাম্প নিতেই
     * ভাঙে। (`BackupService` final, নকল বসানো যায় না — আর সেটাই ভালো।)
     */
    public function test_the_command_the_scheduler_runs_records_a_failed_night(): void
    {
        config(['abos.backup.path' => $this->dump.DIRECTORY_SEPARATOR.'cannot-be-a-folder']);

        $this->artisan('abos:backup')->assertFailed();

        $run = $this->runs()->where('company_id', $this->alpha->id)->first();

        $this->assertNotNull($run, 'ব্যর্থ রাতটা খাতায় ওঠেনি — কমান্ড ব্যর্থতা কেবল পর্দায় লিখছে।');
        $this->assertSame('failed', $run->status);
        $this->assertNotSame('', (string) $run->error, 'ব্যর্থতার কারণটা খাতায় নেই।');
        $this->assertSame('schedule', $run->triggered_by);
    }
}
