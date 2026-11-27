<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseBillLine;
use Tests\TestCase;

/**
 * ⭐ বিলের মালের নাম (খরচের ভাউচারে বিল ট্যাগ করার সময়) — পণ্যের নিজের নিয়মে: বাংলা থাকলে বাংলা, নাহলে ইংরেজি।
 *
 * ⛔ আগে `display_name ?? name_bn ?? '—'` — `display_name` কলাম পণ্যে নেই, তাই কেবল ইংরেজি নামের পণ্য "—" হয়ে যেত।
 *    ধরা পড়েছে PHPStan-এ (২৮ নভেম্বর ২০২৬)।
 */
final class ABillNamesGoodsThatHaveOnlyAnEnglishNameTest extends TestCase
{
    public function test_a_product_with_only_an_english_name_is_named_not_dashed(): void
    {
        app()->setLocale('bn');

        $bill = new PurchaseBill;
        $bill->setRelation('lines', collect([
            $this->line((new Product)->forceFill(['name_en' => 'Soap 100g', 'name_bn' => null])),
            $this->line((new Product)->forceFill(['name_en' => 'Rice', 'name_bn' => 'চাল'])),
            $this->line(null),
        ]));

        $this->assertSame(['Soap 100g', 'চাল', '—'], $bill->goods_names, '⛔ কেবল ইংরেজি নামের পণ্য বিলে নাম পায় না');
    }

    private function line(?Product $product): PurchaseBillLine
    {
        $line = new PurchaseBillLine;
        $line->setRelation('product', $product);

        return $line;
    }
}
