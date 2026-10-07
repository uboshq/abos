<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\PackRebase;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * পিসে নামানো পণ্য কার্টনের দামে বিকোত — Inventory অডিট ম২০, ৫ অক্টোবর ২০২৬।
 *
 * ⛔ [[PackRebase]] মজুদ, স্তর আর কাগজের পরিমাণ ২৪ গুণ করত, কিন্তু পণ্যের নিজের কেনা ও বিক্রির দর, পুনঃক্রয়ের
 * সীমা, সর্বোচ্চ মাত্রা, পুনঃক্রয়ের পরিমাণ আর লটের ছাপা দাম থাকত কার্টনের — এক পিস কার্টনের দামে, আর ৪৮০ পিসের
 * মজুদ "পুনঃক্রয় ২০-এর নিচে নয়" বলত।
 * ⭐ এখন দাম ÷ ২৪, পরিমাণ × ২৪, আর ছাপা দাম পিসের (উপরে গোল — এটা সীমা)।
 */
final class ThePieceStillWoreTheCartonPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_products_own_prices_and_levels_come_down_to_the_piece(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $product = Product::query()->create([
            'company_id' => CompanyContext::id(), 'code' => 'M20-CTN', 'name_en' => 'Carton soap',
            'unit_id' => Unit::query()->where('code', 'CTN')->firstOrFail()->id, 'is_active' => true,
            'purchase_price' => '490', 'sale_price' => '500', 'reorder_level' => '20', 'max_level' => '60', 'reorder_qty' => '30',
        ]);
        $lot = Batch::query()->create(['company_id' => CompanyContext::id(), 'product_id' => $product->id, 'batch_no' => 'M20-L', 'mrp' => '100']);

        app(PackRebase::class)->run($product, Unit::query()->where('code', 'PCS')->firstOrFail(), '24', apply: true);
        $product->refresh();

        $this->assertSame('20.4167', (string) $product->purchase_price, '⛔ কেনা দর কার্টনেরই রইল, বা গোল ভুল (৪৯০ ÷ ২৪ = ২০.৪১৬৬…)।');
        $this->assertSame('20.8333', (string) $product->sale_price, '⛔ এক পিস কার্টনের দামে বিকোবে।');
        $this->assertSame('480.0000', (string) $product->reorder_level, '⛔ পুনঃক্রয়ের সীমা কার্টনের সংখ্যায় রইল।');
        $this->assertSame('1440.0000', (string) $product->max_level);
        $this->assertSame('720.0000', (string) $product->reorder_qty);
        $this->assertSame('4.1667', (string) $lot->fresh()->mrp, '⛔ লটের ছাপা দাম কার্টনেরই রইল।');
    }
}
