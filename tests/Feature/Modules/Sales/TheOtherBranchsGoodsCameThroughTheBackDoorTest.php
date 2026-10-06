<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * অন্য শাখার পণ্য আর বন্ধ পণ্য পিছনের দরজা দিয়ে কাগজে বসত — Inventory অডিট ম২৩, ৫ অক্টোবর ২০২৬।
 *
 * ⛔ "কোন শাখায় বিক্রি" আর "সক্রিয়" দুটোই কেবল পর্দার পিকারে: ফোনের কাউন্টার বা সরাসরি অনুরোধ পণ্যের id পাঠালে
 * লায়নের পণ্য সুপারের চালানে, আর বন্ধ পণ্য নতুন বিলে বসত। ⭐ এখন দেয়াল সেবায় ([[SellableHere]]), চালান আর বিল দুটোতেই;
 * আগের কাগজ থেকে আসা লাইন ছাড়।
 */
final class TheOtherBranchsGoodsCameThroughTheBackDoorTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_another_branchs_product_cannot_go_on_this_branchs_challan_or_bill(): void
    {
        $other = Branch::query()->where('company_id', $this->company->id)->whereKeyNot($this->company->defaultBranch()?->id)->first()
            ?? Branch::query()->create(['company_id' => $this->company->id, 'code' => 'M23B', 'name_en' => 'Other branch', 'is_active' => true]);
        $theirs = $this->product('M23-THEIRS');
        DB::table('inv_product_branches')->insert(['company_id' => $this->company->id, 'product_id' => $theirs->id, 'branch_id' => $other->id,
            'created_at' => now(), 'updated_at' => now()]);

        $this->assertRefused(fn () => $this->challan($theirs), 'অন্য শাখার পণ্য এই শাখার চালানে বসল।');
        $this->assertRefused(fn () => $this->bill($theirs), 'অন্য শাখার পণ্য এই শাখার বিলে বসল।');
        $this->assertRefused(fn () => app(\App\Modules\Sales\Services\SalesOrderService::class)->create(
            ['customer_id' => Customer::query()->orderBy('id')->firstOrFail()->id, 'warehouse_id' => $this->warehouse()->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => $theirs->id, 'ordered_qty' => '1', 'rate' => '100']],
        ), 'অন্য শাখার পণ্য এই শাখার আদেশে বসল।');
        $this->assertRefused(fn () => app(\App\Modules\Sales\Services\DeliveryOrderService::class)->create(
            ['customer_id' => Customer::query()->orderBy('id')->firstOrFail()->id],
            [['product_id' => $theirs->id, 'qty' => '1']],
            User::query()->where('email', 'owner@abos.test')->firstOrFail(),
        ), 'অন্য শাখার পণ্য এই শাখার DO-তে বসল।');

        // ⓘ যে পণ্যের শাখার সারি নেই সে সব শাখার — চলে
        $this->assertNotNull($this->challan($this->product('M23-ALL')));
    }

    public function test_a_switched_off_product_cannot_go_on_a_new_bill(): void
    {
        $off = $this->product('M23-OFF');
        Product::query()->whereKey($off->id)->update(['is_active' => false]);

        $this->assertRefused(fn () => $this->bill($off->fresh()), 'বন্ধ পণ্য নতুন বিলে বসল।');
    }

    private function product(string $code): Product
    {
        return Product::query()->create(['code' => $code, 'name_en' => $code, 'name_bn' => $code, 'sale_price' => '100',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true])->fresh();
    }

    private function challan(Product $product): mixed
    {
        return app(DeliveryChallanService::class)->create(
            ['customer_id' => Customer::query()->orderBy('id')->firstOrFail()->id, 'warehouse_id' => $this->warehouse()->id,
                'trx_date' => now()->toDateString(), 'own_transport' => true],
            [['product_id' => $product->id, 'delivered_qty' => '1', 'rate' => '100']],
        );
    }

    private function bill(Product $product): mixed
    {
        return app(SalesInvoiceService::class)->create(
            ['customer_id' => Customer::query()->orderBy('id')->firstOrFail()->id, 'warehouse_id' => $this->warehouse()->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => '1', 'rate' => '100']],
        );
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::query()->where('branch_id', $this->company->defaultBranch()?->id)->orderBy('id')->firstOrFail();
    }

    private function assertRefused(callable $call, string $why): void
    {
        try {
            $call();
            $this->fail('⛔ '.$why);
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
