<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
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
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ট্রাক গেল ১০ নিয়ে, কাগজ বলল ১০০ — Inventory অডিট ম৩, ৫ অক্টোবর ২০২৬।
 *
 * ⛔ দুই ফাঁক: (ক) সম্পাদনা অবস্থা দেখত হাতের কপি থেকে, তালা ছাড়া — পুরনো কপি দিয়ে রওনা-হওয়া স্থানান্তরের সারি বদলানো
 * যেত (১০ পাঠানো, কাগজে ১০০); (খ) রওনা সারি পড়ত আর সই মাপত তালার **আগে** — মাঝে কেউ সারি বদলালে ট্রাকে যেত পুরনো
 * পরিমাণ, আর সইটা যে পরিমাণে ছিল তার বাইরের কাগজ পার হত।
 * ⭐ এখন সম্পাদনা তালা দিয়ে অবস্থা পড়ে; রওনা তালার পরে সারি নতুন করে পড়ে, আর সইয়ের মুহূর্তের ছাপের সাথে না মিললে থামে।
 */
final class TheTruckLeftWithTenAndThePaperSaidHundredTest extends TestCase
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
            ?? Warehouse::query()->create(['code' => 'TRK3', 'name_en' => 'Third store', 'is_active' => true, 'branch_id' => $this->from->branch_id]);

        $this->product = Product::query()->create([
            'code' => 'TRK-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Truck probe', 'name_bn' => 'ট্রাকের নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true, 'track_batch' => false,
        ]);
        app(StockService::class)->move(product: $this->product, warehouse: $this->from, sourceType: 'test.opening', sourceId: $this->product->id, floor: '500');
    }

    /** ⛔ (ক) পুরনো কপি দিয়ে রওনা-হওয়া স্থানান্তরের সারি বদলানো যায় না */
    public function test_a_stale_copy_cannot_edit_a_transfer_that_has_left(): void
    {
        $transfer = $this->draft('10');
        $stale = StockTransfer::query()->findOrFail($transfer->id);

        app(StockTransferService::class)->dispatch($transfer);

        try {
            app(StockTransferService::class)->update($stale, [], [['product_id' => $this->product->id, 'qty' => '100']]);
            $this->fail('⛔ রওনা-হওয়া স্থানান্তরের সারি পুরনো কপি দিয়ে ১০০ হয়ে গেল।');
        } catch (ValidationException) {
            // ঠিক
        }

        $this->assertSame(0, bccomp('10', (string) $transfer->fresh('lines')->lines->sum('qty'), 4), '⛔ কাগজে পাঠানোর চেয়ে আলাদা পরিমাণ।');
    }

    /** ⛔ (খ) সই মাপার পরে, তালার আগে সারি বদলালে রওনা থামে — পুরনো পরিমাণ ট্রাকে যায় না */
    public function test_an_edit_between_the_check_and_the_lock_stops_the_send(): void
    {
        $transfer = $this->draft('10');

        $once = false;
        Event::listen(TransactionBeginning::class, function () use ($transfer, &$once) {
            if ($once) {
                return;
            }
            $once = true;
            // ⓘ অন্য কারো সম্পাদনা — ঠিক সই মাপা আর তালার মাঝখানে
            DB::table('inv_transfer_lines')->where('stock_transfer_id', $transfer->id)->update(['qty' => 100]);
        });

        try {
            app(StockTransferService::class)->dispatch($transfer);
            $this->fail('⛔ মাঝপথে বদলানো কাগজ রওনা হলো।');
        } catch (ValidationException) {
            // ঠিক
        }

        $this->assertSame(DocumentStatus::DRAFT, $transfer->fresh()->status);
        $this->assertSame(0, bccomp('0', (string) StockMovement::query()->where('product_id', $this->product->id)->sum('hold_change'), 4),
            '⛔ থামা রওনাতেও মাল আটকাল।');
    }

    private function draft(string $qty): StockTransfer
    {
        return app(StockTransferService::class)->create(
            ['from_warehouse_id' => $this->from->id, 'to_warehouse_id' => $this->to->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => $qty]],
        );
    }
}
