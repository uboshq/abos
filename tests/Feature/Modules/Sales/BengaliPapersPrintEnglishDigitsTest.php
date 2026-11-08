<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\BanglaDigits;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * ⭐ বাংলা কাগজেও টাকা আর সংখ্যা ইংরেজি অঙ্কে, লেখা বাংলায় — মালিক, ২৪ অক্টোবর ২০২৬ (fe-র প্রশ্ন: *"বাংলা কাগজে টাকা ইংরেজি
 * অঙ্কে থাকবে, শুধু কথায় লেখা টাকা বাংলায়। ঠিক আছে?"* — উত্তর *"ok"*)।
 *
 * ⓘ এটা ১০ অক্টোবরের নিয়ম বদলায় ("বাংলা নকশায় বাংলা অঙ্ক", আগের দাবি BengaliDesignsPrintBengaliDigitsTest): তখন ছাপা শেষে
 * পুরো লেখা বাংলা অঙ্কে বদলানো হত ([[BanglaDigits::inText()]]); সেই মোড়ক এখন কোনো নকশায় নেই।
 *
 * দাবি:
 *  - বাংলা নকশার (বিল, চালান, আদায়ের রসিদ) পড়ার লেখায় একটাও বাংলা অঙ্ক নেই, অথচ লেখা বাংলায়;
 *  - ভাউচার (লাইভের ট্যালি ক্লাসিক) বাংলা কাগজে ঘরের নাম বাংলায় আর অঙ্ক ইংরেজিতে — আগে ঘরের নাম সবসময় ইংরেজিতে ছিল;
 *  - ইংরেজি নকশা আগের মতোই ইংরেজি;
 *  - BanglaDigits নিজে আগের মতো (খোঁজার নম্বর ইংরেজি, নষ্ট বাইটে কাগজ ফাঁকা নয়) — সাহায্যকারীটা থাকছে।
 */
final class BengaliPapersPrintEnglishDigitsTest extends TestCase
{
    use RefreshDatabase;

    private const BENGALI_LETTER = '/[\x{0985}-\x{09B9}]/u';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_bengali_designs_write_bengali_and_print_english_digits(): void
    {
        foreach ([
            ['sales.invoice_sample', 'mono_light_bn', 'a4'], ['sales.invoice_sample', 'bangla_heritage', 'a4'],
            ['sales.invoice_sample', 'world_standard_bn', 'a5'], ['sales.invoice_sample', 'bangla', 'thermal'],
            ['sales.invoice_sample', 'mono_bold_classic_bn', 'a4'],
            ['sales.challan_sample', 'bangla_heritage', 'a4'], ['sales.receipt_sample', 'bangla_heritage', 'a4'],
        ] as [$route, $design, $size]) {
            $text = $this->text($route, $design, $size);

            $this->assertMatchesRegularExpression(self::BENGALI_LETTER, $text, "দৃশ্যটাই ভুল — {$design} ({$size}) কাগজে বাংলা লেখাই নেই");
            $this->assertMatchesRegularExpression('/[0-9]/', $text, "দৃশ্যটাই ভুল — {$design} ({$size}) কাগজে কোনো অঙ্কই নেই");

            preg_match_all('/\S*[\x{09E6}-\x{09EF}]\S*/u', $text, $found);
            $this->assertSame([], $found[0],
                "⛔ বাংলা নকশা {$design} ({$size}) এখনো বাংলা অঙ্ক ছাপে — মালিক ২৪ অক্টোবর বলেছেন বাংলা কাগজেও টাকা ইংরেজি অঙ্কে");
        }
    }

    public function test_the_live_voucher_design_speaks_bengali_with_english_digits(): void
    {
        DB::table('companies')->where('id', $this->company->id)->update(['locale' => 'bn']);
        $html = $this->voucherHtml();

        foreach (['core.print.account', 'core.table.debit', 'core.print.total'] as $key) {
            $this->assertStringContainsString(mb_strtoupper((string) __($key, [], 'bn')), $html,
                "⛔ বাংলা ভাউচারে «{$key}» ঘরের নাম বাংলায় নয় — ট্যালি ক্লাসিক এখনো সবসময় ইংরেজিতে লেখে, এক কাগজে দুই ভাষা।");
        }

        preg_match_all('/\S*[\x{09E6}-\x{09EF}]\S*/u', $this->readable($html), $found);
        $this->assertSame([], $found[0], '⛔ বাংলা ভাউচারে বাংলা অঙ্ক — মালিক ২৪ অক্টোবর বলেছেন টাকা ইংরেজি অঙ্কে।');
        $this->assertStringContainsString('1,234.50', $html, '⛔ ভাউচারের টাকা ইংরেজি অঙ্কে নেই।');
    }

    /** ⓘ মালিকের ২১ ভাউচার-নকশা অঙ্ক ছাপে এক জায়গা দিয়ে ([[VoucherPaper::n()]]) — A5 টাকার রসিদ দিয়ে মাপা */
    public function test_the_owners_voucher_designs_print_english_digits_in_bengali(): void
    {
        DB::table('companies')->where('id', $this->company->id)->update(['locale' => 'bn']);
        app(\App\Core\Services\SettingsService::class)->set('accounts.print.design.voucher_a5', 'money_receipt');
        $html = $this->voucherHtml('a5');

        $this->assertMatchesRegularExpression(self::BENGALI_LETTER, $this->readable($html), 'দৃশ্যটাই ভুল — টাকার রসিদ বাংলায় আঁকা হয়নি।');
        preg_match_all('/\S*[\x{09E6}-\x{09EF}]\S*/u', $this->readable($html), $found);
        $this->assertSame([], $found[0], '⛔ বাংলা টাকার রসিদে বাংলা অঙ্ক — মালিক ২৪ অক্টোবর বলেছেন টাকা ইংরেজি অঙ্কে।');
    }

    public function test_english_designs_keep_english_digits(): void
    {
        foreach ([['mono_light', 'a4'], ['world_standard', 'a5'], ['acct_card', 'a4']] as [$design, $size]) {
            $text = $this->text('sales.invoice_sample', $design, $size);

            $this->assertMatchesRegularExpression('/[0-9]/', $text);
            preg_match_all('/\S*[\x{09E6}-\x{09EF}]\S*/u', $text, $found);
            $this->assertSame([], $found[0], "⛔ ইংরেজি নকশা {$design} বাংলা অঙ্ক ছাপল");
        }
    }

    /** ⓘ সাহায্যকারীটা থাকছে — খোঁজার নম্বর ইংরেজিতে, আর ভাঙা লেখায় কাগজ ফাঁকা নয় (PR #17 রিভিউ ⚠️৯) */
    public function test_the_digit_helper_still_keeps_search_numbers_and_never_blanks_a_paper(): void
    {
        $this->assertSame('<p>বিল S-2610-0001 · ৳ ১,২০০.০০ · ১১-১০-২০২৬ · ১২ পিস</p>',
            BanglaDigits::inText('<p>বিল S-2610-0001 · ৳ 1,200.00 · 11-10-2026 · 12 পিস</p>'));

        foreach (['adi2024@gmail.com', 'www.adi2024.com', 'DHA-GA-11-2233', 'TrxID:9AB1C2D3', '01711-123456', '01711123456', '002345678901'] as $searched) {
            $this->assertSame("<td>{$searched}</td>", BanglaDigits::inText("<td>{$searched}</td>"), "⛔ «{$searched}» বাংলা অঙ্কে — খোঁজার ঘরে টাইপ করে পাওয়া যাবে না");
        }

        $broken = "<p>বিল 12 \xC3\x28</p>";
        $this->assertSame($broken, BanglaDigits::inText($broken), '⛔ নষ্ট লেখায় কাগজ ফাঁকা হয়ে গেল');
        $this->assertSame('<p style="width: 3mm">১২ &#2453;</p>', BanglaDigits::inText('<p style="width: 3mm">12 &#2453;</p>'));
    }

    /** লাইভের ভাউচার — আসল ছাপার পথে, আঁকার মুহূর্তের locale-সহ ([[PrintsInThePaperLanguage]]) */
    private function voucherHtml(string $paper = 'a4'): string
    {
        app(StandardChart::class)->install();
        $vouchers = app(VoucherService::class);
        $cash = Account::query()->money()->postable()->active()->firstOrFail();
        $capital = Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail();
        $voucher = $vouchers->post($vouchers->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'অঙ্কের পরীক্ষা'],
            [['account_id' => $cash->id, 'debit' => '1234.5', 'credit' => '0'],
                ['account_id' => $capital->id, 'debit' => '0', 'credit' => '1234.5']],
        ));

        $seen = [];
        $template = null;
        $locale = null;
        View::composer('print.voucher*', function ($view) use (&$seen, &$template, &$locale): void {
            $seen = $view->getData();
            $template = $view->name();
            $locale = app()->getLocale();
        });

        $this->get(route('accounts.voucher.print', $voucher).'?paper='.$paper)->assertOk();
        $this->assertNotNull($template, 'দৃশ্যটাই ভুল — ভাউচারের নকশা ডাকা হয়নি।');

        // ⓘ composer-এর ভিতরে আবার আঁকা যায় না; আঁকার মুহূর্তের ভাষাতেই পরে আঁকা
        $before = app()->getLocale();
        app()->setLocale((string) $locale);
        try {
            return view($template, $seen)->render();
        } finally {
            app()->setLocale($before);
        }
    }

    private function text(string $route, string $design, string $size): string
    {
        return $this->readable((string) $this->get(route($route, ['design' => $design, 'size' => $size]))->assertOk()->getContent());
    }

    private function readable(string $html): string
    {
        return (string) preg_replace('/<style\b.*?<\/style>|<script\b.*?<\/script>|<[^>]*>|&#?[a-zA-Z0-9]+;/su', ' ', $html);
    }
}
