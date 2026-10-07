<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Imports;

use App\Core\Contracts\Importer;
use App\Core\Services\SettingsService;
use App\Core\Support\ViewedBranch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\ProductService;
use App\Modules\MasterData\Models\Brand;
use App\Modules\MasterData\Models\ProductCategory;
use App\Modules\MasterData\Models\Tax;
use App\Modules\MasterData\Models\Unit;
use Illuminate\Support\Str;
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
final class ProductImporter implements \App\Core\Contracts\ImportNeedsKeys, Importer
{
    /** ⛔ এই ইমপোর্টের চাবি — পর্দার একই কাজের (পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, SystemAdmin ⛔৫; [[ImportNeedsKeys]]) */
    public static function requiredPermissions(): array
    {
        return ['inventory.product.create'];
    }

    public function __construct(private readonly ProductService $products) {}

    /**
     * ⭐ একই ফাইলে আগে দেখা বারকোড আর নাম — Inventory অডিট ম২৫, ৫ অক্টোবর ২০২৬।
     * ⛔ আগে প্রতিটা সারি কেবল ডেটাবেস দেখত: ফাইলের ভিতরে একই বারকোড দুইবার থাকলে পূর্বদর্শন দুটোকেই ঠিক বলত, আর
     * বসানোর সময় দ্বিতীয়টা ভাঙত; একই নাম দুইবার থাকলে দুইটা আলাদা পণ্য জন্মাত। ⓘ একটা আমদানি = একটা ইমপোর্টার
     * ([[ImportRunner]]), তাই মনে রাখা এই ফাইলেরই।
     *
     * @var array<string, int>
     */
    private array $seenBarcodes = [];

    /** @var array<string, int> */
    private array $seenNames = [];

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
            // ⭐ একক বাধ্যতামূলক — Inventory অডিট ম২৫; ⛔ আগে এককহীন পণ্য ঢুকত, আর প্রথম কাগজেই "পণ্যের একক নেই" বলে থামত
            'unit' => ['label' => 'inventory::field.unit', 'required' => true],
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

        $barcode = trim((string) ($row['barcode'] ?? ''));

        if ($barcode !== '' && isset($this->seenBarcodes[$barcode])) {
            $errors[] = __('inventory::validation.barcode_twice_in_file', ['barcode' => $barcode]);
        }

