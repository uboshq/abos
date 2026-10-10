<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ চালান হয়েছে, বিল হয়নি — সেই মালের মূল্য পর্দায় কোথাও ছিল না (পুরো-ERP অডিট, মজুদ ⚠️৪; মালিকের "সব খোলা ভুল", ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ চালান তাক থেকে মাল নেয়, কিন্তু খরচ স্তর থেকে বেরোয় বিলের দিনে। তাই মজুদের পর্দা (তাক × গড়) খাতার মজুদ খাতের চেয়ে কম
 * দেখাত, আর পার্থক্যটা কোথায় তা কেউ বলতে পারত না। এখন যোগফলের পট্টিতে আলাদা ঘর — মূল্য আর এই ঘর মিলে স্তরের মোট।
 * ⓘ কেবল গোটা কোম্পানির দৃশ্যে; গুদাম বাছলে ঘরটা আসে না (স্তর কোম্পানির, তাক ছাঁকা)।
 */
final class TheStockScreenSaysWhatLeftButIsNotBilledTest extends TestCase
{
    use RefreshDatabase;

    public function test_goods_out_on_a_challan_but_not_billed_show_beside_the_stock_value(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $grand = fn (array $q = []) => $this->get(route('inventory.stock.index', $q))->assertOk()->viewData('grand');
        $before = $grand();
        $this->assertArrayHasKey('not_billed_value', $before, '⛔ গোটা কোম্পানির দৃশ্যে "চালান হয়েছে, বিল হয়নি" ঘরটাই নেই।');

        $product = Product::query()->create(['code' => 'NB-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Not billed probe',
            'name_bn' => 'বিল-না-হওয়ার নমুনা', 'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id, 'is_active' => true]);
        app(StockService::class)->move(product: $product, warehouse: $warehouse, sourceType: 'test.in', sourceId: 1, floor: '10');
        app(CostLayerService::class)->receive($product, '10', '10', 'test.in', 1, 'IN-NB');
        // ⓘ ২টা এসেছে, বসেনি — এগুলো বিল-না-হওয়া নয়, মজুদেরই অংশ
        app(StockService::class)->move(product: $product, warehouse: $warehouse, sourceType: 'test.in', sourceId: 2, unplaced: '2');
        app(CostLayerService::class)->receive($product, '2', '10', 'test.in', 2, 'IN-NB2');
        // ⓘ চালান — তাক থেকে ৪টা বেরোল, খরচ স্তরে রইল (বিল হলে বেরোবে)
        app(StockService::class)->move(product: $product, warehouse: $warehouse, sourceType: 'test.challan', sourceId: 1, floor: '-4');

        $after = $grand();
        $this->assertSame(0, bccomp(bcsub((string) $after['stock_value'], (string) $before['stock_value'], 4), '80', 4), '⛔ তাকের ৬টা আর বসেনি ২টার মূল্য ৮০ নয়।');
        $this->assertSame(0, bccomp(bcsub((string) $after['not_billed_value'], (string) $before['not_billed_value'], 4), '40', 4),
            '⛔ চালান হয়েছে বিল হয়নি এমন ৪টার মূল্য ৪০ দেখাল না।');

        $this->assertArrayNotHasKey('not_billed_value', $grand(['warehouse_id' => $warehouse->id]), '⛔ গুদাম বাছলেও ঘরটা এল — অন্য গুদামের মাল ঢুকত।');
    }
}
