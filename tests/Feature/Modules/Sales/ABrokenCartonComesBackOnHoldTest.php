<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryEventLine;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ ভাঙা মাল পৌঁছালে — মালিকের বিক্রয় পরিকল্পনা (সংস্করণ ২) ধাপ ৭, ৬ অক্টোবর ২০২৬: *"কম বা ভাঙা মাল → ফেরত বা দাবি"*;
 * সমন্বয়কের কথায় আপাতত কেবল ফেরত, আটকে রাখা মজুদে ([[ShortDeliveryReturn]])।
 *
 * ⭐ দাবি:
 *   · ৫টার চালানে ২টা ভালো নিলেন, ১টা ভাঙা — একই ফেরতে ২টা (কম) বিক্রয়যোগ্য লটে, ১টা (ভাঙা) আটকে রাখা মজুদে,
 *     "ক্ষতিগ্রস্ত পণ্য" কারণে; গুদামে ৩টাই গোনা যায়, ১টা আটকে; ঘটনার সারিতে ভাঙা ১
 *   · কেবল ভাঙা (ভালো শূন্য) — তবু আংশিক, "কিছুই পৌঁছায়নি" নয়
 *   · ভালো + ভাঙা চালানের চেয়ে বেশি নয়
 *   · ওয়েবের আংশিক ফর্মে প্রতিটা সারিতে ভাঙার ঘর
 */
final class ABrokenCartonComesBackOnHoldTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_the_broken_part_comes_back_held_and_the_short_part_sellable(): void
    {
        $challan = $this->dispatched();
        [$biscuit, $tea] = $challan->lines()->orderBy('line_no')->get()->all();
        [$floor, $hold] = $this->stock((int) $biscuit->product_id);

        app(DeliveryStageService::class)->move($challan, DeliveryStage::PARTIALLY_DELIVERED, [
            'receiver_name' => 'রহিম', 'receiver_phone' => '01711-000000',
            'lines' => [$biscuit->id => '2', $tea->id => '3'],
            'damaged' => [$biscuit->id => '1'],
        ]);

        $return = $this->returnOf($challan);
        $this->assertSame(DocumentStatus::CONFIRMED, $return->status);

        $held = $return->lines->where('to_hold', true);
        $sellable = $return->lines->where('to_hold', false);
        $this->assertSame(0, bccomp('1', (string) $held->sum('qty'), 4), '⛔ ভাঙা ১টা আটকে রাখা মজুদে ফেরেনি।');
        $this->assertSame(0, bccomp('2', (string) $sellable->sum('qty'), 4), '⛔ কম ২টা বিক্রয়যোগ্য হয়ে ফেরেনি।');
        $this->assertSame('DAMAGE', (string) $held->first()?->reasonCode?->code, '⛔ ভাঙার কারণ "ক্ষতিগ্রস্ত পণ্য" নয়।');
        $this->assertNotSame('DAMAGE', (string) $sellable->first()?->reasonCode?->code, '⛔ না-পৌঁছানো ভালো মাল "ক্ষতিগ্রস্ত" বলে লেখা হলো।');
        $this->assertTrue((bool) $sellable->first()?->reasonCode?->returns_to_stock, 'কম মালের কারণ মজুদে-ফেরার নয়।');

        [$floorAfter, $holdAfter] = $this->stock((int) $biscuit->product_id);
        $this->assertSame(0, bccomp(bcadd($floor, '3', 4), $floorAfter, 4), '⛔ কম আর ভাঙা মিলিয়ে ৩টা গুদামে ফেরেনি।');
        $this->assertSame(0, bccomp(bcadd($hold, '1', 4), $holdAfter, 4), '⛔ ভাঙাটা আটকে যায়নি — আবার বিক্রি হয়ে যেত।');

        $line = DeliveryEventLine::query()->where('delivery_challan_line_id', $biscuit->id)->latest('id')->firstOrFail();
        $this->assertSame(0, bccomp('1', (string) $line->damaged_qty, 4), '⛔ ঘটনার সারিতে ভাঙার পরিমাণ লেখা নেই।');
        $this->assertSame(0, bccomp('2', (string) $line->delivered_qty, 4));
    }

    public function test_only_broken_goods_still_count_as_a_partial_delivery(): void
    {
        $challan = $this->dispatched();
        [$biscuit, $tea] = $challan->lines()->orderBy('line_no')->get()->all();

        app(DeliveryStageService::class)->move($challan, DeliveryStage::PARTIALLY_DELIVERED, [
            'receiver_name' => 'রহিম',
            'lines' => [$biscuit->id => '0', $tea->id => '0'],
            'damaged' => [$biscuit->id => '5'],
        ]);

        $return = $this->returnOf($challan);
        $this->assertSame(0, bccomp('5', (string) $return->lines->where('to_hold', true)->sum('qty'), 4));
        $this->assertSame(0, bccomp('3', (string) $return->lines->where('to_hold', false)->sum('qty'), 4), 'না-নেওয়া চা কম হিসেবে ফেরেনি।');
    }

    public function test_good_and_broken_together_cannot_pass_what_was_sent(): void
    {
        $challan = $this->dispatched();
        [$biscuit, $tea] = $challan->lines()->orderBy('line_no')->get()->all();

        $this->expectException(ValidationException::class);
        app(DeliveryStageService::class)->move($challan, DeliveryStage::PARTIALLY_DELIVERED, [
            'receiver_name' => 'রহিম',
            'lines' => [$biscuit->id => '4', $tea->id => '3'],
            'damaged' => [$biscuit->id => '2'],
        ]);
    }

    public function test_the_web_partial_form_asks_for_the_broken_count_on_each_line(): void
    {
        $challan = $this->dispatched();

        $page = (string) $this->get(route('sales.delivery.show', $challan))->assertOk()->getContent();
        foreach ($challan->lines as $line) {
            $this->assertStringContainsString('name="damaged['.$line->id.']"', $page, '⛔ সারিতে ভাঙার ঘর নেই।');
        }

        $biscuit = $challan->lines()->orderBy('line_no')->firstOrFail();
        $this->post(route('sales.delivery.move', $challan), [
            'stage' => DeliveryStage::PARTIALLY_DELIVERED, 'receiver_name' => 'রহিম',
            'lines' => [$biscuit->id => '3'], 'damaged' => [$biscuit->id => '2'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, bccomp('2', (string) $this->returnOf($challan)->lines->where('to_hold', true)->sum('qty'), 4),
            '⛔ ওয়েবের ফর্মের ভাঙা পরিমাণ সেবায় পৌঁছায়নি।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function dispatched(): DeliveryChallan
    {
        $biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $tea = Product::query()->where('name_en', 'Premium Tea 250gm')->firstOrFail();

        $service = app(DeliveryChallanService::class);
        $challan = $service->confirm($service->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [
            ['product_id' => $biscuit->id, 'delivered_qty' => '5', 'rate' => '10'],
            ['product_id' => $tea->id, 'delivered_qty' => '3', 'rate' => '165'],
        ]));

        app(DeliveryStageService::class)->move($challan, DeliveryStage::DISPATCHED);

        return $challan->fresh();
    }

    private function returnOf(DeliveryChallan $challan): SalesReturn
    {
        $invoice = SalesInvoice::query()->where('sale_no', $challan->fresh()->sale_no)->firstOrFail();
        $return = SalesReturn::query()->where('sales_invoice_id', $invoice->id)->with('lines.reasonCode')->first();
        $this->assertNotNull($return, '⛔ কম বা ভাঙা থাকার পরেও কোনো ফেরত জন্মায়নি।');

        return $return;
    }

    /** @return array{0: string, 1: string} গুদামে মোট, আর তার মধ্যে আটকে */
    private function stock(int $productId): array
    {
        $row = DB::table('inv_stock_movements')->where('product_id', $productId)->where('warehouse_id', $this->warehouse->id)
            ->selectRaw('COALESCE(SUM(floor_change), 0) as f, COALESCE(SUM(hold_change), 0) as h')->first();

        return [bcadd((string) $row->f, '0', 4), bcadd((string) $row->h, '0', 4)];
    }
}
