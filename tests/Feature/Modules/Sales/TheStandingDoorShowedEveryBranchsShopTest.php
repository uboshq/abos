<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ ফোনের "standing" দরজা আর জমার বিজ্ঞপ্তি — যেকোনো শাখার গ্রাহক, সংখ্যার আইডিতেও (পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, ফোন ⛔৩)।
 *
 * আগে `/standing/1`, `/standing/2` … কোম্পানির সব গ্রাহকের বকেয়া, অগ্রিম, সীমা, চেক আর দাবি দিত; জমার বিজ্ঞপ্তিও অন্য
 * শাখার গ্রাহকের নামে তোলা যেত। এখন দেখার শাখার গ্রাহকই, আর ফোনে কেবল public_id ([[OrderStandingController]],
 * [[DepositRequestController]])। ⓘ ওয়েব এখনো সংখ্যার আইডি পাঠায় — সেটা চলে, শাখার দেয়ালসহ।
 */
class TheStandingDoorShowedEveryBranchsShopTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $home;

    private Branch $other;

    private Customer $shop;

    private User $sr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->home = $this->company->defaultBranch();
        CompanyContext::set($this->company->id, $this->home->id);
        $this->other = Branch::query()->where('company_id', $this->company->id)->whereKeyNot($this->home->id)->first()
            ?? Branch::query()->create(['company_id' => $this->company->id, 'code' => 'OOT4', 'name_en' => 'Other branch', 'is_active' => true]);

        $this->shop = Customer::query()->orderBy('id')->firstOrFail();
        $this->shop->forceFill(['branch_id' => $this->home->id])->save();

        $this->sr = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id,
            'view_all_branches' => false, 'current_branch_id' => $this->home->id]);
        $this->sr->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $this->sr->givePermissionTo([
            Permission::findOrCreate('sales.order.create', 'web'), Permission::findOrCreate('sales.collection.create', 'web'),
        ]));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** একই মানুষ, একই দোকান: নিজের শাখায় থাকলে খোলে, অন্য শাখায় গেলে নয়; ফোনে সংখ্যার আইডি কখনো নয় */
    public function test_the_phone_sees_only_the_viewed_branchs_shop_and_only_by_its_public_id(): void
    {
        $this->phone();
        $this->getJson('/api/v1/sales/standing/'.$this->shop->public_id.'?total=100')->assertOk();
        $this->getJson('/api/v1/sales/deposit-requests?customer='.$this->shop->public_id)->assertOk();

        $this->getJson('/api/v1/sales/standing/'.$this->shop->id)->assertNotFound();
        $this->getJson('/api/v1/sales/deposit-requests?customer='.$this->shop->id)->assertNotFound();

        $this->shop->forceFill(['branch_id' => $this->other->id])->save();
        $this->phone();
        $this->getJson('/api/v1/sales/standing/'.$this->shop->public_id)->assertNotFound();
        $this->getJson('/api/v1/sales/deposit-requests?customer='.$this->shop->public_id)->assertNotFound();
        $this->postJson('/api/v1/sales/deposit-requests', [
            'customer' => (string) $this->shop->public_id, 'claimed_on' => now()->toDateString(), 'amount' => '500', 'method' => 'cash',
        ])->assertNotFound();
        $product = \App\Modules\Inventory\Models\Product::query()->orderBy('id')->firstOrFail();
        $this->postJson('/api/v1/sales/offers', [
            'customer' => (string) $this->shop->public_id, 'lines' => [['product' => (string) $product->public_id, 'qty' => 1]],
        ])->assertNotFound();
    }

    /** ওয়েব এখনো সংখ্যার আইডিতে চলে — কিন্তু শাখার দেয়ালসহ */
    public function test_the_web_keeps_its_number_but_not_across_the_wall(): void
    {
        $this->actingAs($this->sr->fresh());
        $this->get(route('sales.order.standing', [$this->shop->id, 'total' => 100]))->assertOk();

        $this->shop->forceFill(['branch_id' => $this->other->id])->save();
        $this->get(route('sales.order.standing', [$this->shop->id, 'total' => 100]))->assertNotFound();
    }

    /**
     * ⛔ ফেরতের বিল-তালিকা আর কাউন্টারের বাতিল — দেখা শাখার দোকানই (পুরো ERP অডিট, ৯ অক্টোবর ২০২৬)। আগে শাখা ছাড়া খোঁজা
     * হত: অন্য শাখার দোকান চাইলে ফেরতে ছাঁকনিই বসত না, আর বাতিলে সেই দোকানের নামে নিরীক্ষার সারি বসত।
     */
    public function test_the_return_list_and_the_counter_void_reach_only_the_viewed_branchs_shop(): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $this->sr->givePermissionTo([
            Permission::findOrCreate('sales.return.create', 'web'), Permission::findOrCreate('sales.challan.create', 'web'),
            Permission::findOrCreate('sales.invoice.create', 'web'),
        ]));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $voided = fn () => \App\Models\AuditTrail::query()->where('action', 'counter_bill_voided')
            ->where('auditable_type', $this->shop->getMorphClass())->where('auditable_id', $this->shop->id)->count();

        $this->phone();
        $this->getJson('/api/v1/sales/returns/setup?customer='.$this->shop->public_id)->assertOk();
        $this->postJson('/api/v1/sales/direct/void', ['reason' => 'ভুল', 'customer' => (string) $this->shop->public_id])->assertOk();
        $this->assertSame(1, $voided());

        $this->shop->forceFill(['branch_id' => $this->other->id])->save();
        $this->phone();
        $this->getJson('/api/v1/sales/returns/setup?customer='.$this->shop->public_id)->assertNotFound();
        $this->postJson('/api/v1/sales/direct/void', ['reason' => 'ভুল', 'customer' => (string) $this->shop->public_id])->assertOk();
        $this->assertSame(1, $voided(), '⛔ অন্য শাখার দোকানের নামে বাতিলের নিরীক্ষা বসল।');
    }

    private function phone(): void
    {
        $this->app['auth']->forgetGuards();
        app(\App\Core\Services\DataScope::class)->forget();
        Sanctum::actingAs($this->sr->fresh(), [AuthController::APP]);
    }
}
