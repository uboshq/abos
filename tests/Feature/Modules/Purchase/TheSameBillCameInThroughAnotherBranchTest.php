<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একই বিল অন্য শাখা দিয়ে ঢুকত — পুরো ERP অডিট, ক্রয় ⚠️৮, ৬ অক্টোবর ২০২৬।
 *
 * ⛔ বিলের মডেল শাখার দেয়ালে; হেডারে একটা শাখা বাছা থাকলে অন্য শাখার একই নম্বর যাচাইয়ে চোখে পড়ত না — একই সরবরাহকারীর একই
 * বিল-নম্বর দ্বিতীয়বার তোলা যেত (দুবার পরিশোধের ঝুঁকি), আর একই কাগজ-নম্বর পরে কোম্পানির ইউনিক সূচকে ধাক্কা খেয়ে ৫০০।
 * ⭐ এখন তিনটা যাচাই শাখার দেয়াল ছাড়া, কোম্পানির দেয়াল রেখে।
 */
final class TheSameBillCameInThroughAnotherBranchTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
    }

    public function test_a_supplier_bill_number_from_another_branch_is_refused(): void
    {
        $a = $this->company->defaultBranch();
        $b = Branch::query()->where('company_id', $this->company->id)->whereKeyNot($a->id)->first()
            ?? Branch::query()->create(['company_id' => $this->company->id, 'code' => 'BR-B', 'name_en' => 'Branch B', 'is_active' => true]);
        $whA = Warehouse::query()->where('is_default', true)->firstOrFail();
        $whB = Warehouse::query()->create(['code' => 'WH-B8', 'name_en' => 'B store', 'is_active' => true, 'branch_id' => $b->id]);
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();

        $this->buy($supplier, $whA, 'MILL-777', ['document_no' => 'PBL-HAND-77']);

        // ⓘ হেডারে শাখা B — বিলের তালিকা এখন কেবল B-র
        $this->owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $b->id])->save();
        CompanyContext::set($this->company->id, $b->id);

        try {
            $this->buy($supplier, $whB, 'MILL-777');
            $this->fail('⛔ একই সরবরাহকারীর একই বিল অন্য শাখায় আবার উঠল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('supplier_bill_no', $e->errors());
        }

        // ⓘ হাতে লেখা কাগজ-নম্বর — আগে ইউনিক সূচকে ধাক্কা খেয়ে ৫০০, এখন পড়ার মতো বার্তা
        try {
            $this->buy($supplier, $whB, 'MILL-778', ['document_no' => 'PBL-HAND-77']);
            $this->fail('⛔ অন্য শাখার একই কাগজ-নম্বর যাচাইয়ে ধরা পড়েনি।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('bill_no', $e->errors());
        }
    }

    private function buy(Supplier $supplier, Warehouse $warehouse, string $billNo, array $extra = [])
    {
        $product = Product::query()->where('track_batch', false)->where('track_serial', false)->orderBy('id')->firstOrFail();

        return app(DirectPurchaseService::class)->complete([
            'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString(), 'supplier_bill_no' => $billNo, ...$extra,
        ], [['product_id' => $product->id, 'qty' => '1', 'rate' => '50', 'sales_price' => '50', 'tax' => '0']])['bill'];
    }
}
