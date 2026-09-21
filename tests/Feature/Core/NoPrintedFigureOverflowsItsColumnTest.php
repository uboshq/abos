<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
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
 * ছাপা কোনো অঙ্ক নিজের ঘর ছাড়িয়ে যায় না।
 *
 * ── ⛔ কেন এই পাহারাটা দরকার ছিল ──────────────────────────────────────
 * [[SalesPrintTest]]-এর মাথায় সোজা লেখা আছে সে কী প্রমাণ করে **না**:
 * *"PDF তৈরি হয়েছে দেখে বোঝা যায় না ... ডান দিকের অঙ্ক কেটে গেছে কি না —
 * ওটা চোখে দেখেই ধরতে হয়।"* ⓘ কথাটা সৎ ছিল, আর ঠিক সেই ফাঁক দিয়েই
 * মালিকের সবচেয়ে পুরনো অভিযোগটা মাসের পর মাস টিকে ছিল।
 *
 * ⚠️ উপচে পড়া **নীরব**: `.num`-এ `white-space: nowrap`, তাই সংখ্যাটা ঘর
 * ছাড়িয়ে পাশের ঘরে উঠে যায়, কিছু ভাঙে না, কোনো ত্রুটি ওঠে না, আর PDF-টা
 * তৈরি হয়ে যায়। ⓘ চোখ ছাড়া ধরার উপায় ছিল না।
 *
 * ── ⭐ কিন্তু চোখই একমাত্র উপায় নয় ───────────────────────────────────
 * mPDF-এর `GetStringWidth()` ঠিক ঐ প্রশ্নটার উত্তর দেয়: এই ফন্টে, এই
 * মাপে, এই লেখাটা কত মিলিমিটার। ⓘ ঘরের চওড়াও HTML-এই লেখা আছে। অর্থাৎ
 * "কেটে গেছে কি না" আসলে **মাপা যায়** — কেবল কেউ মাপেনি।
 *
 * ── ⓘ সংখ্যাগুলো অনুমান নয় ───────────────────────────────────────────
 * ২১ সেপ্টেম্বর ২০২৬-এ হাজারের কমা লাখ-কোটি হলো (মালিকের বাছাই), আর
 * তাতে প্রতিটা অঙ্ক লম্বা হলো। ⭐ নিচের [[HOLDS]] তালিকাটা হলো **মালিককে
 * যা বলা হয়েছে** — কোন কাগজ কত বড় অঙ্ক ধরে। এটা একটা প্রতিশ্রুতি, আর
 * এই পরীক্ষাটা প্রতিশ্রুতিটা রাখা হচ্ছে কি না দেখে।
 *
 * ⚠️ কেউ ফন্ট, মাপ, প্যাডিং বা ঘরের চওড়া বদলালে হয় প্রতিশ্রুতিটা টেকে,
 * নয় এটা লাল হয় — আর তখন নতুন সীমাটা এখানে লিখে মালিককে জানাতে হয়।
 *
 * ── ⭐ ভেঙে দেখা হয়েছে, শুধু সবুজ দেখে ছাড়া হয়নি ─────────────────────
 * ⓘ [[print/layout]]-এ `td.num`-এর মাপ ৩পয়েন্ট বাড়িয়ে চালানো হয়েছিল, আর
 * এটা এগারোটা ঘর ধরে লাল হয়েছে — A4-তে `12,31,87,500.00` ২৯.১৭ থেকে
 * ৩৭.৯২মিমি হয়ে গেছে। ⭐ অর্থাৎ মাপটা সত্যিই **পাতা থেকে** পড়া হচ্ছে।
 *
 * ⛔ এটা যাচাই না করলে পাহারাটা `PaperSize`-এর পুরনো মাপে মেপে সবুজ
 * থাকত আর কাগজে অঙ্ক উপচাত — ঠিক যে ভুলটা ২১ সেপ্টেম্বর ধরা পড়েছে।
 */
final class NoPrintedFigureOverflowsItsColumnTest extends TestCase
{
    use RefreshDatabase;

