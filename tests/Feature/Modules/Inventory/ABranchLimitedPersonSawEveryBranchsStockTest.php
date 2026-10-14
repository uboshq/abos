<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Inventory\Models\Warehouse;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * শাখা-সীমিত মানুষ "সব শাখা"-তে সব শাখার মজুদ দেখতেন — মজুদ লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⛔ [[Warehouse::idsInViewedBranch()]] — বয়সের পাতা, কম-মজুদ, শেষ-মজুদ, ধীর-চলার কাঁচা কোয়েরির দেয়াল — "সব শাখা"-তে
 * `null` ("সব গুদাম") ফেরাত, যদি না মানুষটা গুদাম-সীমিত। ⓘ শাখা-সীমিত মানুষ (দুই শাখার একজন) তখন তৃতীয় শাখার মাল,
 * কাগজ আর আটকে থাকা টাকাও দেখতেন। ⭐ এখন তাঁর নাগালের শাখার গুদাম (আর শাখাহীন গুদাম); সীমাহীন মানুষ আগের মতোই সব।
 */
final class ABranchLimitedPersonSawEveryBranchsStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_branches_for_a_branch_limited_person_means_their_branches_only(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $home = $company->defaultBranch();
        CompanyContext::set($company->id, $home->id);

        $other = Branch::query()->where('company_id', $company->id)->whereKeyNot($home->id)->first()
            ?? Branch::query()->create(['company_id' => $company->id, 'code' => 'BLB', 'name_en' => 'Other branch', 'is_active' => true]);

        $near = Warehouse::acrossWarehouses()->create(['code' => 'BL-NEAR', 'name_en' => 'Near', 'is_active' => true, 'branch_id' => $home->id]);
        $far = Warehouse::acrossWarehouses()->create(['code' => 'BL-FAR', 'name_en' => 'Far', 'is_active' => true, 'branch_id' => $other->id]);

        $clerk = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $clerk->forceFill(['view_all_branches' => true])->save();
        UserDataScope::query()->create([
            'company_id' => $company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $home->id,
        ]);
        app(DataScope::class)->forget();
        $this->actingAs($clerk);

        $ids = Warehouse::idsInViewedBranch();

        $this->assertNotNull($ids, '"সব শাখা"-তে শাখা-সীমিত মানুষের দেয়ালই নেই — সব গুদাম।');
        $this->assertContains((int) $near->id, $ids);
        $this->assertNotContains((int) $far->id, $ids, 'নাগালের বাইরের শাখার গুদাম শাখা-সীমিত মানুষের তালিকায়।');

        // ⓘ সীমাহীন মানুষ (মালিক) আগের মতোই সব — `null`
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(DataScope::class)->forget();
        $this->assertNull(Warehouse::idsInViewedBranch(), 'মালিকের "সব শাখা" আর সব গুদাম নয়।');
    }
}
