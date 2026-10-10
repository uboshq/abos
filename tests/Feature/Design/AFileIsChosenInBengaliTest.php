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
 * ⭐ ফাইল তোলার বাংলা বোতাম — পাতা সাজানোর পরিকল্পনা ধাপ ১, ১০ অক্টোবর ২০২৬: *"ব্রাউজারের ইংরেজি 'Choose File' আর
 * দেখাবে না"* ([[x-ui.file-input]], [[filePick]])।
 *
 * দাবি:
 *  - বোতাম বাংলায়, আসল ঘর চোখে লুকানো (sr-only) কিন্তু ফর্মে আর বোতামের লেবেলে বাঁধা; পাশে "কোনো ফাইল বাছা হয়নি",
 *    বাছলে নাম ([[filePick]] — vitest)। বাড়তি বৈশিষ্ট্য (স্ক্যানার) আসল ঘরেই বসে।
 *  - কাঁচা `<input type="file">` কেবল KNOWN-এর পাতাগুলোয় — যেগুলোর নিজের ছবি-দেখা, ক্যামেরা বা আগের ছবি আছে; তালিকা
 *    কেবল কমে (পাতা ধরে ধাপ ২-এ), নতুন কাঁচা ঘর এলে লাল।
 *  - একটা আসল পাতা (তথ্য আনা) বাংলা বোতাম আঁকে।
 */
final class AFileIsChosenInBengaliTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> নিজের আচরণসহ ফাইল-ঘর — পাতা ধরে বদলাবে, ততদিন গোনা */
    private const KNOWN = [
        'Modules/Accounts/Resources/views/voucher/partials/attachment-field.blade.php',
        'Modules/Hr/Resources/views/employee/form.blade.php',
        'Modules/Inventory/Resources/views/product/form.blade.php',
        'Modules/Sales/Resources/views/claim/request.blade.php',
        'Modules/Sales/Resources/views/portal/claim.blade.php',
        'Modules/SystemAdmin/Resources/views/company/form.blade.php',
        'Modules/SystemAdmin/Resources/views/looks/index.blade.php',
        'Modules/SystemAdmin/Resources/views/print-control/invoice-info.blade.php',
        'components/ui/attachments.blade.php',
        'components/ui/file-input.blade.php',
        'workspace/profile.blade.php',
    ];

    public function test_the_button_speaks_bengali_and_keeps_the_real_box_in_the_form(): void
    {
        app()->setLocale('bn');

        $html = Blade::render('<form><x-ui.file-input id="p" name="paper" accept="image/*" x-on:change="$store.scanner.begin($el, \'paper\')" /></form>');

        $this->assertMatchesRegularExpression('/<label for="p"[^>]*>.*?ফাইল বাছুন/su', $html, '⛔ বাংলা বোতাম আসল ঘরের লেবেল নয়।');
        $this->assertMatchesRegularExpression('/<input type="file" id="p" name="paper" class="sr-only"[^>]*accept="image\/\*"/', $html, '⛔ আসল ঘর ফর্মে নেই বা খোলা দেখায়।');
        $this->assertStringContainsString('x-on:change="$store.scanner.begin($el, \'paper\')"', $html, '⛔ স্ক্যানার আসল ঘর থেকে হারাল।');
        $this->assertStringContainsString('@change="pick($event)"', $html);
        $this->assertStringContainsString('কোনো ফাইল বাছা হয়নি', $html);
        $this->assertStringContainsString('filePick(', $html);
    }

    public function test_raw_file_boxes_live_only_where_counted(): void
    {
        $found = [];

        foreach ([app_path(), resource_path('views')] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                if (str_ends_with($file->getFilename(), '.blade.php') && preg_match('/<input[^>]*type="file"/', $file->getContents())) {
                    $found[] = str_replace('\\', '/', $file->getRelativePathname());
                }
            }
        }

        sort($found);
        $known = self::KNOWN;
        sort($known);

        $this->assertSame([], array_values(array_diff($found, $known)), '⛔ নতুন কাঁচা ফাইল-ঘর — ব্রাউজারের "Choose File" ফিরল; x-ui.file-input নিন।');
        $this->assertSame([], array_values(array_diff($known, $found)), 'এই পাতাগুলো আর কাঁচা ঘর রাখে না — KNOWN থেকে তুলুন।');
    }

    public function test_a_real_page_draws_the_bengali_button(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $html = (string) $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail())
            ->get(route('system_admin.import.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-file-input', $html, '⛔ তথ্য আনার পাতায় বাংলা ফাইল-বোতাম নেই।');
    }
}
