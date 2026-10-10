<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\OpeningStockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ চলাচলের সারাংশে খোলা মজুদ "কেনা"-র বদলে "অন্য"-তে পড়ত (পুরো-ERP অডিট, মজুদ ছ৭; মালিকের "সব খোলা ভুল", ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ রিপোর্ট `opening_stock` খুঁজত — ওটা খাতার ভাউচারের নাম; চলাচল লেখা হয় `opening` নামে ([[OpeningStockService::SOURCE_TYPE]])।
 * তাই সত্যিকারের দরজা দিয়ে ঢোকানো খোলা মজুদ দিয়েই দাবি।
 */
final class TheOpeningStockIsCountedAsBroughtInTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_stock_through_the_real_door_lands_in_brought_not_other(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->create(['code' => 'OP-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Opening probe',
            'name_bn' => 'খোলার নমুনা', 'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id, 'is_active' => true]);

        app(OpeningStockService::class)->bringIn($product, $warehouse, '12', '50', now()->toDateString());

        $row = app(ReportEngine::class)->run('inventory.movement_summary', [
            'from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString(), 'product_id' => $product->id,
        ])->rows[0];

        $this->assertSame(0, bccomp((string) $row['bought'], '12', 4), '⛔ খোলা মজুদ "কেনা"-তে নেই।');
        $this->assertSame(0, bccomp((string) $row['other'], '0', 4), '⛔ খোলা মজুদ "অন্য"-তে পড়ল।');
        $this->assertSame(0, bccomp((string) $row['closing'], '12', 4));
    }
}
