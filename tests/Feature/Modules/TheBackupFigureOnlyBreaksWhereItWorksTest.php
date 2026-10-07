<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Modules\SystemAdmin\Dashboard\SystemAdminDashboard;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * "শেষ ব্যাকআপ কত দিন আগে" — সংখ্যাটা আসল ফাইল থাকলেও বেরোতে হবে।
 *
 * ── কোন ভুল থেকে এই ফাইলটা এসেছে ─────────────────────────────────────
 * ৩ সেপ্টেম্বর ২০২৬-এ সিস্টেম প্রশাসনের ড্যাশবোর্ডে `lastBackup()` লেখা
 * হলো, আর সে দিন গুনতে `Carbon::diffInDays()` ডাকল। Carbon 3-এ ওটা
 * **float** ফেরত দেয় (`3.0000086…`), অথচ পদ্ধতির ঘোষিত ধরন `?int` —
 * অর্থাৎ TypeError, আর **গোটা পাতা ৫০০**।
 *
 * ⚠️ **কিন্তু কোনো পরীক্ষায় ধরা পড়েনি, আর কারণটাই আসল শিক্ষা।**
 *
 * উন্নয়নের মেশিনে ও টেস্টে `ABOS_BACKUP_PATH` ফাঁকা, তাই ফোল্ডারটাই নেই
 * আর পদ্ধতিটা আগেভাগে `null` ফেরত দিত — **ভাঙা লাইনটা কোনোদিন চলেনি**।
 * লাইভে ৭৩টা ব্যাকআপ ফাইল আছে, তাই ওখানে প্রতিবার চলত।
 *
 * **অর্থাৎ বাগটা কেবল সেই মেশিনেই দেখা দিত যেখানে জিনিসটা কাজ করছে।**
 * ব্যাকআপ যত ভালো চলবে, পাতাটা তত নিশ্চিতভাবে ভাঙত।
 *
 * ধরা পড়েছে একটা ফেলনা ডাটাবেসে সত্যিকারের ইনস্টল করে পর্দাগুলো হেঁটে —
 * কোড পড়ে নয়, আর কোনো সবুজ সুইট দেখে নয়।
 *
 * ── তাই এই পরীক্ষাটা যা করে ──────────────────────────────────────────
 * একটা **আসল ফাইল বানায়**, তারপর ড্যাশবোর্ডটা তৈরি করে। ফাইল ছাড়া
 * পরীক্ষা করলে সে ঠিক ওই শাখাটাই পরীক্ষা করত যেটা কোনোদিন ভাঙে না।
 */
class TheBackupFigureOnlyBreaksWhereItWorksTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/backups-'.uniqid());
        File::ensureDirectoryExists($this->folder);
        config()->set('abos.backup.path', $this->folder);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);

        parent::tearDown();
    }

    public function test_the_figure_comes_out_when_a_backup_file_actually_exists(): void
    {
        /*
         * তিন দিনের পুরনো একটা ফাইল — নামটাও আসলের মতো, কারণ
         * `lastBackup()` `*.sql.gz` ছাড়া কিছু গোনে না।
         */
        $file = $this->folder.'/abos-2026-08-31-013000.sql.gz';
        File::put($file, 'not really a dump, but the clock is what matters');
        touch($file, time() - (3 * 86400));

        $dashboard = SystemAdminDashboard::dashboard();

        $stat = collect($dashboard->stats)->first();

        $this->assertNotNull($stat, 'ড্যাশবোর্ডে একটাও সংখ্যা নেই।');

        /*
         * মানটা "কখনো নয়" হতে পারে না — ফাইলটা তো আছে। এটাই ছিল
         * আসল ভাঙন: ফাইল থাকলেই পাতাটা ৫০০ দিত।
         */
        $this->assertNotSame(
            __('system_admin::dashboard.never'),
            $stat->value,
            'ব্যাকআপ ফাইল আছে, তবু "কখনো নয়" দেখাচ্ছে।',
        );

        $this->assertStringContainsString('3', $stat->value, "তিন দিনের পুরনো ফাইল, তবু সংখ্যাটা: {$stat->value}");
    }

    /**
     * আর ফাইল না থাকলে "কখনো নয়" — এই শাখাটাও যেন টিকে থাকে।
     *
     * উপরেরটা একা রাখলে কেউ `null` শাখাটা মুছে দিলেও সবুজ থাকত।
     */
    public function test_it_still_says_never_when_there_is_no_backup_at_all(): void
    {
        $dashboard = SystemAdminDashboard::dashboard();

        $stat = collect($dashboard->stats)->first();

        $this->assertSame(__('system_admin::dashboard.never'), $stat->value);
    }
}