    /**
     * কোন কাগজ কত বড় অঙ্ক ধরে — মালিককে বলা কথা।
     *
     * ⓘ থার্মালে A4-এর মতো জায়গা নেই: ৮০mm-এ পণ্যের নামের ঘর ১৭mm,
     * ৫৮-তে ১৪mm। ⛔ ওর বেশি চাপলে পণ্যটাই চেনা যাবে না, তাই সীমাটা
     * মেনে নেওয়া হয়েছে, লুকানো হয়নি।
     *
     * ⚠️ সংখ্যাটা `qty × rate`, কারণ দরের ঘরটা টাকার ঘরের চেয়ে সরু —
     * পুরোটা দরে বসালে দর উপচাত, আর পরীক্ষাটা ভুল জিনিস মাপত।
     *
     * ⓘ পরিমাণগুলো ছোট (২ + ১০ + ২০), আর সেটা ইচ্ছাকৃত: ডেমো ডেটায়
     * পণ্যটার বিক্রয়যোগ্য মজুদ ৭০, আর তিনটা কাগজ তিনটা আলাদা বিল কাটে।
     * ⛔ বড় পরিমাণ দিলে তৃতীয় বিলটা মজুদের সীমায় আটকে যেত — আর তখন
     * পরীক্ষাটা লাল হত এমন কারণে যার ছাপার সাথে কোনো সম্পর্ক নেই।
     *
     * @var array<string, array{qty: string, rate: string, amount: string}>
     */
    private const HOLDS = [
        // ১২ কোটি
        PaperSize::A4 => ['qty' => '2', 'rate' => '6,15,93,750.00', 'amount' => '12,31,87,500.00'],

        // এক লাখ
        PaperSize::THERMAL_80 => ['qty' => '10', 'rate' => '12,345.60', 'amount' => '1,23,456.00'],

        // ১২ লাখ
        PaperSize::THERMAL_58 => ['qty' => '20', 'rate' => '61,728.35', 'amount' => '12,34,567.00'],
    ];

    private User $user;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($this->user);

