<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Dashboard\SalesDashboard;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বিক্রয়ের ফানেল — মালিকের ড্যাশবোর্ড নকশা (৩ অক্টোবর ২০২৬): এ মাসে কয়টা উদ্ধৃতি, অর্ডার, চালান, বিল ([[SalesCharts]])।
 *
 * ⓘ দাবি: সুইচ বন্ধে চার্ট নেই; কাউন্টারের একটা পাকা বিক্রি চালান আর বিল দুটোই এক করে বাড়ায়, উদ্ধৃতি-অর্ডার নয়;
 * রাখা খসড়া কোনোটাই বাড়ায় না।
 */
final class TheSalesFunnelCountsEachStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sale_moves_the_delivery_and_bill_steps_and_a_draft_moves_nothing(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        config(['abos.dashboards_v2' => false]);
        $this->assertNull($this->funnel(), '⛔ পুরনো ড্যাশবোর্ডেও ফানেল।');

        config(['abos.dashboards_v2' => true]);
        $before = $this->funnel();
        $this->assertNotNull($before, 'নতুন ড্যাশবোর্ডে ফানেল নেই।');

        $data = [
            'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'own_transport' => '1',
        ];
        $lines = [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '1', 'rate' => '10', 'free_qty' => '0']];

        // ⓘ খসড়া অন্য ক্রেতার নামে — এক ক্রেতার খোলা খসড়া থাকলে নতুন বিল হয় না (ব্যবসার নিয়ম)
        $other = Customer::query()->where('name_en', '!=', 'Rahim Traders')->where('is_active', true)->value('id');
        app(DirectSaleService::class)->complete([...$data, 'customer_id' => $other, 'save_as_draft' => '1'], $lines);
        $this->assertSame($before, $this->funnel(), '⛔ রাখা খসড়াও ফানেলে গোনা হয়েছে।');

        app(DirectSaleService::class)->complete($data, $lines);
        $after = $this->funnel();

        $this->assertSame($before[0], $after[0], '⛔ বিক্রিতে উদ্ধৃতির সংখ্যা বদলেছে।');
        $this->assertSame($before[1], $after[1], '⛔ কাউন্টারের বিক্রিতে অর্ডারের সংখ্যা বদলেছে।');
        $this->assertSame($before[3] + 1, $after[3], '⛔ পাকা বিক্রিতে চালান এক বাড়েনি।');
        $this->assertSame($before[4] + 1, $after[4], '⛔ পাকা বিক্রিতে বিল এক বাড়েনি।');
    }

    /** @return list<int>|null উদ্ধৃতি, অর্ডার, DO, চালান, বিল, আদায় (৫ অক্টোবর ২০২৬: DO আর আদায়ের ধাপ যোগ হলো) */
    private function funnel(): ?array
    {
        $panel = collect(SalesDashboard::dashboard()->panels)->firstWhere('label', __('sales::dashboard.funnel'));

        return $panel === null ? null : array_map(fn (array $p) => (int) $p['value'], $panel->parts);
    }
}
