<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একই সরবরাহকারীর একই বিল নম্বর দুবার — দুটো অনুরোধ একসাথে এলে (পুরো-ERP অডিট, ১০ অক্টোবর ২০২৬, ক্রয় ⛔১)।
 *
 * ⓘ খোঁজটা ছিল তালা ছাড়া: দুটো অনুরোধ একসাথে "নেই" দেখত, দুটোই বসত; ডাটাবেজে unique নেই।
 * ⭐ এখন খোঁজের আগে সরবরাহকারীর সারিতে তালা — দ্বিতীয়জন প্রথমজনের বিল দেখে থামে।
 */
final class TwoClicksEnteredOneSupplierBillTwiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_supplier_is_locked_before_the_bill_number_is_looked_up(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $supplier = Supplier::query()->firstOrFail();
        $product = Product::query()->orderBy('id')->firstOrFail();
        $bill = fn () => app(PurchaseBillService::class)->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'RACE-77'],
            [['product_id' => $product->id, 'qty' => '1', 'rate' => '10']],
        );

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        $bill();

        $lockAt = null;
        $askAt = null;
        foreach ($queries as $i => $sql) {
            if ($lockAt === null && str_contains($sql, 'from `suppliers`') && str_contains($sql, 'for update')) {
                $lockAt = $i;
            }
            if ($askAt === null && str_contains($sql, 'from `pur_bills`') && str_contains($sql, 'supplier_bill_no')) {
                $askAt = $i;
            }
        }

        $this->assertNotNull($askAt, 'দৃশ্যটাই বানানো যায়নি — "এই নম্বর আছে কি না" প্রশ্নটা পাওয়া গেল না।');
        $this->assertNotNull($lockAt, '⛔ সরবরাহকারীর সারিতে তালা পড়েনি — দুটো অনুরোধ একই বিল নম্বর একসাথে বসাতে পারে।');
        $this->assertLessThan($askAt, $lockAt, '⛔ খোঁজ তালার আগে — দ্বিতীয় অনুরোধ পুরনো উত্তর পাবে।');

        // ⓘ আর খোঁজটা নিজেও কাজ করে — একই নম্বর দ্বিতীয়বার থামে
        $this->expectException(ValidationException::class);
        $bill();
    }
}
