<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\ListTotals;

use App\Core\Support\CompanyContext;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * মজুদের তালিকাগুলোর নিচে যোগফলের পট্টি — মালিক, ৫ অক্টোবর ২০২৬ ([[x-ui.list-totals]])।
 *
 *   মজুদ      সারি · তাকে (পরিমাণ) · বিক্রয়যোগ্য · মূল্য — মূল্য কেবল দর দেখার চাবি থাকলে আর দর খোলা থাকলে
 *   বাকিগুলো   সারির সংখ্যা
 *
 * ⓘ তাকের যোগ ডাটাবেজের সরাসরি `SUM(floor_change)` — সব গুদামে, আর এক গুদামে ছেঁকে।
 */
final class InventoryListsCarryTheirTotalsTest extends TestCase
{
    use ReadsTheTotalsBar;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitTheOwnerInTheNavyLook();
    }

    public function test_the_stock_list_sums_the_floor_of_every_page_and_under_a_warehouse(): void
    {
        $warehouse = (int) Warehouse::query()->where('is_default', true)->value('id');

        foreach ([[], ['warehouse_id' => $warehouse]] as $filter) {
            $query = ['stock' => 'all', ...$filter];
            $response = $this->get(route('inventory.stock.index', $query))->assertOk();
            $listed = $response->viewData('products');

            $floor = (string) DB::table('inv_stock_movements')->where('company_id', CompanyContext::id())
                ->when($filter !== [], fn ($q) => $q->where('warehouse_id', $warehouse))
                ->sum('floor_change');

            $this->assertGreaterThan(0, bccomp($floor, '0', 4), 'দৃশ্যটাই বানানো যায়নি — ডেমোতে তাকে মাল নেই।');
            $this->assertBar('inventory.stock.index', $query, $listed->total(), [], [__('inventory::field.floor') => $floor]);
        }
    }

    public function test_the_stock_value_is_on_the_bar_only_while_cost_is_shown(): void
    {
        $shown = $this->bar((string) $this->get(route('inventory.stock.index', ['cost' => 'show']))->assertOk()->getContent(), 'cost=show');
        $hidden = $this->bar((string) $this->get(route('inventory.stock.index', ['cost' => 'hide']))->assertOk()->getContent(), 'cost=hide');

        $this->assertStringContainsString(__('inventory::field.stock_value'), $shown, '⛔ দর খোলা, অথচ পট্টিতে মজুদের মূল্য নেই।');
        $this->assertStringNotContainsString(__('inventory::field.stock_value'), $hidden, '⛔ দর লুকানো, অথচ পট্টি মূল্য বলে দিচ্ছে।');
    }

    public function test_the_lists_without_a_sum_still_say_how_many_rows(): void
    {
        foreach ([
            'inventory.product.index' => 'products', 'inventory.warehouse.index' => 'warehouses',
            'inventory.transfer.index' => 'transfers', 'inventory.count.index' => 'counts',
        ] as $list => $rows) {
            $response = $this->get(route($list))->assertOk();
            $this->assertStringContainsString($this->rowsText($response->viewData($rows)->total()),
                $this->bar((string) $response->getContent(), $list));
        }

        $warehouses = DB::table('inv_warehouses')->where('company_id', CompanyContext::id())->count();
        $this->assertStringContainsString($this->rowsText($warehouses),
            $this->bar((string) $this->get(route('inventory.warehouse.index'))->getContent(), 'inventory.warehouse.index'),
            '⛔ গুদামের পট্টি ডাটাবেজের সরাসরি গোনা বলে না।');
    }
}
