<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * অ্যাপ ডাটাবেজ ছাড়াই চালু হয় — রিপোর্ট, মেনু, মডিউল নিবন্ধনে কোনো কোয়েরি নেই।
 *
 * ── ⛔ কী ভাঙা ছিল (১০ অক্টোবর ২০২৬) ─────────────────────────────────────
 * CI-র `composer install` শেষে `php artisan package:discover` চলে, তখনো কোনো ডাটাবেজ নেই। একটা রিপোর্ট
 * ([[InventoryControlReports::stockAlerts()]]) সংজ্ঞা লেখার সময়েই `DB::getPdo()` ডাকত, তাই অ্যাপ চালু হতেই
 * ভাঙত: main-এর প্রতিটা CI রান টেস্টের আগেই লাল, কোনো টেস্ট চলেইনি। ⚠️ সার্ভারে `composer install` আর
 * `config:cache`-ও একই পথে যায় — ডাটাবেজ এক মুহূর্ত বন্ধ থাকলে ডিপ্লয়ও আটকাত।
 *
 * ⭐ তাই আলাদা একটা প্রক্রিয়ায় অ্যাপটা চালু করা হয়, এমন একটা ডাটাবেজের ঠিকানা দিয়ে যেটা নেই। ⓘ এই টেস্টের
 * নিজের প্রক্রিয়ায় মাপা যেত না — সেখানে অ্যাপ আগেই চালু, আর আসল ডাটাবেজ জোড়া।
 */
final class TheAppBootsWithoutADatabaseTest extends TestCase
{
    public function test_package_discover_runs_with_no_database_at_all(): void
    {
        $process = $this->artisanWithoutADatabase(['package:discover']);

        $this->assertSame(0, $process->getExitCode(), implode("\n", [
            '⛔ ডাটাবেজ ছাড়া অ্যাপ চালু হয় না — কোনো মডিউল নিবন্ধনের সময়েই কোয়েরি করছে।',
            '',
            'ⓘ সাধারণ দোষী: রিপোর্টের সংজ্ঞায় `query:`-এর বাইরে `DB::getPdo()` বা `DB::table()`।',
            '   ঐ কাজটা closure-এ রাখুন, কোয়েরির ভিতরে ডাকুন।',
            '',
            trim($process->getOutput()."\n".$process->getErrorOutput()),
        ]));
    }

    /**
     * ⓘ পাহারাটা অন্ধ নয়: একই পথে ডাটাবেজ সত্যিই লাগলে প্রক্রিয়াটা ভাঙে।
     */
    public function test_the_guard_would_see_a_database_touched_at_boot(): void
    {
        $process = $this->artisanWithoutADatabase(['tinker', '--execute=Illuminate\Support\Facades\DB::getPdo();']);

        $this->assertNotSame(0, $process->getExitCode(),
            'ডাটাবেজ না থাকা সত্ত্বেও কোয়েরি সফল — প্রক্রিয়াটা আসলে কোনো ডাটাবেজ পাচ্ছে, তাই উপরের দাবি কিছু মাপে না।');
    }

    /**
     * @param  list<string>  $arguments
     */
    private function artisanWithoutADatabase(array $arguments): Process
    {
        $missing = sys_get_temp_dir().'/abos-no-such-database-'.bin2hex(random_bytes(6)).'.sqlite';

        $process = new Process([PHP_BINARY, base_path('artisan'), ...$arguments], base_path(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $missing,
            'DB_URL' => '',
        ]);
        $process->setTimeout(120);
        $process->run();

        $this->assertFileDoesNotExist($missing, 'প্রস্তুতিটাই ভুল — "নেই" ডাটাবেজটা তৈরি হয়ে গেছে।');

        return $process;
    }
}
