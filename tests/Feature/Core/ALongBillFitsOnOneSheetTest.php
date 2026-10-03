<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Engines\Print\PrintScale;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\DocumentDelivery;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\PrintJob;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * লম্বা বিল A4-এর দুই পাতায় যেত — মালিকের বাছাই (খ), ১ অক্টোবর ২০২৬ ([[PrintScale]])।
 *
 * ── ⛔ আগে ─────────────────────────────────────────────────────────────
 * ২০–২৫ সারির বিল দুই পাতায়, আর ব্রাউজারের "Scale 50%" দুইটা PDF পাতা এক করতে পারে না।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * `?scale=50..200` — লেখার মাপ, কাগজের নয়; না দিলে একটু উপচানো বিল নিজেই এক পাতায় (৯৫ → ৬০)। কাগজটা কাল্পনিক
 * পাতায় আঁকা হয়ে আসল কাগজে মাপমতো বসে ([[PrintEngine::placed()]]) — লেখা লেখাই থাকে, বাংলা সহ।
 */
final class ALongBillFitsOnOneSheetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        // ⓘ নিজের ছোট ছাঁচ — সারির সংখ্যা হাতে, যাতে "কয় পাতা" প্রশ্নটা নকশার উপর নির্ভর না করে
        $dir = storage_path('framework/testing/scale-views');
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/bill.blade.php', <<<'BLADE'
            <h1>ক্রয় বিল SCALE-0001</h1>
            <table width="100%" border="1" cellpadding="6">
                <thead><tr><th>SL</th><th>পণ্য</th><th>Qty</th></tr></thead>
                <tbody>
                @for ($i = 1; $i <= $rows; $i++)
                    <tr><td>{{ $i }}</td><td>বিস্কুট সারি {{ $i }}</td><td>12.5</td></tr>
                @endfor
                </tbody>
            </table>
            BLADE);
        View::addNamespace('scaletest', $dir);
    }

    /** ⭐ এক পাতার বিল — ১০০%, এক পাতা; কিছুই বদলায় না। */
    public function test_a_bill_that_fits_is_left_alone(): void
    {
        $pdf = $this->bill(10);

        $this->assertSame(1, $this->pages($pdf));
        $this->assertSame(100, app(PrintScale::class)->used());
    }

    /**
     * ⛔→⭐ একটু উপচানো বিল — স্বয়ংক্রিয়ভাবে এক পাতায়, ৬০–৯৫%-এর মধ্যে; আর স্পষ্ট ১০০%-এও এক পাতায়।
     *
     * ⓘ মালিক, ৩ অক্টোবর ২০২৬: *"print scale custom korle zate dui pristha ek pataay print hoy"* — ১০০ বা তার কম স্পষ্ট
     * মাপে কাগজ উপচালে আরও ছোট হয় ([[ATwentyFiveLineBillFitsOneSheetTest]])। ⛔ আগে এখানে দাবি ছিল "স্পষ্ট ১০০% = দুই পাতা"।
     */
    public function test_a_bill_that_spills_a_little_fits_by_itself(): void
    {
        $rows = $this->rowsForAboutOneAndAThirdPages();

        $this->assertSame(1, $this->pages($this->bill($rows)), '⛔ একটু উপচানো বিল এখনো দুই পাতায়।');
        $this->assertTrue(app(PrintScale::class)->wasAuto());
        $this->assertGreaterThanOrEqual(PrintScale::AUTO_FLOOR, app(PrintScale::class)->used());
        $this->assertLessThan(100, app(PrintScale::class)->used());

        // ⭐ স্পষ্ট ১০০%-এ উপচালেও এক পাতা — চাওয়া মাপ থেকে নামা, তাই স্বয়ংক্রিয় বলে লেখা
        $this->assertSame(1, $this->pages($this->bill($rows, 100)), '⛔ স্পষ্ট ১০০%-এ উপচানো বিল এখনো দুই পাতায়।');
        $this->assertLessThan(100, app(PrintScale::class)->used());
        $this->assertTrue(app(PrintScale::class)->wasAuto());
    }

    /** ⭐ ২০০% — এক পাতার বিল দুইটা আসল A4 পাতায়; আর বাংলা লেখা লেখাই থাকে। */
    public function test_two_hundred_percent_spreads_a_short_bill_over_two_sheets(): void
    {
        // ⓘ ১০০%-এ প্রায় ভরা এক পাতা — ছোট ছাঁচে ১২ সারি অর্ধেক পাতাও নয়, তাই মাপা সারি
        $rows = $this->rowsForAboutOneAndAThirdPages() - 10;
        $this->assertSame(1, $this->pages($this->bill($rows, 100)), 'দৃশ্যটাই বানানো যায়নি — ১০০%-এ এক পাতা নয়।');
        $this->assertSame(100, app(PrintScale::class)->used(), 'দৃশ্যটাই বানানো যায়নি — ১০০%-এ ছোট করতে হয়েছে।');

        $pdf = $this->bill($rows, 200);

        $this->assertGreaterThanOrEqual(2, $this->pages($pdf), '⛔ ২০০%-এ এক পাতার বিল দুই পাতায় ছড়ায়নি।');
        $this->assertSame(200, app(PrintScale::class)->used());
        // ⓘ ১০০%-এর কাগজ FPDI ছোঁয় না — মাপমতো বসানো পাতার লেখা তার সাথে হুবহু মিলতে হবে (যুক্তাক্ষরসহ)
        $this->assertTextSurvives($pdf, $this->bill($rows, 100));
    }

    /** ⭐ ৬০%-এও না আঁটা বিল — ১০০%-এ কয়েক পাতা, শিরোনাম প্রতিটা পাতায় (mPDF-এর thead)। */
    public function test_a_bill_too_long_to_fit_keeps_its_pages(): void
    {
        $pdf = $this->bill(200);

        $this->assertGreaterThan(2, $this->pages($pdf));
        $this->assertSame(100, app(PrintScale::class)->used());
    }

    /** ⛔ মাপ কেবল পূর্ণসংখ্যা, ৫০–২০০-এ আটকানো; ভাঙা মান মানে স্বয়ংক্রিয়। */
    public function test_the_scale_is_clamped_and_broken_values_mean_auto(): void
    {
        $this->assertSame(50, PrintScale::requested('10'));
        $this->assertSame(200, PrintScale::requested('900'));
        $this->assertSame(85, PrintScale::requested('85'));
        $this->assertNull(PrintScale::requested('abc'));
        $this->assertNull(PrintScale::requested('85.5'));
        $this->assertNull(PrintScale::requested('1e2'));
        $this->assertNull(PrintScale::requested(''));
        $this->assertNull(PrintScale::requested(null));
    }

    /**
     * ⭐ আসল দরজা: `?scale=85` — ছাপা ঠিক একবার গোনা (আঁকা যতবারই হোক), ইতিহাসে ৮৫, উত্তরের মাথায় ৮৫;
     * `?scale=abc` মানে স্বয়ংক্রিয়।
     */
    public function test_the_invoice_door_takes_the_scale_and_counts_the_print_once(): void
    {
        $invoice = $this->aSale();

        $this->get(route('sales.print.invoice', $invoice).'?paper=a4&scale=85')
            ->assertOk()
            ->assertHeader('X-Print-Scale', '85');

        $this->assertSame(1, (int) PrintJob::query()->where('document_id', $invoice->id)->value('printed_count'),
            '⛔ এক অনুরোধে ছাপা একবারের বেশি গোনা হয়েছে।');

        $row = DocumentDelivery::query()->latest('id')->firstOrFail();
        $this->assertSame(85, (int) $row->scale);
        $this->assertFalse((bool) $row->scale_auto);

        $this->get(route('sales.print.invoice', $invoice).'?paper=a4&scale=abc')
            ->assertOk()
            ->assertHeader('X-Print-Scale', '100; auto');

        // ⭐ আসল নকশায় ২০০% — এক সারির বিলও দুইটা আসল A4 পাতায়
        $big = $this->get(route('sales.print.invoice', $invoice).'?paper=a4&scale=200')->assertOk();
        $this->assertGreaterThanOrEqual(2, $this->pages((string) $big->getContent()), '⛔ আসল বিল ২০০%-এ বড় হয়নি।');
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    private function bill(int $rows, ?int $scale = null): string
    {
        app()->forgetScopedInstances();

        return app(PrintEngine::class)->render(
            template: 'scaletest::bill',
            data: ['rows' => $rows, 'title' => 'scale'],
            paper: PaperSize::A4,
            scale: $scale,
        );
    }

    /**
     * ১০০%-এ ঠিক দুই পাতা হয় এমন সবচেয়ে কম সারি, তার এক-তৃতীয়াংশ বেশি নয় — "একটু উপচানো"।
     *
     * ⓘ স্পষ্ট ১০০% এখন উপচালে নিজেই ছোট হয় (৩ অক্টোবর ২০২৬), তাই "এক পাতা" নয় — "১০০%-এই বসল" দিয়ে মাপা।
     */
    private function rowsForAboutOneAndAThirdPages(): int
    {
        $rows = 10;

        while ($this->pages($this->bill($rows, 100)) === 1 && app(PrintScale::class)->used() === 100) {
            $rows += 5;
        }

        return $rows + 5;
    }

    private function pages(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    private function assertTextSurvives(string $scaled, string $plain): void
    {
        $tool = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where pdftotext 2>NUL' : 'command -v pdftotext'));

        // ⚠️ এড়িয়ে যাওয়া নয় — এড়ানো দাবি পাস বলে পড়ে ([[ASkippedTestReadsAsAPassingOneTest]])
        $this->assertNotSame('', $tool, 'pdftotext লাগবে (Git-এর সাথে আসে) — লেখা বাঁচল কি না মাপা যায়নি।');

        $scaledText = $this->words($scaled);

        $this->assertStringContainsString('SCALE-0001', $scaledText, '⛔ মাপমতো বসানো পাতায় লেখা হারিয়েছে।');
        $this->assertSame($this->words($plain), $scaledText, '⛔ মাপমতো বসানো পাতার লেখা ১০০%-এর লেখা থেকে আলাদা — বাংলা ভেঙেছে।');
    }

    /** PDF-এর লেখা, শব্দ ধরে সাজানো — পাতার ভাঙন আর ফাঁকা জায়গা বাদ। */
    private function words(string $pdf): string
    {
        $file = tempnam(sys_get_temp_dir(), 'scale').'.pdf';
        file_put_contents($file, $pdf);
        $text = (string) shell_exec('pdftotext -enc UTF-8 '.escapeshellarg($file).' -');
        @unlink($file);

        // ⓘ আলাদা শব্দের সেট — বেশি পাতায় টেবিলের মাথা বেশিবার আসে, সেটা ভাঙন নয়
        $words = array_unique(preg_split('/\s+/u', trim($text)) ?: []);
        sort($words);

        return implode(' ', $words);
    }

    private function aSale(): SalesInvoice
    {
        return app(DirectSaleService::class)->complete(
            ['customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'own_transport' => '1'],
            [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '2', 'rate' => '10', 'free_qty' => '0']],
        )['invoice']->fresh();
    }
}
