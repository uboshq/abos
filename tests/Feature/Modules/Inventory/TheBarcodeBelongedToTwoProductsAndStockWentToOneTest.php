<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Imports\OpeningStockImporter;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\OpeningStockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একই বারকোড দুই পণ্যে, আর মজুদ চুপচাপ একটাতে বসত।
 *
 * ── ⓘ নিরীক্ষা, ২০ সেপ্টেম্বর ২০২৬ ───────────────────────────────────
 * বারকোডে কোনো unique ছিল না, অথচ তিন জায়গায় ধরে নেওয়া হত একটা
 * বারকোড একটাই পণ্য। ⛔ শুরুর মজুদের আমদানিকারক দুইটা পেলে **ছোট
 * আইডিরটা** বেছে নিত — মাল ভুল পণ্যে বসত, আর কেউ টের পেত না কারণ
 * দুইটার নামই কাছাকাছি।
 */
final class TheBarcodeBelongedToTwoProductsAndStockWentToOneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /**
     * ⭐ ডাটাবেসই এখন একই বারকোড দুইবার বসতে দেয় না।
     */
    public function test_the_database_refuses_a_second_product_with_the_same_barcode(): void
    {
        $first = Product::query()->firstOrFail();
        $first->forceFill(['barcode' => '8901234567890'])->save();

        $second = Product::query()->where('id', '!=', $first->id)->firstOrFail();

        $this->expectException(QueryException::class);

        $second->forceFill(['barcode' => '8901234567890'])->save();
    }

    /**
     * ⛔ আর নকল থেকে গেলেও আমদানিকারক আর আন্দাজে বাছে না।
     *
     * ⚠️ পুরনো ডেটায় নকল থাকতে পারে (তখন মাইগ্রেশন index বসায় না,
     * ডিপ্লয় থামায় না) — তাই পাহারাটা এখানেও।
     */
    public function test_the_importer_stops_when_a_barcode_is_not_alone(): void
    {
        // ⓘ index-টা সরিয়ে পুরনো ডাটাবেসের অবস্থা বানানো হয়
        Schema::table('inv_products', function ($table) {
            $table->dropUnique('inv_products_company_barcode_unique');
        });

        $first = Product::query()->firstOrFail();
        $second = Product::query()->where('id', '!=', $first->id)->firstOrFail();

        DB::table('inv_products')->where('id', $first->id)->update(['barcode' => '999111']);
        DB::table('inv_products')->where('id', $second->id)->update(['barcode' => '999111']);

        $this->expectException(ValidationException::class);

        app(OpeningStockImporter::class)->import($this->row('999111'));
    }

    /**
     * ⭐ একটাই মিললে আগের মতোই চলে — পাহারাটা কাজ থামায় না।
     */
    public function test_a_barcode_that_belongs_to_one_product_still_works(): void
    {
        $product = Product::query()->firstOrFail();
        $product->forceFill(['barcode' => '555000'])->save();

        /*
         * ⓘ ডেমোতে গুদাম একটাই, আর প্রতিটা পণ্যের শুরুর মজুদ ওখানে
         * বসানো — একই পণ্য-গুদামে দুইবার বসে না। ⚠️ তাই নতুন একটা
         * গুদাম, নাহলে পরীক্ষাটা বারকোডের বদলে ঐ নিয়মেই আটকাত।
         */
        $warehouse = Warehouse::query()->create([
            'company_id' => CompanyContext::id(),
            'branch_id' => CompanyContext::branchId(),
            'code' => 'WH-TEST',
            'name_en' => 'Test shed',
            'name_bn' => 'পরীক্ষার গুদাম',
            'is_active' => true,
            'created_by' => auth()->id(),
        ]);

        app(OpeningStockImporter::class)->import(
            ['warehouse' => $warehouse->code] + $this->row('555000'),
        );

        $this->assertTrue(
            StockMovement::query()
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouse->id)
                ->where('source_type', OpeningStockService::SOURCE_TYPE)
                ->exists(),
            'একটাই পণ্যের বারকোড দিলেও শুরুর মজুদ বসেনি।',
        );
    }

    /**
     * @return array<string, string>
     */
    private function row(string $barcode): array
    {
        return [
            'product_code' => $barcode,
            'warehouse' => '',
            'qty' => '10',
            'unit_cost' => '25',
            'trx_date' => '',
        ];
    }
}
