<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বিলের পাতা বলে দেয় **কোন কাগজে** টাকাটা এল।
 *
 * ── ⛔ মালিকের প্রশ্ন, ২১ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"INV-0004 ekta deposit diyechi ta haralo keno?"*
 *
 * ── ⚠️ আর টাকাটা হারায়নি — মেপে দেখা ────────────────────────────────
 * লাইভে INV-0004: মোট ৭১৭.৬৫, আদায় ৫৪৩, বকেয়া ১৭৪.৬৫। ⓘ রসিদটাও
 * ছিল (`RCV-0004`, নিশ্চিত, বিলের বিপরীতে বাঁধা)। সব সংখ্যা ঠিক।
 *
 * ⛔ **হারিয়েছিল কাগজটা।** বিলের পাতায় রসিদের নম্বরটা কোথাও লেখা ছিল
 * না। ⓘ পাতাটা রসিদ দেখাত কেবল **সইয়ের অপেক্ষায়** থাকা জমার বেলায়;
 * নিশ্চিত হয়ে গেলে সে তালিকা থেকেই উধাও হয়ে যেত।
 *
 * ⭐ একটা সংখ্যা যোগ হয়েছে দেখা, আর **কোন কাগজে** যোগ হয়েছে জানা —
 * দুইটা আলাদা প্রশ্ন। দ্বিতীয়টার উত্তর ছাড়া কেউ মেলাতে পারেন না, আর
 * তখন মনে হয় টাকাটাই হারিয়ে গেছে।
 */
final class TheInvoiceSaysWhichPaperBroughtTheMoneyTest extends TestCase
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

    public function test_the_receipt_number_is_on_the_invoice_page(): void
    {
        $invoice = $this->invoice('717.6500');
        $this->receipt($invoice, 'RCV-TEST-1', '543.0000');

        $text = $this->pageOf($invoice);

        $this->assertStringContainsString('RCV-TEST-1', $text, implode("\n", [
            '⛔ রসিদের নম্বরটা বিলের পাতায় নেই।',
            '',
            '⚠️ সংখ্যাটা গোনা হচ্ছে, কিন্তু কোন কাগজে এল তা বলা হচ্ছে না —',
            'আর তখন মনে হয় টাকাটা হারিয়ে গেছে।',
        ]));
    }

    /**
     * ⭐ আর অঙ্কটাও সারিতে থাকে — নম্বর একা যথেষ্ট নয়।
     *
     * ⓘ দুইটা রসিদ থাকলে কোনটা কত এনেছে সেটাই আসল প্রশ্ন। ⚠️ কেবল
     * নম্বর দেখালে মেলানোর জন্য প্রতিটা ভাউচার খুলে দেখতে হত।
     */
    public function test_each_receipt_shows_its_own_amount(): void
    {
        $invoice = $this->invoice('1000.0000');
        $this->receipt($invoice, 'RCV-TEST-1', '543.0000');
        $this->receipt($invoice, 'RCV-TEST-2', '200.0000');

        $text = $this->pageOf($invoice);

        foreach (['RCV-TEST-1', 'RCV-TEST-2', '543', '200'] as $needle) {
            $this->assertStringContainsString($needle, $text, "সারিতে '{$needle}' নেই।");
        }
    }

    /**
     * ⛔ খসড়া রসিদ টাকা নয়, তাই সে তালিকাতেও আসে না।
     *
     * ⚠️ শর্তগুলো [[SalesInvoice::paidByReceiptVouchers()]]-এর হুবহু।
     * ⓘ তালিকা আর যোগফল আলাদা শর্তে চললে পাঠক দুইটা আলাদা সত্য পেতেন —
     * উপরে লেখা "আদায় ৫৪৩", নিচে ৭৪৩ টাকার সারি।
     */
    public function test_a_draft_receipt_is_not_listed(): void
    {
        $invoice = $this->invoice('1000.0000');
        $this->receipt($invoice, 'RCV-DRAFT', '200.0000', DocumentStatus::DRAFT);

        $this->assertStringNotContainsString('RCV-DRAFT', $this->pageOf($invoice),
            '⛔ খসড়া রসিদ তালিকায় এসেছে — সে এখনো টাকা নয়।');
    }

    /**
     * পাহারাটা সত্যিই তাকায়।
     *
     * ⓘ উপরের "নেই" দাবিটা চিরকাল সবুজ থাকত যদি পাতাটাই খালি ফিরত।
     * ⚠️ তাই একই পাতায় একটা নিশ্চিত রসিদ **আছে** কি না সেটাও দেখা হয়।
     */
    public function test_the_page_really_rendered(): void
    {
        $invoice = $this->invoice('1000.0000');
        $this->receipt($invoice, 'RCV-DRAFT', '200.0000', DocumentStatus::DRAFT);
        $this->receipt($invoice, 'RCV-REAL', '300.0000');

        $text = $this->pageOf($invoice);

        $this->assertStringContainsString($invoice->document_no, $text);
        $this->assertStringContainsString('RCV-REAL', $text);
    }

    private function pageOf(SalesInvoice $invoice): string
    {
        $html = (string) $this->get(route('sales.invoice.show', $invoice))->assertOk()->getContent();

        return (string) preg_replace('/\s+/u', ' ', strip_tags($html));
    }

    private function invoice(string $total): SalesInvoice
    {
        return SalesInvoice::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => FinancialYear::query()->where('is_current', true)->firstOrFail()->id,
            'document_no' => 'INV-PAPER-'.mt_rand(1000, 9999),
            'customer_id' => Customer::query()->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'total' => $total,
            'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    private function receipt(
        SalesInvoice $invoice,
        string $no,
        string $amount,
        string $status = DocumentStatus::CONFIRMED,
    ): Voucher {
        return Voucher::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => FinancialYear::query()->where('is_current', true)->firstOrFail()->id,
            'type' => Voucher::RECEIPT,
            'document_no' => $no,
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
            'narration' => 'পরীক্ষা',
            'against_type' => SalesInvoice::drillSourceType(),
            'against_id' => $invoice->getKey(),
            'status' => $status,
        ]);
    }
}
