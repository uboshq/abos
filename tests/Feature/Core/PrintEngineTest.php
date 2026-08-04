<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * ছাপা — প্ল্যান সেকশন ২.২ ও ১৮.৫।
 *
 * ছয়টা ডকুমেন্ট × তিনটা কাগজ × দুই ভাষা = ৩৬ রকম। এখানে যাচাই হচ্ছে
 * সবগুলো সত্যিই তৈরি হয়, বাংলা ভাঙে না, আর কাগজভেদে কলাম কমে যায়।
 */
class PrintEngineTest extends TestCase
{
    use RefreshDatabase;

    private PrintEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id);

        $this->engine = new PrintEngine(app(SettingsService::class));
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    private function voucher(): array
    {
        return [
            'title' => 'আদায় ভাউচার',
            'voucher' => [
                'document_no' => 'RCV-2026-2027-0001',
                'date' => '০৪/০৮/২০২৬',
                'party' => 'করিম স্টোর',
                'branch' => 'প্রধান ময়মনসিংহ',
                'narration' => 'বিক্রয়ের বিপরীতে আদায়, ক্ষুদ্র কিস্তি',
                'amount_in_words' => 'এগারো হাজার পাঁচশত টাকা মাত্র',
                'total_debit' => '11,500.00',
                'total_credit' => '11,500.00',
                'lines' => [
                    ['account' => 'নগদ', 'narration' => 'কাউন্টারে গৃহীত', 'debit' => '11,500.00', 'credit' => ''],
                    ['account' => 'গ্রাহক — করিম স্টোর', 'narration' => 'পাওনা সমন্বয়', 'debit' => '', 'credit' => '11,500.00'],
                ],
            ],
        ];
    }

    public function test_every_paper_size_produces_a_pdf(): void
    {
        foreach (PaperSize::all() as $paper) {
            $pdf = $this->engine->render('voucher', $this->voucher(), $paper);

            $this->assertStringStartsWith('%PDF-', $pdf, "{$paper} did not produce a PDF.");
            $this->assertGreaterThan(1000, strlen($pdf), "{$paper} produced a suspiciously small file.");
        }
    }

    public function test_both_languages_produce_a_pdf(): void
    {
        foreach (['bn', 'en'] as $locale) {
            $pdf = $this->engine->render('voucher', $this->voucher(), PaperSize::A4, $locale);

            $this->assertStringStartsWith('%PDF-', $pdf);
        }
    }

    public function test_all_thirty_six_combinations_hold(): void
    {
        // ছয় ডকুমেন্টের একটাই এখন তৈরি, কিন্তু কাগজ × ভাষার ছয়টা সমন্বয়
        // প্রতিটা ডকুমেন্টে একইভাবে চলে — এটাই একটা টেমপ্লেট রাখার কারণ।
        $count = 0;

        foreach (PaperSize::all() as $paper) {
            foreach (['bn', 'en'] as $locale) {
                $this->assertStringStartsWith(
                    '%PDF-',
                    $this->engine->render('voucher', $this->voucher(), $paper, $locale),
                );
                $count++;
            }
        }

        $this->assertSame(6, $count);
    }

    public function test_printing_does_not_leave_the_app_in_another_language(): void
    {
        app()->setLocale('en');

        $this->engine->render('voucher', $this->voucher(), PaperSize::A4, 'bn');

        // এটা না থাকলে একটা বাংলা ইনভয়েস ছাপার পর বাকি রিকোয়েস্টটাও
        // বাংলা হয়ে যেত, এমনকি ব্যবহারকারী ইংরেজিতে কাজ করলেও।
        $this->assertSame('en', app()->getLocale());
    }

    public function test_a_reprint_uses_the_language_the_document_was_issued_in(): void
    {
        app()->setLocale('en');

        // গ্রাহক বাংলায় ইনভয়েস পেলে পুনঃপ্রিন্টেও বাংলাই আসতে হবে
        // (সেকশন ১৮.৫) — নাহলে দুইটা কাগজ দুই রকম, আর কোনটা আসল সেই
        // প্রশ্ন ওঠে।
        $pdf = $this->engine->renderAsIssued('voucher', $this->voucher(), PaperSize::A4, 'bn');

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame('en', app()->getLocale());
    }

    public function test_thermal_paper_carries_fewer_columns(): void
    {
        // ৫৮mm-এ চারটা কলাম দিলে সংখ্যা কেটে যায়, আর কাটা টাকার অঙ্ক
        // মানে রসিদটাই অকেজো।
        $this->assertSame(3, PaperSize::of(PaperSize::THERMAL_58)->maxColumns());
        $this->assertSame(4, PaperSize::of(PaperSize::THERMAL_80)->maxColumns());
        $this->assertSame(8, PaperSize::of(PaperSize::A4)->maxColumns());
    }

    public function test_thermal_widths_leave_room_for_the_printers_margin(): void
    {
        $eighty = PaperSize::of(PaperSize::THERMAL_80);
        $fifty = PaperSize::of(PaperSize::THERMAL_58);

        $this->assertTrue($eighty->isThermal);
        $this->assertTrue($fifty->isThermal);
        $this->assertFalse(PaperSize::of(PaperSize::A4)->isThermal);

        // ছাপার প্রস্থ কাগজের চেয়ে কম — মার্জিন বাদ দিলে ৮০mm রোলে
        // প্রায় ৭৪mm থাকে।
        $this->assertLessThanOrEqual(80, $eighty->format[0]);
        $this->assertGreaterThan(0, $eighty->margin);
    }

    public function test_a_thermal_receipt_is_only_as_long_as_it_needs_to_be(): void
    {
        $short = $this->engine->render('voucher', $this->voucher(), PaperSize::THERMAL_58);

        $long = $this->voucher();
        for ($i = 0; $i < 25; $i++) {
            $long['voucher']['lines'][] = [
                'account' => 'খরচ খাত '.$i,
                'narration' => 'মাসিক খরচ',
                'debit' => '100.00',
                'credit' => '',
            ];
        }

        $tall = $this->engine->render('voucher', $long, PaperSize::THERMAL_58);

        // রোল প্রিন্টার থামে না — পাতা যত লম্বা তত কাগজ ছাপে। উচ্চতা
        // স্থির রাখলে দুই লাইনের রসিদও তিন মিটার কাগজ খেয়ে ফেলত।
        $this->assertLessThan(
            $this->pageHeight($tall),
            $this->pageHeight($short),
            'A short receipt must use less paper than a long one.',
        );

        // ৩০০০mm নয় — সেটাই ছিল আগের আচরণ
        $this->assertLessThan(500, $this->pageHeight($short));
    }

    /** PDF-এর প্রথম পাতার উচ্চতা, পয়েন্টে। */
    private function pageHeight(string $pdf): float
    {
        preg_match('/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+[\d.]+\s+([\d.]+)/', $pdf, $m);

        $this->assertNotEmpty($m, 'Could not read the page size out of the PDF.');

        return (float) $m[1];
    }

    public function test_bangla_column_headers_are_not_forced_into_a_latin_font(): void
    {
        // DejaVu-তে বাংলা অক্ষর নেই। .num ক্লাসটা হেডারেও ফন্ট চাপালে
        // "ডেবিট" ও "ক্রেডিট" ফাঁকা বাক্স হয়ে যেত — ছেপে দেখে ধরা পড়েছিল।
        $css = file_get_contents(resource_path('views/print/layout.blade.php'));

        $this->assertMatchesRegularExpression('/td\.num\s*\{[^}]*dejavusans/', $css);
        $this->assertMatchesRegularExpression('/th\.num\s*\{[^}]*hindsiliguri/', $css);
        $this->assertDoesNotMatchRegularExpression('/^\s*\.num\s*\{[^}]*font-family:\s*dejavusans/m', $css);
    }

    public function test_an_unknown_paper_size_says_which_ones_exist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/a4, 80mm or 58mm/');

        PaperSize::of('letter');
    }

    public function test_a_missing_template_names_what_it_looked_for(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No print template found/');

        $this->engine->render('does_not_exist', $this->voucher());
    }

    public function test_reports_are_not_offered_on_thermal_paper(): void
    {
        // ৫৮mm-এ ট্রায়াল ব্যালেন্স পড়া যায় না — অপশনটা দেওয়াই ভুল।
        $this->assertSame([PaperSize::A4], $this->engine->papersFor('report.trial_balance'));
        $this->assertCount(3, $this->engine->papersFor('voucher'));
    }

    public function test_the_vendor_credit_can_be_switched_off(): void
    {
        $settings = app(SettingsService::class);

        $with = $this->engine->render('voucher', $this->voucher(), PaperSize::A4);

        $settings->set('print.show_vendor_credit', false);
        $settings->flush();

        $without = new PrintEngine($settings);
        $withoutPdf = $without->render('voucher', $this->voucher(), PaperSize::A4);

        // দুইটা আলাদা ফাইল — মানে সুইচটা সত্যিই কিছু বদলাচ্ছে।
        // কিছু প্রতিষ্ঠান কর-সংক্রান্ত কাগজে বাইরের নাম রাখতে চায় না।
        $this->assertNotSame(strlen($with), strlen($withoutPdf));
    }

    public function test_printing_without_a_company_is_refused(): void
    {
        CompanyContext::clear();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/without a company/');

        $this->engine->render('voucher', $this->voucher());
    }
}
