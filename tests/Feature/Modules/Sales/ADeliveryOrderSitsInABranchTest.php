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
