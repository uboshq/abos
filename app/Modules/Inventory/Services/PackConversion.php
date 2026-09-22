<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
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
    /*
     * ⓘ সিঁড়ির গভীরতার সীমাটা এখানে নেই, আর থাকার দরকারও নেই — এই
     * সেবাটা নিজে সিঁড়ি হাঁটে না, `Unit::toBase()` হাঁটে, আর সীমাটা
     * ওখানেই বসানো (`Unit::MAX_DEPTH`)।
     *
     * ⚠️ এখানে আগে `private const MAX_DEPTH = 8` বসে ছিল, মন্তব্যে লেখা
     * "Unit::toBase()-এর সমান" — কিন্তু সে কিছুই বাঁধত না, কেউ তাকে
     * পড়তও না। দুই ফাইলে একই সংখ্যার দুইটা কপি, তার একটা মৃত: কেউ
     * একটা বদলালে অন্যটা নীরবে দ্বিমত করত, আর মৃত নামটা পড়ে মনে হত
     * সীমাটা বাঁধা আছে।
     */

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

        /*
         * ⭐ আগে পণ্যের নিজের প্যাক — ১৯ সেপ্টেম্বর ২০২৬।
         *
         * ⛔ কার্টনের মাপ পণ্যে পণ্যে আলাদা (সাবানে ২৪, বিস্কুটে ৪৮), আর
         * এককের মাস্টারে একটাই সংখ্যা বসত — লাইভে কার্টনের factor ছিল ১।
         * ⓘ তাই পণ্যের টেবিলে সারি থাকলে সেটাই সত্যি, আর তার factor
         * সরাসরি পণ্যের base-এর হিসাবে ([[ProductUnit]]), শিকল হাঁটা নেই।
         *
         * ⚠️ না থাকলে আগের নিয়মই — এককের মাস্টারের সার্বজনীন রূপান্তর
         * (১ ডজন = ১২ পিস)। তাই যে পণ্যের টেবিল খালি, তার জন্য এই
         * মেথডের উত্তর আগের মতোই, একটা অঙ্কও না বদলে।
         */
        $pack = $this->pack($product, $unitId);

        if ($pack !== null) {
            return bcadd((string) $pack->factor, '0', 6);
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

        $units = Unit::query()->active()->with('baseUnit')->get();
        $packs = ProductUnit::query()->where('product_id', $product->id)->where('is_active', true)->get();

        return collect($this->ladder($product, $units, $packs))
            ->map(fn (array $step) => $step['unit'])
            ->values();
    }

    /**
     * একটা পণ্যের সিঁড়ি — কোন কোন এককে লেখা যায়, আর প্রতিটায় কত base।
     *
     * দুই উৎস: এককের মাস্টারের সার্বজনীন রূপান্তর (গোড়া এক এমন সব), আর
     * পণ্যের নিজের প্যাক। ⚠️ একই একক দুই জায়গায় থাকলে **প্যাক জেতে** —
     * পণ্যের নিজের কথা সার্বজনীনের চেয়ে নির্দিষ্ট, আর [[factorFor()]]-ও
     * ঠিক এই ক্রমেই দেখে। দুই জায়গায় দুই নিয়ম হলে ড্রপডাউন বলত এক
     * সংখ্যা আর মজুদে বসত আরেকটা।
     *
     * বড়টা আগে। নিষ্ক্রিয় একক বা নিষ্ক্রিয় প্যাক আসে না।
     *
     * @param  Collection<int, Unit>  $units  সক্রিয় সব একক
     * @param  Collection<int, ProductUnit>  $packs  এই পণ্যের সক্রিয় প্যাক
     * @return list<array{unit: Unit, factor: string}>
     */
    private function ladder(Product $product, Collection $units, Collection $packs): array
    {
        $byId = $units->keyBy('id');
        $stocking = $byId->get($product->unit_id);
        $steps = [];

        if ($stocking !== null) {
            $root = $stocking->rootUnitId();
            $base = $stocking->toBase('1');

            foreach ($units as $unit) {
                if ($unit->rootUnitId() === $root) {
                    $steps[$unit->id] = ['unit' => $unit, 'factor' => bcdiv($unit->toBase('1'), $base, 6)];
                }
            }
        }

        foreach ($packs as $pack) {
            $unit = $byId->get($pack->unit_id);

            if ($unit !== null) {
                $steps[$unit->id] = ['unit' => $unit, 'factor' => bcadd((string) $pack->factor, '0', 6)];
            }
        }

        uasort($steps, fn (array $a, array $b) => bccomp($b['factor'], $a['factor'], 6));

        return array_values($steps);
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
        $options = [];

        foreach ($this->laddersFor($products) as $productId => $ladder) {
            if (count($ladder) < 2) {
                continue;
            }

            $options[$productId] = array_map(
                fn (array $step) => ['id' => $step['unit']->id, 'label' => $step['unit']->name()],
                $ladder,
            );
        }

        return $options;
    }

    /**
     * অনেক পণ্যের সিঁড়ি একসাথে — পর্দায় পরিমাণ ভেঙে দেখানোর জন্য।
     *
     * ⭐ ২০ সেপ্টেম্বর ২০২৬, মালিকের কথায়: পর্দা "১৯৩ পিস" দেখায়, অথচ
     * তিনি গোনেন "৮ কার্টন ১ পিস"। ⓘ ভাগটা করে [[PackBreakdown]], আর
     * সিঁড়িটা লাগে এখান থেকে।
     *
     * ⚠️ পণ্যপ্রতি একটা করে কোয়েরি নয় — [[optionsFor()]]-এর একই কারণ:
     * পঞ্চাশ সারির পাতায় ওটা পঞ্চাশটা কোয়েরি হত। ⓘ তাই দুইটাই এখন
     * এই একটা পদ্ধতির উপর দাঁড়ানো, আর সিঁড়ির নিয়ম এক জায়গাতেই থাকে।
     *
     * ── ⚠️ `$packsOnly` — কোন সিঁড়িটা কোথায় ─────────────────────────────
     * এন্ট্রির ড্রপডাউনে সার্বজনীন এককও লাগে: কেউ ডজনে লিখতেই পারেন।
     * ⛔ কিন্তু মজুদ **দেখানোর** সময় ওগুলো কেবল গোলমাল বাড়ায় — যে
     * পণ্যের নিজের কোনো প্যাক নেই, তার নিচেও "৬ ডজন · ৩ পিস" বসত,
     * অথচ ঐ পণ্যটা কেউ ডজনে গোনে না।
     *
     * ⭐ মালিকের কথাটা ছিল পণ্যের নিজের প্যাক নিয়ে — কার্টনে কত, সেটা
     * কোম্পানি ঠিক করে। তাই দেখানোর সিঁড়িতে কেবল পণ্যের নিজের সারি
     * আর তার ভিত্তি একক।
     *
     * @param  iterable<Product>  $products
     * @return array<int, list<array{unit: Unit, factor: string}>>
     */
    public function laddersFor(iterable $products, bool $packsOnly = false): array
    {
        $units = Unit::query()->active()->with('baseUnit')->get();

        if ($units->isEmpty()) {
            return [];
        }

        $products = collect($products);

        /*
         * ⭐ সব পণ্যের প্যাক একটা কোয়েরিতে — ১৯ সেপ্টেম্বর ২০২৬।
         * ⚠️ পণ্যপ্রতি একটা করে তুললে পাঁচশো পণ্যের ফর্মে পাঁচশো কোয়েরি,
         * ঠিক যে কারণে [[unitsFor()]] এখানে লুপে ডাকা হয় না।
         */
        $packs = ProductUnit::query()
            ->whereIn('product_id', $products->pluck('id')->filter()->all())
            ->where('is_active', true)
            ->get()
            ->groupBy('product_id');

        $ladders = [];

        foreach ($products as $product) {
            if ($product->unit_id === null) {
                continue;
            }

            $own = $packs->get($product->id, collect());

            $ladders[$product->id] = $packsOnly
                ? $this->ladder($product, $units->whereIn('id', $own->pluck('unit_id')->push($product->unit_id)->all()), $own)
                : $this->ladder($product, $units, $own);
        }

        return $ladders;
    }

    /**
     * ⭐ প্যাকের বারকোড → কোন পণ্য, আর কতটুকু।
     *
     * ── ⛔ যা ভাঙা ছিল, ২২ সেপ্টেম্বর ২০২৬ ──────────────────
     * পণ্যের ফর্মে প্রতিটা প্যাকের গায়ে বারকোডের একটা ঘর আছে,
     * আর সেটা সংরক্ষিতও হত ([[ProductPackService]])। ⚠️ কিন্তু
     * কোনো পর্দা ওগুলো **খুঁজত না** — স্ক্যানার কেবল
     * `inv_products.barcode` মিলাত।
     *
     * ⓘ এই রিপোর সবচেয়ে চেনা আকার: ঘরটা আছে, নামটা
     * আছে, কেউ ওটা **পড়ে না**, আর কিছুই ভাঙে না।
     *
     * ── ⭐ পরিমাণটা `factor`, ১ নয় ────────────────────────
     * `factor` সবসময় পণ্যের base-এর হিসাবে। তাই একটা
     * ১২-পিস কার্টন স্ক্যান করলে সারিতে বসে **১২**, ১ নয়।
     *
     * ⛔ এটাই পুরো কাজটার মানে: কার্টন স্ক্যান করে যদি ১ বসত,
     * তবে বারকোডটা কেবল পণ্যটা চিনত, পরিমাণটা হাতে লিখতে হত —
     * আর ভুল হলে কার্টনের দামে এক পিস বিক্রি হত।
     *
     * ── ⓘ বারকোড হীন সারি বাদ ───────────────────────────
     * ⚠️ একটা ফাঁকা চাবি সব বারকোডহীন প্যাকের **একটাকে**
     * ধরে বসত, আর স্ক্যানারে ফাঁকা কোড এলে এলোমেলো একটা
     * পণ্য বসে যেত — [[SalesOrderController]]-এ পণ্যের বারকোডেও
     * হুবহু এই কারণে ছাঁকা হয়।
     *
     * @param  iterable<Product>  $products
     * @return array<string, array{product_id: int, qty: string}>
     */
    public function barcodesFor(iterable $products): array
    {
        $ids = collect($products)->pluck('id')->filter()->all();

        if ($ids === []) {
            return [];
        }

        $found = [];

        $rows = ProductUnit::query()
            ->whereIn('product_id', $ids)
            ->where('is_active', true)
            ->whereNotNull('barcode')
            ->get();

        foreach ($rows as $row) {
            $code = trim((string) $row->barcode);

            if ($code === '') {
                continue;
            }

            /*
             * ⓘ পণ্যের নিজের এককের সারিটাও এখানে আসতে পারে
             * (`factor` = 1)। ⚠️ বাদ দেওয়া হয় না — সেটা
             * সত্যিই এক পিস, আর উত্তরটা ঠিকই থাকে।
             */
            $found[$code] = [
                'product_id' => (int) $row->product_id,
                'qty' => $this->trim((string) $row->factor),
            ];
        }

        return $found;
    }

    /**
     * প্রতিটা পণ্যে কোন প্যাকটা আগে থেকে বাছা থাকবে — ধাপ ৫, ২০ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ বাছাইটা **মালিকের**, ডেটা থেকে আন্দাজ নয়: পণ্যের ফর্মের চারটা
     * রেডিও (কেনায় · বেচায় · POS-এ · কাউন্টারে) যা বলে, এখানে কেবল সেটাই
     * ফেরে ([[ProductPackService::KINDS]])। ⚠️ কেউ কিছু না বাছলে সারিটা
     * আসেই না, আর পর্দা আগের মতোই পণ্যের নিজের একক ধরে — অর্থাৎ যে
     * ব্যবসা প্যাক ব্যবহার করে না, তার কিছুই বদলায় না।
     *
     * ⛔ ভুল ডিফল্ট কোনো ডিফল্টের চেয়ে খারাপ: ঘরটা ভরা থাকে, তাই কেউ
     * দেখে না, আর কার্টনের দামে পিস বিক্রি হয়ে যায়।
     *
     * @param  iterable<Product>  $products
     * @return array<int, int> পণ্যের id → এককের id
     */
    public function defaultsFor(iterable $products, string $kind): array
    {
        if (! in_array($kind, ProductPackService::KINDS, true)) {
            return [];
        }

        $products = collect($products);

        return ProductUnit::query()
            ->whereIn('product_id', $products->pluck('id')->filter()->all())
            ->where('is_active', true)
            ->where('is_'.$kind.'_default', true)
            ->pluck('unit_id', 'product_id')
            ->map(fn ($unitId) => (int) $unitId)
            ->all();
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

    /** @var array<string, ProductUnit|null> */
    private array $packCache = [];

    /** পণ্যের নিজের সক্রিয় প্যাক, এই এককে — না থাকলে null। */
    private function pack(Product $product, int $unitId): ?ProductUnit
    {
        $key = $product->id.':'.$unitId;

        if (! array_key_exists($key, $this->packCache)) {
            $this->packCache[$key] = ProductUnit::query()
                ->where('product_id', $product->id)
                ->where('unit_id', $unitId)
                ->where('is_active', true)
                ->first();
        }

        return $this->packCache[$key];
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
