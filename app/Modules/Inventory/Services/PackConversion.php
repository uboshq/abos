<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\MasterData\Models\Unit;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * বাক্স, পাতা, পিস — লেখা যায় যেভাবে সুবিধা, জমা থাকে এক এককে।
 *
 * ── কেন লাইনে ভিত্তি একক বসে ──────────────────────────────────────────
 * ওষুধের দোকানে একই পণ্য তিনভাবে যায়: হোলসেলে বাক্স, খুচরায় পাতা,
 * আর কেউ চাইলে একটা পিস। লাইনে যদি যে যেভাবে লিখেছেন সেভাবেই জমা
 * থাকত, তাহলে মজুদের প্রতিটা প্রশ্নে — কত আছে, কত গেল, গড় দর কত —
 * আগে প্রতিটা লাইনের একক দেখে গুণ করতে হত। একটা জায়গায় সেই গুণটা বাদ
 * পড়লেই ১০ পাতা আর ১০ বাক্স এক হয়ে যেত, আর মজুদ ৯০ পিস কমে বা বেড়ে
 * বসত — কোনো ভুলের বার্তা ছাড়াই।
 *
 * তাই রূপান্তরটা এন্ট্রির মুখেই একবার হয়: qty ভিত্তি এককে যায়, আর
 * ব্যবহারকারী যা লিখেছিলেন সেটা entered_qty / entered_unit_id-এ থাকে —
 * কেবল ছাপা আর পর্দায় দেখানোর জন্য। কোনো হিসাব ওই দুইটা ঘর ছোঁয় না।
 *
 * ── কেন গোড়া মিলতে হয় ────────────────────────────────────────────────
 * বস্তা→কেজি→গ্রাম আর গ্রাম — দুইটার গোড়া এক, তাই বদলানো যায়। পিস আর
 * কেজির গোড়া আলাদা; ওদের মধ্যে "রূপান্তর" মানে একটা বানানো সংখ্যা,
 * আর সেটা মজুদে বসে গেলে আর কখনো ধরা পড়ত না।
 */
final class PackConversion
{
    /** সিঁড়ি কত গভীর হতে পারে — Unit::toBase()-এর সমান। */
    private const MAX_DEPTH = 8;

    /**
     * ব্যবহারকারী যা লিখেছেন, তা পণ্যের ভিত্তি এককে।
     *
     * একক না এলে বা পণ্যের নিজের একক এলে সংখ্যাটা যেমন আছে তেমনই
     * ফেরে — পুরনো পর্দা, ইমপোর্ট আর পরীক্ষার কোড কিছুই টের পায় না।
     */
    public function toStockQty(Product $product, string $qty, ?int $unitId = null): string
    {
        if ($unitId === null || $unitId === $product->unit_id) {
            return $qty;
        }

        $result = bcmul($qty, $this->factorFor($product, $unitId), 6);

        /*
         * ভগ্নাংশ না চললে ভাঙা যাবে না।
         *
         * আধখানা পিস বিক্রির চেষ্টা এখানেই থামে। না থামালে মজুদে
         * ০.৫ পিস বসত, আর গোনার সময় কেউ মেলাতে পারত না।
         */
        $stocking = $this->unit((int) $product->unit_id);

        if (! $stocking->allows_fraction && bccomp($result, $this->floor($result), 6) !== 0) {
            throw ValidationException::withMessages([
                'qty' => __('inventory::validation.unit_does_not_split', [
                    'entered' => $this->unit($unitId)->name(),
                    'stocking' => $stocking->name(),
                ]),
            ]);
        }

        return $result;
    }

    /**
     * এন্ট্রির এককে লেখা দরটা পণ্যের এককে।
     *
     * ── এটা বাদ পড়লে যা হত ──────────────────────────────────────────
     * "২ বাক্স @ ৮০০" লিখলে পরিমাণ ২০০ পিস হয়ে যেত অথচ দর থাকত ৮০০,
     * আর বিল হত ১,৬০,০০০ টাকা। ভুলটা এত বড় যে ক্যাশিয়ার ধরে ফেলতেন —
     * কিন্তু ক্রয়ের কাগজে বা রিপোর্টে ওই একই ভুল চুপচাপ বসে যেত।
     *
     * ভাগ ছয় ঘরে: ১ বাক্স = ৩ পিস হলে পিসের দর অসীম দশমিক, আর লাইনের
     * মোট চার ঘরে গোনা হয় বলে ছয় ঘর রাখলে যোগফলে পার্থক্য দেখা যায় না।
     */
    public function toStockRate(Product $product, string $rate, ?int $unitId = null): string
    {
        if ($unitId === null || $unitId === $product->unit_id) {
            return $rate;
        }

        return bcdiv($rate, $this->factorFor($product, $unitId), 6);
    }

