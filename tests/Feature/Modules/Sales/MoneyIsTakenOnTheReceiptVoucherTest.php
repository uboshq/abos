<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Navigation\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ গ্রাহকের টাকা নেওয়া হিসাবের রসিদ ভাউচারে — মালিকের নিয়ম, ১৯ সেপ্টেম্বর ২০২৬।
 *
 * ⛔ ২০ নভেম্বর ২০২৬ লাইভে: গ্রাহকের তালিকা আর বিলের তালিকার "⋯ → টাকা নিন" আর বিক্রয়ের ড্যাশবোর্ড তখনো পুরনো আদায়ের পাতা
 *   (COL-…) খুলত। সেই কাগজ রসিদের তালিকায় আসে না, আর আদায়ের তালিকা মেনুতে ছিল না — মালিক অনুমোদন দিয়ে খাতায় টাকা বসতে দেখলেন,
 *   কিন্তু কাগজটা কোথাও খুঁজে পেলেন না; বাকি চারটা খসড়াও না।
 *
 * দাবি: তিন দরজাই রসিদ ভাউচার খোলে (পক্ষ ভরা; বিলের দরজায় বিল আর বকেয়াও), কোনোটাই আদায়ের ফর্মে নয়; আর আগে লেখা আদায়ের কাগজ
 *   খোঁজার তালিকা বিক্রয়ের মেনুতে আছে।
 */
final class MoneyIsTakenOnTheReceiptVoucherTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        config(['abos.dashboards_v2' => true]);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    public function test_every_take_money_door_opens_the_receipt_voucher_not_the_old_collection_form(): void
    {
        $customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        // ⓘ একটা বাকির বিল — বিলের তালিকায় "টাকা নিন" খোলা থাকে
        app(DirectSaleService::class)->complete(
            ['customer_id' => $customer->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'own_transport' => '1'],
            [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '5', 'rate' => '1250', 'free_qty' => '0']],
        );

        $old = e(route('sales.collection.create'));
        $receipt = e(route('accounts.voucher.create', ['type' => 'receipt']));

        $customers = (string) $this->get(route('customer.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString($old, $customers, '⛔ গ্রাহকের তালিকার "টাকা নিন" এখনো পুরনো আদায়ের ফর্মে।');
        $this->assertStringContainsString($receipt.'?party_type=customer&amp;party_id=', $customers, '⛔ গ্রাহকের তালিকার "টাকা নিন" রসিদ ভাউচার খোলে না।');

        $invoices = (string) $this->get(route('sales.invoice.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString($old, $invoices, '⛔ বিলের তালিকার "টাকা নিন" এখনো পুরনো আদায়ের ফর্মে।');
        $this->assertMatchesRegularExpression('/'.preg_quote($receipt, '/').'\?party_type=customer&amp;party_id=\d+&amp;against_type=[^"&]+&amp;against_id=\d+&amp;amount=/',
            $invoices, '⛔ বিলের তালিকার "টাকা নিন" রসিদে বিল আর বকেয়া ভরে না।');

        $sales = (string) $this->get(route('module.dashboard', ['module' => 'sales']))->assertOk()->getContent();
        $this->assertStringNotContainsString($old, $sales, '⛔ বিক্রয়ের ড্যাশবোর্ডের "আদায়" এখনো পুরনো আদায়ের ফর্মে।');
    }

    public function test_the_collections_written_before_can_be_found_from_the_menu(): void
    {
        $rows = collect(app(MenuBuilder::class)->forUser($this->owner))->firstWhere('code', 'sales')['groups'] ?? [];
        $routes = collect($rows)->flatten(1)->pluck('url')->filter()->all();

        $this->assertContains(route('sales.collection.index'), $routes, '⛔ আগে লেখা আদায়ের কাগজ (COL-…) খোঁজার তালিকা মেনুতে নেই।');
    }
}
