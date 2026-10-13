<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseReturnService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ এক বিলের লাইন একাধিকবার ফেরত যেত — বিলের বেশি (Purchase লাইন-ধরা নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[PurchaseReturnService::assertWithinBilled()]])।
 *
 * ⚠️ "বিলের বেশি নয়" কেবল আগের পাকা ফেরত গুনত, প্রতিটা সারি আলাদা করে, আর সেই গোনা দেখার শাখার দেয়াল দিয়ে: একই ফেরতে ৮ + ৮ দুই
 * সারিতে ১০-এর লাইনে ১৬ যেত; অন্য শাখা দেখানো মানুষের হাতে আগের ফেরতটা "নেই"। প্রতিবার সরবরাহকারীর দেনা বেশি কমত।
 */
final class OneBillLineWasReturnedTwiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private Product $product;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('track_batch', false)->where('track_serial', false)->orderBy('id')->firstOrFail();
        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
    }

    public function test_one_return_cannot_split_a_bill_line_over_two_rows_to_send_back_more_than_was_billed(): void
    {
        $bill = $this->bill();
        $line = $bill->lines()->firstOrFail();

        $return = $this->returns()->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'purchase_bill_id' => $bill->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '8', 'purchase_bill_line_id' => $line->id],
                ['product_id' => $this->product->id, 'qty' => '8', 'purchase_bill_line_id' => $line->id]],
        );

        try {
            $this->returns()->confirm($return->fresh('lines'));
            $this->fail('⛔ ১০-এর বিলের লাইনে এক ফেরতে ৮ + ৮ = ১৬ ফেরত গেল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors(), 'অন্য কারণে থামল।');
        }

        $this->assertSame(DocumentStatus::DRAFT, $return->fresh()->status);
    }

    public function test_an_earlier_return_counts_whatever_branch_the_person_is_looking_at(): void
    {
        $bill = $this->bill();
        $line = $bill->lines()->firstOrFail();
        $first = $this->returns()->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'purchase_bill_id' => $bill->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '6', 'purchase_bill_line_id' => $line->id]],
        );
        $this->returns()->confirm($first->fresh('lines'));
        $second = $this->returns()->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'purchase_bill_id' => $bill->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '6', 'purchase_bill_line_id' => $line->id]],
        );

        // ⓘ যিনি পাকা করছেন তাঁর হেডারে অন্য শাখা — আগের ফেরতটা তাঁর শাখার দেয়ালের বাইরে
        $other = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->whereKeyNot((int) $first->branch_id)->orderBy('id')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $other->id])->save();
        CompanyContext::set($this->company->id, $other->id);
        app(DataScope::class)->forget();
        $this->actingAs($owner->fresh());

        try {
            $this->returns()->confirm(PurchaseReturn::acrossBranches()->with('lines')->findOrFail($second->id));
            $this->fail('⛔ অন্য শাখা দেখানো অবস্থায় আগের ৬-এর ফেরত চোখ এড়াল — ১০-এর লাইনে ১২ ফেরত গেল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors(), 'অন্য কারণে থামল।');
        }
    }

    private function bill(): PurchaseBill
    {
        $bills = app(PurchaseBillService::class);

        return $bills->confirm($bills->create(
            ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString(),
                'supplier_bill_no' => 'RT-'.fake()->unique()->numberBetween(1000, 9999)],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '50']],
        ));
    }

    private function returns(): PurchaseReturnService
    {
        return app(PurchaseReturnService::class);
    }
}
