<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * চালান থেকে আসা বিল সম্পাদনাতেও চালানেই বাঁধা — Sales লাইন-ধরা নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (মালিক, ২১ সেপ্টেম্বর:
 * *"r kono vabei bill generate hobe na"*)।
 *
 * ⛔ আগে PUT-এ চালানের পাহারাই ছিল না — চালানের যোগ ছাড়া যেকোনো পণ্য বসিয়ে পাকা করলে মাল সরাসরি গুদাম থেকে বেরোত।
 */
final class ABillFromAChallanStaysOnItsChallanWhenEditedTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_edit_cannot_add_a_line_that_is_not_on_the_bills_challan(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $paper = ['customer_id' => Customer::query()->orderBy('id')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'trx_date' => now()->toDateString()];
        $product = Product::query()->orderBy('id')->firstOrFail();

        $challan = app(DeliveryChallanService::class)->create($paper + ['own_transport' => true, 'vehicle_no' => 'DHA-GA-11-2233'],
            [['product_id' => $product->id, 'delivered_qty' => '5', 'rate' => '10']]);
        app(DeliveryChallanService::class)->confirm($challan);
        $challanLine = $challan->lines()->firstOrFail();

        $bill = app(SalesInvoiceService::class)->create($paper, [['product_id' => $product->id, 'qty' => '5', 'rate' => '10']]);

        // ⓘ "এই বিল ঐ চালান থেকে" — বিলের সারি চালানের সারিতে যুক্ত
        DB::table('sal_invoice_lines')->where('sales_invoice_id', $bill->id)->update(['delivery_challan_line_id' => $challanLine->id]);

        $put = fn (array $line) => $this->put(route('sales.invoice.update', $bill), $paper + ['lines' => [$line + [
            'product_id' => $product->id, 'qty' => '5', 'rate' => '10', 'discount' => '0']]]);

        $put([])->assertSessionHasErrors('lines');

        $put(['delivery_challan_line_id' => $challanLine->id])->assertSessionDoesntHaveErrors('lines');
    }
}
