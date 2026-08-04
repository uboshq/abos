<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Imports;

use App\Core\Contracts\Importer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\ProductService;
use App\Modules\MasterData\Models\Tax;
use App\Modules\MasterData\Models\Unit;
use Illuminate\Validation\ValidationException;

/**
 * পুরনো খাতা থেকে পণ্য।
 *
 * খোলা মজুদ এখানে নেই, ইচ্ছাকৃতভাবে। পণ্যের তালিকা আর গুদামের গণনা
 * দুইটা আলাদা কাজ: তালিকাটা অফিসে বসে তৈরি হয়, আর গণনাটা গুদামে
 * দাঁড়িয়ে। একসাথে চাইলে ব্যবহারকারী কোনোটাই শেষ করতে পারতেন না।
 *
 * মজুদ বসে গণনার পর্দা থেকে (StockService::adjust), আর তখন প্রতিটা
 * সংখ্যার পেছনে একটা কারণ ও একটা তারিখ থাকে — যা একটা CSV কলামে থাকত না।
 */
final class ProductImporter implements Importer
{
    public function __construct(private readonly ProductService $products) {}

    public static function label(): string
    {
        return 'inventory::menu.products';
    }

    /**
     * @return array<string, array{label: string, required: bool}>
     */
    public static function columns(): array
    {
        return [
            'code' => ['label' => 'inventory::field.code', 'required' => false],
            'name_en' => ['label' => 'inventory::field.name_en', 'required' => true],
            'name_bn' => ['label' => 'inventory::field.name_bn', 'required' => false],
            'barcode' => ['label' => 'inventory::field.barcode', 'required' => false],
            'brand' => ['label' => 'inventory::field.brand', 'required' => false],
            'category' => ['label' => 'inventory::field.category', 'required' => false],
            'unit' => ['label' => 'inventory::field.unit', 'required' => false],
            'tax' => ['label' => 'inventory::field.tax', 'required' => false],
            'purchase_price' => ['label' => 'inventory::field.purchase_price', 'required' => false],
            'sale_price' => ['label' => 'inventory::field.sale_price', 'required' => false],
            'reorder_level' => ['label' => 'inventory::field.reorder_level', 'required' => false],
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    public function check(array $row): array
    {
        $errors = [];

        if (filled($row['code']) && Product::query()->where('code', $row['code'])->withTrashed()->exists()) {
            $errors[] = __('inventory::validation.code_taken', ['code' => $row['code']]);
        }

        /*
         * বারকোড অনন্য হতে হবে।
         *
         * দুইটা পণ্যে একই বারকোড থাকলে স্ক্যানার কোনটা বেছে নেবে তা বলা
         * যায় না — আর কাউন্টারে দাঁড়িয়ে সেটা ধরা পড়ে ভুল জিনিস বিক্রি
         * হওয়ার পরে।
         */
        if (filled($row['barcode']) && Product::query()->where('barcode', $row['barcode'])->withTrashed()->exists()) {
            $errors[] = __('inventory::validation.barcode_taken', ['barcode' => $row['barcode']]);
        }

        foreach (['purchase_price', 'sale_price', 'reorder_level'] as $numeric) {
            if (filled($row[$numeric]) && ! is_numeric($row[$numeric])) {
                $errors[] = __('core.import.not_a_number', ['column' => $numeric]);
            }
        }

        if (filled($row['unit']) && $this->unit($row['unit']) === null) {
            $errors[] = __('core.import.unknown_value', ['column' => 'unit', 'value' => $row['unit']]);
        }

        if (filled($row['tax']) && $this->tax($row['tax']) === null) {
            $errors[] = __('core.import.unknown_value', ['column' => 'tax', 'value' => $row['tax']]);
        }

        if ($errors === []) {
            try {
                $this->products->assertImportable($this->payload($row));
            } catch (ValidationException $e) {
                foreach ($e->errors() as $messages) {
                    foreach ($messages as $message) {
                        $errors[] = $message;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, string>  $row
     */
    public function import(array $row): void
    {
        $this->products->create($this->payload($row));
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function payload(array $row): array
    {
        return [
            'code' => $row['code'] ?: null,
            'name_en' => $row['name_en'],
            'name_bn' => $row['name_bn'] ?: null,
            'barcode' => $row['barcode'] ?: null,
            'brand' => $row['brand'] ?: null,
            'category' => $row['category'] ?: null,
            'unit_id' => $this->unit($row['unit'])?->id,
            'tax_id' => $this->tax($row['tax'])?->id,
            'purchase_price' => $row['purchase_price'] !== '' ? $row['purchase_price'] : 0,
            'sale_price' => $row['sale_price'] !== '' ? $row['sale_price'] : 0,
            'reorder_level' => $row['reorder_level'] !== '' ? $row['reorder_level'] : 0,
        ];
    }

    private function unit(string $value): ?Unit
    {
        if ($value === '') {
            return null;
        }

        // কোড বা নাম — পুরনো খাতায় "PCS" থাকে, CSV-তে কেউ লেখেন "পিস"
        return Unit::query()
            ->where(fn ($q) => $q->where('code', $value)
                ->orWhere('name_en', $value)
                ->orWhere('name_bn', $value))
            ->first();
    }

    private function tax(string $value): ?Tax
    {
        if ($value === '') {
            return null;
        }

        return Tax::query()
            ->where(fn ($q) => $q->where('code', $value)
                ->orWhere('name_en', $value)
                ->orWhere('name_bn', $value))
            ->first();
    }
}
