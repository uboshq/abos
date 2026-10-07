<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\CostLayerService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ ফ্রি মাল আর উপহার স্তরে না কুলালে পুরোটাই কেনা দামে ধরত — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (বিক্রয় ⓘ১২)।
 *
 * ⓘ [[DirectSaleService::giveFromStock()]] আর [[GiftIssuer::bookTheCost()]] স্তরে পুরো পরিমাণ না থাকলে স্তর না ছুঁয়ে সব কেনা দামে ধরত।
 * এখন [[CostLayerService::issueOrPrice()]]: যতটুকু স্তরে, FIFO-তে; কেবল ঘাটতিটুকু কেনা দামে।
 */
final class FreeGoodsTakeTheirCostFromTheLayersFirstTest extends TestCase
{
    use RefreshDatabase;

    public function test_what_the_layers_hold_goes_by_fifo_and_only_the_shortfall_by_purchase_price(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $layers = app(CostLayerService::class);
        $product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $product->forceFill(['purchase_price' => '80'])->save();

        // ⓘ নিজের একটা স্তর — ৩টা, ৫০ করে
        $layers->receive($product, '3', '50', 'test:layer', 1);
        $have = $layers->qtyOnHand($product);
        $worth = $layers->valueOnHand($product);
        $this->assertSame(1, bccomp($have, '0', 4), 'প্রস্তুতিটাই ভুল — স্তরে মাল নেই');

        // ⓘ স্তরে যা আছে তার চেয়ে ২টা বেশি
        $cost = $layers->issueOrPrice($product, bcadd($have, '2', 4), 'test:free', 2);

        $this->assertSame(0, bccomp($cost, bcadd($worth, '160', 4), 2), '⛔ স্তরের মাল FIFO-তে নয়, পুরোটা কেনা দামে — '.$cost);
        $this->assertSame(0, bccomp($layers->qtyOnHand($product), '0', 4), '⛔ স্তরের মাল ছোঁয়া হয়নি — পরের বিক্রিতে পড়ে থাকবে');

        // ⓘ স্তরে কিছু না থাকলে পুরোটা কেনা দামে, থামে না; কুলালে আগের issue() হুবহু
        $this->assertSame(0, bccomp($layers->issueOrPrice($product, '2', 'test:free', 3), '160', 2));
        $layers->receive($product, '4', '70', 'test:layer', 4);
        $this->assertSame(0, bccomp($layers->issueOrPrice($product, '4', 'test:free', 5), '280', 2), 'স্তরে কুলালে পুরোটাই স্তরের দামে');
    }
}
