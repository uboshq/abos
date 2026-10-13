<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * ⛔ পোর্টালের হোমে কেবল পাকা বিল — ১৩ অক্টোবর ২০২৬, Sales লাইন-ধরা নিরীক্ষা ([[CustomerPapers::invoices()]])।
 *
 * ⓘ বিপজ্জনক ইনপুট: একই ডিলারের একটা খসড়া বিল পাশে একটা পাকা বিল। আগে দুটোই দেখাত — খসড়াটার
 * খতিয়ানে কোনো সারি নেই, তাই ডিলার একটা "বিল" দেখতেন যার টাকা তাঁর বকেয়ায় নেই।
 */
final class ThePortalShowsOnlyPostedBillsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_bill_is_not_on_the_portal_home_and_a_posted_one_is(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
        app(SettingsService::class)->set('customer.credit_limit_enabled', true);
        app(SettingsService::class)->set('customer.zero_limit_blocks', false);

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $rice = Product::query()->where('name_en', 'Miniket Rice 50kg')->firstOrFail();

        $dealer = app(CustomerService::class)->create([
            'name_en' => 'Portal Bills', 'credit_limit' => '0', 'credit_days' => 30,
        ]);
        $dealer->forceFill(['credit_limit' => '100000', 'portal_enabled' => true, 'portal_password' => 'dealer-pass-1'])->save();

        $posted = app(DirectSaleService::class)->complete(
            ['customer_id' => $dealer->id, 'warehouse_id' => $warehouse->id,
                'trx_date' => now()->toDateString(), 'deposit' => '0'],
            [['product_id' => $rice->id, 'qty' => '1', 'rate' => '3550']],
        )['invoice']->fresh();

        $draft = app(SalesInvoiceService::class)->create(
            ['customer_id' => $dealer->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $rice->id, 'qty' => '1', 'rate' => '3550']],
        );

        $this->actingAs($dealer->fresh(), 'portal')->get(route('sales.portal.home'))->assertOk()
            ->assertViewHas('invoices', fn (Collection $bills) => $bills->contains('id', $posted->id)
                && ! $bills->contains('id', $draft->id));
    }
}
