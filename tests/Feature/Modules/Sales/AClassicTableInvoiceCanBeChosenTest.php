<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Print\PrintEngine;
use App\Core\Engines\Print\PrintableDocument;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\CollectionLine;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ক্লাসিক টেবিল ইনভয়েস — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬।
 *
 * ⓘ দাবিগুলো: চলতি নকশাই ডিফল্ট; সেটিংসে বাছলে নতুনটা আসে, আসল অঙ্ক
 * নিয়ে; টাকার ঘর ছাপার আকারে মেপে ১২ কোটি ধরে; রোলে চলতি রসিদই থাকে;
 * DUPLICATE হারায় না; আর আদায়ের ছক সত্যিই কাগজে ওঠে।
 *
 * ── ⛔ শেষ দাবিটা একটা পুরনো ভুল ধরেছে ─────────────────────────────────
 * [[PrintableDocument::withWordsFor()]] কপি বানানোর সময় আদায়ের সারিগুলো
 * ফেলে দিত, আর প্রতিটা বিল ছাপার আগে ঐ কপিটাই বানানো হয়। ⚠️ ফলে ছকটা
 * **কোনোদিন কাগজে ওঠেনি**, অথচ [[TheBillDidNotSayWhichDepositsItCountedTest]]
 * সবুজ ছিল — সে সারি তোলার পদ্ধতিটা ডাকত, কাগজ আঁকত না।
 */
final class AClassicTableInvoiceCanBeChosenTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSIC = 'sales::print.invoice-classic';

    private const STANDARD = 'print.document';

    /** ⓘ A4-র প্রতিশ্রুতি — [[NoPrintedFigureOverflowsItsColumnTest]]-এর সাথে এক: ১২ কোটি */
    private const QTY = '2';

    private const RATE = '61593750.00';

    private const AMOUNT = '12,31,87,500.00';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($this->user);

        /*
         * ⓘ ধারের সীমা শূন্য = সীমাহীন ([[Customer]])। ⚠️ ডেমো গ্রাহকের সীমা
         * ৫০ হাজার, আর ১২ কোটির বিল ধারের দেয়ালে আটকাত — এই পরীক্ষা ছাপা
         * মাপে, ধারের দেয়াল নয়।
         */
        // ⛔ ১ অক্টোবর ২০২৬ থেকে শূন্য সীমা মানে বাকি নেই (মালিকের চূড়ান্ত কথা) — তাই এই পরীক্ষার গ্রাহকের সত্যিকারের বড় সীমা (১,০০০ কোটি)
        Customer::query()->firstOrFail()->forceFill(['credit_limit' => '10000000000'])->save();

        /* ⓘ ৩০ সেপ্টেম্বর ২০২৬ থেকে ডিফল্ট "মোনো ক্লাসিক হালকা" — এই ফাইল ক্লাসিকটাকে মাপে, তাই বেছে নেয় */
        $this->choose('classic_table');
    }

    /**
     * ⭐ সেটিং না বসানো কোম্পানি মালিকের ডিফল্ট পায় — ২৯ সেপ্টেম্বর ক্লাসিক, ৩০ সেপ্টেম্বর থেকে
     * "মোনো ক্লাসিক হালকা" (*"keu select na korle egulotei print hobe"*)।
     */
    public function test_a_company_with_no_setting_prints_the_owners_default(): void
    {
        app(SettingsService::class)->reset('sales.print.design.invoice');

        $drawn = false;
        View::composer('sales::print.invoice-mono_light', function () use (&$drawn) {
            $drawn = true;
        });

        $this->actingAs($this->user)->get(route('sales.print.invoice', $this->anInvoice()))->assertOk();

        $this->assertTrue($drawn, 'সেটিং না থাকা কোম্পানিতে মালিকের ডিফল্ট ("মোনো ক্লাসিক হালকা") ছাপা হয়নি।');
    }

    public function test_the_standard_design_can_still_be_chosen(): void
    {
        $this->choose('standard');

        $this->assertSame(self::STANDARD, $this->printed($this->anInvoice())['view'],
            'চলতি নকশা বাছার পরেও ক্লাসিক ছাপা হলো — মালিকের বাছাই মানা হয়নি।');
    }

    /**
     * ⭐ নমুনার হুবহু — ইংরেজি ঘরের নাম, টাকার সারির পুরো ক্রম, কথায় লাখ-কোটি।
     *
     * ⓘ ব্যবহারকারীর ভাষা বাংলা হলেও কাগজ নমুনার ইংরেজিতে — তাই দাবি বাংলা ভাষাতেই চালানো।
     */
    public function test_the_classic_invoice_reads_like_the_owners_sample(): void
    {
        app()->setLocale('bn');
        $invoice = $this->anInvoice();
        $product = Product::query()->firstOrFail();

        $html = $this->printed($invoice)['html'];

        foreach ([
            'INVOICE', 'Bill To,', 'M/S', 'Point:', 'Contact No:',
            'Transportation Details:', 'Transport Name:', 'Driver Mobile#:', 'Vehicle Type &amp; Number:', 'Delivery Date:',
            'INVOICE DATE:', 'INVOICE ID:', 'Order No:', 'INVOICE TYPE:', 'Created by:',
            'SL#', 'Item Name', 'Rate', 'QTY', 'Free', 'Total QTY', 'Amount', 'data-grand-row',
            'Total Item:', 'Delivery Qty.', 'Invoice Amount In Word:',
            'Grand Total', 'Discount', 'Rounding', 'Net Payable Amount', 'Paid Amount', 'Invoice Due',
            '(+) Previous Due', 'Outstanding (',
            'Paid - Received Into Accounts', 'Transaction ID', 'Transaction Date', 'Payment Method',
            'Printing Time:',
            $invoice->document_no, self::AMOUNT,
        ] as $expected) {
            $this->assertStringContainsString($expected, $html, "নমুনার লেখা কাগজে নেই: {$expected}");
        }

        // ⓘ ১২,৩১,৮৭,৫০০ — লাখ-কোটির ইংরেজি, বড় হাতে, "(BDT)"
        $this->assertStringContainsString('Twelve Crore Thirty One Lac Eighty Seven Thousand Five Hundred (BDT)', $html,
            'কথায় অঙ্কটা নমুনার ধাঁচে নয়।');

        // ⛔ পণ্যের নামের ঘরে কেবল নাম — কোড নয়
        $this->assertStringNotContainsString(e((string) $product->code).' - ', $html, 'পণ্যের নামের সাথে কোড জুড়ে গেছে।');
        $this->assertStringContainsString(e($product->name('en')), $html);

        // ⓘ বিলের ধরন কখনো ফাঁকা নয় — শর্ত বা বিলের মেয়াদ থেকে
        $this->assertMatchesRegularExpression('/INVOICE TYPE:\s*(CASH|CREDIT)/', $html, 'INVOICE TYPE ফাঁকা ছাপা হলো।');

        // ⓘ সইয়ের সারি নমুনার মতো বাংলায়
        $this->assertStringContainsString('গ্রহণকারী/পরিবহক', $html);
        $this->assertStringContainsString('প্রস্তুতকারী', $html);

        // ⓘ প্রথম ছাপায় DUPLICATE নেই
        $this->assertStringNotContainsString('data-duplicate', $html, 'প্রথম ছাপাতেই DUPLICATE বসেছে।');
    }

    /**
     * ⭐ টাকার প্রতিটা ঘর — ছাপার আকারে, পাতার নিজের CSS পড়ে।
     *
     * ⚠️ মাপ আর প্যাডিং পাতা থেকে পড়া হয়, হাতে বসানো হয় না: কেউ ছাঁচে
     * `td.num`-এর মাপ বাড়ালে পাহারাটা নতুন মাপেই মাপে।
     */
    public function test_no_money_figure_runs_past_its_column_on_the_classic_paper(): void
    {
        $this->choose('classic_table');
        $html = $this->printed($this->anInvoice())['html'];

        $fontSize = $this->styleValue($html, 'td\.num', 'font-size', 'pt');
        $padding = $this->styleValue($html, 'table\.lines\s+td', 'padding', 'mm', second: true);

        $this->assertNotNull($fontSize, 'ছাঁচে `td.num`-এর মাপ লেখা নেই — পাহারাটা মাপার কিছু পেল না।');
        $this->assertNotNull($padding, 'ছাঁচে সারির প্যাডিং লেখা নেই।');

        $cells = $this->moneyCells($html);
        $texts = array_column($cells, 'text');

        $this->assertContains(self::AMOUNT, $texts, '১২ কোটির অঙ্কটা কোনো মাপা ঘরেই পাওয়া গেল না।');

        $tooWide = [];

        foreach ($cells as $cell) {
            $room = $cell['width'] - 2 * $padding;
            $ink = $this->inkWidth($cell['text'], $fontSize);

            if ($ink > $room) {
                $tooWide[] = sprintf('"%s" %.2fmm, ঘরে জায়গা %.2fmm', $cell['text'], $ink, $room);
            }
        }

        $this->assertSame([], $tooWide, "ক্লাসিক বিলে অঙ্ক ঘর ছাড়িয়ে গেছে:\n".implode("\n", $tooWide));
    }

    /**
     * ⭐ রোলে কখনো A4-এর ক্লাসিক নয় — থার্মালের নিজের তালিকার নকশা (৩০ সেপ্টেম্বর ২০২৬ থেকে প্রতিটা মাপের
     * নিজের বাছাই; আগে রোলে সবসময় চলতি রসিদ ছাপত)।
     */
    public function test_a_roll_paper_never_gets_the_a4_classic_even_when_classic_is_chosen(): void
    {
        $this->choose('classic_table');

        $thermal = \App\Modules\Sales\Support\PaperDesigns::template('invoice', 'thermal',
            \App\Modules\Sales\Support\PaperDesigns::defaultFor('invoice', 'thermal'));
        $this->assertNotNull($thermal);

        $drawn = [];
        foreach ([self::CLASSIC, $thermal] as $view) {
            View::composer($view, function () use (&$drawn, $view) {
                $drawn[] = $view;
            });
        }

        $this->actingAs($this->user)
            ->get(route('sales.print.invoice', $this->anInvoice(qty: '1', rate: '100.00')).'?paper=80mm')
            ->assertOk();

        $this->assertNotContains(self::CLASSIC, $drawn, '৮০মিমি রোলে তিন কলামের ক্লাসিক কাগজ গেছে — রোলে ওটা ধরে না।');
        $this->assertContains($thermal, $drawn, 'রোলে থার্মালের নিজের নকশা আঁকা হয়নি।');
    }

    /**
     * ⭐ দ্বিতীয় কপিতে DUPLICATE — তবে নমুনার মতো কোণে ছোট ছাপ, বড় বাক্স নয়।
     */
    public function test_the_second_print_still_says_duplicate(): void
    {
        $invoice = $this->anInvoice();

        $this->printed($invoice);
        $html = $this->printed($invoice)['html'];

        $this->assertStringContainsString('data-duplicate', $html,
            'ক্লাসিক নকশায় দ্বিতীয় কপিতে DUPLICATE নেই — দুইটা একরকম কাগজ ঘুরবে।');
        $this->assertStringNotContainsString('class="notice"', $html,
            'DUPLICATE আবার বড় বাক্সে — মালিক কোণে ছোট ছাপ চেয়েছেন।');
    }

    /**
     * ⭐ বাতিল বিল ক্লাসিক নকশাতেও বড় করে "বাতিল" বলে — DUPLICATE-এর মতো ছোট ছাপ নয়।
     *
     * ⛔ বাতিল কাগজ দেখিয়ে মাল বা টাকা চাওয়া যেত ([[ACancelledPaperLooksValidTest]] চলতি নকশা মাপে)।
     */
    public function test_a_cancelled_invoice_says_so_in_a_box(): void
    {
        $invoice = $this->cancelledBeforeTheRule($this->anInvoice(qty: '1', rate: '100.00'));

        $html = $this->printed($invoice)['html'];

        $this->assertStringContainsString('class="notice"', $html, 'বাতিল বিলের গায়ে সতর্কবার্তার বাক্স নেই।');
        $this->assertStringContainsString(e(__('core.print.cancelled_notice')), $html);
    }

    /**
     * ⭐ "Payment Method" ফাঁকা নয় — লাইভে ফাঁকা ছাপা হচ্ছিল (২৯ সেপ্টেম্বর ২০২৬)।
     *
     * ⓘ ঠিক সেই অবস্থা বানানো: জমার ভাউচারে `money_account_id` খালি, পদ্ধতিও নেই।
     * ⭐ তখনো পথটা ভাউচারের নিজের নগদ/ব্যাংক সারি থেকে আসে।
     */
    public function test_a_deposit_prints_how_the_money_came(): void
    {
        $invoice = $this->anInvoice(qty: '1', rate: '5000.00');
        $voucher = $this->aReceiptVoucherFor($invoice, '2000');

        $voucher->forceFill(['money_account_id' => null, 'instrument' => null])->saveQuietly();

        $html = $this->printed($invoice)['html'];

        $this->assertMatchesRegularExpression('/data-method>\s*[^<\s][^<]*</u', $html,
            'Payment Method ঘর ফাঁকা ছাপা হলো।');
        $this->assertStringContainsString(e($this->cashAccount()->name()), $html);
    }

    /**
     * ⭐ ছাপা PDF-এর নিজের লেখা — HTML নয়, কাগজ (`pdftotext` থাকলে)।
     *
     * ⚠️ mPDF লেখা চাপা দেয়, তাই PDF-এর বাইট খুঁজে কিছু পাওয়া যায় না; `pdftotext` না থাকলে
     * দাবিটা কারণ বলে থামে — ভান করে সবুজ হয় না।
     */
    public function test_the_printed_pdf_carries_the_sample_words(): void
    {
        $bin = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where pdftotext 2>NUL' : 'command -v pdftotext 2>/dev/null'));
        $bin = strtok($bin, "\r\n") ?: '';

        $invoice = $this->anInvoice();
        $pdf = $this->actingAs($this->user)->get(route('sales.print.invoice', $invoice))->assertOk()->getContent();

        $this->assertStringStartsWith('%PDF', (string) $pdf, 'ছাপার পথ PDF দেয়নি।');

        /*
         * ⓘ `pdftotext` না থাকলে লেখা পড়া যায় না (mPDF অক্ষর চাপা দেয়) — তখন কেবল এটুকু:
         * সত্যিকারের একটা PDF বেরিয়েছে। ⚠️ "এড়িয়ে যাওয়া দাবি" বাড়ানো হয়নি
         * ([[TheNumberOfSkippedClaimsOnlyGoesDown]]); লেখার বাকি দাবি HTML মাপে।
         */
        if ($bin === '') {
            return;
        }

        $file = tempnam(sys_get_temp_dir(), 'inv').'.pdf';
        file_put_contents($file, $pdf);
        $text = (string) shell_exec(escapeshellarg($bin).' -layout '.escapeshellarg($file).' -');
        @unlink($file);

        foreach (['INVOICE', 'Bill To,', 'Net Payable Amount', 'Outstanding (', 'Paid - Received Into Accounts', '(BDT)', $invoice->document_no] as $expected) {
            $this->assertStringContainsString($expected, $text, "ছাপা PDF-এ নেই: {$expected}");
        }
    }

    public function test_a_business_can_write_its_own_red_line(): void
    {
        $this->choose('classic_table');
        app(SettingsService::class)->set('sales.print.invoice_footnote', 'ফেরত কেবল সাত দিনের মধ্যে');

        $html = $this->printed($this->anInvoice())['html'];

        $this->assertStringContainsString('ফেরত কেবল সাত দিনের মধ্যে', $html);
        $this->assertStringNotContainsString(e(__('sales::print.classic.footnote')), $html);
    }

    /**
     * ⭐ আদায়ের ছক কাগজে ওঠে — দুই নকশাতেই।
     *
     * ⛔ ২৮ সেপ্টেম্বরের আগে চলতি নকশাতেও উঠত না (উপরের মন্তব্য)।
     */
    public function test_the_deposits_reach_the_printed_paper_in_both_designs(): void
    {
        /*
         * ⓘ চলতি নকশার `standard` রূপে ছকটা ইচ্ছা করেই বন্ধ (`paid_table` নেই —
         * [[PrintFormat]]); মালিকের নমুনার রূপ `distributor`-এ চালু। ⚠️ তাই
         * চলতি নকশা মাপা হয় ঐ রূপে — যেখানে ছকটা আসার কথা।
         */
        app(SettingsService::class)->set('print.invoice.format', 'distributor');

        foreach (['standard', 'classic_table'] as $design) {
            $this->choose($design);
            $invoice = $this->anInvoice(qty: '1', rate: '5000.00');
            $this->aCollectionOf($invoice, '1500.0000', 'COL-'.strtoupper($design));

            $html = $this->printed($invoice)['html'];

            $this->assertStringContainsString('COL-'.strtoupper($design), $html,
                "{$design}: জমাটা বিলের মোটে গোনা হয়েছে, অথচ আদায়ের ছকে নেই।");
        }
    }

    public function test_a_copy_of_the_paper_keeps_its_deposits(): void
    {
        $row = ['no' => 1, 'ref' => 'COL-1', 'date' => '01/01/2026', 'method' => 'নগদ', 'narration' => '', 'amount' => '10.00'];
        $doc = new PrintableDocument(title: 'বিল', payments: [$row]);

        $this->assertSame([$row], $doc->withWordsFor('10', 'bn')->payments, 'কথায় অঙ্ক বসানোর কপিতে জমা হারাল।');
        $this->assertSame([$row], $doc->withNotice('DUPLICATE')->payments, 'সতর্কবার্তার কপিতে জমা হারাল।');
    }

    // ── যন্ত্রপাতি ─────────────────────────────────────────────────────

    private function aReceiptVoucherFor(SalesInvoice $invoice, string $amount): \App\Modules\Accounts\Models\Voucher
    {
        $service = app(\App\Modules\Accounts\Services\VoucherService::class);
        $receivable = \App\Modules\Accounts\Models\Account::query()->postable()
            ->where('code', \App\Modules\Accounts\Services\StandardChart::RECEIVABLE)->firstOrFail();

        $voucher = $service->create(
            [
                'type' => \App\Modules\Accounts\Models\Voucher::RECEIPT,
                'trx_date' => now()->toDateString(),
                'party_type' => 'customer',
                'party_id' => $invoice->customer_id,
                'narration' => 'Deposit against the bill',
                'against_type' => SalesInvoice::drillSourceType(),
                'against_id' => $invoice->id,
            ],
            $service->twoLineEntry(\App\Modules\Accounts\Models\Voucher::RECEIPT, (int) $receivable->id, (int) $this->cashAccount()->id, $amount, 'Deposit'),
        );

        return $service->post($voucher);
    }

    private function cashAccount(): \App\Modules\Accounts\Models\Account
    {
        return \App\Modules\Accounts\Models\Account::query()->postable()
            ->ofMoneyKind(\App\Modules\Accounts\Models\Account::CASH)->orderBy('id')->firstOrFail();
    }

    private function choose(string $design): void
    {
        app(SettingsService::class)->set('sales.print.design.invoice', $design);
    }

    private function anInvoice(string $qty = self::QTY, string $rate = self::RATE): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create(
            [
                'customer_id' => Customer::query()->firstOrFail()->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->firstOrFail()->id, 'qty' => $qty, 'rate' => $rate]],
        ));
    }

    /**
     * ছাপার পাতা — আসল পথ ধরে, আর কোন ছাঁচ আঁকল সেটাও।
     *
     * ⚠️ কম্পোজারের ভিতরে ভিউ আবার বানানো যায় না (অসীম পুনরাবৃত্তি —
     * [[NoPrintedFigureOverflowsItsColumnTest]]-এ লেখা), তাই ডেটা ধরে পরে আঁকা।
     *
     * @return array{view: string, html: string}
     */
    private function printed(SalesInvoice $invoice, ?string $paper = null): array
    {
        $seen = null;

        foreach ([self::CLASSIC, self::STANDARD] as $name) {
            View::composer($name, function ($view) use (&$seen, $name) {
                $seen ??= ['view' => $name, 'data' => $view->getData()];
            });
        }

        $response = $this->actingAs($this->user)
            ->get(route('sales.print.invoice', $invoice).($paper === null ? '' : '?paper='.$paper))
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'),
            'কাগজ PDF হয়ে বেরোয়নি — mPDF ছাঁচটা আঁকতে পারেনি।');

        $this->assertNotNull($seen, 'কোনো ছাপার ছাঁচই আঁকা হয়নি।');

        $previous = app()->getLocale();
        app()->setLocale($seen['data']['locale'] ?? $previous);

        try {
            $html = View::make($seen['view'], $seen['data'])->render();
        } finally {
            app()->setLocale($previous);
        }

        return ['view' => $seen['view'], 'html' => $html];
    }

    private function aCollectionOf(SalesInvoice $invoice, string $amount, string $no): void
    {
        $collection = Collection::query()->create([
            'branch_id' => $invoice->branch_id,
            'document_no' => $no,
            'customer_id' => $invoice->customer_id,
            'account_id' => Account::query()->postable()->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
            'status' => DocumentStatus::CONFIRMED,
            'narration' => 'হাতে নগদ',
        ]);

        CollectionLine::query()->create([
            'collection_id' => $collection->id,
            'line_no' => 1,
            'sales_invoice_id' => $invoice->id,
            'amount' => $amount,
        ]);
    }

    /**
     * টাকার প্রতিটা ঘর আর তার চওড়া — ঘরে লেখা থাকলে ঘর থেকে, নয়তো ঐ
     * কলামের শিরোনাম থেকে।
     *
     * @return list<array{text: string, width: float}>
     */
    private function moneyCells(string $html): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $cells = [];

        foreach ($xpath->query('//table') ?: [] as $table) {
            $widths = [];

            foreach ($xpath->query('./thead/tr/th', $table) ?: [] as $i => $th) {
                $widths[$i] = $this->widthOf($th);
            }

            foreach ($xpath->query('./tbody/tr|./tr', $table) ?: [] as $row) {
                foreach ($xpath->query('./td', $row) ?: [] as $i => $td) {
                    if (! $td instanceof DOMElement || ! str_contains($td->getAttribute('class'), 'num')) {
                        continue;
                    }

                    $text = trim($td->textContent);
                    $width = $this->widthOf($td) ?? ($widths[$i] ?? null);

                    // ⓘ ক্রমিক নম্বর টাকা নয় — কেবল দশমিক-ওয়ালা অঙ্ক মাপা হয়
                    if ($width === null || ! str_contains($text, '.')) {
                        continue;
                    }

                    $cells[] = ['text' => $text, 'width' => $width];
                }
            }
        }

        $this->assertNotSame([], $cells, 'একটাও টাকার ঘর পাওয়া গেল না — পাহারাটা কিছুই মাপছে না।');

        return $cells;
    }

    private function widthOf(\DOMNode $node): ?float
    {
        if (! $node instanceof DOMElement) {
            return null;
        }

        return preg_match('/width:\s*([\d.]+)mm/', $node->getAttribute('style'), $m) === 1
            ? (float) $m[1]
            : null;
    }

    /** পাতার `<style>` থেকে একটা মান; `second` মানে `padding: a b`-র দ্বিতীয়টা (ডানে-বাঁয়ে) */
    private function styleValue(string $html, string $selector, string $property, string $unit, bool $second = false): ?float
    {
        $value = $second ? '[\d.]+'.$unit.'\s+([\d.]+)' : '([\d.]+)';

        return preg_match('/'.$selector.'\s*\{[^}]*'.$property.'\s*:\s*'.$value.$unit.'/', $html, $m) === 1
            ? (float) $m[1]
            : null;
    }

    /** ⓘ ফন্টের বিন্যাস ইঞ্জিন থেকেই — হাতে লিখলে পাহারাটা অন্য ফন্ট মাপত */
    private function inkWidth(string $text, float $fontSize): float
    {
        static $mpdf = null;

        if ($mpdf === null) {
            $engine = app(PrintEngine::class);
            $call = fn (string $name) => (new ReflectionMethod($engine, $name))->invoke($engine);

            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'default_font' => 'hindsiliguri',
                'tempDir' => storage_path('framework/cache'),
                'fontDir' => $call('fontDirs'),
                'fontdata' => $call('fontData'),
            ]);
        }

        $mpdf->SetFont('dejavusans', '', $fontSize);

        return $mpdf->GetStringWidth($text);
    }

    /**
     * ⓘ ২ অক্টোবর ২০২৬-এর আগে নিশ্চিত বিল বাতিল করা যেত — সেই কাগজগুলো খাতায় আছে, আর ছাপলে "বাতিল" বলতেই হয়।
     * এখন সেবা আর বাতিল করে না ([[SalesInvoiceService::cancel()]]), তাই সেই পুরনো অবস্থাটা সরাসরি বসানো।
     */
    private function cancelledBeforeTheRule(SalesInvoice $invoice): SalesInvoice
    {
        $invoice->forceFill([
            'status' => DocumentStatus::CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason' => 'ভুল দামে কাটা হয়েছিল',
        ])->save();

        return $invoice->fresh();
    }
}
