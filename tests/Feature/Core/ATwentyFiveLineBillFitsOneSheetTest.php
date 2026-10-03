<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintableDocument;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Engines\Print\PrintProfile;
use App\Core\Engines\Print\PrintScale;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ২৫ সারির বিল এক A4 পাতায়, আর স্পষ্ট মাপেও এক পাতা — মালিক, ৩ অক্টোবর ২০২৬।
 *
 * ── ⛔ আগে ─────────────────────────────────────────────────────────────
 * *"etar print scale custom korle zate dui pristha ek pataay print hoy seta bolecilam but hoyni, tai eta koro ekhono,
 * & ei pristhay zate 25 item print hoy tar bebosta koro"*। ১৮ সারির বিল ("মোনো ক্লাসিক হালকা", সব কোম্পানির ডিফল্ট
 * নকশা) দুই পাতায়: প্রথম পাতায় মাথা আর ছক, দ্বিতীয়তে জমা, QR, টাকার সারি আর বকেয়া। ১০০%-এ ৮টা সারিতেই পাতা ভরত।
 * আর "মাপ %"-এ ৯০ লিখলে কাগজ ৯০%-এ দুই পাতাতেই থাকত — স্পষ্ট মাপ সবসময় জিতত।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * নকশাটা আঁটসাঁট ([[sales::print.partials.invoice-mono-light]]): ২৫ সারি, নিচের সব ঘরসহ, ১০০%-এই এক পাতা। আর ১০০
 * বা তার কম স্পষ্ট মাপে কাগজ উপচালে ইঞ্জিন ৫ ধাপে ৫০% পর্যন্ত নামে ([[PrintEngine::toPdf()]]); বড় করা (> ১০০) আগের মতো।
 */
