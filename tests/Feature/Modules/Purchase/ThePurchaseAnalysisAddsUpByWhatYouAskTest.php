<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseReturnService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ক্রয় বিশ্লেষণ ও দরের বিশ্লেষণ — রিপোর্ট সেন্টার ধাপ ৩ ([[PurchaseAnalysisReports]])।
 *
 * এক সরবরাহকারী, এক পণ্য: আজ ১০ @ ১০০ আর ১০ @ ১২০, ২টা ফেরত (১২০ দরে); ৪০ দিন আগে ৫ @ ৯০ (আগের সময়ে)।
 *   বিশ্লেষণ (গত ৩০ দিন) → কেনা ২০ / ২,২০০; ফেরত ২ / ২৪০; নিট ১,৯৬০; গড় ১১০
 *   দরের বিশ্লেষণ        → শেষ ১২০, গড় ১১০, কম ১০০, বেশি ১২০, আগের সময়ের গড় ৯০
 * ⓘ পণ্য ধরে চাইলে একই সারি পণ্যের নামে; এক শাখা বাছলে অন্য শাখার বিল নেই।
 */
final class ThePurchaseAnalysisAddsUpByWhatYouAskTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private int $supplierId;

    private Warehouse $warehouse;

    public function test_bought_returned_net_and_prices_add_up_and_the_branch_wall_holds(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->supplierId = (int) Supplier::query()->orderBy('id')->value('id');
        $this->product = Product::query()->whereNull('tax_id')->where('track_batch', false)->where('track_serial', false)
            ->where('qc_required', false)->orderBy('id')->firstOrFail();

        $this->bill('5', '90', now()->subDays(40)->toDateString());
        $this->bill('10', '100', now()->subDay()->toDateString());
        $later = $this->bill('10', '120', now()->toDateString());

        $return = app(PurchaseReturnService::class)->create([
            'supplier_id' => $this->supplierId, 'warehouse_id' => $this->warehouse->id,
            'purchase_bill_id' => $later->id, 'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->product->id, 'qty' => '2', 'purchase_bill_line_id' => $later->lines->first()->id]]);
        $this->post(route('purchase.return.confirm', $return))->assertSessionHasNoErrors();

        $range = ['from' => now()->subDays(29)->toDateString(), 'to' => now()->toDateString()];

        $row = collect($this->report('purchase.analysis', $range))->firstWhere('dim_key', (string) $this->supplierId);
        $this->assertNotNull($row, 'সরবরাহকারীর সারি নেই।');
        $this->assertSame(['20', '2200', '2', '240', '1960', '110'], $this->nums($row,
            ['bought_qty', 'bought_value', 'returned_qty', 'returned_value', 'net_value', 'avg_rate']), 'বিশ্লেষণের অঙ্ক ভুল।');

        $byProduct = collect($this->report('purchase.analysis', [...$range, 'group_by' => 'product']))
            ->firstWhere('dim_key', (string) $this->product->id);
        $this->assertSame('1960', $this->nums($byProduct, ['net_value'])[0], 'পণ্য ধরে নিট ভুল।');

        $price = collect($this->report('purchase.price_analysis', $range))
            ->first(fn ($r) => str_starts_with((string) $r['product_name'], $this->product->code.' - '));
        $this->assertSame(['120', '110', '100', '120', '90'], $this->nums($price,
            ['last_rate', 'avg_rate', 'min_rate', 'max_rate', 'previous_avg']), 'দরের বিশ্লেষণ ভুল।');

        // ⛔ বিলগুলো অন্য শাখায় — এক শাখা বাছলে কিছুই নেই
        $other = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)
            ->where('id', '<>', $company->defaultBranch()->id)->firstOrFail();
        PurchaseBill::query()->update(['branch_id' => $other->id]);
        PurchaseReturn::query()->update(['branch_id' => $other->id]);

        $this->assertNull(collect($this->report('purchase.analysis', [...$range, 'branch_id' => $company->defaultBranch()->id]))
            ->firstWhere('dim_key', (string) $this->supplierId), '⛔ অন্য শাখার কেনা এই শাখার বিশ্লেষণে।');
    }

    private function bill(string $qty, string $rate, string $date): PurchaseBill
    {
        $service = app(PurchaseBillService::class);

        return $service->confirm($service->create(
            ['supplier_id' => $this->supplierId, 'warehouse_id' => $this->warehouse->id, 'trx_date' => $date],
            [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => $rate]],
        ))->fresh(['lines']);
    }

    private function report(string $key, array $filters): array
    {
        return app(ReportEngine::class)->run($key, $filters, perPage: 1000)->rows;
    }

    /** @return list<string> সংখ্যাগুলো পূর্ণ টাকায় — ছোট ভগ্নাংশ বাদ */
    private function nums(?array $row, array $keys): array
    {
        $this->assertNotNull($row);

        return array_map(fn ($k) => (string) round((float) $row[$k]), $keys);
    }
}
