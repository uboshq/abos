<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Core\Support\ViewedBranch;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\OpeningStockService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ পেছনের তারিখের খোলা মজুদ কেবল নিজের গুদাম দেখত, অথচ খরচের স্তর গোটা কোম্পানির; আর এক-পণ্যের খোলা মজুদ দেখত না
 * পণ্যটা এই শাখায় বিক্রি হয় কি না (পুরো-ERP অডিট, ৯ অক্টোবর ২০২৬, মজুদের দুই ⓘ; মালিকের "সব খোলা ভুল", ১০ অক্টোবর ২০২৬)।
 */
final class OpeningStockFollowsTheWholeCompanyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
    }

    public function test_a_backdated_opening_waits_for_goods_that_left_another_warehouse_after_that_day(): void
    {
        $branch = $this->company->defaultBranch()->id;
        $here = Warehouse::query()->create(['branch_id' => $branch, 'code' => 'OP-1', 'name_en' => 'Opening here', 'is_active' => true]);
        $there = Warehouse::query()->create(['branch_id' => $branch, 'code' => 'OP-2', 'name_en' => 'Opening there', 'is_active' => true]);
        $product = Product::query()->create(['code' => 'OP-'.mb_substr(md5(microtime()), 0, 6), 'name_en' => 'Opening probe',
            'name_bn' => 'খোলার নমুনা', 'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id, 'is_active' => true]);

        // ⓘ অন্য গুদামে: দশ দিন আগে এল, তিন দিন আগে বেরোল
        app(StockService::class)->move(product: $product, warehouse: $there, sourceType: 'test.in', sourceId: 1, floor: '10', date: now()->subDays(10));
        app(StockService::class)->move(product: $product, warehouse: $there, sourceType: 'test.out', sourceId: 2, floor: '-4', date: now()->subDays(3));

        // ⛔ পাঁচ দিন আগের খোলা — বের হওয়ার আগের দিন, তাই নয়
        $this->assertFalse(app(OpeningStockService::class)->stillOpen($product, $here, now()->subDays(5)),
            '⛔ অন্য গুদামের পরের বিক্রি সত্ত্বেও পেছনের তারিখে খোলা মজুদ খোলা রইল।');

        try {
            app(OpeningStockService::class)->bringIn($product, $here, '5', '20', now()->subDays(5)->toDateString());
            $this->fail('⛔ খোলার স্তর অন্য গুদামের বিক্রির আগে বসে গেল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('product_id', $e->errors());
        }
        $this->assertSame(0, StockMovement::query()->where('warehouse_id', $here->id)->count(), '⛔ ফিরিয়েও কিছু বসল।');

        // ⓘ আজকের খোলা — বের হওয়ার পরে, তাই চলে; আর অন্য গুদামের **আগের** বের হওয়া আটকায় না
        $this->assertTrue(app(OpeningStockService::class)->stillOpen($product, $here, now()), '⛔ বের হওয়ার পরের তারিখেও আটকাল।');
        app(OpeningStockService::class)->bringIn($product, $here, '5', '20', now()->toDateString());
        $this->assertSame(0, bccomp(app(StockService::class)->floorQty($product, $here), '5', 4));
    }

    public function test_one_product_opening_takes_only_a_product_sold_in_this_branch(): void
    {
        $home = $this->company->defaultBranch();
        $other = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->whereKeyNot($home->id)->firstOrFail();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $theirs = Product::query()->create(['code' => 'OP-T-'.mb_substr(md5(microtime()), 0, 6), 'name_en' => 'Their product',
            'name_bn' => 'অন্য শাখার পণ্য', 'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id, 'is_active' => true]);
        DB::table('inv_product_branches')->insert(['company_id' => $this->company->id, 'product_id' => $theirs->id, 'branch_id' => $other->id]);

        $this->owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $home->id])->save();
        app(\App\Core\Services\DataScope::class)->forget();
        $this->actingAs($this->owner->fresh());
        $this->assertSame((int) $home->id, ViewedBranch::one(), 'প্রস্তুতিটাই ভুল — এক শাখা দেখা হচ্ছে না।');

        $this->from(route('inventory.stock.opening'))->post(route('inventory.stock.opening.store'), [
            'product_id' => $theirs->id, 'warehouse_id' => $warehouse->id, 'qty' => '3', 'unit_cost' => '10',
        ])->assertSessionHasErrors('product_id');

        $this->assertSame(0, StockMovement::query()->where('product_id', $theirs->id)->count(), '⛔ অন্য শাখার পণ্যের খোলা মজুদ এই শাখায় বসল।');
    }
}
