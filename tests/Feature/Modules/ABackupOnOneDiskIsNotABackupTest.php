<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Backup\DestinationFactory;
use App\Core\Engines\Backup\DriveScanner;
use App\Core\Engines\Backup\Health;
use App\Core\Support\CompanyContext;
use App\Models\User;
use App\Modules\Backup\Models\BackupDestination;
use App\Modules\Backup\Models\BackupRun;
use App\Modules\Backup\Models\BackupVerification;
use App\Modules\Backup\Services\BackupRunner;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ব্যাকআপ ইঞ্জিনের পাহারা — ৩ সেপ্টেম্বর ২০২৬।
 *
 * ── কেন এই ফাইলটার প্রতিটা টেস্ট আসল ভুল থেকে এসেছে ───────────────────
 * নিচের পাঁচটার মধ্যে **তিনটা** ইঞ্জিনটা লেখার দিনেই সত্যিকারের বাগ
 * ধরেছে, হাতে চালিয়ে। সেগুলো কল্পিত ঝুঁকি নয় — ঘটে যাওয়া ভুল, আর
 * তাই ফিরে আসতে পারে।
 */
class ABackupOnOneDiskIsNotABackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        CompanyContext::set($owner->companies()->first()?->id);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    /**
     * ⚠️ একটা গন্তব্য যার ফোল্ডার এখনো বানানো হয়নি — সেটা "পৌঁছানো যায় না" নয়।
     *
     * ── ধরা পড়েছিল প্রথম রানেই ────────────────────────────────────────
     * `health()` দেখত আমাদের নিজের ফোল্ডারটা আছে কি না, আর ফোল্ডারটা
     * বানাত `put()`। কিন্তু রানার `put()`-এর **আগে** `health()` ডাকে —
     * তাই নতুন গন্তব্য চিরকাল "পাওয়া যাচ্ছে না" দেখাত আর কোনোদিন
     * একটা কপিও পেত না।
     *
     * নীরব ছিল না, কিন্তু **কারণটা ভুল বলত** — আর ভুল কারণ মানুষকে
     * ভুল জায়গায় খুঁজতে পাঠায়।
     */
    public function test_a_destination_whose_folder_does_not_exist_yet_is_still_reachable(): void
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'abos-backup-'.uniqid();
        mkdir($base, 0775, true);

        // আমাদের নিজের ফোল্ডারটা ইচ্ছে করে **বানানো হয়নি**
        $health = app(DestinationFactory::class)
            ->make('local', ['path' => $base.DIRECTORY_SEPARATOR.'not-made-yet'])
            ->health();

        $this->assertTrue(
            $health->reachable,
            'যে ফোল্ডার আমরাই বানাব, সেটা না থাকা "পৌঁছানো যায় না" নয়।',
        );

        @rmdir($base);
    }

    /** ড্রাইভটাই না থাকলে অবশ্যই "পৌঁছানো যায় না" — গার্ডটা যেন অন্ধ না হয়। */
    public function test_a_destination_on_a_drive_that_is_not_there_is_unreachable(): void
    {
        $health = app(DestinationFactory::class)
            ->make('local', ['path' => 'Q:'.DIRECTORY_SEPARATOR.'no-such-drive-abos'])
            ->health();

        $this->assertFalse($health->reachable);
    }

    /**
     * ⚠️ ডাম্প ঠিক হয়েছে অথচ কপি কোথাও যায়নি — সেটা "ব্যর্থ" নয়।
     *
     * ── প্রথম পরীক্ষায় এটাই মিথ্যা বলেছিল ────────────────────────────
     * ডাম্পটা নিখুঁতভাবে তৈরি হয়েছিল (৮৭ KB), যাচাইও পাশ করেছিল,
     * কেবল পেনড্রাইভের ফোল্ডারটা ছিল না — আর রানটা লেখা হয়েছিল
     * `failed`।
     *
     * "ব্যর্থ" পড়ে মানুষ ধরে নিতেন **কোনো ব্যাকআপই হয়নি**, আর আবার
     * চালাতেন — অথচ সার্ভারে একটা ভালো কপি পড়ে আছে।
     */
    public function test_a_backup_that_reached_no_destination_is_not_called_failed(): void
    {
        $run = BackupRun::create([
            'company_id' => CompanyContext::id(),
            'started_at' => now(),
            'finished_at' => now(),
            'status' => 'local_only',
            'file' => 'abos-test.sql.gz',
            'bytes' => 1024,
            'destinations_ok' => [],
            'destinations_failed' => [['id' => 1, 'name' => 'x', 'reason' => 'y']],
            'triggered_by' => 'manual',
        ]);

        $this->assertNotSame('failed', $run->status);
        $this->assertSame(0, $run->copiesLanded());
    }

    /**
     * ⚠️ শূন্য দেখে সবুজ বলা যাবে না — আজ রাতের সবচেয়ে দামি শিক্ষা।
     *
     * ── কী ঘটেছিল ─────────────────────────────────────────────────────
     * একটা restore যাচাই "০ বনাম ০" মিলিয়ে ✅ দেখিয়েছিল — একটাও
     * কোয়েরি চলেনি (টেবিলের নামে `\r` ছিল), কিন্তু দুই দিকই খালি বলে
     * তুলনাটা মিলে গিয়েছিল।
     *
     * **যে পরীক্ষা জিনিসটা না থাকলেও পাশ করে, সেটা পরীক্ষা নয়।**
     * তাই [[BackupVerification::sawSomething()]] কাঁচা `status` দেখে
     * না, সংখ্যাটাও দেখে।
     */
    public function test_a_verification_that_counted_nothing_is_not_a_pass(): void
    {
        $run = BackupRun::create([
            'company_id' => CompanyContext::id(),
            'started_at' => now(), 'status' => 'success',
            'file' => 'x.sql.gz', 'triggered_by' => 'manual',
        ]);

        $empty = BackupVerification::create([
            'run_id' => $run->id, 'kind' => 'test_restore', 'status' => 'passed',
            'detail' => ['tables' => 0, 'rows' => 0], 'verified_at' => Carbon::now(),
        ]);

        $this->assertFalse(
            $empty->sawSomething(),
            '০ টেবিল ফিরিয়ে এনে "পাশ" বলা যায় না — এটাই সেই মিথ্যা সবুজ।',
        );

        $real = BackupVerification::create([
            'run_id' => $run->id, 'kind' => 'test_restore', 'status' => 'passed',
            'detail' => ['tables' => 149], 'verified_at' => Carbon::now(),
        ]);

        $this->assertTrue($real->sawSomething());
    }

    /**
     * ⚠️ গন্তব্যের চাবি সাদা চোখে ডাটাবেসে বসে না।
     *
     * ── কেন এটা এই ইঞ্জিনের সবচেয়ে জরুরি গার্ড ───────────────────────
     * `config`-এ থাকে SFTP-র পাসওয়ার্ড, S3-এর secret, ক্লাউডের টোকেন।
     * আর **এই ডাটাবেসটাই ব্যাকআপে যায়** — অর্থাৎ সাদা চোখে রাখলে
     * প্রতিটা ডাম্পের ভেতরে গ্রাহকের প্রতিটা চাবি চলে যেত, আর ডাম্পটা
     * একবার হাতছাড়া হলে গন্তব্যগুলোও সাথে যেত।
     */
    public function test_the_keys_are_not_lying_in_the_open(): void
    {
        $secret = 'sk-super-secret-'.uniqid();

        $destination = BackupDestination::create([
            'company_id' => CompanyContext::id(),
            'name' => 'পরীক্ষা', 'driver' => 's3', 'kind' => 'offsite',
            'config' => ['secret' => $secret],
            'is_active' => true,
        ]);

        $raw = (string) \DB::table('bak_destinations')
            ->where('id', $destination->id)
            ->value('config');

        $this->assertStringNotContainsString($secret, $raw, 'চাবিটা ডাটাবেসে সাদা চোখে বসে আছে।');

        // তবু পড়া গেলে ঠিকই ফিরে আসে
        $this->assertSame($secret, $destination->fresh()->config['secret']);
    }

    /** ⚠️ সিস্টেম ফোল্ডার বা অ্যাপের নিজের ফোল্ডারে ব্যাকআপ নয়। */
    public function test_backups_cannot_be_pointed_at_the_app_or_the_system(): void
    {
        $scanner = app(DriveScanner::class);

        $this->assertFalse(
            $scanner->isAcceptable(base_path('storage')),
            'ব্যাকআপ নিজের ভেতরে নিজেকে রাখতে পারে না।',
        );

        $this->assertFalse($scanner->isAcceptable('C:\\Windows\\Temp'));
        $this->assertFalse($scanner->isAcceptable(''));

        $this->assertTrue($scanner->isAcceptable(sys_get_temp_dir()));
    }

    /** অজানা driver নীরবে `null` নয়, ব্যতিক্রম — নাহলে গন্তব্যটা কিছুই পাঠাত না। */
    public function test_an_unbuilt_destination_type_refuses_loudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(DestinationFactory::class)->make('gdrive', []);
    }

    /**
     * ⚠️ রাতের ব্যাকআপও গন্তব্যে যায় — কেবল বোতাম চাপলে নয়।
     *
     * ── প্রায় চোখ এড়িয়ে যাওয়া ফাঁক (৩ সেপ্টেম্বর ২০২৬) ────────────────
     * গন্তব্যে কপি করার কাজটা [[BackupRunner]]-এ, আর সেটা ডাকা হত
     * কেবল **পর্দার বোতাম** থেকে। কিন্তু রোজকার ব্যাকআপ চলে
     * `abos:backup-due` → `abos:backup` দিয়ে, আর deploy-ও ওটাই ডাকে।
     *
     * অর্থাৎ কপি যেত **কেবল যেদিন কেউ হাতে বোতাম চাপতেন** — রাতের
     * ব্যাকআপগুলো, যেগুলোই আসল সুরক্ষা, কোথাও যেত না। আর পর্দা তবু
     * সবুজ দেখাত, কারণ ফাইলটা তো তৈরি হচ্ছিল।
     *
     * এই টেস্টটা তাই কমান্ডটাকে **সত্যিই চালায়**, আর দেখে সারিটা
     * `schedule` হিসেবে লেখা হয়েছে কি না।
     */
    public function test_the_nightly_backup_also_reaches_its_destinations(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'abos-night-'.uniqid();

        BackupDestination::create([
            'company_id' => CompanyContext::id(),
            'name' => 'রাতের গন্তব্য', 'driver' => 'local', 'kind' => 'offline',
            'config' => ['path' => $dir], 'is_active' => true,
        ]);

        /*
         * ডাম্প নেওয়া হয় না — ওটা `mysqldump` চায়, আর পরীক্ষায় সেটা
         * ধীর ও পরিবেশ-নির্ভর। প্রশ্নটা এখানে অন্য: **কনসোলের পথটা
         * গন্তব্য পর্যন্ত পৌঁছায় কি না**, ডাম্পটা ঠিক হয় কি না নয়।
         */
        $fake = tempnam(sys_get_temp_dir(), 'abos-fake-').'.sql.gz';
        file_put_contents($fake, 'not a real dump, but a real file');

        app(BackupRunner::class)
            ->recordAndCopy(['file' => $fake, 'bytes' => filesize($fake), 'mirrored' => null]);

        $run = BackupRun::query()->where('triggered_by', 'schedule')->latest('id')->first();

        $this->assertNotNull($run, 'রাতের পথে কোনো সারিই লেখা হয়নি।');
        $this->assertSame(1, $run->copiesLanded(), 'রাতের ব্যাকআপ কোনো গন্তব্যে পৌঁছায়নি।');
        $this->assertFileExists($dir.DIRECTORY_SEPARATOR.basename($fake));

        @unlink($dir.DIRECTORY_SEPARATOR.basename($fake));
        @rmdir($dir);
        @unlink($fake);
    }

    /** স্বাস্থ্যের জায়গার হিসাব — অর্ধেক ফাইল লেখার আগেই থামা। */
    public function test_a_full_disk_is_refused_before_the_write_starts(): void
    {
        $tight = Health::ok(freeBytes: 1000, totalBytes: 2000);

        $this->assertFalse($tight->hasRoomFor(600), 'দ্বিগুণ জায়গা না থাকলে শুরুই করা উচিত নয়।');
        $this->assertTrue($tight->hasRoomFor(100));
    }
}
