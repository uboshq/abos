<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ বিল সম্পাদনার পরে ফেরতের খরচ ভুল স্তরে নামত (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬, মজুদ M8)।
 *
 * ⓘ সম্পাদনায় বিলের পুরনো টান `sales_invoice:cancel`-এ স্তরে ফেরে, তারপর নতুন টান। [[CostLayerService::returnToLayers()]]
 * "বিলটা এই স্তর থেকে কত টেনেছিল" গুনত কেবল ধনাত্মক টান — ফেরানো পুরনোটাও। তাই যে স্তরে বিলের আর কিছু নেই, সেখানেও ফেরতের
 * জায়গা দেখাত। এখন নিট: টান বিয়োগ নিজের উল্টানো।
 */
final class AReturnAfterABillEditFindsOnlyWhatTheBillHoldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_return_lands_only_where_the_edited_bill_still_draws(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $product = Product::query()->create(['code' => 'M8-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Edit probe',
            'name_bn' => 'সম্পাদনার নমুনা', 'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id, 'is_active' => true]);
        $costs = app(CostLayerService::class);

        $costs->receive($product, '10', '10', 'test_in', 1, 'IN-1', now()->subDays(3)->toDateString());
        $costs->receive($product, '10', '20', 'test_in', 2, 'IN-2', now()->subDays(2)->toDateString());

        // ⓘ বিল ১: ৫টা (স্তর ১ থেকে, ১০ দরে) — তারপর সম্পাদনা: পুরনো টান ফেরে
        $costs->issue($product, '5', 'sales_invoice', 1, 'S-1');
        $costs->returnToLayers($product, '5', 'sales_invoice', 1, 'sales_invoice:cancel', 1, 'S-1', null, [1]);

        // ⓘ মাঝে অন্য বিল স্তর ১ খালি করে; সম্পাদিত বিল ১ নতুন করে ৫টা টানে — এবার স্তর ২ থেকে, ২০ দরে
        $costs->issue($product, '10', 'sales_invoice', 2, 'S-2');
        $costs->issue($product, '5', 'sales_invoice', 1, 'S-1');

        // ⛔ বিল ১ এখন ধরে কেবল ৫টা — ৮টা ফেরত চাইলে থামে, পুরনো স্তরে নামে না
        try {
            $costs->returnToLayers($product, '8', 'sales_invoice', 1, 'sales_return', 9, 'R-9', null, [9]);
            $this->fail('⛔ সম্পাদিত বিল ৫টা ধরে, অথচ ৮টা ফেরত স্তরে নামল — ৩টা গেল বিলের আর-না-থাকা স্তরে।');
        } catch (ValidationException) {
        }

        // ⓘ যা ধরে, তা ঠিক তার নিজের স্তরে: ৫টা × ২০
        $this->assertSame(0, bccomp($costs->returnToLayers($product, '5', 'sales_invoice', 1, 'sales_return', 10, 'R-10', null, [10]), '100', 4),
            '⛔ ফেরত বিলের আসল স্তরের দামে ফিরল না।');
    }

    /**
     * ⓘ বাতিল নিজেও এই পথে আসে (`sales_invoice:cancel`) — তখন উল্টানো সারিগুলো "আগে ফিরেছে"-তেই বাদ পড়ে; দুইবার বাদ দিলে
     * একই স্তরে নতুন করে টানা মাল ফেরার জায়গা পেত না, আর সম্পাদিত বিল বাতিলই হত না।
     */
    public function test_an_edited_bill_that_drew_the_same_layer_again_can_still_be_cancelled(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $product = Product::query()->create(['code' => 'M8B-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Same layer probe',
            'name_bn' => 'একই স্তরের নমুনা', 'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id, 'is_active' => true]);
        $costs = app(CostLayerService::class);
        $costs->receive($product, '10', '10', 'test_in', 3, 'IN-3');

        $costs->issue($product, '5', 'sales_invoice', 7, 'S-7');
        $costs->returnToLayers($product, '5', 'sales_invoice', 7, 'sales_invoice:cancel', 7, 'S-7', null, [7]);
        $costs->issue($product, '3', 'sales_invoice', 7, 'S-7');

        // ⛔ বাতিল — একই স্তরে ধরা ৩টা ফেরে
        $this->assertSame(0, bccomp($costs->returnToLayers($product, '3', 'sales_invoice', 7, 'sales_invoice:cancel', 7, 'S-7', null, [7]), '30', 4),
            '⛔ সম্পাদিত বিলের বাতিলে একই স্তরের ৩টা ফিরল না।');
        $this->assertSame(0, bccomp($costs->qtyOnHand($product), '10', 4));
    }
}
