<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\GoodsWaitingToBePlaced;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বাতিল হওয়া বিল "মাল বসানো" তালিকায় চিরকাল ঝুলে থাকত — মালিকের ছবি, ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ⛔ PBL-0004 ২২ সেপ্টেম্বরে বাতিল, অথচ তালিকায় "১৮টা সারি বসানোর অপেক্ষায়", আর বসাতে গেলে
 * *"এত মাল বসানোর অপেক্ষায় নেই — বাকি আছে 0.0000"*। মজুদ ঠিক ছিল; তালিকাটা ভুল গুনছিল।
 *
 * ⓘ কারণ: আসার সারি `purchase_bill`, বাতিলের উল্টো সারি `purchase_bill:cancel` — তালিকা উৎসের নাম
 * ধরে দল বানাত, তাই দুইটা আলাদা দলে পড়ে কাটাকাটি হত না।
 *
 * ⭐ একই বিল, একই পর্দা — কেবল বাতিলের আগে আর পরে।
 */
final class ACancelledBillStayedOnThePutAwayListTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bill_waits_until_it_is_cancelled_and_then_leaves_the_list(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $product = Product::query()->orderBy('id')->firstOrFail();
        $counted = fn () => app(GoodsWaitingToBePlaced::class)->pendingCount();
        $before = $counted();
        $bill = app(DirectPurchaseService::class)->complete([
            'supplier_id' => Supplier::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'CANCEL-PUTAWAY-1',
        ], [[
            'product_id' => $product->id,
            'unit_id' => $product->unit_id,
            'qty' => '10',
            'free_qty' => '2',
            'rate' => '100', 'sales_price' => '100',
            'batch_no' => 'LOT-CANCEL-1',
        ]])['bill'];

        $this->assertTrue($this->onTheList($bill->id), 'প্রস্তুতিটাই ভুল — নিশ্চিত বিলটা তালিকায় নেই।');
        $this->assertSame($before + 1, $counted(), 'প্রস্তুতিটাই ভুল — অপেক্ষার গুনতিতে বিলটা ওঠেনি।');

        app(PurchaseBillService::class)->cancel($bill->fresh(), 'ভুল বিল');

        $this->assertFalse($this->onTheList($bill->id),
            'বাতিল হওয়া বিল এখনো "বসানোর অপেক্ষায়" — গুদামের লোক এমন মাল খুঁজবেন যা কখনো আসেনি।');
        $this->assertSame($before, $counted(), 'তালিকা থেকে গেছে, কিন্তু অপেক্ষার গুনতিতে বাতিল বিলটা এখনো গোনা হচ্ছে।');
    }

    private function onTheList(int $billId): bool
    {
        $papers = $this->get(route('inventory.stock.placement'))->assertOk()->viewData('papers');

        return collect($papers)->contains(fn (array $p) => (int) $p['source_id'] === $billId
            && str_starts_with((string) $p['source_type'], 'purchase_bill'));
    }
}
