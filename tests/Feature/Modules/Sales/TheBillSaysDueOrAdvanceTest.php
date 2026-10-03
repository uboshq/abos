<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
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
 */
final class TheBillSaysDueOrAdvanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_advance_prints_as_advance_and_the_next_due_bill_says_due_again(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $service = app(SalesInvoiceService::class);
        $invoice = $service->confirm($service->create(
            [
                'customer_id' => Customer::query()->firstOrFail()->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->firstOrFail()->id, 'qty' => '1', 'rate' => '100']],
        ));

        $facts = fn (SalesInvoice $bill) => (new ReflectionMethod(SalesPrintController::class, 'classicFacts'))
            ->invoke(app(SalesPrintController::class), $bill);

        /* গ্রাহকের জের −৫,০০০ — অর্থাৎ তাঁর অগ্রিম */
        $invoice->setRelation('customer', $invoice->customer->setAttribute('outstanding_net', '-5000'));
        $advance = $facts($invoice);
        $this->assertSame(0, bccomp(str_replace(',', '', (string) $advance['sums']['outstanding']), '-5000', 4), '⛔ অগ্রিমের ঋণাত্মক চিহ্ন হারিয়েছে।');
        /* ⭐ আগের সারিও চিহ্নসহ — "Previous Due aseni keno": আগের + এই বিলের বাকি = শেষ সারি */
        $this->assertSame(0, bccomp(
            bcadd(str_replace(',', '', (string) $advance['sums']['previous_due']), $invoice->dueAmount(), 4), '-5000', 4),
            '⛔ আগের বকেয়া শূন্যে থেমেছে — যোগ শেষ সারির সাথে মেলে না।');
        /* ⭐ "Previous Due na ese Previous Advance aste hobe" */
        $this->assertSame('(-) Previous Advance', __('sales::print.classic.previous_due', [], 'en'));
        $this->assertSame('আগের অগ্রিম', __('sales::print.classic.previous_due', [], 'bn'));
        $this->assertSame('Outstanding (Advance)', __('sales::print.classic.total_due', [], 'en'));
        $this->assertSame('মোট অগ্রিম', __('sales::print.classic.total_due', [], 'bn'));

        /* একই প্রসেসে পরের বিলে বকেয়া — নামটা ফিরে "Due" */
        $invoice->setRelation('customer', $invoice->customer->setAttribute('outstanding_net', '750'));
        $due = $facts($invoice);
        $this->assertSame(0, bccomp(str_replace(',', '', (string) $due['sums']['outstanding']), '750', 4));
        $this->assertSame('Outstanding (Due)', __('sales::print.classic.total_due', [], 'en'), '⛔ আগের বিলের "অগ্রিম" পরের বিলে থেকে গেছে।');
        $this->assertSame('মোট বকেয়া', __('sales::print.classic.total_due', [], 'bn'));
        $this->assertSame('(+) Previous Due', __('sales::print.classic.previous_due', [], 'en'), '⛔ আগের বিলের "Previous Advance" পরের বিলে থেকে গেছে।');
    }
}
