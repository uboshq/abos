<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Imports;

use App\Core\Contracts\Importer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\OpeningStockService;
use Illuminate\Support\Carbon;

/**
 * শুরুর দিনের মজুদ — ফাইল ধরে।
 *
 * ── কেন পর্দাটা যথেষ্ট নয় ───────────────────────────────────────────
 * খোলা মজুদের পর্দা একবারে একটা সারি নেয়, আর সেটাই ঠিক যখন গুদামে
 * দাঁড়িয়ে গোনা হচ্ছে। কিন্তু পুরনো ব্যবস্থা থেকে আসা ডিপোর চারশো
 * পণ্যের তালিকা ওভাবে বসানো মানে চারশোবার ফর্ম ভরা — বাস্তবে সেটা কেউ
 * করেন না, আর তখন ABOS চালু হয় অর্ধেক মজুদ নিয়ে।
 *
 * `ProductImporter`-এর মাথায় লেখা আছে মজুদ ওখানে নেই, ইচ্ছাকৃতভাবে:
 * *"পণ্যের তালিকা অফিসে বসে তৈরি হয়, আর গণনাটা গুদামে দাঁড়িয়ে"*। কথাটা
 * এখনো সত্যি — তাই এটা **আলাদা** একটা ইমপোর্ট, একই ফাইলের বাড়তি কলাম
 * নয়। পণ্য আগে বসে, তারপর গোনা মজুদ।
 *
 * ── কেন দর বাধ্যতামূলক ──────────────────────────────────────────────
 * শুরুর দিনের মালের আগে কোনো চালান নেই, তাই দরটা কোথাও থেকে বের করে
 * নেওয়ার উপায় নেই — মানুষকেই বলতে হয়। দর ছাড়া মজুদ বসালে মজুদের
 * মূল্য শূন্য হত, আর প্রথম বিক্রিতেই মুনাফা পুরো বিক্রয়মূল্যের সমান
 * দেখাত।
 */
final class OpeningStockImporter implements Importer
{
    public function __construct(private readonly OpeningStockService $opening) {}

    public static function label(): string
    {
        return 'inventory::menu.opening';
    }

    /**
     * @return array<string, array{label: string, required: bool}>
     */
    public static function columns(): array
    {
        return [
            /*
             * পণ্য চেনা যায় কোড বা বারকোড ধরে — নাম ধরে নয়।
             *
             * নামে বানানভেদ থাকে ("সয়াবিন তেল ৫ লিটার" বনাম "সয়াবিন
             * তেল ৫ লি."), আর ভুল পণ্যে মজুদ বসানো মানে দুইটা সংখ্যাই
             * ভুল — একটায় বেশি, একটায় কম।
             */
            'product_code' => ['label' => 'inventory::field.code', 'required' => true],
            'warehouse' => ['label' => 'inventory::field.warehouse', 'required' => false],
            'qty' => ['label' => 'inventory::field.quantity', 'required' => true],
            'unit_cost' => ['label' => 'inventory::field.purchase_price', 'required' => true],
            'trx_date' => ['label' => 'core.table.date', 'required' => false],
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    public function check(array $row): array
    {
        $errors = [];

        $product = $this->product($row['product_code']);

        if ($product === null) {
            $errors[] = __('core.import.unknown_value', [
                'column' => 'product_code', 'value' => $row['product_code'],
            ]);
        }

        $warehouse = $this->warehouse($row['warehouse']);

        if ($warehouse === null) {
            $errors[] = __('core.import.unknown_value', [
                'column' => 'warehouse', 'value' => $row['warehouse'] ?: '—',
            ]);
        }

        foreach (['qty', 'unit_cost'] as $numeric) {
            if (! is_numeric($row[$numeric])) {
                $errors[] = __('core.import.not_a_number', ['column' => $numeric]);

                continue;
            }

            if (bccomp((string) $row[$numeric], '0', 4) <= 0) {
                $errors[] = __('inventory::validation.opening_must_be_positive', ['column' => $numeric]);
            }
        }

        if (filled($row['trx_date']) && ! strtotime($row['trx_date'])) {
            $errors[] = __('core.import.not_a_date', ['column' => 'trx_date']);
        }

        /*
         * একই পণ্য-গুদামে দুইবার খোলা মজুদ নয়।
         *
         * ── কেন এটা এখানে আটকাতেই হবে ───────────────────────────────
         * ফাইলে একই পণ্য দুইবার থাকা খুব সাধারণ — পুরনো ব্যবস্থায় দুই
         * লটে ছিল, রপ্তানিতে দুই সারি হয়ে এসেছে। দুইটাই বসে গেলে
         * মজুদ দ্বিগুণ, আর কেউ ধরতে পারত না কারণ দুইটা সারিই দেখতে
         * ঠিক।
         *
         * পর্দাটাও একই নিয়ম মানে (`openProducts()` বসানো জোড়া বাদ
         * দেয়), তাই ফাইল আর পর্দা এক কথা বলে।
         */
        if ($product !== null && $warehouse !== null && $this->alreadyOpened($product, $warehouse)) {
            $errors[] = __('inventory::validation.opening_already_set', [
                'product' => $product->name(),
                'warehouse' => $warehouse->name(),
            ]);
        }

        return $errors;
    }

    /**
     * @param  array<string, string>  $row
     */
    public function import(array $row): void
    {
        $product = $this->product($row['product_code']);
        $warehouse = $this->warehouse($row['warehouse']);

        if ($product === null || $warehouse === null) {
            return;
        }

        $this->opening->bringIn(
            product: $product,
            warehouse: $warehouse,
            qty: (string) $row['qty'],
            unitCost: (string) $row['unit_cost'],
            date: filled($row['trx_date']) ? Carbon::parse($row['trx_date'])->toDateString() : null,
            narration: __('inventory::message.opening_from_file'),
        );
    }

    /** কোড, নাহলে বারকোড — দুইটাই কাগজে ছাপা থাকে। */
    private function product(string $key): ?Product
    {
        $key = trim($key);

        if ($key === '') {
            return null;
        }

        return Product::query()->where('code', $key)->first()
            ?? Product::query()->where('barcode', $key)->first();
    }

    /**
     * গুদাম — খালি রাখলে প্রধানটাই।
     *
     * এক গুদামের ডিপোতে কলামটা ভরতে বলা মানে চারশো সারিতে চারশোবার
     * একই লেখা, আর একটাতে টাইপো হলে ওই মালটা অন্য কোথাও বসত।
     */
    private function warehouse(string $name): ?Warehouse
    {
        $name = trim($name);

        if ($name === '') {
            return Warehouse::query()->where('is_default', true)->first();
        }

        return Warehouse::query()->where('code', $name)->first()
            ?? Warehouse::query()->where('name_en', $name)->first()
            ?? Warehouse::query()->where('name_bn', $name)->first();
    }

    private function alreadyOpened(Product $product, Warehouse $warehouse): bool
    {
        return StockMovement::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('source_type', OpeningStockService::SOURCE_TYPE)
            ->exists();
    }
}
