<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\DealerBinding;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ শাখায় সীমিত ব্যবস্থাপক আইডি লিখে অন্য শাখার ডিলার নিজের বিক্রয়কর্মীকে বেঁধে দিতেন (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬;
 * [[DealerBindingService::bind()]])।
 *
 * ⚠️ বাঁধার পর্দা কেবল নিজের শাখার ডিলার দেখায়, কিন্তু সার্ভার যেকোনো শাখার আইডি নিত — অথচ ঐ গ্রাহকের পাতা, সম্পাদনা আর পোর্টাল
 * তাঁর কাছে বন্ধ ([[CustomerPolicy]])। বাঁধনের পর বিক্রয়কর্মী অন্য শাখার ডিলারের সব কাগজ দেখতেন।
 */
final class ABranchManagerBoundAnotherBranchsDealerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_branch_manager_binds_only_dealers_of_his_branch(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $mine = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $other = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->whereKeyNot($mine->id)->orderBy('id')->firstOrFail();
        $sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $manager = User::factory()->create(['current_company_id' => $company->id]);
        $manager->companies()->attach($company->id, ['is_active' => true]);
        $manager->givePermissionTo(['customer.view', 'customer.binding.manage']);
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $manager->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $mine->id]);
        app(DataScope::class)->forget();

        $ours = Customer::query()->create([
            'company_id' => $company->id, 'branch_id' => $mine->id, 'code' => 'BIND-OURS', 'name_en' => 'Our Dealer',
            'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);
        $theirs = Customer::query()->create([
            'company_id' => $company->id, 'branch_id' => $other->id, 'code' => 'BIND-THEIRS', 'name_en' => 'Their Dealer',
            'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);

        $this->actingAs($manager)->post(route('customer.binding.store'), [
            'user_id' => $sales->id, 'customer_ids' => [$theirs->id], 'starts_on' => now()->toDateString(),
        ])->assertSessionHasErrors('customer_ids');

        $this->assertFalse(DealerBinding::query()->where('customer_id', $theirs->id)->where('user_id', $sales->id)->exists(),
            '⛔ অন্য শাখার ডিলার শাখার ব্যবস্থাপকের হাতে বাঁধা পড়ল');

        // ⓘ নিজের শাখার ডিলার আগের মতোই বাঁধা যায় — দরজাটা বন্ধ নয়, কেবল দেয়াল
        $this->actingAs($manager)->post(route('customer.binding.store'), [
            'user_id' => $sales->id, 'customer_ids' => [$ours->id], 'starts_on' => now()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertTrue(DealerBinding::query()->where('customer_id', $ours->id)->where('user_id', $sales->id)->exists());
    }
}
