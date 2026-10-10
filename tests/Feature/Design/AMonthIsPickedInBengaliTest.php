<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ⭐ মাস বাছাই বাংলায় — পাতা সাজানোর পরিকল্পনা ধাপ ১, ১০ অক্টোবর ২০২৬: *"ব্রাউজারের ইংরেজি 'October 2026' আর দেখাবে না"*
 * ([[x-ui.month]])।
 *
 * দাবি:
 *  - কোনো পর্দায় ব্রাউজারের `<input type="month">` নেই — সব x-ui.month (x-ui.field type="month"-ও সেখানে যায়)।
 *  - তালিকার সারি পর্দার ভাষায় ("অক্টোবর"), মান `Y-m` — সার্ভার আগের মতোই পায়; দেওয়া মাসটা বাছা।
 *  - `max`-এর পরের মাস তালিকায় নেই; তালিকার বাইরের পুরনো মাস দিলে সেটাও আছে আর বাছা।
 *  - `placeholder` দিলে খালি মানে খালি সারিটাই বাছা ("চলতি চক্র")।
 *  - একটা আসল পাতা (মাস-শেষ) তালিকাটা আঁকে।
 */
final class AMonthIsPickedInBengaliTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_screen_uses_the_browsers_month_box(): void
    {
        $found = [];

        foreach ([app_path(), resource_path('views')] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                if (str_ends_with($file->getFilename(), '.blade.php') && preg_match('/<input[^>]*type="month"/', $file->getContents())) {
                    $found[] = $file->getRelativePathname();
                }
            }
        }

        $this->assertSame([], $found, "⛔ ব্রাউজারের ইংরেজি মাস-ঘর ফিরে এসেছে — x-ui.month নিন:\n".implode("\n", $found));
    }

    public function test_the_list_speaks_the_screens_language_and_keeps_the_value(): void
    {
        app()->setLocale('bn');

        $html = Blade::render('<x-ui.month name="month" value="2026-10" max="2026-10" />');

        $this->assertMatchesRegularExpression('/<option value="2026-10" selected[^>]*>\s*অক্টোবর 2026/u', $html, '⛔ মাসটা বাংলায় নয়, বা বাছা নয়।');
        $this->assertStringNotContainsString('value="2026-11"', $html, '⛔ max-এর পরের মাস তালিকায়।');
        $this->assertStringNotContainsString('October', $html);

        $old = Blade::render('<x-ui.month name="month" value="2019-03" />');
        $this->assertMatchesRegularExpression('/<option value="2019-03" selected/', $old, '⛔ পুরনো মাস খুললে অন্য মাসে সরে গেল।');

        $blank = Blade::render('<x-ui.month name="month" value="" placeholder="চলতি চক্র" />');
        $this->assertMatchesRegularExpression('/<option value="" selected[^>]*>\s*চলতি চক্র/u', $blank, '⛔ খালি মানে খালি সারি বাছা নয়।');
        $this->assertSame(1, substr_count($blank, ' selected'), '⛔ দুইটা সারি একসাথে বাছা।');
    }

    public function test_a_real_page_draws_the_list(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $html = (string) $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail())
            ->get(route('accounts.control.month_end', ['month' => '2026-08']))->assertOk()->getContent();

        $this->assertStringContainsString('data-month-select', $html, '⛔ মাস-শেষের পাতায় বাংলা মাস-তালিকা নেই।');
        // ⓘ চাওয়া মাসটাই বাছা ফেরে — সার্ভার যা পেয়েছে, পর্দা তা-ই দেখায়
        $this->assertMatchesRegularExpression('/<option value="2026-08" selected/', $html, '⛔ চাওয়া মাস বাছা ফেরেনি।');
    }
}
