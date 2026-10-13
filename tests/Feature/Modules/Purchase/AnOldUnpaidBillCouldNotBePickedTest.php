<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * পুরনো অপরিশোধিত বিল পরিশোধের পর্দায় বাছা যেত না — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬।
 *
 * ⛔ [[PaymentController]] নতুন থেকে পুরনো ২০০টা নিশ্চিত বিল তুলে তারপর PHP-তে বাকি ছাঁকত — ২০০টার পরের
 * পুরনো বাকি বিল তালিকাতেই আসত না। ⓘ এখানে ২০০টা নতুন বাকি বিল, আর একটা পুরনো; পুরনোটাও বাছা যায় কি না।
 */
final class AnOldUnpaidBillCouldNotBePickedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_oldest_unpaid_bill_is_still_offered_after_two_hundred_newer_ones(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $old = app(DirectPurchaseService::class)->complete([
            'supplier_id' => Supplier::query()->firstOrFail()->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'OLD-UNPAID-1',
        ], [['product_id' => Product::query()->firstOrFail()->id, 'qty' => '1', 'rate' => '100', 'sales_price' => '100', 'tax' => '0']])['bill'];

        // ⓘ ২০০টা নতুন বাকি বিল — হাতে বসানো সারি; বাকি গোনা হয় পরিশোধ/ফেরতের টেবিল থেকে, তাই এগুলো "পুরো বাকি"
        // ⓘ তারিখটা পরে পেছানো — বিল বসাতে খোলা অর্থবছর লাগে, তালিকার জন্য লাগে কেবল ক্রম
        DB::table('pur_bills')->where('id', $old->id)->update(['trx_date' => now()->subDays(40)->toDateString()]);
        $row = (array) DB::table('pur_bills')->where('id', $old->id)->first();
        unset($row['id']);
        $rows = [];

        foreach (range(1, 200) as $i) {
            $rows[] = [...$row, 'public_id' => (string) Str::uuid7(), 'document_no' => 'NEW-'.$i,
                'supplier_bill_no' => 'NEW-'.$i, 'trx_date' => now()->toDateString()];
        }
        DB::table('pur_bills')->insert($rows);

        $offered = $this->get(route('purchase.payment.create'))->assertOk()->viewData('openBills');

        $this->assertTrue($offered->contains(fn (PurchaseBill $b) => $b->id === $old->id),
            '⛔ ২০০টা নতুন বিলের পরে পুরনো অপরিশোধিত বিলটা পরিশোধের তালিকায় নেই।');
        $this->assertCount(201, $offered);
    }
}
