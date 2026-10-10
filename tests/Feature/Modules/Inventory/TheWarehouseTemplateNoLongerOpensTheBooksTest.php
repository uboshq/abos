<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Module\ModuleRegistry;
use Tests\TestCase;

/**
 * ⛔ গুদামের ভূমিকার ছাঁচে খোলা মজুদের চাবি ছিল (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬, মজুদ M12a)।
 *
 * ⓘ খোলা মজুদ খাতায় টাকা বসায় — শুরুর হিসাবের সিদ্ধান্ত, গুদামের মেঝের কাজ নয়। এখন নতুন কোম্পানির `Warehouse` ছাঁচে
 * `inventory.stock.opening` নেই; মেঝের বাকি কাজ (বসানো, লট, বদলি, গণনা) আগের মতোই। ⓘ চলমান ভূমিকা ছাঁচ বদলালে বদলায় না।
 */
final class TheWarehouseTemplateNoLongerOpensTheBooksTest extends TestCase
{
    public function test_the_warehouse_template_keeps_the_floor_work_but_not_opening_stock(): void
    {
        $warehouse = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            $warehouse = array_merge($warehouse, array_values($module->roleTemplates['Warehouse'] ?? []));
        }

        $this->assertContains('inventory.stock.place', $warehouse, 'প্রস্তুতিটাই ভুল — গুদামের ছাঁচ পাওয়া যায়নি।');
        $this->assertNotContains('inventory.stock.opening', $warehouse, '⛔ গুদামের ছাঁচ এখনো খোলা মজুদ বসাতে দেয় — খাতায় শুরুর টাকা।');

        foreach (['inventory.stock.lot', 'inventory.transfer.receive', 'inventory.count.create'] as $floor) {
            $this->assertContains($floor, $warehouse, "⛔ মেঝের কাজ {$floor} ছাঁচ থেকে হারাল।");
        }
    }
}
