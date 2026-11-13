<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Contracts\ShowsItselfForSigning;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ মালিক, ১২ নভেম্বর ২০২৬: "Approval পর্দায় পারচেজ ইনভয়েস ভিউ হয় বাট প্রিভিউ হয় না … অ্যাপে আবার হয়, ওয়েবে হয় না"।
 *
 * ⓘ সইকারীর পাতার সারি কাগজ নিজে বলে ([[ShowsItselfForSigning]]) — বিক্রির বিল বলত, ক্রয়ের বিল বলত না, তাই অনুমোদনের পর্দায় (ওয়েব আর
 *   ফোন — একই সারি) ক্রয়ের বিলে কেবল লিংক ছিল, কাগজের মতো সারি নয়।
 */
final class ThePurchaseBillShowsItsLinesToTheSignerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_bill_gives_the_signer_its_supplier_lines_free_qty_and_total(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $product = Product::query()->where('is_active', true)->firstOrFail();
        $supplier = Supplier::query()->firstOrFail();
        $bill = app(PurchaseBillService::class)->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => Warehouse::query()->value('id'), 'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'SUP-77'],
            [['product_id' => $product->id, 'qty' => '10', 'free_qty' => '2', 'rate' => '50']],
        );

        $this->assertInstanceOf(ShowsItselfForSigning::class, $bill->fresh(), '⛔ ক্রয়ের বিল সইকারীকে নিজের সারি দেখায় না — অনুমোদনের পর্দায় কেবল লিংক।');
        $sheet = PurchaseBill::query()->findOrFail($bill->id)->signingSheet();

        $facts = array_column($sheet['facts'], 'value', 'label');
        $this->assertSame($supplier->name(), $facts[__('purchase::field.supplier')] ?? null, '⛔ সরবরাহকারীর নাম নেই।');
        $this->assertSame('SUP-77', $facts[__('purchase::field.supplier_bill_no')] ?? null, '⛔ সরবরাহকারীর বিল নম্বর নেই।');
        $this->assertCount(1, $sheet['rows']);
        $this->assertStringContainsString((string) $product->code, $sheet['rows'][0]['product']);
        $this->assertNotNull($sheet['rows'][0]['free'], '⛔ ফ্রি পরিমাণ দেখায় না।');
        $this->assertSame(\App\Core\Support\Money::format((string) $bill->fresh()->total), $sheet['totals']['amount'], '⛔ মোট বিলের মোট নয়।');
    }
}
