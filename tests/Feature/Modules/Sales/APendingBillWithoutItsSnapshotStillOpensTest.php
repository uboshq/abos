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
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * মালিক, ১ অক্টোবর ২০২৬: *"পেন্ডিং বিল কাউন্টারে ওপেন হচ্ছে না"*।
 *
 * ⛔ পর্দার ছবি (`counter_draft`) ছাড়া রাখা খসড়া বাছলে পাতা একটা খালি নতুন বিল খুলত —
 * ডেমোতে S-0003/S-0004 (ছবি-ব্যবস্থার আগের খসড়া) হুবহু তাই করেছে। ⭐ ছবি না থাকলে খসড়ার
 * নিজের সারি থেকে পর্দা ফেরে।
 */
final class APendingBillWithoutItsSnapshotStillOpensTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_with_no_screen_snapshot_opens_with_its_customer_and_lines(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $customer = app(CustomerService::class)->create(['name_en' => 'Snapshot Proof Store', 'name_bn' => 'ছবি প্রমাণ স্টোর', 'credit_limit' => '0']);
        $customer->forceFill(['credit_limit' => '100000'])->save();
        app(SettingsService::class)->set('customer.credit_limit_enabled', true);
        $rice = Product::query()->where('name_en', 'Miniket Rice 50kg')->firstOrFail();

        $this->post(route('sales.direct.store'), [
            'own_transport' => '1', 'save_as_draft' => '1',
            'customer_id' => $customer->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'lines' => [['product_id' => $rice->id, 'qty' => '7', 'rate' => '3550']],
        ])->assertSessionHasNoErrors();

        $draft = SalesInvoice::query()->latest('id')->firstOrFail();
        $draft->forceFill(['counter_draft' => null, 'counter_screen' => null])->save();   // ⓘ ছবি-ব্যবস্থার আগের খসড়ার মতো

        $resume = $this->get(route('sales.direct.create', ['draft' => $draft->id]))->assertOk()->viewData('resume');

        $this->assertNotNull($resume, 'ছবি নেই বলে খসড়াটাই খুলল না — পাতা খালি নতুন বিল দেখাল।');
        $this->assertSame($draft->id, $resume['invoiceId']);
        $this->assertSame((string) $customer->id, (string) $resume['screen']['customerId'], 'খসড়া খুলল, কিন্তু ক্রেতা হারিয়ে গেছে।');
        $this->assertCount(1, $resume['screen']['lines'], 'খসড়া খুলল, কিন্তু পণ্যের সারি নেই।');
        $this->assertSame($rice->id, $resume['screen']['lines'][0]['id']);
        $this->assertSame(0, bccomp('7', (string) $resume['screen']['lines'][0]['qty'], 4));
        $this->assertSame(0, bccomp('3550', (string) $resume['screen']['lines'][0]['rate'], 4));
    }
}
