<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * প্রতিটা ভূমিকা নিজের কাজ পারে — মালিকের ভূমিকা-ভাগ, ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ⭐ মালিক: *"baki sob tumar poramorso motei koro"* — ব্যবস্থাপক: সরাসরি ক্রয়, সরাসরি বিক্রয়
 * (বিকল্প হিসেবে), নতুন পণ্য, দাম বদল, গ্রাহক, সরবরাহকারী, একক ও এলাকা; গুদাম: আসা মাল
 * দেখা ও তাকে তোলা; হিসাবরক্ষক: বিল ও পরিশোধ; ক্রয়মূল্য দেখেন ব্যবস্থাপক ও হিসাবরক্ষক,
 * কাউন্টার কখনো নয়।
 *
 * ⛔ আগে এগুলো ছাঁচে বসেনি, আর ব্যবসা চালুর পরীক্ষায় প্রতিটা পাতা ৪০৩ দিয়েছিল — মালিক সব
 * চাবি নিয়ে বসে থাকায় কেউ টের পায়নি। ⚠️ প্রতিটা দাবি একই লোক দিয়ে দুইবার: ভূমিকা ছাড়া
 * ৪০৩, ভূমিকা দিলে ২০০ — যাতে দরজাটা সত্যিই চাবিটাই দেখে, অন্য কিছু নয়।
 */
final class EachRoleCanDoItsOwnWorkTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        /* ⚠️ FMART — এর ভূমিকাগুলো ছাঁচ থেকে জন্মায়, নতুন কোম্পানির মতো। TDEPOT-এর "accountant"
           ডেমো-সিডার হাতে বানায় (accounts.% কেবল), তাই ছাঁচের দাবি ওখানে মাপা যায় না। */
        $this->company = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    private function person(): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user;
    }

    /** ⚠️ এই কোম্পানির ভূমিকা — ভূমিকা কোম্পানিপ্রতি আলাদা ([[PermissionSyncer::applyRoleTemplates()]]) */
    private function role(string $name): Role
    {
        return Role::query()->where('name', $name)->where('guard_name', 'web')
            ->where('company_id', $this->company->id)->firstOrFail();
    }

    /**
     * @param  list<string>  $routes
     */
    private function assertRoleOpens(string $role, array $routes): void
    {
        $user = $this->person();

        foreach ($routes as $route) {
            $this->actingAs($user)->get(route($route))->assertForbidden();
        }

        $user->assignRole($this->role($role));
        $user = $user->fresh();

        foreach ($routes as $route) {
            $this->actingAs($user)->get(route($route))->assertOk();
        }
    }

    public function test_the_manager_buys_sells_and_keeps_the_masters(): void
    {
        $this->assertRoleOpens('Manager', [
            'purchase.direct.create',      // সরাসরি ক্রয়
            'purchase.receipt.index',      // মাল গ্রহণ
            'sales.direct.create',         // সরাসরি বিক্রয় — বিকল্প হিসেবে
            'inventory.product.create',    // নতুন পণ্য
            'customer.create',
            'supplier.create',
        ]);
    }

    public function test_the_accountant_writes_the_bills(): void
    {
        $this->assertRoleOpens('Accountant', ['purchase.direct.create', 'purchase.bill.index']);
    }

    public function test_the_warehouse_sees_the_goods_that_came(): void
    {
        $this->assertRoleOpens('Warehouse', ['purchase.receipt.index']);
    }

    /** ⛔ ক্রয়মূল্য — ব্যবস্থাপক ও হিসাবরক্ষক দেখেন, কাউন্টার কখনো নয়। */
    public function test_only_the_manager_and_the_accountant_see_the_cost(): void
    {
        $role = fn (string $name) => $this->role($name);

        $this->assertTrue($role('Manager')->hasPermissionTo('inventory.cost.view'));
        $this->assertTrue($role('Accountant')->hasPermissionTo('inventory.cost.view'));
        $this->assertFalse($role('Counter')->hasPermissionTo('inventory.cost.view'), '⛔ কাউন্টার ক্রয়মূল্য দেখে।');
        $this->assertFalse($role('Warehouse')->hasPermissionTo('inventory.cost.view'));

        /* ⓘ "এখন দিন"-এর টাকা কেবল পরিশোধের চাবিতে — ব্যবস্থাপকের নেই, তাই তাঁর ক্রয় বকেয়ায় যায় */
        $this->assertFalse($role('Manager')->hasPermissionTo('purchase.payment.create'));
    }
}
