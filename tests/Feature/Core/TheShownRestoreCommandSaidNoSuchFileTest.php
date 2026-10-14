<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * পর্দায় দেখানো ফেরানোর কমান্ডটাই "ফাইলটা নেই" বলত — ব্যাকআপ লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ ব্যাকআপ আর ফেরানোর পর্দা `php artisan abos:restore <নাম>` দেখায় — কেবল নাম। [[Restore]] নামটা খুঁজত চলতি ফোল্ডারে,
 * ব্যাকআপ ফোল্ডারে নয়; বিপদের দিনে হুবহু কমান্ড চালিয়ে মানুষ পেতেন "ফাইলটা নেই"।
 * ⓘ ফাইলটা ইচ্ছে করে খালি ডাম্প — যাচাইয়ে আটকায়, তাই খাতা কখনো মোছে না; দাবি কেবল এটুকু: ফাইলটা খুঁজে পেয়ে যাচাই শুরু হল।
 */
final class TheShownRestoreCommandSaidNoSuchFileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bare_file_name_is_found_in_the_backup_folder(): void
    {
        $dir = storage_path('framework/testing/backups-bare-name-'.getmypid());
        File::deleteDirectory($dir);
        File::makeDirectory($dir, 0775, true);
        config(['abos.backup.path' => $dir]);

        $name = 'abos-2026-10-14-010000.sql.gz';
        File::put($dir.DIRECTORY_SEPARATOR.$name, gzencode('-- empty'));

        try {
            $this->artisan('abos:restore', ['file' => $name, '--force' => true, '--no-safety' => true])
                ->doesntExpectOutputToContain('ফাইলটা নেই')
                ->expectsOutputToContain('যাচাই করা হচ্ছে: '.$name)
                ->assertFailed();

            $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('companies'), 'খালি ডাম্পে খাতাটা মুছে গেল।');
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
