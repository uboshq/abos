<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনে বিক্রয় বিল আর চালান — মালিক, ১৩ অক্টোবর ২০২৬: *"Mobile App e kora invoice, challan, voucher — kono kichuri list
 * show korche na"*।
 *
 * দাবি ([[SalesPaperApiController]]):
 *  - কাউন্টারের বিক্রি বিলের তালিকায় প্রথমে, বাকি বিলের নিজের হিসাবে ([[SalesInvoice::dueAmount()]]); পাতায় সারি আর চালানের লিংক।
 *  - চালানের তালিকায় তার চালান, আর চালানের পাতায় এই বিল ([[ChallanBills]])।
 *  - তারিখের বাইরের কাগজ তালিকায় নেই; চাবি ছাড়া ৪০৩; সংখ্যার id ঠিকানা নয় (৪০৪)।
 *  - বিক্রয়কর্মী অন্যের দোকানের বিল তালিকাতেও পান না, পাতাতেও না (৪০৪)।
 */
final class ThePhoneListsTheSalesBillsAndChallansTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        CompanyContext::set((int) $this->company->id, $this->owner->current_branch_id);
    }

    public function test_a_counter_sale_is_on_the_bill_list_with_its_own_due_and_its_challan(): void
    {
        $invoice = $this->sell(Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail());

        $list = $this->phone($this->owner, '/api/v1/sales/invoices')->assertOk()->json();
        $this->assertSame($invoice->public_id, $list['rows'][0]['id'], '⛔ কাউন্টারের নতুন বিল ফোনের তালিকার মাথায় নেই।');
        $this->assertSame(0, bccomp($list['rows'][0]['due'], $invoice->fresh()->dueAmount(), 2), '⛔ ফোনের বাকি বিলের নিজের বাকি নয়।');
        $this->assertSame(0, bccomp($list['rows'][0]['total'], (string) $invoice->total, 2));
        $this->assertGreaterThanOrEqual(1, $list['count']);

        $page = $this->phone($this->owner, '/api/v1/sales/invoices/'.$invoice->public_id)->assertOk()->json();
        $this->assertSame($invoice->document_no, $page['no']);
        $this->assertNotEmpty($page['lines'], '⛔ বিলের সারি ফোনে নেই।');
        $this->assertStringContainsString('Cosmos', implode(' | ', array_column($page['lines'], 'name')), '⛔ বিলের পণ্যের নাম ফোনে নেই।');
        $this->assertCount(1, $page['challans'], '⛔ বিলের চালান ফোনে নেই।');

        $challans = $this->phone($this->owner, '/api/v1/sales/challans')->assertOk()->json('rows');
        $this->assertContains($page['challans'][0]['id'], array_column($challans, 'id'), '⛔ কাউন্টারের চালান ফোনের তালিকায় নেই।');

        $challan = $this->phone($this->owner, '/api/v1/sales/challans/'.$page['challans'][0]['id'])->assertOk()->json();
        $this->assertSame([$invoice->public_id], array_column($challan['bills'], 'id'), '⛔ চালানের পাতায় তার বিল নেই।');
        $this->assertNotEmpty($challan['lines']);
    }

    public function test_dates_outside_the_range_stay_out_and_the_doors_ask_their_keys(): void
    {
        $invoice = $this->sell(Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail());

        $lastMonth = Carbon::today()->subMonthNoOverflow();
        $old = $this->phone($this->owner, '/api/v1/sales/invoices?from='.$lastMonth->copy()->startOfMonth()->toDateString()
            .'&to='.$lastMonth->copy()->endOfMonth()->toDateString())->assertOk()->json('rows');
        $this->assertNotContains($invoice->public_id, array_column($old, 'id'), '⛔ এ মাসের বিল আগের মাসের তালিকায়।');

        $nobody = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $nobody->companies()->attach($this->company, ['is_active' => true]);
        $this->phone($nobody, '/api/v1/sales/invoices')->assertForbidden();
        $this->phone($nobody, '/api/v1/sales/challans')->assertForbidden();

        $this->phone($this->owner, '/api/v1/sales/invoices/'.$invoice->id)->assertNotFound();
        $this->phone($this->owner, '/api/v1/sales/invoices?status=nonsense')->assertStatus(422);
    }

    public function test_a_salesman_never_sees_another_salesmans_shop_bill(): void
    {
        $sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        CompanyContext::forCompany((int) $this->company->id, fn () => $sales->givePermissionTo(
            Permission::findOrCreate('sales.invoice.view', 'web'), Permission::findOrCreate('sales.challan.view', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($sales->fresh());
        $his = Customer::query()->pluck('id')->all();
        $this->actingAs($this->owner);
        $other = Customer::acrossDealers()->where('company_id', $this->company->id)->whereNotIn('id', $his)->where('is_active', true)->orderBy('id')->first();
        $this->assertNotNull($other, '⛔ দাবির ভিত্তি নেই — বিক্রয়কর্মী সব দোকান দেখেন।');

        $invoice = $this->sell($other);
        $this->assertContains($invoice->public_id, array_column($this->phone($this->owner, '/api/v1/sales/invoices')->json('rows'), 'id'),
            'দাবির ভিত্তি নেই — মালিকও বিলটা দেখছেন না।');

        $this->assertNotContains($invoice->public_id, array_column($this->phone($sales, '/api/v1/sales/invoices')->assertOk()->json('rows'), 'id'),
            '⛔ বিক্রয়কর্মী অন্যের দোকানের বিল দেখলেন।');
        $this->phone($sales, '/api/v1/sales/invoices/'.$invoice->public_id)->assertNotFound();
    }

    private function sell(Customer $customer): SalesInvoice
    {
        $warehouse = Warehouse::query()->where('code', 'WH-MMS')->firstOrFail();
        CompanyContext::set((int) $this->company->id, (int) $warehouse->branch_id);

        $result = app(DirectSaleService::class)->complete(
            ['customer_id' => $customer->id, 'warehouse_id' => $warehouse->id, 'own_transport' => '1', DirectSaleService::REPEAT_FIELD => '1'],
            [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->id, 'qty' => '2', 'rate' => '1500', 'free_qty' => '0']],
        );

        $invoice = $result['invoice']->fresh();
        $this->assertContains($invoice->status, DocumentStatus::POSTED, 'প্রস্তুতিটাই ভুল — বিক্রি পাকা হয়নি।');

        return $invoice;
    }

    private function phone(User $user, string $url): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->getJson($url);
    }
}
