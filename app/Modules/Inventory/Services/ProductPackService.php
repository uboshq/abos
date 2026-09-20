<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\MasterData\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * পণ্যের প্যাকের টেবিল — ফর্ম থেকে যা এল, যাচাই করে জমা রাখা।
 * ধাপ ৪, ১৯ সেপ্টেম্বর ২০২৬।
 *
 * ── মানুষ যেভাবে বলেন, সেভাবেই লেখা ───────────────────────────────────
 * মালিকের উদাহরণ: *"২৪ পিসে এক বক্স, ১২ বক্সে এক কার্টন"*। ⓘ তাই প্রতিটা
 * সারি "১ <একক> = N <আরেকটা একক>" — কার্টন = ১২ বক্স, বক্স = ২৪ পিস।
 * এখানে শিকলটা নামিয়ে `factor` বানানো হয় (কার্টন = ২৮৮), কারণ মজুদ
 * কেবল সেটাই পড়ে ([[PackConversion]])। লেখাটাও (`per_qty`, `per_unit_id`)
 * রাখা হয়, যাতে ফর্ম আবার খুললে মানুষ নিজের কথাই দেখেন।
 *
 * ── ⛔ যা আটকায়, আর কেন ─────────────────────────────────────────────────
 * - base নিজেই একটা সারি হিসেবে — base সবসময় ১, আলাদা করে লেখার কিছু নেই।
 * - একই একক দুইবার — দুই মাপের দুইটা "কার্টন", কোনটা সত্যি বলা যেত না।
 * - শিকলে চক্র (কার্টন = ২ বক্স, বক্স = ৩ কার্টন) — শেষ হত না।
 * - আধখানা base (১ বক্স = ২.৫ পিস, অথচ পিস ভাঙে না) — মজুদে ভগ্নাংশ বসত।
 * - একই বারকোড দুই জায়গায় — স্ক্যানার কোন পণ্য, কোন প্যাক, বলতে পারত না।
 *
 * ⓘ সব ভুল একসাথে ফেরে, প্রথমটায় থেমে নয় — সাত সারির টেবিলে একটা একটা
 * করে ভুল দেখাতে সাতবার জমা দিতে হত।
 */
final class ProductPackService
{
    /** যে চার কাজে কোনো একটা প্যাক আগে থেকে বাছা থাকে। */
    public const KINDS = ['purchase', 'sales', 'pos', 'counter'];

    /**
     * @param  array<int|string, array<string, mixed>>  $rows  base ছাড়া বাকি প্যাক
     * @param  array<string, mixed>  $defaults  কাজ → একক-id (না দিলে base)
     */
    public function sync(Product $product, array $rows, array $defaults = []): void
    {
        $base = (int) $product->unit_id;

        if ($base <= 0) {
            throw ValidationException::withMessages([
                'unit_id' => __('inventory::validation.product_has_no_unit', ['product' => $product->name()]),
            ]);
        }

        $baseUnit = Unit::query()->findOrFail($base);
        $errors = [];
        $clean = $this->clean($rows, $base, $errors);
        $factors = $errors === [] ? $this->factors($clean, $base, $baseUnit, $errors) : [];

        $this->checkBarcodes($product, $clean, $errors);
        $chosen = $this->defaults($defaults, $base, array_keys($clean), $errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($product, $base, $clean, $factors, $chosen) {
            /*
             * ⚠️ আগে এই পণ্যের সব বারকোড খালি — তারপর বসানো। নইলে কার্টন আর
             * বক্সের বারকোড অদলবদল করলে প্রথম লেখাতেই unique ইনডেক্স আপত্তি
             * করত, যদিও শেষ অবস্থাটা পুরোপুরি বৈধ।
             */
            ProductUnit::query()->where('product_id', $product->id)->update(['barcode' => null]);

            $write = [$base => ['factor' => '1', 'per_qty' => null, 'per_unit_id' => null, 'barcode' => null]];

            foreach ($clean as $unitId => $row) {
                $write[$unitId] = [
                    'factor' => $factors[$unitId],
                    'per_qty' => $row['per_qty'],
                    'per_unit_id' => $row['per_unit_id'],
                    'barcode' => $row['barcode'],
                ];
            }

            foreach ($write as $unitId => $values) {
                ProductUnit::query()->updateOrCreate(
                    ['product_id' => $product->id, 'unit_id' => $unitId],
                    [
                        ...$values,
                        'company_id' => $product->company_id,
                        'is_active' => true,
                        'is_purchase_default' => $chosen['purchase'] === $unitId,
                        'is_sales_default' => $chosen['sales'] === $unitId,
                        'is_pos_default' => $chosen['pos'] === $unitId,
                        'is_counter_default' => $chosen['counter'] === $unitId,
                    ],
                );
            }

            // ফর্ম থেকে সরিয়ে দেওয়া প্যাক — আর base বদলালে পুরনো base-এর সারিও
            ProductUnit::query()->where('product_id', $product->id)
                ->whereNotIn('unit_id', array_keys($write))
                ->delete();
        });
    }

