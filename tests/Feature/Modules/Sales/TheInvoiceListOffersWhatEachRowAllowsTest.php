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

    public function test_a_draft_row_offers_edit_and_confirm(): void
    {
        $this->invoice('INV-DRAFT', DocumentStatus::DRAFT);

        $text = $this->listText();

        $this->assertStringContainsString(__('core.action.edit', [], 'bn'), $text,
            '⛔ খসড়া বিলের সারিতে সম্পাদনা নেই — অথচ খসড়া খাতায় কিছুই বসায়নি।');

        $this->assertStringContainsString(__('sales::action.confirm', [], 'bn'), $text,
            '⛔ খসড়া বিলের সারিতে "নিশ্চিত করুন" নেই।');
    }

    /**
     * ⭐ আর নিশ্চিত হওয়া বিলে সম্পাদনা **থাকে না** — এটাই আসল দাবি।
     *
     * ⓘ উপরেরটা বলে কী আছে; এটা বলে **কী নেই**। ⚠️ কেবল "আছে" মাপলে
     * কেউ শর্তগুলো তুলে দিয়ে সব সারিতে সব কাজ বসিয়ে দিলেও সবুজ থাকত,
     * আর তখন নিশ্চিত বিল সম্পাদনার লিংকটা পর্দায় বসে থাকত — চাপলে ৪০৩।
     */
    public function test_a_confirmed_row_offers_cancel_but_never_edit(): void
    {
        $this->invoice('INV-LIVE', DocumentStatus::CONFIRMED);

        $text = $this->listText();

        $this->assertStringContainsString(__('sales::action.cancel_invoice', [], 'bn'), $text,
            '⛔ নিশ্চিত বিলের সারিতে বাতিলের পথ নেই।');

        $this->assertStringNotContainsString(__('core.action.edit', [], 'bn'), $text, implode("\n", [
            '⛔ নিশ্চিত হওয়া বিলের সারিতে সম্পাদনা দেখা যাচ্ছে।',
            '',
            '⚠️ কাগজটা ইতিমধ্যে আয়, প্রাপ্য ও ব্যয় বসিয়ে ফেলেছে — বদলানো যায় না।',
            'ⓘ পথটা দেখিয়ে ৪০৩ দেওয়া মানে ব্যবহারকারীকে একটা মিথ্যা দরজা দেখানো।',
        ]));
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
        $this->assertStringContainsString(__('core.table.actions', [], 'bn'), $text, 'কাজের কলামটাই নেই।');
    }

    private function listText(): string
    {
        $html = (string) $this->get(route('sales.invoice.index'))->assertOk()->getContent();

        return (string) preg_replace('/\s+/u', ' ', strip_tags($html));
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