        $this->customer = Customer::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->firstOrFail();
    }

    /**
     * প্রতিটা কাগজে, প্রতিশ্রুত অঙ্কটা বসিয়ে, প্রতিটা ঘর মেপে দেখা।
     *
     * ⓘ ছোট অঙ্ক দিয়ে মাপলে পরীক্ষাটা চিরকাল সবুজ থাকত আর কিছুই দেখত
     * না — তাই বিপজ্জনক সংখ্যাটাই খাওয়ানো হয়।
     */
    public function test_no_figure_runs_past_the_edge_of_its_column(): void
    {
        $tooWide = [];

        foreach (self::HOLDS as $paper => $figures) {
            $size = PaperSize::of($paper);

            $html = $this->htmlFor($paper, $figures);

            /*
             * ⚠️ মাপ আর প্যাডিং পাতাটা থেকেই পড়া হয়, `PaperSize` থেকে নয়।
             *
             * ⛔ প্রথমে `$size->fontSize` ব্যবহার করা হয়েছিল, আর সেটা একটা
             * **নীরব অনুমান**: ধরে নেওয়া হচ্ছিল ব্লেডটা ঐ মাপটাই বসায়।
             * কেউ [[print/layout]]-এ `td.num`-এর মাপ বদলালে কাগজে সংখ্যা
             * বড় হয়ে উপচাত, অথচ পাহারাটা পুরনো মাপে মেপে সবুজ থাকত —
             * অর্থাৎ ঠিক যে শ্রেণির ভুল ধরার জন্য এটা লেখা, সেটাই।
             *
             * ⓘ এখন পাতার নিজের `<style>` পড়া হয়। তাই ওখানকার স্পষ্ট
             * `td.num { font-size }` লাইনটা আর কেবল মন্তব্য নয় — এই
             * পাহারাটা ওটার উপরেই দাঁড়িয়ে।
             */
            $fontSize = $this->styleValue($html, 'td\.num', 'font-size', 'pt')
                ?? $size->fontSize;

            $padding = $this->paddingFrom($html) ?? ($size->isThermal ? 0.5 : 2.0);

            foreach ($this->cellsOf($html) as $cell) {
                $room = $cell['width'] - 2 * $padding;
                $ink = $this->inkWidth($cell['text'], $fontSize);

                if ($ink > $room) {
                    $tooWide[] = sprintf(
                        '%s: "%s" %.2fmm, কিন্তু ঘরে জায়গা %.2fmm (ঘর %.1fmm, প্যাডিং বাদে)',
                        $paper, $cell['text'], $ink, $room, $cell['width'],
                    );
                }
            }
        }

        $this->assertSame([], $tooWide, implode(PHP_EOL, [
            'ছাপা অঙ্ক নিজের ঘর ছাড়িয়ে গেছে:',
            '',
            implode(PHP_EOL, $tooWide),
            '',
            '⛔ কাগজে এটা ত্রুটি হয়ে ওঠে না — সংখ্যাটা চুপচাপ পাশের ঘরে',
            'উঠে যায়, আর PDF-টা তৈরি হয়ে যায়। মালিক কাগজ হাতে পেয়ে',
            'জানেন, আমরা নয়।',
            '',
            'হয় ঘরটা চওড়া করুন (print/document-body.blade.php), নয়',
            'এই ফাইলের HOLDS তালিকায় নতুন সীমাটা লিখে মালিককে জানান।',
        ]));
    }

    // ── মাপার যন্ত্রপাতি ─────────────────────────────────────────────

    /**
     * একটা কাগজের ছাপা HTML — আসল পথ ধরেই।
     *
     * ⚠️ ব্লেডটা হাতে রেন্ডার করা হয় না: [[PrintEngine]] নিজে `company`,
     * `paper`, `locale` ও `settings` জুড়ে দেয়, আর ভাষাও সাময়িক বদলায়।
     * হাতে করলে পরীক্ষাটা এমন একটা পাতা মাপত যেটা কেউ কোনোদিন ছাপে না।
     *
     * @param  array{qty: string, rate: string, amount: string}  $figures
     */
    private function htmlFor(string $paper, array $figures): string
    {
        $invoice = app(SalesInvoiceService::class)->confirm(
            app(SalesInvoiceService::class)->create(
                ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id,
                    'trx_date' => now()->toDateString()],
                [[
                    'product_id' => $this->product->id,
                    'qty' => $figures['qty'],
                    'rate' => str_replace(',', '', $figures['rate']),
                ]],
            )
        );

        /*
         * ⛔ কম্পোজারের **ভিতরে** ভিউটা আবার বানানো যায় না।
         *
         * ⚠️ প্রথমে ঠিক সেটাই লেখা হয়েছিল, আর তাতে `View::make` কম্পোজারটা
         * আবার ডাকত, সে আবার `View::make`... রানটা ৫১২MB স্মৃতি শেষ করে
         * মারা গেল। ⓘ তাই এখানে কেবল **ডেটাটা** ধরা হয়, আঁকা হয় পরে।
         */
        $data = null;

        View::composer('print.document', function ($view) use (&$data) {
            $data = $view->getData();
        });

        $this->actingAs($this->user)
            ->get(route('sales.print.invoice', $invoice).'?paper='.$paper)
            ->assertOk();

        $this->assertNotNull($data, "{$paper}: ছাপার পাতাটা ধরা গেল না");

        /*
         * ⓘ ভাষাটা ডকুমেন্টের সাথেই আসে — [[PrintEngine]] ছাপার সময়
         * সাময়িকভাবে ওটাই বসায়। এখানে না বসালে পাতাটা অন্য ভাষায় আঁকা
         * হত, আর `__()` থেকে আসা শিরোনামগুলো অন্য চওড়া পেত।
         */
        $previous = app()->getLocale();
        app()->setLocale($data['locale'] ?? $previous);

        try {
            return View::make('print.document', $data)->render();
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * সংখ্যার প্রতিটা ঘর, তার লেখা আর তার চওড়া।
     *
     * ── ⚠️ চওড়াটা ঘরে লেখা থাকে না, শিরোনামে থাকে ───────────────────
     * `<td class="num">` খালি — মাপটা বসানো `<th class="num" style="width:">`-এ,
     * আর ঘরটা কলামের ক্রম ধরে সেটা পায়। ⓘ তাই আগে শিরোনামের সারি পড়ে
     * কলামের মাপগুলো নেওয়া হয়, তারপর সারির ঘরগুলো ক্রম মিলিয়ে।
     *
     * ⓘ যোগফলের টেবিলে মাপটা `<td>`-তেই লেখা — দুইটা ক্ষেত্রেই ধরা হয়।
     *
     * @return list<array{text: string, width: float}>
     */
    private function cellsOf(string $html): array
    {
        $dom = new DOMDocument;

        // ⓘ ব্লেডটা একটা টুকরো, পুরো নথি নয় — libxml-এর অভিযোগ চাপা
        // দেওয়া হয়, নাহলে প্রতিটা রানে শ'খানেক সতর্কবার্তা উঠত।
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $cells = [];

        foreach ($xpath->query('//table') ?: [] as $table) {
            $widths = [];

            foreach ($xpath->query('.//thead//th', $table) ?: [] as $i => $th) {
                $widths[$i] = $this->widthOf($th);
            }

            foreach ($xpath->query('.//tr', $table) ?: [] as $row) {
                $i = -1;

                foreach ($xpath->query('./td', $row) ?: [] as $td) {
                    $i++;

                    if (! $td instanceof DOMElement || ! str_contains($td->getAttribute('class'), 'num')) {
                        continue;
                    }

                    $text = trim($td->textContent);
                    $width = $this->widthOf($td) ?? ($widths[$i] ?? null);

                    if ($text === '' || $width === null) {
                        continue;
                    }

                    $cells[] = ['text' => $text, 'width' => $width];
                }
            }
        }

        $this->assertNotSame([], $cells, implode(PHP_EOL, [
            'একটাও সংখ্যার ঘর পাওয়া গেল না।',
            '',
            'তাহলে এই পাহারাটা কিছুই মাপছে না — আর সবুজ থাকছে ঠিক সেই',
            'কারণে যেটা সবচেয়ে খারাপ: মাপার মতো কিছু নেই বলে।',
        ]));

        return $cells;
    }

    /** `style="width: 36mm"` থেকে মিলিমিটারটা। */
    private function widthOf(\DOMNode $node): ?float
    {
        if (! $node instanceof DOMElement) {
            return null;
        }

        return preg_match('/width:\s*([\d.]+)mm/', $node->getAttribute('style'), $m) === 1
            ? (float) $m[1]
            : null;
    }

    /**
     * পাতার নিজের `<style>` থেকে একটা মান।
     *
     * ⓘ ব্লেডটা রেন্ডার হওয়ার পর CSS-টা সাধারণ লেখা — `{{ }}`গুলো ততক্ষণে
     * সংখ্যা হয়ে গেছে। তাই যা কাগজে যাবে ঠিক তা-ই পড়া হয়।
     *
     * @param  string  $selector  রেগুলার এক্সপ্রেশনের জন্য escape করা
     */
    private function styleValue(string $html, string $selector, string $property, string $unit): ?float
    {
        $pattern = '/'.$selector.'\s*\{[^}]*'.$property.'\s*:\s*([\d.]+)'.$unit.'/';

        return preg_match($pattern, $html, $m) === 1 ? (float) $m[1] : null;
    }

    /**
     * `table.lines td`-র প্যাডিং, এক পাশে।
     *
     * ⓘ `padding: 1.5mm 2mm` — দ্বিতীয় সংখ্যাটা ডানে-বাঁয়ে, আর ঘরের
     * ভিতরের জায়গা ঐটুকুই কমে। ⚠️ প্রথমটা ধরলে A4-তে ১.৫ ধরা হত আর
     * প্রতিটা ঘরকে ১মিমি বেশি চওড়া মনে হত।
     */
    private function paddingFrom(string $html): ?float
    {
        $pattern = '/table\.lines\s+td\s*\{[^}]*padding\s*:\s*[\d.]+mm\s+([\d.]+)mm/';

        return preg_match($pattern, $html, $m) === 1 ? (float) $m[1] : null;
    }

    /**
     * লেখাটা কাগজে কত চওড়া।
     *
     * ── ⚠️ ফন্টের বিন্যাসটা ইঞ্জিন থেকেই নেওয়া, হাতে লেখা নয় ─────────
     * ⛔ এখানে ফন্টের তালিকাটা নকল করে লিখলে দুইটা জায়গা আলাদা হয়ে
     * যেতে পারত, আর তখন এই পাহারাটা **অন্য একটা ফন্ট** মাপত — ঠিক যে
     * শ্রেণির ভুল ধরার জন্য এটা লেখা, সেটাই।
     *
     * ⓘ তাই ব্যক্তিগত পদ্ধতি দুইটা প্রতিফলন দিয়ে ডাকা হয়। মাপার যন্ত্র
     * যা মাপে তার সাথে একমত থাকাই এখানে গোপনীয়তার চেয়ে জরুরি।
     */
    private function inkWidth(string $text, float $fontSize): float
    {
        static $mpdf = null;

        if ($mpdf === null) {
            $engine = app(PrintEngine::class);

            $call = function (string $name) use ($engine) {
                $method = new ReflectionMethod($engine, $name);
                $method->setAccessible(true);

                return $method->invoke($engine);
            };

            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'default_font' => 'hindsiliguri',
                'tempDir' => storage_path('framework/cache'),
                'fontDir' => $call('fontDirs'),
                'fontdata' => $call('fontData'),
            ]);
        }

        // ⓘ `td.num`-এ DejaVu — অঙ্কগুলো সমান চওড়া বলে ([[print/layout]]-এ
        // কারণ লেখা)। হেডারে নয়, আর হেডার এখানে মাপাও হয় না।
        $mpdf->SetFont('dejavusans', '', $fontSize);

        return $mpdf->GetStringWidth($text);
    }
}
