<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\PackBackfill;
use App\Modules\Inventory\Services\PackSnapshot;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ পুরনো পণ্য তাদের প্যাক শেখে — মজুদের একটা সংখ্যাও না নড়িয়ে।
 * ধাপ ২, ১৯ সেপ্টেম্বর ২০২৬।
 *
 * ⓘ লাইভের ছবি নকল করা হয়েছে: ডজনের কোনো base নেই, কিছু পণ্যের একক
 * নেই, আর একটা পণ্যের মজুদ কার্টনে গোনা। মালিকের চার সিদ্ধান্ত
 * ([[PackBackfill]]) এই চারটার ওপরেই মাপা।
 */
final class TheOldProductsLearnTheirPacksWithoutMovingStockTest extends TestCase
{
    use RefreshDatabase;

    private Unit $piece;

    private Unit $dozen;

    private Product $unitless;

    private Product $inCartons;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->piece = Unit::query()->where('code', 'PCS')->firstOrFail();
        $this->dozen = Unit::query()->where('code', 'DOZ')->firstOrFail();

        // লাইভের মতো: ডজনের ১২ আছে, কিন্তু "১২ কিসের" নেই
        $this->dozen->forceFill(['base_unit_id' => null])->save();

        $this->unitless = $this->product('TNOUNIT', null);
        $this->inCartons = $this->product('TCARTON', Unit::query()->where('code', 'CTN')->firstOrFail()->id);
    }

    private function product(string $code, ?int $unitId): Product
    {
        return Product::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => $code,
            'name_en' => $code,
            'unit_id' => $unitId,
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> এই কোম্পানির রিপোর্টটা */
    private function mine(array $report): array
    {
        return collect($report)->firstWhere('company', 'TDEPOT');
    }

    /**
     * ⛔ ডিফল্টে কিছুই লেখে না — কিন্তু রিপোর্টটা আসল চালানোর মতোই পূর্ণ।
     */
    public function test_a_dry_run_reports_everything_and_writes_nothing(): void
    {
        $report = $this->mine(app(PackBackfill::class)->run());

        $this->assertTrue($report['dozen_linked']);
        $this->assertContains('TNOUNIT', $report['given_a_unit']);
        $this->assertGreaterThan(0, $report['base_rows']);

        $this->assertSame(0, ProductUnit::query()->count(), 'dry-run প্যাক লিখে ফেলেছে।');
        $this->assertNull($this->unitless->fresh()->unit_id, 'dry-run পণ্যে একক বসিয়ে ফেলেছে।');
        $this->assertNull($this->dozen->fresh()->base_unit_id, 'dry-run ডজন জুড়ে ফেলেছে।');
    }

    /**
     * ⭐ মালিকের চার সিদ্ধান্ত, আসল চালানোয়।
     */
    public function test_applying_follows_the_owners_four_decisions(): void
    {
        $report = $this->mine(app(PackBackfill::class)->run(apply: true));

        // ③ ডজন = ১২ পিস
        $this->assertSame($this->piece->id, $this->dozen->fresh()->base_unit_id);
        $this->assertSame('12.000000', $this->dozen->fresh()->factor);

        // ② একক ছাড়া পণ্যে PCS
        $this->assertSame($this->piece->id, $this->unitless->fresh()->unit_id);

        // ① প্রতিটা পণ্যে base সারি, factor ১, চার কাজেই ডিফল্ট
        $base = ProductUnit::query()->where('product_id', $this->unitless->id)->sole();
        $this->assertSame($this->piece->id, $base->unit_id);
        $this->assertSame('1.000000', $base->factor);
        $this->assertTrue($base->is_purchase_default && $base->is_sales_default
            && $base->is_pos_default && $base->is_counter_default, 'base সারি ডিফল্ট হয়নি।');

        // ④ কার্টনে গোনা পণ্য ছোঁয়া হয় না — অপেক্ষায়
        $this->assertSame([['product' => 'TCARTON', 'unit' => 'CTN']], $report['waiting']);
        $this->assertSame(0, ProductUnit::query()->where('product_id', $this->inCartons->id)->count());
        $this->assertSame(Unit::query()->where('code', 'CTN')->value('id'), $this->inCartons->fresh()->unit_id);

        // সব পণ্য (কার্টনেরটা ছাড়া) একটা করে base পেয়েছে
        $this->assertSame(
            Product::query()->withTrashed()->count() - 1,
            ProductUnit::query()->distinct('product_id')->count('product_id'),
        );
    }

    /**
     * ⭐ বারবার চালালে দ্বিতীয়বার কিছুই হয় না।
     */
    public function test_running_twice_changes_nothing_the_second_time(): void
    {
        app(PackBackfill::class)->run(apply: true);
        $rows = ProductUnit::query()->orderBy('id')->get(['id', 'product_id', 'unit_id', 'factor'])->toArray();

        $again = $this->mine(app(PackBackfill::class)->run(apply: true));

        $this->assertFalse($again['dozen_linked']);
        $this->assertSame([], $again['given_a_unit']);
        $this->assertSame(0, $again['base_rows']);
        $this->assertSame($rows, ProductUnit::query()->orderBy('id')->get(['id', 'product_id', 'unit_id', 'factor'])->toArray());
    }

    /**
     * ⚠️ আগে থেকে প্যাক থাকলে তার ডিফল্ট কেড়ে নেওয়া হয় না।
     */
    public function test_an_existing_pack_keeps_its_defaults(): void
    {
        $product = Product::query()->where('unit_id', $this->piece->id)->orderBy('id')->firstOrFail();

        ProductUnit::query()->create([
            'company_id' => CompanyContext::id(),
            'product_id' => $product->id,
            'unit_id' => $this->dozen->id,
            'factor' => '12',
            'is_sales_default' => true,
        ]);

        app(PackBackfill::class)->run(apply: true);

        $base = ProductUnit::query()->where('product_id', $product->id)->where('unit_id', $this->piece->id)->sole();
        $this->assertFalse($base->is_sales_default, 'base সারি হাতে বসানো ডিফল্টটা কেড়ে নিয়েছে।');
        $this->assertTrue(ProductUnit::query()->where('product_id', $product->id)
            ->where('unit_id', $this->dozen->id)->sole()->is_sales_default);
    }

    /**
     * ⭐ মূল প্রতিশ্রুতি: মজুদ, খরচ আর প্রতিটা লাইনের ছাপ আগে-পরে হুবহু এক।
     *
     * ⚠️ ছাপটা অন্ধ নয় — মজুদ-চলাচলের সারি আছে, আর একটা ঘর বদলালে
     * ছাপ সেটা ধরে (শেষ দাবি)।
     */
    public function test_the_stock_and_every_line_are_the_same_after_as_before(): void
    {
        $snapshot = app(PackSnapshot::class);
        $before = $snapshot->take();

        $this->assertGreaterThan(0, $before['inv_stock_movements']['rows'], 'ছাপ অন্ধ: মজুদ-চলাচলের সারিই নেই।');

        app(PackBackfill::class)->run(apply: true);

        $this->assertSame([], $snapshot->differences($before, $snapshot->take()));

        DB::table('inv_stock_movements')
            ->where('company_id', CompanyContext::id())
            ->orderBy('id')->limit(1)
            ->update(['floor_change' => DB::raw('floor_change + 1')]);

        $this->assertSame(['inv_stock_movements'], $snapshot->differences($before, $snapshot->take()),
            'একটা মজুদ-সারি বদলালেও ছাপ ধরেনি।');
    }

    /**
     * ⭐ নতুন কোম্পানিতে ডজন শুরু থেকেই পিসের সাথে জোড়া।
     */
    public function test_a_new_company_starts_with_the_dozen_joined_to_the_piece(): void
    {
        $this->dozen->forceFill(['base_unit_id' => null])->save();

        app(\App\Modules\MasterData\Services\MasterListService::class)->installDefaults();

        $this->assertSame($this->piece->id, $this->dozen->fresh()->base_unit_id);
    }
}
