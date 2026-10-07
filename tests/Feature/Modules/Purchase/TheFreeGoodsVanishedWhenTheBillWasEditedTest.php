<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ফ্রি মাল উধাও — মালিকের অভিযোগ, ৩ অক্টোবর ২০২৬ (UB, সরবরাহকারীর বিল ৮০৯, PBL-1014)।
 *
 * *"গোডাউনে মাল বুঝে নেবে তখন আর ফ্রিটা থাকতেছিল না"*।
 *
 * ── ⓘ লাইভে যা পাওয়া গেল ──────────────────────────────────────────────
 * বিলটা নিশ্চিত, `warehouse_id` NULL, আর ১৮টা সারির প্রতিটায় `free_qty` = ০। ⚠️ সরাসরি ক্রয়ের
 * কাউন্টার গুদাম ছাড়া বিল বানাতেই পারে না (ঘরটা `required`), তাই NULL গুদাম বলে বিলটা বিলের
 * **ফর্ম** দিয়ে সংরক্ষিত হয়েছিল — আর ঐ ফর্মের সম্পাদনার সারিতে `free_qty` ছিলই না।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 *   ১. `bill/form.blade.php`-র `$seed` সংরক্ষিত সারি থেকে পরিমাণ, দর, ছাড় তুলত — ফ্রি নয়।
 *      ফ্রি ঘরটা খালি খুলত, আর "সংরক্ষণ" চাপলে সেবা খালি ঘর ০ পড়ত।
 *   ২. [[PurchaseBillService::update()]] / `updatePosted()` গুদাম লিখত `$data['warehouse_id'] ?? null`
 *      — অথচ ফর্ম গুদাম পাঠায়ই না, তাই কাউন্টারের বাছা গুদাম মুছে যেত।
 *
 * ⭐ দাবিগুলো ব্রাউজারের পথেই: সম্পাদনার পাতা খোলা, পাতা যে সারি দেয় হুবহু সেগুলোই জমা দেওয়া
 * (মানুষটা কিছু না ছুঁয়ে "সংরক্ষণ" চাপলেন), তারপর বসানোর পর্দা যে যোগফল পড়ে সেটা মাপা।
 */
