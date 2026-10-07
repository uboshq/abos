<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\StockTransferService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ট্রাকে তাকের চেয়ে বেশি মাল উঠত — Inventory অডিট ম২, ৫ অক্টোবর ২০২৬।
 *
 * ⛔ স্থানান্তর পাঠানোর আগে প্রতিটা সারি আলাদা মাপা হত, আর "পাওয়া যায়" পড়া হত তালা ছাড়া: তাকে ৮, একই পণ্য দুই সারিতে
 * ৬ + ৬ — দুটো সারিই পাস, ট্রাকে ১২; দুটো স্থানান্তর একসাথে বেরোলেও দুজনেই পুরো ৮ দেখত।
 * ⭐ এখন প্রতিটা সারির আটকানো "পাওয়া যায়" থেকে তালাসহ মাপা হয়, একই লেনদেনে — আগের সারির আটকানো পরের সারি দেখে, আর
 * অন্য স্থানান্তর তালায় অপেক্ষা করে ([[StockService::move()]] `fromAvailable`)।
 */
final class TheTruckTookMoreThanTheShelfHadTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $from;

    private Warehouse $to;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->from = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->to = Warehouse::query()->whereKeyNot($this->from->id)->where('is_active', true)->orderBy('id')->first()
            ?? Warehouse::query()->create(['code' => 'TRK2', 'name_en' => 'Second store', 'is_active' => true, 'branch_id' => $this->from->branch_id]);

        $this->product = Product::query()->create([
            'code' => 'TRK-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Truck probe', 'name_bn' => 'ট্রাকের নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true, 'track_batch' => false,
        ]);
        app(StockService::class)->move(product: $this->product, warehouse: $this->from, sourceType: 'test.opening', sourceId: $this->product->id, floor: '8');
    }

    /** ⛔ তাকে ৮, একই পণ্য দুই সারিতে ৬ + ৬ — পাঠানো থামে, কিছুই আটকায় না */
    public function test_one_product_on_two_rows_cannot_send_more_than_the_shelf(): void
    {
        $transfer = $this->draft([['product_id' => $this->product->id, 'qty' => '6'], ['product_id' => $this->product->id, 'qty' => '6']]);

        try {
            app(StockTransferService::class)->dispatch($transfer);
            $this->fail('⛔ তাকে ৮, অথচ ট্রাকে ১২ উঠল।');
        } catch (ValidationException) {
            // ঠিক
        }

        $this->assertSame(0, bccomp('0', $this->held(), 4), '⛔ থামা স্থানান্তরেও মাল আটকে রইল।');
    }

    /** ⭐ পাহারা সব দরজা বন্ধ করে না: ৪ + ৪ = ৮ — ঠিক তাকের সমান, যায় */
    public function test_two_rows_that_fit_still_go(): void
    {
        $transfer = $this->draft([['product_id' => $this->product->id, 'qty' => '4'], ['product_id' => $this->product->id, 'qty' => '4']]);

        app(StockTransferService::class)->dispatch($transfer);

        $this->assertSame(0, bccomp('8', $this->held(), 4));
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  list<array<string, string|int>>  $lines */
    private function draft(array $lines): StockTransfer
    {
        return app(StockTransferService::class)->create(
            ['from_warehouse_id' => $this->from->id, 'to_warehouse_id' => $this->to->id, 'trx_date' => now()->toDateString()],
            $lines,
        );
    }

    private function held(): string
    {
        return (string) StockMovement::query()->where('product_id', $this->product->id)->where('warehouse_id', $this->from->id)->sum('hold_change');
    }
}
