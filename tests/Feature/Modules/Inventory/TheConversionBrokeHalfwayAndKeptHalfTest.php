<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\PackBackfill;
use App\Modules\Inventory\Services\PackRebase;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * প্যাক-রূপান্তর মাঝপথে ভেঙেও অর্ধেক কাজ পাকা করত — Inventory অডিট গ১৭, ৪ অক্টোবর ২০২৬।
 *
 * ⛔ [[PackRebase::run()]] আর [[PackBackfill::run()]] লেনদেন শেষ করত `finally`-তে: `apply` হলে commit — ত্রুটি হলেও।
 * ফল: মজুদ ২৪ গুণ, অথচ কাগজ আর পণ্যের একক আগের মতো; আবার চালালে আরেকবার ২৪ গুণ।
 * ⭐ এখন সব-বা-কিছুই-না: ত্রুটিতে পুরোটা ফেরে, আর ত্রুটিটা চাপা পড়ে না।
 */
final class TheConversionBrokeHalfwayAndKeptHalfTest extends TestCase
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

    /** ⛔ পণ্যের একক বদলানোর মুহূর্তে ভাঙে — তার আগে গুণ হওয়া মজুদ ফেরে */
    public function test_a_rebase_that_breaks_keeps_nothing(): void
    {
        $carton = Unit::query()->where('code', 'CTN')->firstOrFail();
        $product = Product::query()->create([
            'company_id' => CompanyContext::id(), 'code' => 'TBREAK', 'name_en' => 'Break 40gm', 'unit_id' => $carton->id, 'is_active' => true,
        ]);
        StockMovement::query()->create([
            'company_id' => CompanyContext::id(), 'product_id' => $product->id,
            'warehouse_id' => Warehouse::query()->orderBy('id')->value('id'), 'trx_date' => now()->toDateString(),
            'floor_change' => '20', 'source_type' => 'opening', 'source_id' => $product->id,
        ]);

        Product::saving(fn (Product $p) => $p->code === 'TBREAK' ? throw new RuntimeException('মাঝপথে ভাঙল') : null);

        try {
            app(PackRebase::class)->run($product, Unit::query()->where('code', 'PCS')->firstOrFail(), '24', apply: true);
            $this->fail('⛔ ভাঙনটা চাপা পড়েছে — কমান্ড "হয়ে গেছে" বলল।');
        } catch (RuntimeException $e) {
            $this->assertSame('মাঝপথে ভাঙল', $e->getMessage());
        }

        $this->assertSame(0, bccomp('20', (string) StockMovement::query()->where('product_id', $product->id)->sum('floor_change'), 4),
            '⛔ ভাঙা রূপান্তরের অর্ধেক পাকা — মজুদ ২৪ গুণ, অথচ পণ্য এখনো কার্টনে।');
        $this->assertSame($carton->id, (int) $product->fresh()->unit_id);
    }

    /** ⛔ ব্যাকফিল base সারি বসাতে গিয়ে ভাঙে — তার আগে একক পাওয়া পণ্য আবার এককহীন */
    public function test_a_backfill_that_breaks_keeps_nothing(): void
    {
        $product = Product::query()->orderBy('id')->firstOrFail();
        $product->forceFill(['unit_id' => null])->save();

        ProductUnit::creating(fn () => throw new RuntimeException('মাঝপথে ভাঙল'));

        try {
            app(PackBackfill::class)->run(apply: true);
            $this->fail('⛔ ভাঙনটা চাপা পড়েছে — ব্যাকফিল "হয়ে গেছে" বলল।');
        } catch (RuntimeException $e) {
            $this->assertSame('মাঝপথে ভাঙল', $e->getMessage());
        }

        $this->assertNull(DB::table('inv_products')->where('id', $product->id)->value('unit_id'),
            '⛔ ভাঙা ব্যাকফিলের অর্ধেক পাকা — পণ্য একক পেয়ে গেছে, অথচ base সারি বসেনি।');
    }
}
