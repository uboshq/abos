<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\BackupService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ব্যাকআপ ঘড়ি ধরে নয়, অবস্থা ধরে।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * ব্যাকআপ বাঁধা ছিল একটা ঘড়ির কাঁটায়: `dailyAt('01:30')`। শিডিউলার
 * প্রতি মিনিটে জেগে দেখে "এখন কি ০১:৩০?" — আর কেবল **ওই এক মিনিটেই**
 * হ্যাঁ বলে। মিনিটটা ফসকে গেলে ওই দিন আর কোনো ব্যাকআপ হয় না।
 *
 * ফসকানোর কারণ সাধারণ, আর সবগুলোই নীরব: শিডিউলার ঠিক ৬০.০ সেকেন্ড পরে
 * চলে না (ড্রিফট জমে একদিন পুরো মিনিট লাফায়), মেশিন ঘুমিয়ে থাকলে জেগে
 * ওঠার পর সময় পেরিয়ে গেছে, কিংবা ওই মিনিটে রিবুট।
 *
 * Mac mini-তে ধরা পড়েছে ২০ আগস্ট ২০২৬-এ: ১৫, ১৭, ১৮, ১৯ চলেছে —
 * **১৬ আর ২০ চলেনি**। লগে কোনো ভুল নেই, কারণ ভুল কিছু হয়নি; প্রশ্নটাই
 * কেবল করা হয়নি।
 *
 * ── কেন এই পরীক্ষাটা ────────────────────────────────────────────────
 * এই ধরনের ব্যর্থতার একটাই বৈশিষ্ট্য: **কোনো চিহ্ন থাকে না**। ব্যাকআপ
 * না হওয়া আর ব্যাকআপ নিখুঁত হওয়া — লগে দুইটা দেখতে এক। তাই আচরণটা
 * পরীক্ষায় বেঁধে রাখা ছাড়া উপায় নেই।
 */
class TheNightTheBackupDidNotRunTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/backup-due');
        File::ensureDirectoryExists($this->dir);
        File::cleanDirectory($this->dir);

        config()->set('abos.backup.path', $this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /** ওই তারিখের নামে একটা নকল ডাম্প — সত্যিকারের ডাম্প নেওয়ার দরকার নেই। */
    private function dumpFor(string $date): string
    {
        $file = $this->dir.DIRECTORY_SEPARATOR."abos-{$date}-013041.sql.gz";
        File::put($file, 'x');

        return $file;
    }

    public function test_it_does_nothing_when_todays_backup_is_already_there(): void
    {
        Carbon::setTestNow('2026-08-20 09:00:00');
        $this->dumpFor('2026-08-20');

        $before = count(app(BackupService::class)->all());

        $this->artisan('abos:backup-due')
            ->expectsOutputToContain('আগেই নেওয়া হয়েছে')
            ->assertSuccessful();

        $this->assertCount($before, app(BackupService::class)->all(),
            'আজকের ডাম্প থাকা সত্ত্বেও আরেকটা নেওয়া হয়েছে।');
    }

    /**
     * কালকেরটা থাকলেও আজকেরটা লাগে।
     *
     * এটাই আসল পরীক্ষা: পুরনো একটা ডাম্প থাকা মানে "ব্যাকআপ আছে" নয়।
     * `latest()` দেখে সিদ্ধান্ত নিলে গতকালের ফাইলটা আজকের অভাব ঢেকে
     * দিত — আর ঠিক ওভাবেই ব্যাকআপ ছাড়া দিনগুলো কেটে যায়।
     */
    public function test_yesterdays_backup_does_not_count_as_todays(): void
    {
        Carbon::setTestNow('2026-08-20 09:00:00');
        $this->dumpFor('2026-08-19');

        /*
         * সত্যিকারের ডাম্প নেওয়া হয় না — mysqldump লাগত, আর পরীক্ষার
         * কাজ ওটা নয়। এখানে দেখা হচ্ছে **সিদ্ধান্তটা**: আজকেরটা নেই,
         * তাই `abos:backup` ডাকা হচ্ছে কি না।
         */
        $this->artisan('abos:backup-due')
            ->expectsOutputToContain('পাওয়া যায়নি');
    }

    /** ফোল্ডার একেবারে খালি হলেও একই কথা। */
    public function test_an_empty_folder_means_a_backup_is_due(): void
    {
        Carbon::setTestNow('2026-08-20 09:00:00');

        $this->artisan('abos:backup-due')
            ->expectsOutputToContain('পাওয়া যায়নি');
    }

    /**
     * সিদ্ধান্তটা ফাইলের নামে, ফাইলের সময়ে নয়।
     *
     * `filemtime()` বদলে যায় — কপি করলে, ডিস্ক থেকে ডিস্কে সরালে, বা
     * কোনো ব্যাকআপ-টুল ছুঁয়ে গেলে। নামটা বদলায় না, আর নামেই তারিখ
     * লেখা আছে।
     *
     * এখানে আজকের নামের ফাইলটার সময় দুই বছর আগে বসিয়ে দেওয়া হলো;
     * তবু ওটাকে আজকের ব্যাকআপ হিসেবেই গোনা হওয়ার কথা।
     */
    public function test_the_file_name_decides_not_the_file_time(): void
    {
        Carbon::setTestNow('2026-08-20 09:00:00');
        $file = $this->dumpFor('2026-08-20');
        touch($file, Carbon::parse('2024-01-01')->timestamp);

        $this->artisan('abos:backup-due')
            ->expectsOutputToContain('আগেই নেওয়া হয়েছে')
            ->assertSuccessful();
    }

    /**
     * সময়সূচিটা ঘণ্টায় ঘণ্টায়, দিনে একবার নয়।
     *
     * এটাই পুরো সংশোধনের কেন্দ্র। `dailyAt` ফিরে এলে উপরের সব পরীক্ষা
     * পাশ করত — কমান্ডটা ঠিকই আছে — অথচ সে দিনে একবারের বেশি ডাকা
     * হত না, আর ফসকানো মিনিটের সমস্যাটা হুবহু ফিরে আসত।
     */
    public function test_the_schedule_asks_again_every_hour(): void
    {
        $events = collect(app(Schedule::class)->events());

        $backup = $events->first(
            fn ($e) => str_contains((string) $e->command, 'abos:backup-due'),
        );

        $this->assertNotNull($backup, 'abos:backup-due সময়সূচিতে নেই।');

        // প্রতি ঘণ্টার নির্দিষ্ট মিনিটে — "৩০ * * * *"
        $this->assertMatchesRegularExpression('/^\d+ \* \* \* \*$/', $backup->expression,
            "ব্যাকআপ দিনে একবার জিজ্ঞেস করছে ({$backup->expression}) — একটা মিনিট ফসকালেই ওই দিন আর হবে না।");
    }
}
