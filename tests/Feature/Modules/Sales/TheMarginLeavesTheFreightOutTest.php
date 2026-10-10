<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Dashboard\SalesCharts;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ ড্যাশবোর্ডের মাসের লাভ বিলে যোগ করা ভাড়াকে বিক্রি ধরে না — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (বিক্রয় ৮; [[SalesCharts::profit()]])।
 *
 * ⓘ ১,০০০ টাকার মাল আর ১৫০ টাকা ভাড়া, খরচ ৮০০। খাতায় ভাড়া ভাড়ার আয়, বিক্রি নয় ([[MonthlySalesReport]] তাই বাদ দেয়); আগে ড্যাশবোর্ড
 * বিক্রি ১,১৫০ আর লাভ ৩৫০ দেখাত — মাসিক রিপোর্ট বলত ১,০০০ আর ২০০।
 */
final class TheMarginLeavesTheFreightOutTest extends TestCase
{
    use RefreshDatabase;

    public function test_freight_on_the_bill_is_not_counted_as_sales(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        // ⓘ এ মাসে কেবল এই একটা বিল
        DB::table('sal_invoices')->update(['trx_date' => now()->subYears(2)->toDateString()]);
        DB::table('sal_returns')->update(['trx_date' => now()->subYears(2)->toDateString()]);

        SalesInvoice::query()->create(['branch_id' => $company->defaultBranch()?->id, 'document_no' => 'INV-FR-1',
            'customer_id' => Customer::query()->value('id'), 'trx_date' => now()->toDateString(),
            'subtotal' => '1000', 'discount' => '0', 'tax' => '0', 'freight_charge' => '150', 'total' => '1150',
            'cost_of_goods' => '800', 'status' => DocumentStatus::CONFIRMED]);

        $chart = (new \ReflectionMethod(SalesCharts::class, 'profit'))->invoke(null);
        $this->assertNotNull($chart);

        $this->assertSame([Money::format('1000'), Money::format('800'), Money::format('200')], array_column($chart->parts, 'value'),
            '⛔ ভাড়া বিক্রি আর লাভে মিশল');
    }
}
