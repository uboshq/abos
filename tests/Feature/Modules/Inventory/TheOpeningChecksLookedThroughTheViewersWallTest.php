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
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\OpeningStockService;
use App\Modules\Inventory\Services\StockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * খোলা মজুদের যাচাই মানুষের দেখার দেয়ালের ভেতর দিয়ে দেখত — মজুদ লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⛔ [[OpeningStockService::stillOpen()]], `exists()`, `editable()` আর প্রথম চলাচলের খোঁজ চলত দেয়ালসহ। শাখাহীন গুদামের
 * যে সারি অন্য শাখার নামে লেখা, শাখা-সীমিত মানুষ সেটা দেখেন না — তখন "কিছুই বের হয়নি", আর পেছনের তারিখে খোলা মজুদ
 * বসত মাল বেরিয়ে যাওয়ার পরেও: FIFO-র খরচ আর লাভ চুপচাপ ভুল। ⓘ পাশের `$elsewhere` আগে থেকেই দেয়াল ছাড়া খুঁজত।
 */
final class TheOpeningChecksLookedThroughTheViewersWallTest extends TestCase
{
    use RefreshDatabase;

    public function test_goods_that_left_under_another_branch_close_the_opening_for_a_branch_limited_person(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $home = $company->defaultBranch();
        $other = Branch::query()->where('company_id', $company->id)->whereKeyNot($home->id)->first()
            ?? Branch::query()->create(['company_id' => $company->id, 'code' => 'OCB', 'name_en' => 'Other branch', 'is_active' => true]);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        // ⓘ শাখাহীন গুদাম; মাল এল আর বেরোল অন্য শাখার নামে
        CompanyContext::set($company->id, $other->id);
        $shared = Warehouse::acrossWarehouses()->create(['code' => 'OC-SHARED', 'name_en' => 'Shared', 'is_active' => true, 'branch_id' => null]);
        $product = Product::query()->orderBy('id')->firstOrFail();
        app(StockService::class)->move(product: $product, warehouse: $shared, sourceType: 'test.in', sourceId: 1, floor: '5', date: now()->subDays(3));
        app(StockService::class)->move(product: $product, warehouse: $shared, sourceType: 'test.out', sourceId: 2, floor: '-5', date: now()->subDays(2));

        $this->assertFalse(app(OpeningStockService::class)->stillOpen($product, $shared, now()->subDays(5)),
            'পূর্বশর্ত: সব দেখেন এমন মানুষের কাছে খোলার সময় পেরিয়ে গেছে।');

        // ⭐ এখন এক শাখায় সীমিত মানুষ — অন্য শাখার সারি তাঁর চোখের আড়ালে
        CompanyContext::set($company->id, $home->id);
        $clerk = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $clerk->forceFill(['view_all_branches' => true])->save();
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $home->id]);
        app(DataScope::class)->forget();
        $this->actingAs($clerk);

        $this->assertFalse(app(OpeningStockService::class)->stillOpen($product, $shared, now()->subDays(5)),
            'শাখা-সীমিত মানুষের কাছে "কিছুই বের হয়নি" — মাল বেরোনোর পরেও পেছনের তারিখে খোলা মজুদ বসত।');
    }
}
