<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\BackupService;
use App\Core\Services\StatusNotices;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ব্যাকআপটা একই ডিস্কে পড়ে ছিল, আর কেউ বলার ছিল না।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * `config/abos.php`-এ দ্বিতীয় গন্তব্যের পাশে লেখা ছিল:
 * *"খালি রাখলে সতর্কবার্তা আসে, কাজ থামে না"*।
 *
 * কথাটা সত্যি ছিল না। **সতর্কবার্তাটা কোনোদিন বানানোই হয়নি** —
 * `StatusNotices`-এ `mirror` শব্দটাই ছিল না। মন্তব্যটা একটা পাহারার দাবি
 * করত যা কোথাও নেই।
 *
 * ধরা পড়েছে ২২ আগস্ট ২০২৬, চালু সার্ভারে হাতে দেখে: `ABOS_BACKUP_MIRROR`
 * বসানো নেই, আর Mac mini-তে একটাই ডিস্ক। অর্থাৎ ডাটাবেজ, ABOS-এর ডাম্প
 * আর DMS-এর ঘণ্টাভিত্তিক ডাম্প — তিনটাই একই থালায়। যেই একটা ক্ষেত্রে
 * ব্যাকআপ সবচেয়ে বেশি দরকার (ডিস্ক নষ্ট), ঠিক সেখানেই সেটা নেই।
 *
 * ── এই ফাইলের সবচেয়ে জরুরি পরীক্ষা ──────────────────────────────────
 * প্রথমটা: গন্তব্য বসানো না থাকলে বার্তা **আসতেই হবে**। ওটা তুলে নিলে
 * ব্যবস্থাটা আবার নীরব হয়ে যায়, আর নীরবতাই এই ব্যর্থতার একমাত্র
 * বৈশিষ্ট্য — ডিস্ক নষ্ট হওয়ার দিন পর্যন্ত সব ঠিকই দেখায়।
 */
class TheBackupSatOnOneDiskTest extends TestCase
{
    use RefreshDatabase;

    private string $main;

    private string $mirror;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->main = storage_path('framework/testing/backup-main');
        $this->mirror = storage_path('framework/testing/backup-mirror');

        foreach ([$this->main, $this->mirror] as $dir) {
            File::ensureDirectoryExists($dir);
            File::cleanDirectory($dir);
        }

        config()->set('abos.backup.path', $this->main);
        config()->set('abos.backup.mirror', null);
        $this->forgetLedger();

