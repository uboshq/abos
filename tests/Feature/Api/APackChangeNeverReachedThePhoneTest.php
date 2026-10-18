<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\ProductService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ পণ্যের প্যাক বা শাখা বদল ফোনে পৌঁছাত না (Api/Sync নিরীক্ষা, ১৪ অক্টোবর ২০২৬; [[ProductService::update()]])।
 *
 * ⚠️ ফোনের টানা `products.updated_at` দেখে, অথচ কেবল প্যাক (কার্টন ১২ → ২৪) বা কোন শাখায় বিক্রি হয় বদলালে পণ্যের সারি অপরিবর্তিত
 * থাকত — ফোন পুরনো প্যাকে অর্ডার গুনত, নতুন শাখার পণ্য দেখাতই না। ⭐ এখন এমন বদলে পণ্যের সারিও "বদলেছে"।
 */
final class APackChangeNeverReachedThePhoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_branch_list_change_moves_the_products_sync_time(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $product = Product::query()->orderBy('id')->firstOrFail();
        DB::table('inv_products')->where('id', $product->id)->update(['updated_at' => now()->subDays(3)]);
        $before = Product::query()->findOrFail($product->id)->updated_at;

        app(ProductService::class)->update($product->fresh(), ['branch_table' => '1', 'branch_ids' => [$company->defaultBranch()?->id]]);

        $this->assertTrue(Product::query()->findOrFail($product->id)->updated_at->gt($before),
            '⛔ শাখার তালিকা বদলাল, অথচ পণ্যের সারি "বদলায়নি" — ফোনে পৌঁছাবে না');
    }
}
