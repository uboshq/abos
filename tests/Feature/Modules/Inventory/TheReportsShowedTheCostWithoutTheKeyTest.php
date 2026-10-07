<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockFacts;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * খরচ দেখার চাবি ছাড়াও রিপোর্টে কেনা দর আর মজুদের মূল্য — Inventory অডিট ম১৪, ৫ অক্টোবর ২০২৬।
 *
 * ⛔ পণ্যের পাতায় কেনা দর `inventory.cost.view`-এর পেছনে, অথচ মজুদ-মূল্যের রিপোর্টে কেনা দর আর চার মূল্যের কলাম,
 * মজুদের অবস্থা, ধীর-মরা মাল আর গণনা-বনাম-খাতার মূল্য, আর বয়সের পাতার আটকে থাকা টাকা — সব চাবি ছাড়া খোলা;
 * পর্দা, ছাপা, ফাইল আর ফোন একই কলাম-তালিকা পড়ে, তাই চারটাতেই। মূল্য ÷ পরিমাণ = কেনা দর।
 * ⭐ এখন কলামগুলো চাবি চায়, আর [[StockFacts::agingValue()]] চাবি ছাড়া `null`।
 * ⓘ ম১৪-এর দ্বিতীয় ভাগ (মাল বসানোয় অন্য লট বা কাগজের নাম) গ১৪-তে বন্ধ — [[PlacementTookAnotherPapersGoodsTest]]।
 */
final class TheReportsShowedTheCostWithoutTheKeyTest extends TestCase
{
    use RefreshDatabase;

    private const COST_COLUMNS = [
        'inventory.stock_value' => ['purchase_price', 'opening_value', 'in_value', 'out_value', 'closing_value'],
        'inventory.stock_position' => ['value'],
        'inventory.slow_dead' => ['value'],
        'inventory.count_vs_book' => ['difference_value'],
    ];

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    public function test_a_report_reader_without_the_cost_key_gets_no_cost_column(): void
    {
        $plain = $this->reader('m14-plain', ['inventory.report']);
        $costed = $this->reader('m14-costed', ['inventory.report', 'inventory.cost.view']);
        $engine = app(ReportEngine::class);

        foreach (self::COST_COLUMNS as $report => $keys) {
            $columns = collect($engine->get($report)->columns)->keyBy('key');

            foreach ($keys as $key) {
                $this->assertTrue($columns->has($key), "প্রস্তুতিটাই ভুল — {$report}-এ {$key} কলাম নেই।");
                $this->assertFalse($columns[$key]->visibleTo($plain), "⛔ চাবি ছাড়া {$report}-এর {$key} দেখা গেল।");
                $this->assertTrue($columns[$key]->visibleTo($costed), "{$report}-এর {$key} চাবিওয়ালার কাছেও ঢাকা।");
            }

            // ⓘ বিক্রির দর খরচ নয় — সেটা খোলা থাকে
            if ($columns->has('sale_price')) {
                $this->assertTrue($columns['sale_price']->visibleTo($plain), 'বিক্রির দরও ঢাকা পড়ল।');
            }
        }
    }

    public function test_the_stock_age_page_shows_no_money_without_the_cost_key(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        $product = Product::query()->create([
            'code' => 'M14-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Old sack', 'name_bn' => 'পুরনো বস্তা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true, 'track_batch' => false,
        ]);
        app(CostLayerService::class)->receive(
            product: $product, qty: '3', unitCost: '4321.5', sourceType: 'opening', sourceId: 91401,
            documentNo: 'M14-AGE', date: Carbon::today()->subDays(120)->toDateString(),
        );

        $this->get(route('inventory.stock.age'))->assertOk()->assertSee('পুরনো বস্তা')->assertSee('12,964');

        $plain = $this->reader('m14-age', ['inventory.report']);
        $this->actingAs($plain);
        $this->assertNull(app(StockFacts::class)->agingValue(90), '⛔ চাবি ছাড়া আটকে থাকা টাকা ফিরল।');
        $this->get(route('inventory.stock.age'))->assertOk()
            ->assertSee('পুরনো বস্তা')
            ->assertDontSee('12,964');
    }

    /** @param list<string> $permissions */
    private function reader(string $name, array $permissions): User
    {
        $role = Role::query()->create(['name' => $name, 'guard_name' => 'web', 'company_id' => $this->company->id]);
        $role->syncPermissions(Permission::query()->whereIn('name', $permissions)->get());
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        $user->assignRole($role);

        return $user->fresh();
    }
}
