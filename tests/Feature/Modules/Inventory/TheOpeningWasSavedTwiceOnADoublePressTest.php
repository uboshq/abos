<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\OpeningStockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * দুইবার চাপলে খোলা মজুদ দুইবার বসত — মজুদ লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⛔ "আগেই বসেছে কি না" ([[OpeningStockService::exists()]]) চলত তালা ছাড়া, আর একক পথে লেনদেনের বাইরেও। দুইবার চাপা
 * বা দুই ট্যাবে দুইজনই "বসেনি" দেখতেন — মজুদ, খরচের স্তর আর Dr মজুদ / Cr শুরুর মূলধন দুইবার।
 *
 * ⓘ একটা পরীক্ষা-সংযোগে দুই সমান্তরাল অনুরোধ চালানো যায় না, তাই দাবিটা ক্রম মাপে: যাচাইয়ের কোয়েরির **আগে** পণ্যের
 * সারিতে `FOR UPDATE`, আর দুটোই এক লেনদেনে। তালা সরালে বা যাচাই আবার লেনদেনের বাইরে গেলে লাল।
 */
final class TheOpeningWasSavedTwiceOnADoublePressTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_already_done_check_runs_after_a_lock_on_the_product_inside_the_transaction(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->create([
            'code' => 'OPN2-'.mb_substr(md5(microtime()), 0, 6), 'name_en' => 'Opening probe', 'name_bn' => 'খোলার নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'track_batch' => false, 'is_active' => true,
        ]);

        $base = DB::transactionLevel(); // ⓘ RefreshDatabase নিজেই একটা লেনদেন খোলে
        $seen = [];
        DB::listen(function (QueryExecuted $q) use (&$seen): void {
            $seen[] = ['sql' => strtolower($q->sql), 'level' => DB::transactionLevel()];
        });

        app(OpeningStockService::class)->bringIn(product: $product, warehouse: $warehouse, qty: '10', unitCost: '50');

        $lock = collect($seen)->search(fn ($q) => str_contains($q['sql'], 'from `inv_products`') && str_contains($q['sql'], 'for update'));
        $check = collect($seen)->search(fn ($q) => str_contains($q['sql'], 'from `inv_stock_movements`') && str_contains($q['sql'], 'exists'));

        $this->assertNotFalse($lock, 'পণ্যের সারিতে কোনো তালা পড়েনি — দুইবার চাপলে দুইবার বসত।');
        $this->assertNotFalse($check, 'পূর্বশর্ত: "আগেই বসেছে" কোয়েরি চোখে পড়েনি।');
        $this->assertLessThan($check, $lock, 'তালা পড়েছে যাচাইয়ের পরে — দুইজনই আগে "বসেনি" দেখে ফেলতেন।');
        $this->assertGreaterThan($base, $seen[$check]['level'], 'যাচাই লেনদেনের বাইরে — তালা তখন কিছুই আটকায় না।');

        // ⭐ আর দ্বিতীয় চাপ সত্যিই থামে
        $this->expectException(ValidationException::class);
        app(OpeningStockService::class)->bringIn(product: $product, warehouse: $warehouse, qty: '10', unitCost: '50');
    }

    /**
     * ⛔ কার্টের পথে তালাই লেনদেনের প্রথম কোয়েরি (১৩ অক্টোবর ২০২৬)। ⚠️ MySQL-এর REPEATABLE READ-এ প্রথম সাধারণ পড়াতেই
     * ছবি তোলা হয়; তালার আগে `Product::find()` চললে দ্বিতীয় চাপ অপেক্ষা শেষেও পুরনো ছবিতে "বসেনি" দেখত।
     */
    public function test_the_cart_locks_its_products_before_any_other_read_in_its_transaction(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $unit = Unit::query()->orderBy('id')->firstOrFail()->id;
        $make = fn (string $tag) => Product::query()->create([
            'code' => 'OPC'.$tag.'-'.mb_substr(md5(microtime().$tag), 0, 6), 'name_en' => 'Cart probe '.$tag, 'name_bn' => 'কার্টের নমুনা',
            'unit_id' => $unit, 'track_batch' => false, 'is_active' => true,
        ]);
        $first = $make('A');
        $second = $make('B');

        $base = DB::transactionLevel();
        $seen = [];
        DB::listen(function (QueryExecuted $q) use (&$seen): void {
            $seen[] = ['sql' => strtolower($q->sql), 'level' => DB::transactionLevel()];
        });

        app(OpeningStockService::class)->bringInMany($warehouse, [
            ['product_id' => $second->id, 'qty' => '4', 'unit_cost' => '20'],
            ['product_id' => $first->id, 'qty' => '6', 'unit_cost' => '30'],
        ]);

        $inside = collect($seen)->filter(fn ($q) => $q['level'] > $base)->values();

        $this->assertNotEmpty($inside, 'পূর্বশর্ত: কার্টের লেনদেনের কোনো কোয়েরি চোখে পড়েনি।');
        $this->assertStringContainsString('from `inv_products`', $inside[0]['sql'], 'কার্টের লেনদেনের প্রথম কোয়েরি পণ্যের তালা নয়।');
        $this->assertStringContainsString('for update', $inside[0]['sql'], 'প্রথম কোয়েরি সাধারণ পড়া — ছবি তালার আগেই তোলা হয়ে যেত।');

        // ⭐ আর দ্বিতীয় চাপ সত্যিই থামে
        $this->expectException(ValidationException::class);
        app(OpeningStockService::class)->bringInMany($warehouse, [['product_id' => $first->id, 'qty' => '6', 'unit_cost' => '30']]);
    }
}
