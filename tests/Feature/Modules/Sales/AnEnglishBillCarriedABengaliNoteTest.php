<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Print\PaperSize;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\PaperLanguage;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Support\InvoicePaperView;
use App\Modules\Sales\Support\InvoicePrintLook;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * ⛔ ইংরেজি বিলের নিচে বাংলা নোট — ভা-১৬ ধাপ ২ (২৪ অক্টোবর ২০২৬; [[InvoicePrintLook::footnote()]])।
 *
 * দাবি:
 *  - ইংরেজি ঘরে লেখা থাকলে ইংরেজি কাগজে সেটাই;
 *  - বাংলা নোট কোম্পানির নিজের লেখা আর ইংরেজি ঘর খালি — ইংরেজি কাগজে কোনো নোট নয় (বাংলা কখনো নীরবে নয়), আর বিলের তথ্যের
 *    পাতায় "ইংরেজি নোট নেই";
 *  - বাংলা নোট কেবল সাধারণ লেখা (পাতা খালি ঘরে সেটাই ভরে সেভ করে, CRLF-সহ) — ইংরেজি কাগজে সাধারণ ইংরেজি লেখা, সতর্কবার্তা নয়;
 *  - দুই ভাষার কাগজে দুটোই, বাংলা আগে; বাংলা কাগজ আগের মতো;
 *  - আসল ছাপার পথে (?lang=en) বিলের view ইংরেজি নোট পায়।
 */
final class AnEnglishBillCarriedABengaliNoteTest extends TestCase
{
    use RefreshDatabase;

    private const OWN_BN = "আমাদের শর্ত:\n১. পণ্য গুনে নিন";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_with_nothing_set_each_language_gets_its_standard_text(): void
    {
        $look = app(InvoicePrintLook::class);

        $this->assertSame((string) __('sales::print.classic.footnote', [], 'en'), $look->footnote(false, PaperLanguage::EN),
            '⛔ কিছু বসানো নেই, তবু ইংরেজি কাগজে সাধারণ ইংরেজি নোট নয়।');
        $this->assertSame((string) __('sales::print.classic.footnote', [], 'bn'), $look->footnote(false, PaperLanguage::BN),
            '⛔ বাংলা কাগজের নোট বদলে গেল।');
        $this->assertSame(__('sales::print.classic.footnote', [], 'bn')."\n".__('sales::print.classic.footnote', [], 'en'),
            $look->footnote(false, PaperLanguage::BOTH), '⛔ দুই ভাষার কাগজে দুটো নোট (বাংলা আগে) নেই।');
    }

    public function test_an_own_bengali_note_never_lands_on_an_english_paper(): void
    {
        app(SettingsService::class)->set('sales.print.invoice_footnote', self::OWN_BN);
        $look = app(InvoicePrintLook::class);

        $english = $look->footnote(false, PaperLanguage::EN);
        $this->assertSame('', $english, '⛔ ইংরেজি নোট নেই, অথচ ইংরেজি কাগজে কিছু একটা বসল: '.$english);
        $this->assertTrue($look->englishFootnoteMissing(), '⛔ বাংলা নোট নিজের লেখা আর ইংরেজি খালি — তবু "ইংরেজি নোট নেই" ধরা পড়ল না।');
        $this->assertSame(self::OWN_BN, $look->footnote(false, PaperLanguage::BOTH), '⛔ দুই ভাষার কাগজে বাংলা নোট একা থাকার কথা।');

        $page = $this->get(route('system_admin.print_control.invoice_info'))->assertOk()->getContent();
        $this->assertStringContainsString('data-english-note-missing', (string) $page,
            '⛔ বিলের তথ্যের পাতা "ইংরেজি নোট নেই" বলে না — মালিক জানেন না ইংরেজি কাগজে নোট যাচ্ছে না।');

        app(SettingsService::class)->set('sales.print.invoice_footnote_en', "OUR TERMS:\n1. Count the goods");
        $this->assertSame("OUR TERMS:\n1. Count the goods", app(InvoicePrintLook::class)->footnote(false, PaperLanguage::EN),
            '⛔ ইংরেজি ঘরে লেখা নোট ইংরেজি কাগজে বসেনি।');
        $this->assertFalse(app(InvoicePrintLook::class)->englishFootnoteMissing(), '⛔ ইংরেজি নোট লেখা হলো, তবু "ইংরেজি নোট নেই"।');
        $this->assertStringNotContainsString('data-english-note-missing',
            (string) $this->get(route('system_admin.print_control.invoice_info'))->getContent(), '⛔ ইংরেজি নোট আছে, তবু পাতায় সতর্কবার্তা।');
    }

