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
        Customer::query()->firstOrFail()->forceFill(['credit_limit' => '0'])->save();
    }

    public function test_the_standard_design_stays_the_default(): void
    {
        $seen = $this->printed($this->anInvoice());

        $this->assertSame(self::STANDARD, $seen['view'],
            'সেটিং না ছুঁয়েই বিলের নকশা বদলে গেছে — চলতি কাগজ নিজে থেকে বদলানোর কথা নয়।');
    }

    public function test_the_classic_design_prints_the_real_bill_when_chosen(): void
    {
        $this->choose('classic_table');
        $invoice = $this->anInvoice();

        $seen = $this->printed($invoice);

        $this->assertSame(self::CLASSIC, $seen['view'], 'ক্লাসিক বাছার পরেও চলতি নকশা ছাপা হলো।');

        $html = $seen['html'];

        foreach ([
            __('sales::print.classic.heading'),
            __('sales::print.classic.bill_to'),
            __('sales::print.classic.transport'),
            $invoice->document_no,
            $invoice->customer->name(),
            self::AMOUNT,
            __('sales::print.classic.net_payable'),
            __('sales::print.classic.total_due'),
            __('sales::print.classic.received_by'),
            __('sales::print.classic.prepared_by'),
            __('sales::print.classic.approved_by'),
            __('sales::print.classic.footnote'),
            $this->user->name,
        ] as $expected) {
            $this->assertStringContainsString(e($expected), $html, "ক্লাসিক কাগজে নেই: {$expected}");
        }

        $this->assertStringNotContainsString(e(__('core.print.duplicate_notice')), $html,
            'প্রথম ছাপাতেই DUPLICATE বসেছে।');
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

    public function test_a_roll_paper_keeps_the_counter_receipt_even_when_classic_is_chosen(): void
    {
        $this->choose('classic_table');

        $seen = $this->printed($this->anInvoice(qty: '1', rate: '100.00'), paper: '80mm');

        $this->assertSame(self::STANDARD, $seen['view'],
            '৮০মিমি রোলে তিন কলামের ক্লাসিক কাগজ গেছে — রোলে ওটা ধরে না।');
    }

    public function test_the_second_print_still_says_duplicate(): void
    {
        $this->choose('classic_table');
        $invoice = $this->anInvoice();

        $this->printed($invoice);
        $html = $this->printed($invoice)['html'];

        $this->assertStringContainsString(e(__('core.print.duplicate_notice')), $html,
            'ক্লাসিক নকশায় দ্বিতীয় কপিতে DUPLICATE নেই — দুইটা একরকম কাগজ ঘুরবে।');
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
}
