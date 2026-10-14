<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\SerialNumber;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\SerialNumberService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * এক শাখার কাউন্টার অন্য শাখার সিরিয়াল পিস "বিক্রি" করত — মজুদ লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⛔ [[SerialNumberService::issue()]] পিস খুঁজত কেবল কোম্পানির দেয়ালে; নম্বর টাইপ করলেই অন্য শাখার তাকের টিভি খাতায়
 * "বিক্রীত" হত, অথচ তাকে পড়ে থাকত। ⓘ পাশের [[SerialNumberService::backToStock()]] আগে থেকেই দেয়াল মানত।
 */
final class ASerialFromAnotherBranchWasSoldTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_branch_limited_person_cannot_issue_another_branchs_piece_but_the_owner_can(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $home = $company->defaultBranch();
        CompanyContext::set($company->id, $home->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $other = Branch::query()->where('company_id', $company->id)->whereKeyNot($home->id)->first()
            ?? Branch::query()->create(['company_id' => $company->id, 'code' => 'SNB', 'name_en' => 'Other branch', 'is_active' => true]);
        $far = Warehouse::acrossWarehouses()->create(['code' => 'SN-FAR', 'name_en' => 'Far', 'is_active' => true, 'branch_id' => $other->id]);

        $product = Product::query()->orderBy('id')->firstOrFail();
        $product->forceFill(['track_serial' => true])->save();
        app(SerialNumberService::class)->receive($product->fresh(), ['SN-FAR-1'], ['warehouse_id' => $far->id]);

        $clerk = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $clerk->forceFill(['view_all_branches' => true])->save();
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $home->id]);
        app(DataScope::class)->forget();
        $this->actingAs($clerk);

        try {
            app(SerialNumberService::class)->issue(['SN-FAR-1']);
            $this->fail('অন্য শাখার পিস এক শাখার মানুষের হাতে বিক্রি হয়ে গেল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('serials', $e->errors());
        }

        $this->assertSame(SerialNumber::IN_STOCK, SerialNumber::query()->numbered('SN-FAR-1')->firstOrFail()->status);

        // ⓘ সব শাখা দেখেন এমন মানুষ (মালিক) আগের মতোই পারেন
        $this->actingAs($owner);
        app(DataScope::class)->forget();
        app(SerialNumberService::class)->issue(['SN-FAR-1']);
        $this->assertSame(SerialNumber::SOLD, SerialNumber::query()->numbered('SN-FAR-1')->firstOrFail()->status);
    }
}