    public function test_the_standard_bengali_text_saved_by_the_page_is_not_an_own_note(): void
    {
        // ⓘ পাতা খালি ঘরে সাধারণ লেখা ভরে — ব্রাউজার লাইনের শেষ CRLF করে পাঠায়
        app(SettingsService::class)->set('sales.print.invoice_footnote', str_replace("\n", "\r\n", (string) __('sales::print.classic.footnote', [], 'bn')));
        $look = app(InvoicePrintLook::class);

        $this->assertSame((string) __('sales::print.classic.footnote', [], 'en'), $look->footnote(false, PaperLanguage::EN),
            '⛔ বাংলা নোট কেবল সাধারণ লেখা, তবু ইংরেজি কাগজ সাধারণ ইংরেজি নোট পেল না।');
        $this->assertFalse($look->englishFootnoteMissing(), '⛔ সাধারণ বাংলা লেখাকে নিজের লেখা ধরে "ইংরেজি নোট নেই" বলা হলো।');
    }

    public function test_the_printed_english_bill_takes_the_english_note(): void
    {
        app(SettingsService::class)->set('sales.print.invoice_footnote_en', 'ENGLISH NOTE FOR THE PAPER');
        // ⓘ লাইভে চলা নকশা, যার ভিতরে InvoicePaperView বানানো হয়
        app(SettingsService::class)->set('sales.print.design.invoice', 'mono_light');
        $seen = [];
        // ⓘ নকশা $v-টা নিজের ভিতরে বানায়, তাই ঠিক সেই উপকরণে, ঠিক সেই অনুরোধের মধ্যে আরেকটা বানিয়ে দেখা (view আবার আঁকা নয়)
        View::composer('*', function ($view) use (&$seen): void {
            $data = $view->getData();
            if (str_starts_with($view->name(), 'sales::print.invoice-') && isset($data['doc'], $data['facts'], $data['company'], $data['profile'])) {
                $seen[] = (new InvoicePaperView($data['doc'], $data['facts'], $data['company'], $data['profile']))->footnote;
            }
        });

        $service = app(SalesInvoiceService::class);
        $invoice = $service->confirm($service->create(
            ['customer_id' => Customer::query()->orderBy('id')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => Carbon::today()->toDateString()],
            [['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => '1', 'rate' => '100']],
        ));

        $this->get(route('sales.print.invoice', $invoice).'?paper='.PaperSize::A4.'&lang=en')->assertOk();

        $this->assertNotSame([], $seen, 'দৃশ্যটাই ভুল — বিলের view InvoicePaperView পায়নি।');
        $this->assertSame('ENGLISH NOTE FOR THE PAPER', end($seen), '⛔ ইংরেজিতে ছাপা বিলের view ইংরেজি নোট পেল না।');
    }

    /**
     * ⭐ ইংরেজি বিলে বাংলা কিছু নীরবে নয় — সইয়ের ঘরের নাম আর QR-এর নিচের লেখাও কাগজের ভাষায়, আর নকশার নমুনাও ছাপার মেনুর বাছাই মানে
     * (৭ নভেম্বর ২০২৬-এর নমুনা: ইংরেজি বিলে "প্রস্তুতকারী" আর "স্ক্যান করুন"; নমুনা পর্দার ভাষায় আঁকত)।
     */
    public function test_an_english_bill_names_its_signatures_and_qr_in_english(): void
    {
        app()->setLocale('bn');
        $english = (string) $this->get(route('sales.invoice_sample', ['design' => 'special_db', 'size' => 'a4', 'lang' => 'en']))->assertOk()->getContent();
        $bengali = (string) $this->get(route('sales.invoice_sample', ['design' => 'special_db', 'size' => 'a4', 'lang' => 'bn']))->assertOk()->getContent();

        $this->assertStringContainsString(__('sales::print.classic.prepared_by', [], 'en'), $english, '⛔ ইংরেজি বিলে সইয়ের ঘরের নাম ইংরেজিতে নেই।');
        $this->assertStringNotContainsString(__('sales::print.classic.prepared_by', [], 'bn'), $english, '⛔ ইংরেজি বিলে সইয়ের ঘরের নাম বাংলায়।');
        $this->assertStringNotContainsString(__('sales::print.classic.scan_hint', [], 'bn'), $english, '⛔ ইংরেজি বিলে QR-এর নিচে বাংলা লেখা।');
        $this->assertStringContainsString(__('sales::print.classic.prepared_by', [], 'bn'), $bengali, '⛔ বাংলা বিলে সইয়ের ঘরের নাম বাংলায় নেই।');
    }
}