    /**
     * এক এন্ট্রি-একক মানে পণ্যের কতটা — ১ বাক্স = ১০০ পিস হলে "১০০"।
     */
    public function factorFor(Product $product, int $unitId): string
    {
        $entered = $this->unit($unitId);
        $stocking = $product->unit_id !== null ? $this->unit($product->unit_id) : null;

        if ($stocking === null) {
            throw ValidationException::withMessages([
                'unit_id' => __('inventory::validation.product_has_no_unit', [
                    'product' => $product->name(),
                ]),
            ]);
        }

        if ($entered->rootUnitId() !== $stocking->rootUnitId()) {
            throw ValidationException::withMessages([
                'unit_id' => __('inventory::validation.units_do_not_meet', [
                    'entered' => $entered->name(),
                    'stocking' => $stocking->name(),
                ]),
            ]);
        }

        /*
         * দুইটাই গোড়ায় নামিয়ে ভাগ — সরাসরি factor গুণ নয়।
         *
         * পণ্যের নিজের একক সিঁড়ির মাঝখানেও থাকতে পারে: বাক্স→পাতা→পিস
         * সিঁড়িতে পণ্যটা পাতায় গোনা হতে পারে। তখন "বাক্সের factor"
         * বলে একটা সংখ্যা নেই — গোড়ায় নামিয়ে ভাগ করলেই ১ বাক্স = ১০
         * পাতা বেরোয়, আর পণ্যের একক পাল্টালেও অঙ্কটা ঠিক থাকে।
         */
        return bcdiv(
            $entered->toBase('1'),
            $stocking->toBase('1'),
            6,
        );
    }

    /**
     * এই পণ্যের জন্য যে এককগুলোতে লেখা যায় — গোড়া এক, এমন সব।
     *
     * বড়টা আগে (১ বাক্স = ১০০ পিস আগে, তারপর পাতা, তারপর পিস), কারণ
     * ড্রপডাউনে হাত সাধারণত বড় প্যাকেই যায়।
     *
     * @return Collection<int, Unit>
     */
    public function unitsFor(Product $product): Collection
    {
        if ($product->unit_id === null) {
            return collect();
        }

        $stocking = $this->unit($product->unit_id);
        $root = $stocking->rootUnitId();

        return Unit::query()
            ->active()
            ->with('baseUnit')
            ->get()
            ->filter(fn (Unit $unit) => $unit->rootUnitId() === $root)
            ->sortByDesc(fn (Unit $unit) => (float) $unit->toBase('1'))
            ->values();
    }

    /**
     * অনেক পণ্যের জন্য একসাথে — পর্দার ড্রপডাউন ভরার জন্য।
     *
     * ── কেন unitsFor() লুপে ডাকা হয় না ──────────────────────────────
     * ওটা প্রতিবার পুরো একক-তালিকা তোলে। পাঁচশো পণ্যের ফর্মে সেটা
     * পাঁচশোটা কোয়েরি — ঠিক ওই জিনিসটাই একবার আদায়ের পর্দাকে ধীর
     * করে দিয়েছিল। এখানে তালিকাটা একবার ওঠে, তারপর গোড়া ধরে ভাগ
     * করে প্রতিটা পণ্যকে তার সিঁড়িটা ধরিয়ে দেওয়া হয়।
     *
     * ফেরত আসে কেবল সেই পণ্যগুলো যাদের একাধিক একক আছে — একটামাত্র
     * বিকল্পের ড্রপডাউন পর্দায় শুধু জায়গা নিত।
     *
     * @param  iterable<Product>  $products
     * @return array<int, list<array{id: int, label: string}>>
     */
    public function optionsFor(iterable $products): array
    {
        $units = Unit::query()->active()->with('baseUnit')->get();

        if ($units->isEmpty()) {
            return [];
        }

        // গোড়া ধরে ভাগ, আর প্রতিটা দলে বড়টা আগে
        $byRoot = $units
            ->groupBy(fn (Unit $unit) => $unit->rootUnitId())
            ->map(fn (Collection $group) => $group
                ->sortByDesc(fn (Unit $unit) => (float) $unit->toBase('1'))
                ->map(fn (Unit $unit) => ['id' => $unit->id, 'label' => $unit->name()])
                ->values()
                ->all());

        $rootOf = $units->mapWithKeys(fn (Unit $unit) => [$unit->id => $unit->rootUnitId()]);

        $options = [];

        foreach ($products as $product) {
            $root = $rootOf[$product->unit_id] ?? null;

            if ($root === null || count($byRoot[$root] ?? []) < 2) {
                continue;
            }

            $options[$product->id] = $byRoot[$root];
        }

        return $options;
    }

    /**
     * ছাপা আর পর্দার জন্য: "২ বাক্স (২০০ পিস)"।
     *
     * এক এককে লেখা হলে বন্ধনীটা আসে না — "১০ পিস (১০ পিস)" কেউ পড়ে
     * না, আর ওটা দেখলে মনে হত হিসাবে কিছু একটা ঘটেছে।
     */
    public function describe(Product $product, string $stockQty, ?string $enteredQty, ?int $enteredUnitId): string
    {
        $stockingName = $product->unit_id !== null ? $this->unit($product->unit_id)->name() : '';
        $plain = trim($this->trim($stockQty).' '.$stockingName);

        if ($enteredQty === null || $enteredUnitId === null || $enteredUnitId === $product->unit_id) {
            return $plain;
        }

        $entered = $this->trim($enteredQty).' '.$this->unit($enteredUnitId)->name();

        return trim($entered).' ('.$plain.')';
    }

    /** একই অনুরোধে একই একক বারবার — একবার এনে ধরে রাখা হয়। */
    private array $cache = [];

    private function unit(int $id): Unit
    {
        return $this->cache[$id] ??= Unit::query()
            ->with('baseUnit')
            ->findOr($id, fn () => throw ValidationException::withMessages([
                'unit_id' => __('inventory::validation.unknown_unit'),
            ]));
    }

    /** পেছনের অর্থহীন শূন্য ফেলে দেওয়া — "২ বাক্স", "২.০০০০০০ বাক্স" নয়। */
    private function trim(string $number): string
    {
        return str_contains($number, '.')
            ? rtrim(rtrim($number, '0'), '.')
            : $number;
    }

    private function floor(string $number): string
    {
        return bcadd($number, '0', 0);
    }
}
