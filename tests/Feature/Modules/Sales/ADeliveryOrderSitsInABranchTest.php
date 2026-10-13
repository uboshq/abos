<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Services\DeliveryOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DO শাখা ছাড়া জমা হয় না — অডিট ⛔১২ (সমন্বয়ক, ৬ অক্টোবর ২০২৬; DeliveryOrderService::create ঘরটাই লিখত না)।
 *
 * দাবি — একই কর্মী, একই গ্রাহক: হেডারে সুপার দেখলে DO সুপারে; "সব শাখা" দেখলে গ্রাহকের শাখায়; ডিলার নিজে লিখলে
 * তাঁর নিজের শাখায়, কর্মীর দেখা শাখা যা-ই হোক।
 */
final class ADeliveryOrderSitsInABranchTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Branch $home;

    private Branch $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->home = Branch::query()->firstOrCreate(['company_id' => $this->company->id, 'code' => 'HOMEX'],
            ['name_en' => 'Home', 'name_bn' => 'Home', 'is_active' => true]);
        $this->super = Branch::query()->firstOrCreate(['company_id' => $this->company->id, 'code' => 'SUPERX'],
            ['name_en' => 'Super', 'name_bn' => 'Super', 'is_active' => true]);

        $this->customer = Customer::query()->orderBy('id')->firstOrFail();
        $this->customer->forceFill(['branch_id' => $this->home->id])->save();
    }

    public function test_the_do_takes_the_viewed_branch_or_the_dealers_own(): void
    {
        $owner = auth()->user();

        CompanyContext::set($this->company->id, $this->super->id);
        $this->assertSame($this->super->id, $this->branchOf($owner), '⛔ সুপার দেখে লেখা DO সুপারে বসেনি।');

        CompanyContext::set($this->company->id, null);
        $this->assertSame($this->home->id, $this->branchOf($owner), '⛔ "সব শাখা" দেখে লেখা DO শাখাহীন — গ্রাহকের শাখা বসেনি।');

        // ⭐ ডিলার নিজে — প্রসঙ্গে সুপার থাকলেও তাঁর নিজের শাখা
        CompanyContext::set($this->company->id, $this->super->id);
        $this->assertSame($this->home->id, $this->branchOf($this->customer->fresh()), '⛔ ডিলারের নিজের DO তাঁর শাখায় বসেনি।');
    }

    /**
     * ⛔ নতুন ধারার আদেশে DO লেখা যায় না — Sales লাইন-ধরা নিরীক্ষা, ১৩ অক্টোবর ২০২৬। ⚠️ আগে সুইচ বন্ধ করে ঐ আদেশের উপর DO লেখা যেত:
     * আদেশ আর DO দুইটাই বাকির সীমায় গোনা হত, একই বিক্রি দুবার।
     */
    public function test_a_do_cannot_ride_on_an_order_that_holds_its_own_goods(): void
    {
        $product = Product::query()->orderBy('id')->value('id');
        $order = app(\App\Modules\Sales\Services\SalesOrderService::class)->create(
            ['customer_id' => $this->customer->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product, 'qty' => '2', 'rate' => '10']]);

        $write = fn () => app(DeliveryOrderService::class)->create(
            ['customer_id' => $this->customer->id, 'sales_order_id' => $order->id], [['product_id' => $product, 'qty' => '2']], auth()->user());

        $this->assertNotNull($write(), 'দৃশ্যটাই ভুল — পুরনো ধারার আদেশে DO লেখা গেল না।');

        \Illuminate\Support\Facades\DB::table('sal_orders')->where('id', $order->id)->update([
            'hold_mode' => \App\Modules\Sales\Support\SalesOrderStatus::HOLD_HOLDS,
            'status' => \App\Modules\Sales\Support\SalesOrderStatus::APPROVED,
        ]);

        try {
            $write();
            $this->fail('⛔ মাল-ধরা আদেশের উপর DO লেখা হয়ে গেল — সীমায় একই বিক্রি দুবার।');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('sales_order_id', $e->errors());
        }
    }

    private function branchOf(User|Customer $by): ?int
    {
        $branch = $this->write($by)->branch_id;

        return $branch === null ? null : (int) $branch;
    }

    private function write(User|Customer $by): DeliveryOrder
    {
        $data = $by instanceof User ? ['customer_id' => $this->customer->id] : [];

        return DeliveryOrder::query()->withoutGlobalScopes()->findOrFail(app(DeliveryOrderService::class)->create(
            $data, [['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => '2']], $by)->id);
    }
}
