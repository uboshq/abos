<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Widgets;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockFacts;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ ফোনের "মজুদ সতর্কতা" উইজেট — `GET /api/v1/widgets/summary` → `widgets.stock_alerts` (মালিকের উইজেট-নকশা ৫, ২০ অক্টোবর
 * ২০২৬; [[StockAlertsSummary]])।
 *
 * দাবি, আসল HTTP দিয়ে:
 *  - সারি সীমার সবচেয়ে কম ভাগ আগে (২০০-র ২৪ → ১৫০-র ৬১ → ১২০-র ৮৮), তিনটা; গোনা ওয়েবের [[StockFacts::belowReorder()]]-এর সাথে
 *    হুবহু এক (চারটা — ১০০-র ৯০-ও), সীমার উপরের পণ্য নেই;
 *  - প্রতিটা সারিতে পরিমাণ, সীমা, একক, ভাগ আর মজুদের পাতার লিংক; মাথায় গুদাম আর শাখা;
 *  - ⛔ বিক্রয়কর্মী (মজুদের চাবি নেই) — চাবিটাই উত্তরে নেই, ফোন উইজেট দেখায় না;
 *  - ⛔ এক শাখা বাছা থাকলে অন্য শাখার গুদামের মাল আসে না।
 *
 * ⓘ লেখা হয়েছে ২০ অক্টোবর, Mac বন্ধ থাকায় চালানো হয়নি (ec)।
 */
final class TheStockAlertWidgetShowsTheLowestShareFirstTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Branch $home;

    private Warehouse $store;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->home = $this->company->defaultBranch();
        CompanyContext::set((int) $this->company->id, $this->home->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        // ⓘ নমুনার পণ্যগুলোর সীমা শূন্য — কেবল এই টেস্টের পণ্য সীমার নিচে, তাই ক্রম আর গোনা নিশ্চিত
        Product::query()->update(['reorder_level' => 0]);

        $this->store = Warehouse::query()->create(['branch_id' => $this->home->id, 'code' => 'W5-MAIN',
            'name_en' => 'প্রধান', 'name_bn' => 'প্রধান', 'is_active' => true]);

        $this->probe('W5-A', 'Bullet King 500ml', '200', '24', $this->store);
        $this->probe('W5-B', 'Star Line Atta 2kg', '150', '61', $this->store);
        $this->probe('W5-C', 'Jabed Mustard Oil 1L', '120', '88', $this->store);
        $this->probe('W5-D', 'Fourth below', '100', '90', $this->store);
        $this->probe('W5-E', 'Above the level', '50', '80', $this->store);
    }

    public function test_the_lowest_share_comes_first_and_the_count_is_the_webs(): void
    {
        $this->narrowTo($this->home);
        $widget = $this->summary($this->owner)->json('widgets.stock_alerts');

        $this->assertNotNull($widget, 'প্রস্তুতিটাই ভুল — মালিক মজুদ দেখেন।');
        $this->assertSame(['Bullet King 500ml', 'Star Line Atta 2kg', 'Jabed Mustard Oil 1L'], array_column($widget['rows'], 'product'),
            '⛔ সীমার সবচেয়ে কম ভাগ আগে নয়, বা তিনটার বেশি/কম।');
        $this->assertSame(4, $widget['count'], '⛔ গোনা চারটা — ১০০-র ৯০-ও সীমার নিচে, ৫০-এর ৮০ নয়।');
        $this->assertSame(CompanyContext::forCompany((int) $this->company->id, fn () => app(StockFacts::class)->belowReorder()), $widget['count'],
            '⛔ উইজেটের গোনা ওয়েবের "পুনঃক্রয়ের নিচে"-র সাথে মেলে না।');

        $first = $widget['rows'][0];
        $this->assertSame('24', $first['available']);
        $this->assertSame('200', $first['reorder']);
        $this->assertSame(0, bccomp($first['share'], '0.12', 4), '⛔ ভাগ ২৪ ÷ ২০০ = ০.১২ নয়।');
        $this->assertSame('/inventory/stock?q=W5-A', $first['link']);
        $this->assertSame('/inventory/stock?sort=available', $widget['link']);
        $this->assertNotEmpty($first['unit']);
        $this->assertNotContains('Above the level', array_column($widget['rows'], 'product'));
        // ⓘ শাখায় একটাই গুদাম হলে তার নাম; নমুনার শাখায় আগে থেকে গুদাম থাকলে কেবল শাখা ("সব গুদাম")
        $alone = Warehouse::query()->where('branch_id', $this->home->id)->count() === 1;
        $this->assertSame($alone ? 'প্রধান' : null, $widget['warehouse'] ?? null, '⛔ মাথার গুদাম ভুল।');
        $this->assertSame($this->home->name(), $widget['branch'] ?? null);
    }

    public function test_a_salesman_never_gets_the_stock_widget(): void
    {
        $sr = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->assertFalse(CompanyContext::forCompany((int) $this->company->id, fn () => $sr->fresh()->can('inventory.stock.view')),
            'প্রস্তুতিটাই ভুল — বিক্রয়কর্মীর মজুদের চাবি আছে।');

        $widgets = $this->summary($sr)->json('widgets');
        $this->assertArrayNotHasKey('stock_alerts', (array) $widgets, '⛔ বিক্রয়কর্মীর ফোনে মজুদ গেল (মালিক, ১ অক্টোবর ২০২৬)।');
    }

    public function test_another_branchs_store_stays_behind_the_wall(): void
    {
        $other = Branch::query()->where('company_id', $this->company->id)->whereKeyNot($this->home->id)->firstOrFail();
        $far = Warehouse::query()->create(['branch_id' => $other->id, 'code' => 'W5-FAR', 'name_en' => 'Far store', 'is_active' => true]);
        $this->probe('W5-Z', 'Far away low', '1000', '1', $far);

        $this->narrowTo($this->home);
        $rows = $this->summary($this->owner)->json('widgets.stock_alerts.rows');
        $this->assertNotContains('Far away low', array_column($rows, 'product'), '⛔ অন্য শাখার গুদামের মাল এই শাখার উইজেটে।');

        $this->narrowTo($other);
        $rows = $this->summary($this->owner)->json('widgets.stock_alerts.rows');
        $this->assertSame('Far away low', $rows[0]['product'] ?? null, 'প্রস্তুতিটাই ভুল — নিজের শাখায় পণ্যটা আসে।');
    }

    private function probe(string $code, string $name, string $reorder, string $onHand, Warehouse $warehouse): Product
    {
        $product = Product::query()->create(['code' => $code, 'name_en' => $name, 'name_bn' => $name,
            'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id, 'is_active' => true, 'reorder_level' => $reorder]);
        StockMovement::query()->create(['company_id' => $this->company->id, 'branch_id' => $warehouse->branch_id,
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString(),
            'source_type' => 'test.widget5', 'source_id' => ++$this->n, 'floor_change' => $onHand]);

        return $product;
    }

    private function narrowTo(Branch $branch): void
    {
        $this->owner->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $branch->id,
            'view_all_branches' => false])->save();
        CompanyContext::set((int) $this->company->id, $branch->id);
    }

    private function summary(User $user): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->getJson('/api/v1/widgets/summary')->assertOk();
    }
}
