<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\BatchService;
use App\Modules\Inventory\Services\FreeRatio;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\StrandedStock;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ পরে লট পাওয়া মালের ফ্রি-অনুপাত শূন্য গোনা হত (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬, মজুদ ⓘ১৩)।
 *
 * ⓘ [[FreeRatio]] লট ধরে গোনে "কত টাকার মাল আর কত ফ্রি এসেছিল", কেবল কেনা আর খোলা মজুদের উৎস থেকে। লট ধরার আগের মাল পরে
 * লট পায় [[StrandedStock::giveItALot()]] দিয়ে (`lot_assignment`) — ঐ লটে মাল আর ফ্রি আসে ঐ সারিতেই। গোনায় না থাকায় ২৪:১-এর
 * লটও "ফ্রি আসেনি" দেখাত, আর কাউন্টার ফ্রি আটকাত।
 */
final class LotsGivenLaterCountTheirFreeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_lot_given_later_carries_its_twenty_four_to_one(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $product = Product::query()->create(['code' => 'Q13-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Late lot probe',
            'name_bn' => 'পরের লটের নমুনা', 'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id, 'is_active' => true,
            'track_batch' => false]);

        // ⓘ লট ধরার আগের মাল — লট ছাড়া, ২৪ টাকার আর ১ ফ্রি; তারপর পণ্যে লট ধরা চালু
        app(StockService::class)->move(product: $product, warehouse: $warehouse, sourceType: 'test.before_lots', sourceId: 1,
            floor: '24', free: '1');
        $product->forceFill(['track_batch' => true])->save();

        $lot = app(BatchService::class)->receive($product, 'Q13-LOT', now()->addYear()->toDateString());
        app(StrandedStock::class)->giveItALot($product, $warehouse, $lot, '24', '1');

        $this->assertSame(['paid' => '24.0000', 'free' => '1.0000'], app(FreeRatio::class)->arrivedIn($lot),
            '⛔ লট-বাছাইয়ে আসা মাল আর ফ্রি লটের গোনায় নেই।');
        $this->assertSame(['paid' => '24.0000', 'free' => '1.0000'], app(FreeRatio::class)->arrivedInMany([$lot->id])[(string) $lot->id] ?? null,
            '⛔ কাউন্টারের লট-তালিকার গোনায় লট-বাছাইয়ের মাল নেই।');
    }
}