        // মূল ব্যাকআপটা টাটকা — নাহলে অন্য নোটিশটা এসে ভিড় করত
        $this->dump($this->main);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->main);
        File::deleteDirectory($this->mirror);
        $this->forgetLedger();

        parent::tearDown();
    }

    /**
     * শেষ সফল কপির কাগজটা — যেটা রাতের ব্যাকআপ লেখে।
     *
     * পরীক্ষাটা ফোল্ডার ভরে না, কারণ পাহারাটাও ফোল্ডার দেখে না।
     * দূরের পথ ছোঁয়া হয় কেবল রাতে; ফুটার কেবল এই কাগজটা পড়ে।
     */
    private function ledger(string $when): void
    {
        File::put(storage_path('app/backup-mirror.json'), (string) json_encode([
            'at' => now()->parse($when)->toIso8601String(),
            'target' => $this->mirror,
        ]));
    }

    private function forgetLedger(): void
    {
        File::delete(storage_path('app/backup-mirror.json'));
    }

    /** আজকের তারিখে একটা নকল ডাম্প। */
    private function dump(string $dir, string $when = 'now'): string
    {
        $file = $dir.DIRECTORY_SEPARATOR.'abos-'.now()->format('Y-m-d').'-013041.sql.gz';
        File::put($file, 'x');
        touch($file, strtotime($when));

        return $file;
    }

    /** @return list<string> */
    private function notices(): array
    {
        /*
         * ক্যাশটা ইচ্ছাকৃতভাবে মুছে ফেলা।
         *
         * `StatusNotices::all()` কোম্পানি ও ব্যবহারকারী ধরে ক্যাশ করে।
         * পরীক্ষায় গন্তব্যটা বারবার বদলায়, তাই ক্যাশ না মুছলে দ্বিতীয়
         * দাবিটা প্রথমটার উত্তরই আবার পড়ত — আর পরীক্ষাটা পাশ করত
         * ভুল কারণে।
         */
        Cache::flush();

        return array_map(
            fn (array $n) => (string) $n['text'],
            app(StatusNotices::class)->all(),
        );
    }

    /**
     * গন্তব্য বসানো না থাকলে বার্তা আসে।
     *
     * এটাই এই ফাইলের ভিত্তি। `config/abos.php` এই আচরণটার প্রতিশ্রুতি
     * দিয়ে রেখেছিল বছরখানেক ধরে; কোড দিত না।
     */
    public function test_one_disk_is_said_out_loud(): void
    {
        $this->assertContains(__('core.notice.backup_no_mirror'), $this->notices(),
            'দ্বিতীয় গন্তব্য বসানো নেই, অথচ কেউ কিছু বলছে না — '.
            'ডিস্ক নষ্ট হওয়ার দিন পর্যন্ত সব ঠিকই দেখাবে।');
    }

    /** গন্তব্য বসানো আর সেখানে টাটকা ডাম্প থাকলে চুপ। */
    public function test_a_second_destination_that_works_says_nothing(): void
    {
        config()->set('abos.backup.mirror', $this->mirror);
        $this->ledger('now');

        $said = $this->notices();

        $this->assertNotContains(__('core.notice.backup_no_mirror'), $said);
        $this->assertNotContains(__('core.notice.backup_mirror_stale'), $said);
    }

    /**
     * বসানো আছে, কিন্তু কপি থেমে গেছে — আলাদা বার্তা।
     *
     * ── কেন দুইটা আলাদা বার্তা ──────────────────────────────────────
     * পেনড্রাইভ খুলে নেওয়া, নেটওয়ার্ক ড্রাইভ আর মাউন্ট না হওয়া, ডিস্ক
     * ভরে যাওয়া — তিনটাই নীরব, কারণ ব্যতিক্রমটা কেবল রাতের লগে থাকে।
     *
     * "গন্তব্য বসান" বার্তাটা এখানে দিলে যিনি জানেন গন্তব্য বসানোই আছে
     * তিনি ধরে নিতেন বার্তাটা ভুল, আর পরেরবার থেকে পড়তেনই না।
     */
    public function test_a_destination_that_stopped_receiving_is_a_different_message(): void
    {
        config()->set('abos.backup.mirror', $this->mirror);
        $this->ledger('-5 days');

        $said = $this->notices();

        $this->assertContains(__('core.notice.backup_mirror_stale'), $said);
        $this->assertNotContains(__('core.notice.backup_no_mirror'), $said);
    }

    /**
     * কোনোদিন কপি হয়নি — সেটাও থেমে যাওয়া।
     *
     * গন্তব্যটা বসানো হয়েছে, কিন্তু একটা রাতও পেরোয়নি বা প্রতিটা
     * চেষ্টাই ব্যর্থ হয়েছে। দুইটার ফল এক: ওখানে কিছু নেই।
     */
    public function test_a_destination_that_never_received_counts_as_stopped(): void
    {
        config()->set('abos.backup.mirror', $this->mirror);

        $this->assertContains(__('core.notice.backup_mirror_stale'), $this->notices());
    }

    /**
     * ফুটার দূরের পথটা ছোঁয় না।
     *
     * মিরর একটা নেটওয়ার্ক ড্রাইভ হলে, আর সেটা মাউন্ট না থাকলে,
     * `is_dir()` কয়েক সেকেন্ড ঝুলে থাকে — আর ওটা প্রতিটা পাতায়।
     * তাই এমন একটা পথ বসিয়ে দেখা হয় যা থাকতেই পারে না: পাহারাটা
     * তবু উত্তর দেয়, কারণ সে ফোল্ডার নয়, নিজের ডিস্কের কাগজ পড়ে।
     */
    public function test_the_footer_never_reaches_for_the_far_away_path(): void
    {
        config()->set('abos.backup.mirror', '//no-such-host/no-such-share/abos');

        $said = $this->notices();

        $this->assertContains(__('core.notice.backup_mirror_stale'), $said);
        $this->assertNotContains(__('core.notice.backup_no_mirror'), $said);
    }

    /**
     * সেবাটা নিজেই বলতে পারে গন্তব্য কোথায় আর সেখানে শেষ কী পৌঁছেছে।
     *
     * পাহারাটা `StatusNotices`-এর, কিন্তু উত্তরটা `BackupService`-এর —
     * নাহলে নোটিশের কোড ফোল্ডার হাতড়াত, আর ব্যাকআপের নিয়ম দুই জায়গায়
     * লেখা হত।
     */
    public function test_the_service_can_answer_where_the_second_copy_is(): void
    {
        $backups = app(BackupService::class);

        $this->assertNull($backups->mirrorPath());
        $this->assertNull($backups->latestMirror());

        config()->set('abos.backup.mirror', $this->mirror);

        $this->assertSame($this->mirror, $backups->mirrorPath());

        $this->assertNull($backups->mirroredAt(), 'কপি হওয়ার আগেই কাগজটা কিছু বলছে।');

        $this->ledger('now');

        $this->assertNotNull($backups->mirroredAt());
        $this->assertTrue($backups->mirroredAt()->isToday());
    }
}
