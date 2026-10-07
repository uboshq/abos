<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Http\Controllers\SalesPrintController;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * বিলের শেষ সারি বকেয়া না অগ্রিম — মালিক, ৩ অক্টোবর ২০২৬: *"Outstanding due hole Outstanding (Due), r advance thakle
 * Outstanding (Advance)"*।
 *
 * ⓘ আগে অঙ্কটা আগের বকেয়া (শূন্যে থামানো) + এই বিলের বাকি — অগ্রিম কখনো দেখাতই না। এখন গ্রাহকের আসল জের,
 * অগ্রিম ঋণাত্মক চিহ্নেই ("na renatok hole renatok ei hobe"), নামটাও বলে কোন দিকে; আর এক প্রসেসে পরের বিলে আগের নাম থেকে যায় না।
 *
 * ⓘ ৬ অক্টোবর ২০২৬ থেকে জের বিলের মুহূর্তের, খাতা থেকে ([[AReprintedBillSaysWhatItSaidTest]]) — তাই এখানে গ্রাহকের আগের
 * জের খাতায় সত্যিকারের সারি বসিয়ে বানানো, আজকের মোট পাওনা জাল করে নয়।
 */
final class TheBillSaysDueOrAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->customer = Customer::query()->orderBy('id')->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '10000000'])->save();
    }

    public function test_an_advance_prints_as_advance_and_the_next_due_bill_says_due_again(): void
    {
        /* গ্রাহকের জের −৫,০০০ — অর্থাৎ তাঁর অগ্রিম, বিলের আগে খাতায় */
        $this->balanceBeforeTheBill('-5000');
        $invoice = $this->bill('100');
        $advance = $this->facts($invoice);

        // ⓘ হিসাব চিহ্নসহ (`signed_sums`) — কাগজে চিহ্ন ছাড়া (মালিক, ৪ অক্টোবর ২০২৬: "advance likle r - dewar dorkar nai")
        $this->assertSame(0, bccomp($this->plain($advance['signed_sums']['previous_due']), '-5000', 4), '⛔ আগের অগ্রিমের ঋণাত্মক চিহ্ন হিসাব থেকে হারিয়েছে।');
        $this->assertSame(0, bccomp($this->plain($advance['signed_sums']['outstanding']), bcadd('-5000', (string) $invoice->total, 4), 4),
            '⛔ শেষ জের = আগের অগ্রিম + এই বিল নয়।');
        $this->assertStringNotContainsString('-', (string) $advance['sums']['outstanding'], '⛔ "Advance" লেখা ঘরে এখনো "−"।');
        $this->assertStringNotContainsString('-', (string) $advance['sums']['previous_due'], '⛔ "Previous Advance" লেখা ঘরে এখনো "−"।');
        /* ⭐ "Previous Due na ese Previous Advance aste hobe" */
        $this->assertSame('(-) Previous Advance', __('sales::print.classic.previous_due', [], 'en'));
        $this->assertSame('আগের অগ্রিম', __('sales::print.classic.previous_due', [], 'bn'));
        $this->assertSame('Outstanding (Advance)', __('sales::print.classic.total_due', [], 'en'));
        $this->assertSame('মোট অগ্রিম', __('sales::print.classic.total_due', [], 'bn'));

        /* একই প্রসেসে পরের বিলে বকেয়া — আগের জের ৭৫০ বাকি, নামটা ফিরে "Due" */
        $this->balanceBeforeTheBill('750');
        $due = $this->facts($this->bill('100'));
        $this->assertSame(1, bccomp($this->plain($due['signed_sums']['outstanding']), '0', 4), 'প্রস্তুতিটাই ভুল — শেষ জের বকেয়া নয়।');
        $this->assertSame(1, bccomp($this->plain($due['signed_sums']['previous_due']), '0', 4), 'প্রস্তুতিটাই ভুল — আগের জের বকেয়া নয়।');
        $this->assertSame('Outstanding (Due)', __('sales::print.classic.total_due', [], 'en'), '⛔ আগের বিলের "অগ্রিম" পরের বিলে থেকে গেছে।');
        $this->assertSame('মোট বকেয়া', __('sales::print.classic.total_due', [], 'bn'));
        $this->assertSame('(+) Previous Due', __('sales::print.classic.previous_due', [], 'en'), '⛔ আগের বিলের "Previous Advance" পরের বিলে থেকে গেছে।');
    }

    /**
     * ⭐ বিলের চেয়ে বেশি জমা — মালিকের ছবি, S-0001: বিল ৩৯,১০৬.১২, জমা ৪০,০০০, আগের বকেয়া ৩০,৬৪২.১৫।
     * ⛔ আগে "Previous Due" ছাপত ২৯,৭৪৮.২৭ (আজকের মোট) — বাড়তি ৮৯৩.৮৮ দুবার বাদ পড়ত।
     */
    public function test_money_beyond_the_bill_does_not_hide_the_old_due(): void
    {
        /* আগের বকেয়া ১৩০; বিল, তার উপর জমা ১৫০ → শেষ জের ১৩০ + বিল − ১৫০ */
        $this->balanceBeforeTheBill('130');
        $invoice = $this->bill('100');
        $invoice->setAttribute('collected_total', '150')->setAttribute('voucher_total', '0');
        $after = bcsub(bcadd('130', (string) $invoice->total, 4), '150', 4);

        $facts = $this->facts($invoice);
        $this->assertSame(0, bccomp($this->plain($facts['sums']['previous_due']), '130', 4), '⛔ বাড়তি জমা আগের বকেয়া লুকিয়েছে।');
        $this->assertSame(0, bccomp($this->plain($facts['signed_sums']['outstanding']), $after, 4), '⛔ শেষ সারি আগের + বিল − জমা নয়।');

        $rows = (new ReflectionMethod(SalesPrintController::class, 'invoiceTotals'))->invoke(app(SalesPrintController::class), $invoice);
        $this->assertSame(0, bccomp($this->plain($rows['sales::print.outstanding']), $after, 4), '⛔ চলতি নকশার শেষ সারি ক্লাসিকের সাথে মেলে না।');
    }

    /** গ্রাহকের খাতায় একটা সারি, যাতে বিলের আগের জের ঠিক `$target` হয় */
    private function balanceBeforeTheBill(string $target): void
    {
        $gap = bcsub($target, $this->customer->fresh()->outstanding(), 4);

        LedgerEntry::query()->create([
            'company_id' => $this->customer->company_id,
            'branch_id' => CompanyContext::branchId(),
            'financial_year_id' => (int) FinancialYear::query()->orderBy('id')->firstOrFail()->id,
            'account_id' => Account::query()->where('is_group', false)->firstOrFail()->id,
            'party_type' => Customer::drillSourceType(),
            'party_id' => $this->customer->id,
            'trx_date' => now()->subDay()->toDateString(),
            'debit' => bccomp($gap, '0', 4) > 0 ? $gap : '0',
            'credit' => bccomp($gap, '0', 4) < 0 ? ltrim($gap, '-') : '0',
            'source_type' => 'print_test',
            'source_id' => $this->customer->id,
            'narration' => 'বিলের আগের জের',
        ]);
    }

    private function bill(string $rate): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->orderBy('id')->firstOrFail()->id, 'qty' => '1', 'rate' => $rate]],
        ));
    }

    /** @return array<string, mixed> */
    private function facts(SalesInvoice $bill): array
    {
        return (new ReflectionMethod(SalesPrintController::class, 'classicFacts'))->invoke(app(SalesPrintController::class), $bill);
    }

    private function plain(mixed $money): string
    {
        return bcadd(str_replace(',', '', (string) $money), '0', 4);
    }
}
