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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ ফ্রি মাল আর উপহার স্তরে না কুলালে পুরোটাই কেনা দামে ধরত — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (বিক্রয় ⓘ১২)।
 *
 * ⓘ [[DirectSaleService::giveFromStock()]] আর [[GiftIssuer::bookTheCost()]] স্তরে পুরো পরিমাণ না থাকলে স্তর না ছুঁয়ে সব কেনা দামে ধরত।
 * ⓘ ৬ অক্টোবরের সারাই ঘাটতিটুকু কেনা দামে ধরত, স্তর না ছুঁয়ে — আর মজুদের খাত (১১২০) স্তরের থেকে আলাদা হয়ে যেত।
 * ⭐ fe-র সিদ্ধান্ত, ১৩ অক্টোবর ২০২৬ (মালিকের নিয়ম: মজুদ শূন্যের নিচে নামে না): স্তরে যা আছে কেবল তা থেকে, FIFO-তে; না কুলালে
 * স্পষ্ট বাংলা বার্তায় থামে, আর স্তরের এক কণাও ছোঁয়া হয় না — খাত আর স্তর সবসময় এক কথা বলে।
 */
final class FreeGoodsTakeTheirCostFromTheLayersFirstTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_goods_take_their_cost_from_the_layers_and_stop_when_the_layers_run_short(): void
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

        // ⛔ স্তরে যা আছে তার চেয়ে ২টা বেশি — থামে, স্তর ছোঁয় না, আর কেনা দামে কিছুই বসে না
        try {
            $layers->issueOrPrice($product, bcadd($have, '2', 4), 'test:free', 2);
            $this->fail('⛔ স্তরে না কুলালেও ফ্রি মাল বেরোল — ঘাটতির দাম বসল, খাত আর স্তর আলাদা হলো।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('product_id', $e->errors());
        }

        $this->assertSame(0, bccomp($layers->qtyOnHand($product), $have, 4), '⛔ থামা কাজেও স্তর থেকে মাল কাটা গেল।');
        $this->assertSame(0, bccomp($layers->valueOnHand($product), $worth, 2));

        // ⓘ কুলালে স্তরের দামে, FIFO-তে — আগের issue() হুবহু
        $this->assertSame(0, bccomp($layers->issueOrPrice($product, $have, 'test:free', 3), $worth, 2), 'স্তরে কুলালে পুরোটাই স্তরের দামে');
        $layers->receive($product, '4', '70', 'test:layer', 4);
        $this->assertSame(0, bccomp($layers->issueOrPrice($product, '4', 'test:free', 5), '280', 2));
    }
}
