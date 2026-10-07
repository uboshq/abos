<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Sales\Support\InvoicePrintLook;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বিল আর চালানের নিচের নির্দেশনা — মালিক, ৩ অক্টোবর ২০২৬।
 *
 * *"এই বাক্যটাকে আরো কিভাবে গুছিয়ে লেখা যায় … দুই কাগজে দুই রকম বসিয়ে দেব — etai koro … eta user edit kote pare"*।
 * ⓘ A4/A5-এ তিন দফা (প্রতিটা নতুন লাইনে), সরু রোলে এক লাইন; দুইটাই বিলের তথ্যের পাতায় বদলানো যায়,
 * আর পাতায় সাধারণ লেখাটা ভরা থাকে যাতে বদলে নেওয়া যায়।
 */
final class TheNoteUnderTheBillSpeaksToTheDriverTest extends TestCase
{
    use RefreshDatabase;

    private const HEADING = 'গ্রাহকের প্রতি নির্দেশনা:';

    private const ROLL = 'চালক থাকাকালীন চালান অনুযায়ী পণ্য বুঝে নিন';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_the_big_paper_gets_three_points_and_the_roll_one_line(): void
    {
        $look = app(InvoicePrintLook::class);

        $big = $look->footnote();
        $this->assertStringStartsWith(self::HEADING, $big);
        $this->assertSame(3, substr_count($big, "\n"), 'A4/A5-এর নির্দেশনা শিরোনাম আর তিন দফায় নয়।');

        $roll = $look->footnote(true);
        $this->assertStringStartsWith(self::ROLL, $roll);
        $this->assertStringNotContainsString("\n", $roll, 'সরু রোলের নির্দেশনা এক লাইনে নয়।');
    }

    public function test_each_paper_takes_its_own_edited_text(): void
    {
        $this->put(route('system_admin.print_control.invoice_info.update'), ['settings' => [
            'sales.print.invoice_footnote' => "OUR RULES:\n1. Count\n2. Sign",
            'sales.print.invoice_footnote_thermal' => 'OUR ROLL LINE',
        ]])->assertRedirect();

        $look = app(InvoicePrintLook::class);
        $this->assertSame("OUR RULES:\n1. Count\n2. Sign", $look->footnote(), 'A4/A5-এর বদলানো লেখা বসেনি, বা লাইন ভেঙে হারিয়েছে।');
        $this->assertSame('OUR ROLL LINE', $look->footnote(true), 'রোলের বদলানো লেখা বসেনি।');

        /* ⭐ কাগজে লাইনগুলো আলাদা লাইনেই ছাপে — একটা লম্বা দলা নয় */
        $paper = $this->get(route('sales.invoice_sample'))->assertOk()->getContent();
        $this->assertStringContainsString('OUR RULES:<br />', $paper, 'কাগজে নির্দেশনার লাইন ভাঙেনি।');
    }

    public function test_the_page_shows_the_standard_text_ready_to_edit(): void
    {
        $page = $this->get(route('system_admin.print_control.invoice_info'))->assertOk()->getContent();

        $this->assertStringContainsString('name="settings[sales.print.invoice_footnote]"', $page);
        $this->assertStringContainsString('name="settings[sales.print.invoice_footnote_thermal]"', $page);
        $this->assertStringContainsString(e(self::HEADING), $page, 'পাতায় A4/A5-এর সাধারণ লেখা ভরা নেই — বদলাতে হলে শূন্য থেকে লিখতে হত।');
        $this->assertStringContainsString(e(self::ROLL), $page, 'পাতায় রোলের সাধারণ লেখা ভরা নেই।');
    }

    /**
     * ⭐ চালান, আদেশ আর রসিদের মাথায় বিলের পাতায় বসানো নাম-ঠিকানা — মালিক, ৩ অক্টোবর ২০২৬: চালানে *"Adress nai"*।
     * ⓘ ডেমোতে প্রোফাইলে ঠিকানা ছিল না, ঠিকানা ছিল কেবল বিলের তথ্যের পাতায় — বিলে উঠত, চালানে নয়।
     */
    public function test_the_challan_heading_reads_the_invoice_heading(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('sales.print.header.name', 'HEAD NAME LTD');
        $settings->set('sales.print.header.address', 'HEAD ADDRESS 12');
        $settings->set('sales.print.header.phone', '01700000000');

        $head = app(InvoicePrintLook::class)->paperHead(Company::query()->where('code', 'TDEPOT')->firstOrFail(), false);

        $this->assertSame('HEAD NAME LTD', $head['name']);
        $this->assertSame('HEAD ADDRESS 12', $head['address'], '⛔ চালানের মাথায় বিলের পাতার ঠিকানা নেই।');
        $this->assertStringContainsString('01700000000', $head['contact']);

        /* পাহারা: বিক্রয়ের কোনো কাগজ আর কেবল প্রোফাইল থেকে মাথা বানায় না */
        $files = glob(base_path('app/Modules/Sales/Resources/views/print/partials/*.blade.php'));
        $this->assertGreaterThan(10, count($files));
        foreach ($files as $file) {
            $this->assertStringNotContainsString('PaperLook::head(', (string) file_get_contents($file),
                basename($file).' মাথা বানায় কেবল প্রোফাইল থেকে — বিলের পাতার ঠিকানা উঠবে না।');
        }
    }

    /** ⭐ লোগো বাছলেই দেখায়, সংরক্ষণের আগেই — মালিক, ৩ অক্টোবর ২০২৬: *"upload hole ekhane dekhar bebosta koro"* */
    public function test_the_logo_box_previews_the_chosen_picture(): void
    {
        $page = $this->get(route('system_admin.print_control.invoice_info'))->assertOk()->getContent();

        $this->assertStringContainsString('data-logo-preview', $page);
        $this->assertStringContainsString('imagePreview(', $page);
        $this->assertStringContainsString('@change="pick($event)"', $page);
    }

    /** ⛔ কোনো ছাপার নকশা নির্দেশনা ছাপে লাইন না ভেঙে — পাহারা, দুই ধরনের লেখা খাইয়ে */
    public function test_no_print_design_flattens_the_note(): void
    {
        $bare = '/\{\{ *\$(footnote|v->footnote(Thermal)?|look_?->footnote\((true)?\)) *\}\}/';

        $this->assertSame(1, preg_match($bare, '<div>{{ $footnote }}</div>'), 'পাহারাটাই অন্ধ — খালি ছাপা ধরে না।');
        $this->assertSame(0, preg_match($bare, '<div>{!! nl2br(e($footnote)) !!}</div>'), 'পাহারা ঠিক লেখাকেও ধরছে।');

        $files = glob(base_path('app/Modules/Sales/Resources/views/print/{,partials/}*.blade.php'), GLOB_BRACE);
        $this->assertGreaterThan(50, count($files), 'পাহারা প্রায় কোনো নকশা পড়েনি।');

        foreach ($files as $file) {
            $this->assertSame(0, preg_match($bare, (string) file_get_contents($file)), basename($file).' নির্দেশনা লাইন না ভেঙে ছাপে।');
        }
    }
}
