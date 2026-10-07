<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ গেট পাসে বিলের তারিখ আজকের হত, অর্থবছর পুরনো থাকত — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (বিক্রয় ⓘ১২)।
 *
 * ⓘ [[GoodsIssue::issue()]] খসড়া বিলের তারিখ আজকের করত, কিন্তু `financial_year_id` বদলাত না: বছর পেরিয়ে গেট পার হলে বিল নতুন
 * বছরের তারিখে, পুরনো বছরের ঘরে। এখন বছরও আজকের তারিখের।
 */
final class TheGateBillTakesTodaysYearTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_written_last_year_passes_the_gate_into_this_years_books(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SettingsService::class)->set('sales.invoice_at_goods_issue', true);

        $customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $customer->forceFill(['credit_limit' => '1000000'])->save();

        $this->post(route('sales.direct.store'), [
            'customer_id' => $customer->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(), 'payment_term' => 'credit', 'vehicle_owner' => 'customer', 'delivery_mode' => 'send_later',
            'ship_to' => 'কাপ্তান বাজার', 'ship_date' => now()->addDay()->toDateString(),
            'lines' => [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->id, 'qty' => '2', 'rate' => '10', 'free_qty' => '0']],
        ])->assertSessionHasNoErrors();

        $challan = DeliveryChallan::query()->latest('id')->firstOrFail();
        $draft = SalesInvoice::query()->latest('id')->firstOrFail();
        $this->assertSame(DocumentStatus::DRAFT, $draft->status, 'প্রস্তুতিটাই ভুল — বিল গেটের অপেক্ষায় খসড়া থাকার কথা');

        // ⓘ খসড়াটা গত বছরের ঘরে বসা — যেমন জুনে লেখা, জুলাইয়ে গেট পার
        $current = FinancialYear::forDate(now());
        $last = FinancialYear::query()->create([
            'name' => 'LAST-'.$current->name, 'starts_on' => $current->starts_on->copy()->subYear()->toDateString(),
            'ends_on' => $current->starts_on->copy()->subDay()->toDateString(), 'is_closed' => false, 'is_current' => false,
        ]);
        $draft->forceFill(['financial_year_id' => $last->id, 'trx_date' => $last->ends_on])->save();

        app(DeliveryStageService::class)->move($challan->fresh(), DeliveryStage::DISPATCHED);

        $bill = $draft->fresh();
        $this->assertSame(DocumentStatus::CONFIRMED, $bill->status);
        $this->assertSame(now()->toDateString(), $bill->trx_date->toDateString());
        $this->assertSame((int) $current->id, (int) $bill->financial_year_id, '⛔ গেট পাসের বিল আজকের তারিখে, অথচ গত বছরের ঘরে');
    }
}
