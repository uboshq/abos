<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PrintsTheStandardPaper;
use Tests\TestCase;

/**
 * ⛔ পাকা বিল `/draft` দরজায় "চূড়ান্ত নয়" হয়ে ছাপা হয় না — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (ছাপা ১৭; [[SalesPrintController::draft()]])।
 *
 * ⓘ খাতায় বসা বিলও এই দরজায় "খসড়া — এটি চূড়ান্ত বিল নয়" লিখে ছাপা হত, গোনা আর DUPLICATE ছাড়া।
 */
final class APostedBillIsNotPrintedAsADraftTest extends TestCase
{
    use PrintsTheStandardPaper;
    use RefreshDatabase;

    public function test_the_draft_door_refuses_a_posted_bill_and_the_normal_door_prints_it(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->printTheStandardPaper();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $bill = app(DirectSaleService::class)->complete(
            ['customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'own_transport' => '1'],
            [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '2', 'rate' => '10', 'free_qty' => '0']],
        )['invoice']->fresh();
        $this->assertSame('confirmed', $bill->status);

        $this->get(route('sales.print.draft', $bill))->assertSessionHasErrors('status');
        $this->get(route('sales.print.invoice', $bill))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // ⓘ পর্দার ছাপার মেনুও পাকা বিলে ঐ দরজা দেখায় না — নইলে বোতাম চাপলেই ফেরত আসত
        $screen = $this->get(route('sales.invoice.show', $bill))->assertOk();
        $screen->assertDontSee(route('sales.print.draft', $bill), false);
        // ⓘ মেনুটা নিজে আঁকা হয় — ছাঁচের লেখা পর্দায় গলে পড়ে না, আর আসল বিলের ছাপা পথ থাকে
        $screen->assertDontSee("route('sales.print", false);
        $screen->assertSee(route('sales.print.invoice', $bill), false);
    }

    /**
     * ⛔ বাতিল বিল "খসড়া — চূড়ান্ত নয়" হয়ে ছাপে না, আর মেনুতে ঐ পথ নেই (১১ অক্টোবর ২০২৬, PR #17 রিভিউ ⚠️১৫)।
     *
     * ⓘ দরজা খসড়া আর পাকা দুইটাই ফেরায়; বাকি ছিল কেবল বাতিল বিল — মেনুতে "চূড়ান্ত নয়" দেখাত, আর কাগজে "খসড়া" আর "বাতিল"
     * পাশাপাশি। এখন বাতিল বিলের গায়ে কেবল "বাতিল" (আসল দরজার মতো), আর মেনুতে পথটাই নেই।
     */
    public function test_a_cancelled_bill_is_not_called_a_draft_and_the_menu_offers_no_draft_print(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->printTheStandardPaper();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $service = app(\App\Modules\Sales\Services\SalesInvoiceService::class);
        $bill = $service->cancel($service->create(
            ['customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '2', 'rate' => '10']],
        ), 'ভুল বিল')->fresh();
        $this->assertSame('cancelled', $bill->status);

        $notice = null;
        \Illuminate\Support\Facades\View::composer('print.*', function ($view) use (&$notice) {
            $notice ??= (string) ($view->getData()['doc']->notice ?? '');
        });

        $this->get(route('sales.print.draft', $bill))->assertOk();
        $this->assertStringContainsString(__('core.print.cancelled_notice'), (string) $notice, 'ⓘ বাতিলের বাক্সই নেই — দৃশ্যটা ঠিক বানানো যায়নি');
        $this->assertStringNotContainsString(__('core.print.draft_notice'), (string) $notice, '⛔ বাতিল বিলে "খসড়া — চূড়ান্ত নয়"');

        $screen = $this->get(route('sales.invoice.show', $bill))->assertOk();
        $screen->assertDontSee(route('sales.print.draft', $bill), false);
    }
}