final class TheFreeGoodsVanishedWhenTheBillWasEditedTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $product;

    private Supplier $supplier;

    /** প্রধান গুদাম নয় — নাহলে "গুদাম মুছে প্রধানে গেল" ভুলটা চোখেই পড়ত না */
    private Warehouse $depot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        // মালিক — super admin; নিশ্চিত বিলের সম্পাদনা কেবল তাঁর ([[PurchaseBillPolicy::update]])
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();

        $this->product = Product::query()->where('track_batch', false)->whereNotNull('unit_id')->firstOrFail();
        $this->supplier = Supplier::query()->firstOrFail();

        $this->depot = Warehouse::query()->create([
            'branch_id' => $company->defaultBranch()?->id ?? Branch::query()->firstOrFail()->id,
            'code' => 'FREE-QA',
            'name_en' => 'Second depot',
            'name_bn' => 'দ্বিতীয় গুদাম',
            'is_default' => false,
            'is_active' => true,
        ]);
    }

    /**
     * ⓘ নিয়ন্ত্রণ: কাউন্টার নিজে ফ্রি হারায় না — ১০ কেনা, ৫ ফ্রি, আর বসানোর পর্দায় ৫ ফ্রি।
     * ⚠️ এটা সবুজ না হলে নিচের দাবিটা কাউন্টারের ভুল মাপত, সম্পাদনার নয়।
     */
    public function test_the_counter_itself_keeps_the_free_goods(): void
    {
        $bill = $this->boughtAtTheCounter();

        $this->assertSame(DocumentStatus::CONFIRMED, $bill->status);
        $this->assertFreeKept($bill, 'কাউন্টার');
    }

    /**
     * ⭐ PBL-1014-এর পথ: নিশ্চিত বিল খুলে কিছু না বদলে "সংরক্ষণ"।
     */
    public function test_saving_a_confirmed_bill_untouched_keeps_its_free_goods_and_its_depot(): void
    {
        $bill = $this->boughtAtTheCounter();

        $this->saveTheEditFormUntouched($bill);

        $bill = $bill->fresh(['lines']);
        $this->assertSame(DocumentStatus::CONFIRMED, $bill->status);
        $this->assertFreeKept($bill, 'নিশ্চিত বিলের সম্পাদনা');
    }

    /**
     * ⭐ খসড়ার পথ: সইয়ে আটকানো খসড়া খুলে সংরক্ষণ, তারপর নিশ্চিত — একই দুইটা ফাঁক।
     */
    public function test_saving_a_draft_untouched_then_confirming_keeps_its_free_goods_and_its_depot(): void
    {
        $payload = [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->depot->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => '809-D',
        ];

        $bill = app(PurchaseBillService::class)->create($payload, [[
            'product_id' => $this->product->id, 'qty' => '10', 'free_qty' => '5', 'rate' => '100',
        ]]);

        $this->saveTheEditFormUntouched($bill);

        $bill = app(PurchaseBillService::class)->confirm($bill->fresh());

        $this->assertFreeKept($bill->fresh(['lines']), 'খসড়ার সম্পাদনা');
    }

    private function boughtAtTheCounter(): PurchaseBill
    {
        $this->post(route('purchase.direct.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->depot->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => '809',
            'lines' => [[
                'product_id' => $this->product->id,
                'qty' => '10',
                'free_qty' => '5',
                'rate' => '100',
                'sales_price' => '120',
            ]],
        ])->assertSessionHasNoErrors();

        return PurchaseBill::query()->latest('id')->firstOrFail();
    }

    /**
     * সম্পাদনার পাতা খুলে, পাতার নিজের সারিগুলোই জমা — ব্রাউজার যা পাঠাত।
     *
     * ⓘ সারিগুলো আসে `purchaseLineEditor({ rows: … })` থেকে ([[AlpineLiteral]] — HTML-escaped JSON),
     * আর জমা যায় কেবল যে ঘরগুলোর `name` আছে সেগুলো ([[line-editor.blade.php]])।
     */
    private function saveTheEditFormUntouched(PurchaseBill $bill): void
    {
        $html = (string) $this->get(route('purchase.bill.edit', $bill))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/rows:\s*(\[.*?\]),\s*packs:/s', $html, $m),
            'সম্পাদনার পাতায় সারির তালিকাটাই পাওয়া গেল না — দাবিটা কিছু মাপছে না।');

        $rows = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true, 512, JSON_THROW_ON_ERROR);

        $this->assertNotEmpty($rows);

        $named = ['product_id', 'qty', 'free_qty', 'unit_id', 'batch_no', 'expiry_date', 'mrp', 'rate', 'sales_price', 'discount'];

        $lines = array_map(
            fn (array $row) => array_intersect_key($row, array_flip($named)),
            $rows,
        );

        $this->put(route('purchase.bill.update', $bill), [
            'supplier_id' => $bill->supplier_id,
            'trx_date' => $bill->trx_date->toDateString(),
            'supplier_bill_no' => $bill->supplier_bill_no,
            'lines' => $lines,
        ])->assertSessionHasNoErrors()->assertRedirect(route('purchase.bill.show', $bill));
    }

    /**
     * বিলের সারিতে ৫ ফ্রি, গুদাম অক্ষত, আর বসানোর পর্দা যে যোগফল পড়ে
     * ([[StockPlacementController::waiting()]] — `:cancel` কেটে উৎস ধরে) তাতে ৫ ফ্রি **ঐ গুদামেই**।
     */
    private function assertFreeKept(PurchaseBill $bill, string $when): void
    {
        $line = $bill->lines->firstOrFail();

        $this->assertSame(0, bccomp('5', (string) $line->free_qty, 4),
            "⛔ {$when}: বিলের সারিতে ফ্রি {$line->free_qty} — থাকার কথা ৫ (PBL-1014: \"ফ্রিটা থাকতেছিল না\")।");

        $this->assertSame($this->depot->id, $bill->warehouse_id === null ? null : (int) $bill->warehouse_id,
            "⛔ {$when}: বিলের গুদাম মুছে গেছে — লাইভে PBL-1014-এর `warehouse_id` NULL।");

        $waiting = fn (string $column, int $warehouseId) => (string) StockMovement::query()
            ->where('source_id', $bill->id)
            ->where('source_type', 'like', PurchaseBill::STOCK_SOURCE.'%')
            ->where('warehouse_id', $warehouseId)
            ->sum($column);

        $this->assertSame(0, bccomp('5', $waiting('unplaced_free_change', $this->depot->id), 4),
            "⛔ {$when}: বসানোর পর্দায় ফ্রি ".$waiting('unplaced_free_change', $this->depot->id).' — থাকার কথা ৫।');

        $this->assertSame(0, bccomp('10', $waiting('unplaced_change', $this->depot->id), 4),
            "⛔ {$when}: বসানোর পর্দায় কেনা মাল ".$waiting('unplaced_change', $this->depot->id).' — থাকার কথা ১০।');

        $main = (int) Warehouse::query()->where('is_default', true)->value('id');

        $this->assertSame(0, bccomp('0', bcadd($waiting('unplaced_change', $main), $waiting('unplaced_free_change', $main), 4), 4),
            "⛔ {$when}: মাল প্রধান গুদামে গিয়ে বসেছে — লরি নেমেছিল দ্বিতীয় গুদামে।");
    }
}
