<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Core\Services\ListPaper;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ প্রতিটা তালিকা আর রিপোর্টের কাগজ — লেজারের ছাঁচে, প্রিন্ট ভিউ আর PDF (মালিক, ১৬ নভেম্বর ২০২৬: *"সবগুলাই যাতে প্রিন্ট করা যায় …
 *   প্রিন্ট ভিউ এ দেখতে পারবো, আবার প্রিন্টও করতে পারবো"*)।
 *
 *   - `?export=print` → PDF, ব্রাউজারেই খোলে (inline); `?export=pdf` → PDF ফাইল (attachment) — তালিকা আর রিপোর্ট দুটোতেই
 *   - "তালিকা নামানো" চাবি ছাড়া কাগজও নয় (৪০৩)
 *   - কাগজে কোম্পানির প্যাড, শিরোনাম, "যা দেখানো হচ্ছে", কলামের মাথা, সর্বমোট, পৃষ্ঠা ক / মোট; সই সেটিং ধরে
 *   - অ্যাকশনের কলাম কোনো ফাইলে নয়, আর "ক্রম" ফাইলে ১, ২, ৩ … (আগে সবগুলোয় "1" আর মেনুর লেখা)
 *   - ৮টার বেশি কলামে পাতা আড়াআড়ি; টাকার ঘর ডানে, নাম আর মোবাইল বাঁয়ে
 */
final class EveryListPrintsOnTheLedgerPaperTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    public function test_the_print_view_opens_in_the_browser_and_the_pdf_downloads(): void
    {
        $view = $this->get(route('customer.index', ['export' => 'print']))->assertOk();
        $this->assertSame('application/pdf', $view->headers->get('content-type'), '⛔ প্রিন্ট ভিউ PDF নয়');
        $this->assertStringStartsWith('inline;', (string) $view->headers->get('content-disposition'), '⛔ প্রিন্ট ভিউ ব্রাউজারে না খুলে নামছে');
        $this->assertStringStartsWith('%PDF', (string) $view->getContent());

        $file = $this->get(route('customer.index', ['export' => 'pdf']))->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $file->headers->get('content-disposition'), '⛔ PDF ফাইল হয়ে নামছে না');
        $this->assertStringContainsString('.pdf"', (string) $file->headers->get('content-disposition'));

        // রিপোর্টও — রেওয়ামিল
        $tb = $this->get(route('accounts.report.show', ['slug' => 'trial-balance', 'export' => 'print']))->assertOk();
        $this->assertSame('application/pdf', $tb->headers->get('content-type'), '⛔ রিপোর্টের প্রিন্ট ভিউ PDF নয়');
    }

    public function test_the_paper_wears_the_ledger_pad_and_every_part(): void
    {
        $html = view('print.listing', [
            'company' => Company::query()->where('code', 'TDEPOT')->firstOrFail(),
            'title' => 'রেওয়ামিল', 'titleEn' => 'Trial Balance', 'period' => '01-11-2026 থেকে 16-11-2026',
            'facts' => ['শাখা: প্রধান'], 'columns' => [['label' => 'হিসাব', 'numeric' => false], ['label' => 'ডেবিট', 'numeric' => true]],
            'rows' => [['প্রধান', ''], ['1120 — মজুদ', '8,40,000.00'], ['শাখার মোট', '8,40,000.00']], 'kinds' => ['head', 'row', 'total'],
            'footer' => ['সর্বমোট', '8,40,000.00'], 'truncated' => false, 'branch' => 'সব শাখা', 'printedBy' => 'Owner',
            'printedAt' => '16-11-2026, 09:00 PM', 'signatures' => true,
        ])->render();

        foreach (['রেওয়ামিল', 'Trial Balance', '01-11-2026 থেকে 16-11-2026', 'শাখা: প্রধান', 'repeat_header="1"', '{PAGENO} / {nbpg}',
            __('core.listing.sign_prepared'), __('core.listing.sign_approved'), 'class="grand"', 'class="head"', 'class="sub"'] as $part) {
            $this->assertStringContainsString($part, $html, "⛔ কাগজে \"{$part}\" নেই");
        }
        // ⓘ "সারি" গোনে কেবল আসল সারি — শাখার মাথা আর মোট নয়
        $this->assertMatchesRegularExpression('/'.preg_quote(__('core.listing.rows'), '/').':<\/span> <b>1<\/b>/u', $html, '⛔ সারির সংখ্যায় মাথা/মোটও গোনা হচ্ছে');
        // ⛔ মাথায় বাক্স নয়, এক লাইনের তথ্য (মালিক, ১৬ নভেম্বর ২০২৬)
        $this->assertStringContainsString('data-info-line', $html, '⛔ তথ্যের লাইন নেই');
        $this->assertStringNotContainsString('class="box', $html, '⛔ কাগজের মাথায় আবার বাক্স');
        // ⓘ "সর্বমোট" বাংলা ফন্টে — সংখ্যার ফন্টে চৌকো বাক্স হত
        $this->assertDoesNotMatchRegularExpression('/<td class="num">সর্বমোট/u', $html, '⛔ "সর্বমোট" সংখ্যার ফন্টে — কাগজে চৌকো বাক্স');
    }

    public function test_the_action_column_never_reaches_a_file_and_the_serial_counts(): void
    {
        $csv = (string) $this->get(route('customer.index', ['export' => 'csv']))->assertOk()->getContent();
        $lines = array_values(array_filter(preg_split('/\r?\n/', ltrim($csv, "\u{FEFF}"))));
        $head = str_getcsv($lines[0]);

        $this->assertNotContains(__('core.table.action_menu'), $head, '⛔ অ্যাকশনের কলাম ফাইলে — প্রতিটা সারিতে মেনুর লেখা');
        $serial = array_search(__('core.table.serial'), $head, true);
        $this->assertNotFalse($serial, 'গ্রাহকের তালিকায় "ক্রম" কলাম থাকার কথা');
        $numbers = array_map(fn (string $l) => str_getcsv($l)[$serial], array_slice($lines, 1, -1));
        $this->assertSame(array_map('strval', range(1, count($numbers))), $numbers, '⛔ ফাইলে "ক্রম" ১, ২, ৩ … নয়');
        // ⓘ সর্বমোটের সারিতে কলামের সংখ্যা মাথার সমান — বাদ পড়া কলামের ঘরটাও বাদ
        $this->assertCount(count($head), str_getcsv(end($lines)), '⛔ সর্বমোটের সারি এক ঘর সরে বসেছে');
    }

    /** ⓘ অ্যাকশনের কলাম চাবির নামে না চিনলেও মাথার লেখায় চেনা যায় — যে তালিকা কলামটার অন্য চাবি রেখেছে */
    public function test_an_action_column_is_known_by_its_heading_too(): void
    {
        $this->app['request']->query->set('export', 'csv');
        $export = app(\App\Core\Services\ListExport::class);
        $export->reset();
        $export->capture([
            ['key' => 'name', 'label' => 'নাম', 'render' => null],
            ['key' => 'tools', 'label' => __('core.table.action_menu'), 'render' => null],
        ], [['name' => 'আলম স্টোর', 'tools' => 'দেখুন · বদলান']], fn ($row, array $column) => $row[$column['key']]);

        $this->assertSame(['নাম'], array_column($export->captured()['columns'], 'label'), '⛔ "অ্যাকশন" মাথার কলাম ফাইলে এসেছে');
    }

    /** ⓘ সংখ্যার কলামেও লেখা থাকলে ("বাকি") সংখ্যার ফন্ট নয় — সেই ফন্টে বাংলা অক্ষর নেই, কাগজে চৌকো বাক্স হত */
    public function test_words_in_a_number_column_keep_the_bengali_font(): void
    {
        $html = view('print.listing', [
            'company' => Company::query()->where('code', 'TDEPOT')->firstOrFail(),
            'title' => 'বিল', 'titleEn' => null, 'period' => null, 'facts' => [],
            'columns' => [['label' => 'বিল', 'numeric' => false], ['label' => 'টাকা', 'numeric' => true]],
            'rows' => [['INV-1', '1,000.00'], ['INV-2', 'বাকি']], 'kinds' => ['row', 'row'],
            'footer' => [], 'truncated' => false, 'branch' => 'সব শাখা', 'printedBy' => 'Owner', 'printedAt' => '17-11-2026', 'signatures' => false,
        ])->render();

        $this->assertMatchesRegularExpression('/<td class="num">1,000.00<\/td>/u', $html);
        $this->assertDoesNotMatchRegularExpression('/<td class="num">বাকি/u', $html, '⛔ সংখ্যার কলামের লেখা সংখ্যার ফন্টে — চৌকো বাক্স');
    }

    public function test_numbers_sit_right_and_names_and_phones_sit_left(): void
    {
        $this->assertTrue(ListPaper::looksNumeric(['1,25,000.00', '(8,40,000.00)', '', '—', '12.5%', '(Dr) 250.79']));
        $this->assertFalse(ListPaper::looksNumeric(['+8801811000004', '01711019262']), '⛔ মোবাইল নম্বর টাকার মতো ডানে');
        $this->assertFalse(ListPaper::looksNumeric(['আলম স্টোর', '1,000.00']));
        $this->assertFalse(ListPaper::looksNumeric(['', '—']), 'খালি কলাম সংখ্যার নয়');
    }

    /**
     * ⭐ স্থিতিপত্র — IAS 1 ছকের নিজের কাগজ: সম্পদ → মালিকানা স্বত্ব → দায়, পাশে আগের অর্থবছরের শেষ দিন, শেষে "পার্থক্য"।
     */
    public function test_the_balance_sheet_prints_in_the_ias_1_order(): void
    {
        $view = $this->get(route('accounts.balance_sheet', ['export' => 'print']))->assertOk();
        $this->assertSame('application/pdf', $view->headers->get('content-type'), '⛔ স্থিতিপত্রের প্রিন্ট ভিউ PDF নয়');
        $this->assertStringStartsWith('inline;', (string) $view->headers->get('content-disposition'));

        $page = (string) $this->get(route('accounts.balance_sheet'))->assertOk()->getContent();
        $this->assertStringContainsString('data-paper="print"', $page, '⛔ স্থিতিপত্রের পাতায় প্রিন্ট ভিউয়ের বোতাম নেই');

        /*
         * ⓘ কাগজ এখন NEXUS-এর মানের বিবরণী (মালিক, ১৭ নভেম্বর ২০২৬ "ok"): অঙ্ক [[FinancialStatements::position()]] থেকে, সাথে নোট আর
         *   মালিকানা স্বত্বের পরিবর্তন — তাই কাগজের উপাদান বদলেছে; দাবি একই: IAS 1-এর ক্রম, তুলনার তারিখ, শেষে মিল।
         */
        $statements = app(\App\Modules\Accounts\Services\FinancialStatements::class);
        $built = ['prior' => ['as_of' => app(\App\Modules\Accounts\Services\BalanceSheetService::class)->priorDate('2026-11-16')]];
        $html = view('accounts::print.balance-sheet', [
            'company' => Company::query()->where('code', 'TDEPOT')->firstOrFail(), 'locale' => 'bn',
            'fs' => $statements->position('2026-11-16'), 'soce' => $statements->equity($statements->periodStart('2026-11-16'), '2026-11-16'),
            'branch' => 'সব শাখা', 'branchNote' => false, 'printedBy' => 'Owner', 'printedAt' => '16-11-2026', 'signatures' => true, 'board' => true,
        ])->render();

        $assets = mb_strpos($html, __('accounts::statement.bs.total_assets'));
        $equity = mb_strpos($html, __('accounts::statement.bs.total_equity'));
        $liabilities = mb_strpos($html, __('accounts::statement.bs.total_liabilities'));
        $this->assertNotFalse($assets);
        $this->assertTrue($assets < $equity && $equity < $liabilities, '⛔ IAS 1-এর ক্রম নয় — সম্পদ, তারপর মালিকানা স্বত্ব, তারপর দায়');
        $this->assertStringContainsString(\Illuminate\Support\Carbon::parse($built['prior']['as_of'])->format('d-m-Y'), $html, '⛔ তুলনার তারিখ নেই');
        $this->assertStringContainsString('data-recon', $html, '⛔ শেষে মিলের হিসাব নেই');
    }

    public function test_without_the_download_key_there_is_no_paper_either(): void
    {
        $clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->owner->current_company_id]);
        $clerk->companies()->attach($this->owner->current_company_id, ['is_active' => true]);
        CompanyContext::forCompany((int) $this->owner->current_company_id, fn () => $clerk->givePermissionTo('customer.view'));

        $this->actingAs($clerk)->get(route('customer.index', ['export' => 'print']))->assertForbidden();
        $this->actingAs($clerk)->get(route('customer.index', ['export' => 'pdf']))->assertForbidden();
    }
}
