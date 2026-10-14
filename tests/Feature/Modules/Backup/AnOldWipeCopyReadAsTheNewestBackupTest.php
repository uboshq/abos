<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Backup;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * পুরনো একটা কপি "সবচেয়ে নতুন ব্যাকআপ" হয়ে বসত — ব্যাকআপ লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ ব্যাকআপ আর ফেরানোর পর্দা ফাইল সাজাত নামে, উল্টো ক্রমে: `abos-BEFORE-WIPE-…` (বড় হাতের অক্ষর) তারিখওয়ালা নামের চেয়ে
 * "বড়", তাই পুরনো কপিটাই তালিকার মাথায়, আর পুরনো-ব্যাকআপের সতর্কতা ও ফেরানোর কমান্ড সেটার দিকেই যেত।
 * ⓘ [[BackupService::all()]] এই কারণেই সময় ধরে সাজায়; পর্দা দুইটা আবার নামে সাজিয়ে সেটা নষ্ট করত।
 */
final class AnOldWipeCopyReadAsTheNewestBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_newest_file_by_time_heads_the_list(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'abos-order-'.uniqid();
        File::ensureDirectoryExists($dir);
        config(['abos.backup.path' => $dir]);

        $old = $dir.DIRECTORY_SEPARATOR.'abos-BEFORE-WIPE-2026-09-01.sql.gz';
        $new = $dir.DIRECTORY_SEPARATOR.'abos-2026-10-14-010000.sql.gz';
        file_put_contents($old, 'old');
        file_put_contents($new, 'new');
        touch($old, strtotime('2026-09-01 10:00:00'));
        touch($new, strtotime('2026-10-14 01:00:00'));

        try {
            $files = $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail())
                ->get(route('backup.index'))->assertOk()->viewData('files');

            $this->assertSame('abos-2026-10-14-010000.sql.gz', $files->first()['name'],
                'পুরনো "BEFORE-WIPE" কপি তালিকার মাথায় — সতর্কতা আর ফেরানোর কমান্ড ভুল ফাইল দেখাত।');
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
