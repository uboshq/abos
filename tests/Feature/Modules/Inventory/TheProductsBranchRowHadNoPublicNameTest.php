<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * পণ্যের শাখার সারির বাইরের নাম ছিল না — ৬ অক্টোবর ২০২৬ (PublicIdTest-এর লাল)।
 *
 * ⭐ ঘরটা এল মাইগ্রেশনে, আর নতুন সারি নাম পায় [[ProductBranch]]-এর জন্মে। ⓘ দাবি দুটো: নতুন সারি নাম পায়, আর আবার
 * `sync()` করলে আগের সারির নাম **বদলায় না** — বাইরের নাম স্থির না থাকলে তার কোনো মানে নেই।
 */
final class TheProductsBranchRowHadNoPublicNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_branch_row_gets_a_public_name_that_survives_a_resync(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $home = $company->defaultBranch();
        $other = Branch::query()->where('company_id', $company->id)->whereKeyNot($home->id)->first()
            ?? Branch::query()->create(['company_id' => $company->id, 'code' => 'PBRB', 'name_en' => 'Other branch', 'is_active' => true]);
        $product = Product::query()->create(['code' => 'PBR', 'name_en' => 'Branch row probe', 'name_bn' => 'শাখা-সারি',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true]);

        $product->branches()->sync([$home->id => ['company_id' => $company->id]]);
        $first = DB::table('inv_product_branches')->where('product_id', $product->id)->where('branch_id', $home->id)->value('public_id');
        $this->assertNotEmpty($first, '⛔ নতুন শাখার সারি বাইরের নাম পেল না।');

        $product->branches()->sync([$home->id => ['company_id' => $company->id], $other->id => ['company_id' => $company->id]]);

        $this->assertSame($first, DB::table('inv_product_branches')->where('product_id', $product->id)->where('branch_id', $home->id)->value('public_id'),
            '⛔ আবার sync করায় আগের সারির বাইরের নাম বদলে গেল।');
        $this->assertNotEmpty(DB::table('inv_product_branches')->where('product_id', $product->id)->where('branch_id', $other->id)->value('public_id'));
    }
}
