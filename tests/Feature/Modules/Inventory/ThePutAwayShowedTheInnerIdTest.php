<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ভেতরের আইডি পর্দায় — পাতা-ঝাড়ু, ধাপ ০ (১০ অক্টোবর ২০২৬)।
 *
 * ⛔ "গুদামে মাল বসানো"-য় কাগজের মাথায় "আইডি: purchase_bill:18"; বিল সম্পাদনায় পণ্যের ঘরে কেবল "7" (ঘরটা চেপে কোডের প্রথম অক্ষর)।
 * ⭐ মাল বসানোয় কাগজের নম্বর থাকে, ভেতরের নাম:আইডি নয়; বিলের লাইনে পণ্যের ঘরের একটা ন্যূনতম চওড়া।
 */
final class ThePutAwayShowedTheInnerIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_put_away_and_the_bill_edit_show_papers_and_products_not_ids(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $product = Product::query()->orderBy('id')->firstOrFail();
        $bill = app(DirectPurchaseService::class)->complete([
            'supplier_id' => Supplier::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'INNER-ID-1',
        ], [[
            'product_id' => $product->id, 'unit_id' => $product->unit_id,
            'qty' => '10', 'free_qty' => '0', 'rate' => '100', 'sales_price' => '100', 'batch_no' => 'LOT-INNER-1',
        ]])['bill'];

        $html = $this->get(route('inventory.stock.placement'))->assertOk()->assertSee($bill->document_no)->getContent();
        $this->assertStringNotContainsString('purchase_bill:'.$bill->id, $html, '⛔ মাল বসানোয় ভেতরের আইডি।');
        $this->assertStringNotContainsString(__('inventory::field.paper_id'), $html, '⛔ মাল বসানোয় "আইডি" ঘর।');

        // ⓘ বিলের লাইনে পণ্যের ঘর চাপে না — বাছা পণ্যের পুরো নাম তালিকায়, ঘরের একটা ন্যূনতম চওড়া
        $form = $this->get(route('purchase.bill.edit', $bill))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<select[^>]*data-product-pick[^>]*class="([^"]*)"/s', $form, $m), 'দৃশ্যটাই বানানো যায়নি — পণ্যের ঘর নেই।');
        $this->assertMatchesRegularExpression('/(^|\s)min-w-\d+(\s|$)/', $m[1], '⛔ পণ্যের ঘরের ন্যূনতম চওড়া নেই — বারো কলামে চেপে কেবল কোডের প্রথম অক্ষর।');
        $this->assertStringContainsString($product->code.' - '.e($product->name()), $form);
    }
}
