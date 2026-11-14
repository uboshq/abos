<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Core\Support\CompanyContext;
use App\Core\Support\PerPage;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ মালিক, ১৩ নভেম্বর ২০২৬: "ভাউচার লিস্টে … এক পেজে কতগুলো থাকে তার কোনো বক্স নাই"।
 *
 * দাবি: সব তালিকার একই পেজারে "এক পাতায়" — ২৫, ৫০, ১০০, ২০০; বাছলে সেই সংখ্যাই আসে, ঠিকানার বাকি ছাঁকনি থাকে, পাতা প্রথমে ফেরে;
 *   বাছাই মানুষটার মনে থাকে (অন্য তালিকাতেও); তালিকার বাইরের সংখ্যা চলে না (গোটা খাতা এক পাতায় টানা যায় না)।
 * ⓘ ১৪১টা ওয়েব-তালিকা একই [[PerPage::of()]] পড়ে — কোনোটা আবার হাতে লেখা ৫০-এ ফিরলে [[test_no_web_list_hard_codes_fifty_again]] ধরে।
 */
final class EveryListLetsYouPickHowManyRowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_the_pager_offers_the_four_sizes_and_keeps_the_filters(): void
    {
        $response = $this->get(route('accounts.voucher.list', ['status' => 'draft', 'page' => 3]))->assertOk();

        $html = (string) $response->getContent();
        $this->assertStringContainsString('data-per-page', $html, '⛔ পেজারে "প্রতি পাতায়" ঘরটা নেই।');
        foreach (PerPage::CHOICES as $n) {
            $this->assertStringContainsString('<option value="'.$n.'"', $html, "⛔ প্রতি পাতায় {$n} বাছার উপায় নেই।");
        }
        $this->assertStringContainsString('<option value="50" selected', $html, '⛔ বাছাই ছাড়া ৫০ বাছা দেখায় না।');
        $this->assertStringContainsString('name="status" value="draft"', $html, '⛔ সংখ্যা বদলালে ছাঁকনি (খসড়া) হারায়।');
        $this->assertStringNotContainsString('name="page"', $html, '⛔ সংখ্যা বদলালে পুরোনো পাতা নম্বর থেকে যায় — ফাঁকা পাতায় পড়তে পারে।');
        $this->assertSame(50, $response->viewData('vouchers')->perPage(), '⛔ বাছাই ছাড়া আগের ৫০ নয়।');
    }

    public function test_a_picked_size_is_used_and_remembered_on_other_lists(): void
    {
        $picked = $this->get(route('accounts.voucher.list', ['per_page' => 100]))->assertOk();
        $this->assertSame(100, $picked->viewData('vouchers')->perPage(), '⛔ ১০০ বাছলাম, তবু অন্য সংখ্যা।');
        $picked->assertSee('<option value="100" selected', false);

        $later = $this->get(route('accounts.voucher.list'))->assertOk();
        $this->assertSame(100, $later->viewData('vouchers')->perPage(), '⛔ বাছাই মনে থাকল না — পরের বার আবার ৫০।');
    }

    public function test_a_size_off_the_list_is_refused(): void
    {
        foreach (['100000', '7', 'all', '-1'] as $bad) {
            $response = $this->get(route('accounts.voucher.list', ['per_page' => $bad]))->assertOk();
            $this->assertSame(50, $response->viewData('vouchers')->perPage(), "⛔ per_page={$bad} মানা হলো — গোটা খাতা এক পাতায় টানা যেত।");
        }
    }

    public function test_no_web_list_hard_codes_fifty_again(): void
    {
        $hits = [];

        foreach ((new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()))) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if ($file->getExtension() !== 'php' || str_contains($path, '/Api/')) {
                continue;
            }

            foreach (explode("\n", (string) file_get_contents($path)) as $i => $line) {
                if (preg_match('/->paginate\(\s*(50|self::PER_PAGE)\s*[,)]/', $line) === 1) {
                    $hits[] = str_replace(str_replace('\\', '/', app_path()).'/', '', $path).':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $hits, '⛔ এই তালিকাগুলো আবার হাতে লেখা সংখ্যায় — "এক পাতায়" বাছাই কাজ করবে না: '.implode(', ', $hits));
    }
}