final class ATwentyFiveLineBillFitsOneSheetTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATE = 'sales::print.invoice-mono_light';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /**
     * ⭐ ২৫ সারি, প্রতিটায় লট, জমা, আগের বকেয়া, QR, DUPLICATE, নিচের লেখা আর সই — ১০০%-এ এক পাতা, একটুও ছোট না করে।
     * ⓘ ইংরেজি আর বাংলা দুই রূপই — কাঠামো একটাই।
     */
    public function test_twenty_five_lines_fit_one_a4_sheet_at_full_size(): void
    {
        foreach ([self::TEMPLATE, 'sales::print.invoice-mono_light_bn'] as $template) {
            $pdf = $this->bill(25, 100, $template);

            $this->assertSame(1, $this->pages($pdf), "⛔ {$template}: ২৫ সারির বিল এখনো এক পাতার বেশি।");
            $this->assertSame(100, app(PrintScale::class)->used(), "⛔ {$template}: এক পাতায় আঁটাতে লেখা ছোট করতে হয়েছে।");
            $this->assertFalse(app(PrintScale::class)->wasAuto());
        }

        // ⓘ আঁটসাঁট মানে কিছু বাদ দেওয়া নয় — লট প্রতিটা সারিতে, আর টাকার ঘর সব আছে
        $html = app(PrintEngine::class)->preview(self::TEMPLATE, $this->data(25), PaperSize::A4,
            PrintProfile::for('invoice', app(SettingsService::class)));
        $this->assertSame(25, substr_count($html, 'data-line-lot'), '⛔ লট সারি থেকে হারিয়েছে।');
        foreach (['data-deposits', 'data-previous-due', 'data-scan-qr', 'data-words', 'data-signature'] as $part) {
            $this->assertStringContainsString($part, $html, "⛔ {$part} কাগজ থেকে বাদ পড়েছে।");
        }
    }

    /** ⛔→⭐ স্পষ্ট ৯৫% — ৯৫%-এ কাগজ উপচায়, তাই আরও ছোট হয়ে এক পাতায়; বসানো মাপ ৯৫-এর নিচে, স্বয়ংক্রিয়। */
    public function test_an_explicit_scale_that_still_spills_keeps_shrinking_to_one_sheet(): void
    {
        $rows = 40;
        $this->assertGreaterThan(1, $this->pages($this->bill($rows, 101)), 'দৃশ্যটাই বানানো যায়নি — ৪০ সারি এক পাতায় ধরে গেছে।');

        $pdf = $this->bill($rows, 95);

        $this->assertSame(1, $this->pages($pdf), '⛔ স্পষ্ট ৯৫%-এ উপচানো বিল এখনো দুই পাতায়।');
        $this->assertLessThan(95, app(PrintScale::class)->used());
        $this->assertGreaterThanOrEqual(PrintScale::MIN, app(PrintScale::class)->used());
        $this->assertTrue(app(PrintScale::class)->wasAuto(), '⛔ চাওয়া মাপ থেকে নামা হলো, অথচ স্বয়ংক্রিয় বলে লেখা নেই।');
    }

    /** ⭐ বড় করা (১৫০%) আগের মতোই — ২৫ সারির বিল কয়েক পাতায়, ছোট করে এক পাতায় ফেরানো নয়। */
    public function test_an_enlarging_scale_keeps_its_pages(): void
    {
        $pdf = $this->bill(25, 150);

        $this->assertGreaterThan(1, $this->pages($pdf), '⛔ ১৫০%-এ বিল এক পাতায় চেপে গেছে।');
        $this->assertSame(150, app(PrintScale::class)->used());
        $this->assertFalse(app(PrintScale::class)->wasAuto());
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    private function bill(int $rows, int $scale, string $template = self::TEMPLATE): string
    {
        app()->forgetScopedInstances();

        return app(PrintEngine::class)->render(
            template: $template,
            data: $this->data($rows),
            paper: PaperSize::A4,
            profile: 'invoice',
            scale: $scale,
        );
    }

    /**
     * আসল বিলের আকারের তথ্য ([[InvoiceSampleController]]-এর মতো) — লম্বা নাম, প্রতিটায় লট, একটা জমা, আগের বকেয়া।
     *
     * @return array<string, mixed>
     */
    private function data(int $rows): array
    {
        $items = [];

        for ($i = 1; $i <= $rows; $i++) {
            $items[] = ['name' => "Cosmos Biscuit Chocolate Cream 40gm Item {$i}", 'code' => '', 'lot' => "LOT-00{$i} · 12/2027",
                'rate' => '1,200.00', 'qty' => '10 Ctn', 'free' => $i % 2 === 1 ? '1 Ctn' : '', 'total_qty' => '11 Ctn', 'amount' => '12,000.00'];
        }

        $today = '03-10-2026';

        return [
            'title' => 'S-0025',
            'doc' => new PrintableDocument(title: 'Invoice', notice: 'DUPLICATE — Print No. 2', payments: [[
                'no' => 1, 'ref' => 'RV-0000', 'date' => $today, 'method' => 'Cash', 'narration' => '', 'amount' => '5,000.00',
            ]]),
            'facts' => [
                'bill_to' => ['name' => 'Rahim Traders', 'point' => 'Mirpur Point', 'phone' => '01700-000000', 'address' => 'House 1, Road 1, Mirpur 10, Dhaka'],
                'transport' => ['carrier' => 'Own Transport', 'driver_phone' => '01800-000000', 'vehicle' => 'Truck DM-TA-11-0000', 'delivery_date' => $today],
                'bill' => ['bill_date' => $today, 'bill_no' => 'S-0025', 'order_no' => 'SO-0025', 'type' => 'CREDIT', 'created_by' => 'Sample Staff'],
                'total_items' => (string) $rows,
                'total_delivery' => (string) (11 * $rows),
                'items' => ['rows' => $items, 'totals' => ['qty' => (10 * $rows).' Ctn', 'free' => '13 Ctn',
                    'total_qty' => (11 * $rows).' Ctn', 'amount' => '300,000.00']],
                'sums' => ['grand_total' => '300,000.00', 'discount' => '200.00', 'vat' => '0.00', 'rounding' => '0.00',
                    'net_payable' => '299,800.00', 'paid' => '5,000.00', 'invoice_due' => '294,800.00',
                    'previous_due' => '3,000.00', 'outstanding' => '297,800.00'],
                'words' => 'Two Lakh Ninety Nine Thousand Eight Hundred Taka Only (BDT)',
                'scan_url' => route('sales.scan', '00000000-0000-7000-8000-000000000000'),
            ],
        ];
    }

    private function pages(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }
}