        /*
         * ⓘ নাম মেলানো হয় ছোট-বড় হাতের অক্ষর আর ফাঁকা বাদ দিয়ে, আর ডেটাবেসে কেবল দেখা শাখায় বিক্রি হওয়া পণ্যের সাথে
         * ([[Product::scopeSoldInViewedBranch()]]): একই নামের পণ্য আলাদা শাখায় বৈধ (তিন গ্রুপের তালিকা)।
         */
        $name = mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) ($row['name_en'] ?? ''))) ?? '');

        if ($name !== '' && isset($this->seenNames[$name])) {
            $errors[] = __('inventory::validation.name_twice_in_file', ['name' => $row['name_en']]);
        } elseif ($name !== '' && Product::query()->soldInViewedBranch()->whereRaw('LOWER(TRIM(name_en)) = ?', [$name])->exists()) {
            $errors[] = __('inventory::validation.name_taken', ['name' => $row['name_en']]);
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

        // ⓘ কেবল ঠিক সারিই মনে থাকে — ভুল সারি বসবে না, তাই সে পরেরটাকে "দ্বিতীয়বার" বানায় না
        if ($errors === []) {
            if ($barcode !== '') {
                $this->seenBarcodes[$barcode] = 1;
            }

            if ($name !== '') {
                $this->seenNames[$name] = 1;
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, string>  $row
     */
    public function import(array $row): void
    {
        $data = $this->payload($row);

        /* ⭐ ইমপোর্টও কোম্পানির লটের সুইচ মানে — চালু থাকলে প্রতিটা পণ্য লট ধরে (৪ অক্টোবর ২০২৬) */
        if ((bool) app(SettingsService::class)->get('inventory.lots_always', true)) {
            $data['track_batch'] = true;
        }

        /*
         * ⭐ হেডারে শাখা বাছা থাকলে পণ্যটা সেই শাখার — মালিক, ৫ অক্টোবর ২০২৬ (ADI: SL-Lion Group শাখায় আমদানি, অথচ
         * SL-Super Group-এর ৮৩টা পণ্যও লায়নে দেখাত)। ⛔ আগে আমদানি শাখা ছুঁত না, আর শাখার সারি ছাড়া পণ্য **সব
         * শাখার** ([[Product::scopeSoldInViewedBranch()]]) — তাই এক গ্রুপের তালিকা সব গ্রুপে ছড়াত।
         * ⓘ "সব শাখা" দেখার সময় আমদানি করলে আগের মতোই সব শাখার।
         */
        $branch = ViewedBranch::one();

        if ($branch !== null) {
            $data['branch_table'] = '1';
            $data['branch_ids'] = [$branch];
        }

        $this->products->create($data);
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
            /*
             * ইমপোর্টেও ব্র্যান্ড এখন সারি — লেখা নয়।
             *
             * ── কেন ইমপোর্টে নতুন সারি তৈরি হয়, ফর্মে হয় না ─────────
             * ফর্মে টাইপ করা মানে একজন মানুষ একবার ভুল বানান লিখতে
             * পারেন, আর তালিকা থেকে বাছতে বললে সেটা আটকায়। CSV-তে
             * দুই হাজার সারি আসে, আর তার মধ্যে একটা অচেনা ব্র্যান্ড
             * থাকলে পুরো ফাইল আটকে দেওয়া মানে ইমপোর্টটাই অচল।
             *
             * তাই অচেনা নাম সারি হয়ে বসে, আর মালিক পরে সেটিংসে গিয়ে
             * বানানভেদগুলো মিলিয়ে নেন। নামটা হারায় না, সেটাই আসল।
             */
            'brand_id' => $this->brand($row['brand'])?->id,
            'category_id' => $this->category($row['category'])?->id,
            'unit_id' => $this->unit($row['unit'])?->id,
            'tax_id' => $this->tax($row['tax'])?->id,
            'purchase_price' => $row['purchase_price'] !== '' ? $row['purchase_price'] : 0,
            'sale_price' => $row['sale_price'] !== '' ? $row['sale_price'] : 0,
            'reorder_level' => $row['reorder_level'] !== '' ? $row['reorder_level'] : 0,
        ];
    }

    private function brand(string $value): ?Brand
    {
        return $this->namedRow(Brand::class, $value);
    }

    private function category(string $value): ?ProductCategory
    {
        return $this->namedRow(ProductCategory::class, $value);
    }

    /**
     * নামে খুঁজি, না পেলে বানাই।
     *
     * খোঁজাটা কোড ও দুই ভাষার নামে — পুরনো খাতায় "NESTLE" থাকে, CSV-তে
     * কেউ লেখেন "নেসলে"।
     *
     * ── কেন ইমপোর্টে নতুন সারি বানানো চলে, ফর্মে চলে না ─────────────
     * CSV-তে দুই হাজার সারি আসে; তার একটায় অচেনা ব্র্যান্ড থাকলে পুরো
     * ফাইল আটকে দেওয়া মানে ইমপোর্টটাই অচল। ফর্মে উল্টো — ওখানে একজন
     * মানুষ একবারে একটা পণ্য লেখেন, আর তালিকা থেকে বাছতে বলা যায়।
     *
     * @param  class-string<Brand|ProductCategory>  $model
     */
    private function namedRow(string $model, string $value): Brand|ProductCategory|null
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $found = $model::query()
            ->where(fn ($q) => $q->where('code', $value)
                ->orWhere('name_en', $value)
                ->orWhere('name_bn', $value))
            ->first();

        if ($found !== null) {
            return $found;
        }

        // কোডে ধাক্কা লাগলে সংখ্যা — মাইগ্রেশনের একই নিয়ম
        $base = Str::limit(Str::upper(Str::slug($value, '-')) ?: 'X', 28, '');
        $code = $base;
        $n = 1;

        while ($model::query()->where('code', $code)->exists()) {
            $code = $base.'-'.(++$n);
        }

        return $model::query()->create([
            'code' => $code,
            'name_en' => $value,
            'name_bn' => $value,
            'is_active' => true,
        ]);
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
