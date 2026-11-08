<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বিলের তালিকায় প্রতিটা সারি বলে দেয় **তাকে নিয়ে কী করা যায়**।
 *
 * ── ⭐ মালিকের নির্দেশ, ২১ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"ei list e action buton daw edite delet r ki ki dewa zay ta diye"* —
 * আর ছবিতে ডান ধারের **খালি কলামটা** দাগ দিয়ে দেখানো। ⓘ জায়গাটা রাখা
 * ছিল, কিছু বসানো হয়নি।
 *
 * ── ⛔ কেন এই পাহারাটা লাগল, আর সেটা আজকের শিক্ষা ──────────────────
 * কলামটা বসানোর পর পাতাটা এঁকে দেখা হয়েছিল — শিরোনাম এল, একটাও কাজ
 * এল না। ⚠️ কারণ **ডেভ ডেটাবেসে একটাও বিল ছিল না**: সারি নেই, তাই কাজও
 * নেই। ⓘ রেন্ডারটা সবুজ দেখাচ্ছিল আর কিছুই প্রমাণ করছিল না।
 *
 * ⭐ তাই এই ফাইলটা নিজেই দুইটা বিল বানায় — একটা খসড়া, একটা নিশ্চিত —
 * আর দুইটার জন্য **আলাদা তালিকা** আশা করে।
 *
 * ── ⛔ "মুছুন" কোনোদিন আসবে না ─────────────────────────────────────
 * ⓘ বিল আয়, প্রাপ্য আর বিক্রীত পণ্যের ব্যয় — তিনটাই একসাথে বসায়।
 * নিশ্চিত বিল মুছলে ভুক্তিগুলো খাতায় থেকে যেত আর কাগজটা থাকত না।
 * ⚠️ সিস্টেমে মোছার রুটই নেই; নিচের শেষ দাবিটা সেটা নাম ধরে পাহারা দেয়।
 */
