<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\BackupService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * "সবচেয়ে নতুন ব্যাকআপ" নামের ক্রমে খোঁজা হত, সময়ের ক্রমে নয়।
 *
 * ── ⛔ কী ভাঙা ছিল ───────────────────────────────────────────────────
 * `BackupService::all()`-এ ছিল শুধু `sort($files)` — অর্থাৎ **বর্ণানুক্রম**।
 * মন্তব্যে লেখা ছিল *"সব ডাম্প, পুরনো থেকে নতুন"*, আর কথাটা বছরখানেক
 * সত্যিও ছিল: প্রতিটা নাম `abos-2026-09-08-235307.sql.gz` ছাঁচের হলে
 * বর্ণানুক্রম আর সময়ের ক্রম হুবহু মিলে যায়।
 *
 * ⛔ ৭ সেপ্টেম্বর ২০২৬-এ একটা ডাম্প রাখা হয় `abos-BEFORE-WIPE-...` নামে।
 * ⚠️ ASCII-তে অঙ্ক (`2` = 0x32) আসে বড় হাতের অক্ষরের (`B` = 0x42) **আগে**
 * — তাই ওই একটা ফাইল সেদিন থেকে চিরকালের জন্য "সবচেয়ে নতুন" হয়ে বসে।
 *
 * ── ⚠️ এর দাম, আর কেন এটা সবচেয়ে খারাপ জায়গায় ভেঙেছে ────────────────
 * `deploy.sh` ব্যর্থ মাইগ্রেশনের পর `abos:restore` ডাকত কোনো নাম না দিয়ে।
 * ⛔ ৮ সেপ্টেম্বর রাতে সেটা লাইভকে **একদিন পুরনো** অবস্থায় ফিরিয়ে দেয় —
 * ৩ কোম্পানির জায়গায় ৫, ১৮৪ অনুমতির জায়গায় ১৮৮।
 *
 * ⚠️ অর্থাৎ যে ব্যবস্থাটা দুর্ঘটনা থেকে বাঁচানোর জন্য, **সেটাই দুর্ঘটনাটা
 * ঘটিয়েছে** — আর ঘটিয়েছে ঠিক সেই মুহূর্তে যখন তার উপর ভরসা করা ছাড়া
 * উপায় নেই।
 *
 * ── ⭐ কেন পরীক্ষাটা এই আকারে ─────────────────────────────────────────
 * ভুলটা ধরা পড়ে **কেবল তখনই যখন নামের ক্রম আর সময়ের ক্রম আলাদা হয়**।
 * তাই এখানে ইচ্ছে করে এমন নাম রাখা হয়েছে যেগুলো বর্ণানুক্রমে উল্টো বসে।
 *
 * ⓘ পুরনো কোডে এই ফাইলের তিনটা পরীক্ষার তিনটাই লাল হয় — ভেঙে দেখা
 * হয়েছে, সবুজ কিন্তু অন্ধ পাহারা রাখা হয়নি।
 */
class TheRollbackRestoredTheWrongDayTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/backup-order');
        File::ensureDirectoryExists($this->dir);
        File::cleanDirectory($this->dir);

        config()->set('abos.backup.path', $this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /**
     * একটা নকল ডাম্প, নিজের নাম ও নিজের সময় নিয়ে।
     *
     * ⓘ `touch()` ছাড়া সব ফাইলের সময় এক হত, আর তখন পরীক্ষাটা কিছুই
     * প্রমাণ করত না — সময়ের ক্রম বলে কিছু থাকত না।
     */
    private function dump(string $name, string $at): string
    {
        $file = $this->dir.DIRECTORY_SEPARATOR.$name;

        File::put($file, 'x');
        touch($file, strtotime($at));

        return $file;
    }

    public function test_a_hand_named_dump_does_not_become_the_newest_one(): void
    {
        // ⓘ এটাই আসল দুর্ঘটনাটা, হুবহু।
        $this->dump('abos-BEFORE-WIPE-2026-09-07-113126.sql.gz', '2026-09-07 11:31:26');
        $this->dump('abos-2026-09-08-235307.sql.gz', '2026-09-08 23:53:07');

        /*
         * ⓘ তুলনাটা `basename` ধরে, পুরো পথ ধরে নয় — `storage_path()`
         * স্ল্যাশ আর ব্যাকস্ল্যাশ মিশিয়ে দেয়, আর সেই অমিলটা এই
         * পরীক্ষার বিষয় নয়। ⚠️ প্রথমবার পুরো পথ মিলিয়ে লেখায় পরীক্ষাটা
         * ঠিক কোডের উপরেও লাল হয়েছিল।
         */
        $this->assertSame(
            'abos-2026-09-08-235307.sql.gz',
            basename((string) app(BackupService::class)->latest()),
            'হাতে নাম দেওয়া পুরনো একটা ডাম্প "সবচেয়ে নতুন" হয়ে বসেছে — '
            .'অর্থাৎ ব্যর্থ ডিপ্লয় লাইভকে ভুল দিনে ফিরিয়ে দেবে।',
        );
    }

    public function test_the_list_runs_oldest_to_newest_whatever_the_names_are(): void
    {
        /*
         * ⚠️ নামগুলো ইচ্ছে করে বর্ণানুক্রমে উল্টো: z… আগে, a… পরে।
         * ⓘ সময়ের ক্রমে ঠিক উল্টোটাই সত্যি।
         */
        $this->dump('abos-zulu-2026-09-01-000000.sql.gz', '2026-09-01 00:00:00');
        $this->dump('abos-2026-09-05-000000.sql.gz', '2026-09-05 00:00:00');
        $this->dump('abos-alpha-2026-09-09-000000.sql.gz', '2026-09-09 00:00:00');

        $names = array_map('basename', app(BackupService::class)->all());

        $this->assertSame([
            'abos-zulu-2026-09-01-000000.sql.gz',
            'abos-2026-09-05-000000.sql.gz',
            'abos-alpha-2026-09-09-000000.sql.gz',
        ], $names, 'তালিকাটা পুরনো থেকে নতুন নয় — মন্তব্যে যা লেখা, কোড তা করছে না।');
    }

    public function test_two_dumps_made_in_the_same_second_still_have_one_order(): void
    {
        /*
         * ⓘ একই সেকেন্ডে দুইটা ডাম্প হলে সময় দিয়ে আলাদা করা যায় না।
         * ⚠️ তখন ফলটা এলোমেলো হলে "সবচেয়ে নতুন" প্রতিবার বদলাত, আর
         * সেটা এই ভুলটারই আরেকটা রূপ — কেবল ধরা পড়ত আরও দেরিতে।
         */
        $this->dump('abos-2026-09-09-120000-a.sql.gz', '2026-09-09 12:00:00');
        $this->dump('abos-2026-09-09-120000-b.sql.gz', '2026-09-09 12:00:00');

        $service = app(BackupService::class);

        $this->assertSame(
            array_map('basename', $service->all()),
            array_map('basename', $service->all()),
            'একই সময়ের দুইটা ডাম্পে ক্রমটা স্থির নয়।',
        );

        $this->assertSame(
            'abos-2026-09-09-120000-b.sql.gz',
            basename((string) $service->latest()),
            'সমান সময় হলে নামের ক্রমে শেষেরটা আসার কথা।',
        );
    }
}