    /**
     * পণ্যের base কি এখন বদলানো যায়?
     *
     * ⛔ মালিকের নিয়ম: মজুদ-চলাচল হয়ে গেলে আর নয়। ⓘ কাগজের লাইনও ধরা হয়
     * (খসড়াও): লাইনের `qty` পুরনো base-এ লেখা, আর base বদলালে একই সংখ্যা
     * অন্য মানে পেত — ১২ পিস হঠাৎ ১২ কার্টন।
     */
    public function assertBaseCanChange(Product $product, bool $packsGiven): void
    {
        foreach (PackSnapshot::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'product_id')) {
                continue;
            }

            if (DB::table($table)->where('product_id', $product->id)->exists()) {
                throw ValidationException::withMessages([
                    'unit_id' => __('inventory::validation.unit_locked', [
                        'unit' => $product->unit?->name() ?? '',
                    ]),
                ]);
            }
        }

        /*
         * প্যাকগুলো পুরনো base-এর হিসাবে লেখা (১ কার্টন = ২৪ পিস)। নতুন
         * base-এ সেই সংখ্যা মিথ্যা — তাই ফর্ম নতুন করে না পাঠালে থামা।
         */
        $hasPacks = ProductUnit::query()->where('product_id', $product->id)
            ->where('unit_id', '!=', $product->unit_id)->exists();

        if ($hasPacks && ! $packsGiven) {
            throw ValidationException::withMessages([
                'unit_id' => __('inventory::validation.unit_change_needs_packs'),
            ]);
        }
    }

    /**
     * ফাঁকা সারি বাদ, বাকিগুলো যাচাই — এককের id ধরে।
     *
     * @return array<int, array{i: int|string, per_qty: string, per_unit_id: int, barcode: ?string}>
     */
    private function clean(array $rows, int $base, array &$errors): array
    {
        $clean = [];

        foreach ($rows as $i => $row) {
            $unitId = (int) ($row['unit_id'] ?? 0);
            $qty = trim((string) ($row['per_qty'] ?? ''));

            // পুরোপুরি ফাঁকা সারি — "+ সারি" চেপে কিছু না লিখে জমা দেওয়া
            if ($unitId <= 0 && $qty === '') {
                continue;
            }

            $at = "packs.{$i}";

            if ($unitId <= 0 || Unit::query()->whereKey($unitId)->doesntExist()) {
                $errors["{$at}.unit_id"] = __('inventory::validation.unknown_unit');

                continue;
            }

            $name = Unit::query()->find($unitId)->name();

            if ($unitId === $base) {
                $errors["{$at}.unit_id"] = __('inventory::validation.pack_is_base', ['unit' => $name]);

                continue;
            }

            if (isset($clean[$unitId])) {
                $errors["{$at}.unit_id"] = __('inventory::validation.pack_twice', ['unit' => $name]);

                continue;
            }

            if (! is_numeric($qty) || bccomp($qty, '0', 6) <= 0) {
                $errors["{$at}.per_qty"] = __('inventory::validation.pack_qty_positive', ['unit' => $name]);

                continue;
            }

            $per = (int) ($row['per_unit_id'] ?? 0) ?: $base;

            if ($per === $unitId) {
                $errors["{$at}.per_unit_id"] = __('inventory::validation.pack_of_itself', ['unit' => $name]);

                continue;
            }

            $barcode = trim((string) ($row['barcode'] ?? ''));

            $clean[$unitId] = [
                'i' => $i,
                'per_qty' => bcadd($qty, '0', 6),
                'per_unit_id' => $per,
                'barcode' => $barcode === '' ? null : $barcode,
            ];
        }

        return $clean;
    }

    /**
     * শিকল নামিয়ে প্রতিটা প্যাকের factor — base-এ কত।
     *
     * ⓘ ক্রম মেনে লেখা লাগে না: কার্টন = ১২ বক্স আগে, বক্স = ২৪ পিস পরে
     * লিখলেও চলে। প্রতি পাকে যেগুলোর "কিসের" জানা, সেগুলো মেটে; কোনো পাকে
     * কিছু না মিটলে বাকিরা চক্রে বা অজানা এককে আটকে।
     *
     * @param  array<int, array{i: int|string, per_qty: string, per_unit_id: int, barcode: ?string}>  $clean
     * @return array<int, string>
     */
    private function factors(array $clean, int $base, Unit $baseUnit, array &$errors): array
    {
        $factor = [$base => '1'];
        $left = $clean;

        while ($left !== []) {
            $progress = false;

            foreach ($left as $unitId => $row) {
                if (isset($factor[$row['per_unit_id']])) {
                    $factor[$unitId] = bcmul($row['per_qty'], $factor[$row['per_unit_id']], 6);
                    unset($left[$unitId]);
                    $progress = true;
                }
            }

            if (! $progress) {
                foreach ($left as $row) {
                    $known = isset($clean[$row['per_unit_id']]) || $row['per_unit_id'] === $base;

                    $errors["packs.{$row['i']}.per_unit_id"] = $known
                        ? __('inventory::validation.pack_circle')
                        : __('inventory::validation.pack_per_unknown');
                }

                return [];
            }
        }

        /*
         * ⛔ base না ভাঙলে প্যাকেও আধখানা base নয় — "১ বক্স = ২.৫ পিস"
         * মানে প্রতি দুই বক্সে একটা পিস কোথাও হারিয়ে যায়।
         */
        if (! $baseUnit->allows_fraction) {
            foreach ($clean as $unitId => $row) {
                if (bccomp($factor[$unitId], bcadd($factor[$unitId], '0', 0), 6) !== 0) {
                    $errors["packs.{$row['i']}.per_qty"] = __('inventory::validation.pack_splits_base', [
                        'unit' => Unit::query()->find($unitId)?->name() ?? '',
                        'base' => $baseUnit->name(),
                    ]);
                }
            }
        }

        unset($factor[$base]);

        return $factor;
    }

    /**
     * প্যাকের বারকোড — ফর্মের ভেতরে, অন্য পণ্যে, আর অন্য পণ্যের প্যাকে।
     *
     * ⚠️ এই পণ্যের নিজের `barcode`-ও (পিসের গায়েরটা) আটকায়: কার্টনে একই
     * নম্বর থাকলে স্ক্যান করলে এক পিস না এক কার্টন, বলা যেত না।
     *
     * @param  array<int, array{i: int|string, per_qty: string, per_unit_id: int, barcode: ?string}>  $clean
     */
    private function checkBarcodes(Product $product, array $clean, array &$errors): void
    {
        $seen = [];

        foreach ($clean as $row) {
            $barcode = $row['barcode'];

            if ($barcode === null) {
                continue;
            }

            $at = "packs.{$row['i']}.barcode";

            $taken = isset($seen[$barcode])
                || Product::query()->withTrashed()->where('barcode', $barcode)->exists()
                || ProductUnit::query()->where('barcode', $barcode)->where('product_id', '!=', $product->id)->exists();

            if ($taken) {
                $errors[$at] = __('inventory::validation.barcode_taken', ['barcode' => $barcode]);
            }

            $seen[$barcode] = true;
        }
    }

    /**
     * চার কাজের প্রতিটায় একটা প্যাক — না বললে base।
     *
     * @param  list<int>  $packUnits
     * @return array<string, int>
     */
    private function defaults(array $defaults, int $base, array $packUnits, array &$errors): array
    {
        $chosen = [];

        foreach (self::KINDS as $kind) {
            $unitId = (int) ($defaults[$kind] ?? 0);

            if ($unitId !== 0 && $unitId !== $base && ! in_array($unitId, $packUnits, true)) {
                $errors["pack_defaults.{$kind}"] = __('inventory::validation.pack_default_unknown');
                $unitId = 0;
            }

            $chosen[$kind] = $unitId ?: $base;
        }

        return $chosen;
    }
}