final class TheInvoiceListOffersWhatEachRowAllowsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /**
     * ⭐ ৮ নভেম্বর ২০২৬ থেকে সারির শেষে "অ্যাকশন ▾" (মালিকের নমুনা "লিস্টের অ্যাকশন বোতাম") — দাবিগুলো লেখা নয়, ঠিকানা মাপে:
     * সম্পাদনার লিংক আছে কি না, নিশ্চিত করার ফর্ম আছে কি না। ⓘ ধূসর সারিতেও "সম্পাদনা" লেখাটা থাকে, তাই লেখা মাপলে চলত না।
     */
    public function test_a_draft_row_offers_edit_and_confirm(): void
    {
        $draft = $this->invoice('INV-DRAFT', DocumentStatus::DRAFT);

        $html = $this->listHtml();

        $this->assertStringContainsString('href="'.e(route('sales.invoice.edit', $draft)).'"', $html,
            '⛔ খসড়া বিলের সারিতে সম্পাদনা নেই — অথচ খসড়া খাতায় কিছুই বসায়নি।');

        $this->assertStringContainsString('action="'.e(route('sales.invoice.confirm', $draft)).'"', $html,
            '⛔ খসড়া বিলের সারিতে "নিশ্চিত করুন" নেই।');

        $this->assertStringContainsString('href="'.e(route('sales.invoice.show', ['invoice' => $draft, 'cancel' => 1])).'#cancel"', $html,
            '⛔ খসড়া বিলের সারিতে "খসড়া বাতিল" নেই।');

        $this->assertStringContainsString(__('core.row_menu.why_not_posted_collect', [], 'bn'), $this->text($html),
            '⛔ খসড়ায় "টাকা নিন" ধূসর নয়, বা কারণ লেখা নেই।');
    }

    /**
     * ⭐ আর নিশ্চিত হওয়া বিলে সম্পাদনা **থাকে না** — এটাই আসল দাবি।
     *
     * ⓘ উপরেরটা বলে কী আছে; এটা বলে **কী নেই**। ⚠️ কেবল "আছে" মাপলে
     * কেউ শর্তগুলো তুলে দিয়ে সব সারিতে সব কাজ বসিয়ে দিলেও সবুজ থাকত,
     * আর তখন নিশ্চিত বিল সম্পাদনার লিংকটা পর্দায় বসে থাকত — চাপলে ৪০৩।
     */
    public function test_a_confirmed_row_offers_neither_cancel_nor_edit(): void
    {
        $live = $this->invoice('INV-LIVE', DocumentStatus::CONFIRMED);

        $html = $this->listHtml();
        $text = $this->text($html);

        /* ⛔ ২ অক্টোবর ২০২৬ থেকে নিশ্চিত বিলে খসড়ার বাতিল নেই ([[AConfirmedBillCouldStillBeCancelledTest]]) — উল্টাতে হলে
           আলাদা বাতিল-কাগজ (`sales.invoice.cancellation`, কারণসহ), যা পাতাতেও একই চাবিতে আছে */
        $this->assertStringNotContainsString(__('sales::action.cancel_invoice', [], 'bn'), $text,
            '⛔ নিশ্চিত বিলের সারিতে খসড়ার বাতিলের পথ দেখা যাচ্ছে।');
        $this->assertStringNotContainsString('href="'.e(route('sales.invoice.show', ['invoice' => $live, 'cancel' => 1])), $html,
            '⛔ নিশ্চিত বিলের সারি খসড়ার বাতিল-ফর্মে নিয়ে যাচ্ছে।');
        $this->assertStringContainsString('href="'.e(route('sales.invoice.show', ['invoice' => $live, 'cancellation' => 1])).'#cancellation"', $html,
            '⛔ নিশ্চিত বিলের সারিতে "বাতিল (রিভার্স)" নেই — মালিকের নমুনায় আছে।');

        $this->assertStringNotContainsString('href="'.e(route('sales.invoice.edit', $live)).'"', $html, implode("\n", [
            '⛔ নিশ্চিত হওয়া বিলের সারিতে সম্পাদনার লিংক।',
            '',
            '⚠️ কাগজটা ইতিমধ্যে আয়, প্রাপ্য ও ব্যয় বসিয়ে ফেলেছে — বদলানো যায় না।',
            'ⓘ পথটা দেখিয়ে ৪০৩ দেওয়া মানে ব্যবহারকারীকে একটা মিথ্যা দরজা দেখানো।',
        ]));
        $this->assertStringContainsString(__('core.row_menu.why_posted', [], 'bn'), $text,
            '⛔ নিশ্চিত বিলে "সম্পাদনা" ধূসর নয়, বা কারণ লেখা নেই।');

        /* ⓘ বাকি ১০০ — "টাকা নিন" সাদা, আদায়ের পর্দায় এই বিলটা বেছে */
        $this->assertStringContainsString('href="'.e(route('sales.collection.create', ['sales_invoice_id' => $live->id])).'"', $html,
            '⛔ বাকিওয়ালা নিশ্চিত বিলে "টাকা নিন" নেই।');
    }

    /**
     * ⛔ আর মোছার পথ কোথাও নেই — না সারিতে, না রুটে।
     *
     * ⓘ দুইটাই মাপা হয়: পর্দায় শব্দটা নেই, আর রুটের তালিকাতেও
     * `destroy` নেই। ⚠️ কেবল পর্দা মাপলে কেউ একদিন রুটটা বানিয়ে
     * ফেললে পাহারাটা চুপ থাকত।
     */
    public function test_nothing_anywhere_offers_to_delete_an_invoice(): void
    {
        $this->invoice('INV-DRAFT', DocumentStatus::DRAFT);

        $this->assertStringNotContainsString(__('core.action.delete', [], 'bn'), $this->listText(),
            '⛔ বিলের সারিতে "মুছুন" এসেছে — বিল মোছা যায় না, বাতিল করা যায়।');

        $named = collect(app('router')->getRoutes()->getRoutesByName())
            ->keys()
            ->filter(fn (string $n) => str_starts_with($n, 'sales.invoice.'))
            ->values()
            ->all();

        $this->assertNotContains('sales.invoice.destroy', $named, implode("\n", [
            '⛔ বিল মোছার একটা রুট তৈরি হয়েছে।',
            '',
            '⚠️ বিল আয়, প্রাপ্য আর বিক্রীত পণ্যের ব্যয় — তিনটাই বসায়।',
            'মুছলে ভুক্তিগুলো খাতায় থেকে যায় আর কাগজটা থাকে না।',
            'ⓘ বাতিল করুন: কাগজ থাকে, উল্টো ভুক্তি বসে, কারণ লেখা থাকে।',
        ]));
    }

    /**
     * পাহারাটা সত্যিই তাকায়।
     *
     * ⓘ উপরের "নেই" দাবিগুলো চিরকাল সবুজ থাকত যদি তালিকাটা খালি
     * ফিরত — আর ঠিক সেটাই প্রথমবার ঘটেছিল (ডেভ ডেটাবেসে বিল ছিল না)।
     */
    public function test_the_list_really_drew_a_row(): void
    {
        $this->invoice('INV-DRAFT', DocumentStatus::DRAFT);

        $text = $this->listText();

        $this->assertStringContainsString('INV-DRAFT', $text, 'বিলের সারিটাই তালিকায় আসেনি।');
        $this->assertStringContainsString(__('core.table.action_menu', [], 'bn'), $text, 'অ্যাকশন কলামটাই নেই।');
        $this->assertStringContainsString('data-row-actions-button', $this->listHtml(), '"অ্যাকশন ▾" বোতামটাই নেই।');
    }

    private function listHtml(): string
    {
        return (string) $this->get(route('sales.invoice.index'))->assertOk()->getContent();
    }

    private function text(string $html): string
    {
        return (string) preg_replace('/\s+/u', ' ', strip_tags($html));
    }

    private function listText(): string
    {
        return $this->text($this->listHtml());
    }

    private function invoice(string $no, string $status): SalesInvoice
    {
        return SalesInvoice::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => FinancialYear::query()->where('is_current', true)->firstOrFail()->id,
            'document_no' => $no,
            'customer_id' => Customer::query()->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'total' => '100.0000',
            'status' => $status,
        ]);
    }
}
