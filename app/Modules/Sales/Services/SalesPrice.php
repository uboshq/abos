<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\Money;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\PackConversion;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\Sales\Models\PriceListItem;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * এই গ্রাহক এই পণ্য আজ কত দামে পাবেন — দর তালিকা থেকে, নাহলে পণ্যের দাম (মালিক, ৫ অক্টোবর ২০২৬: SAP-এর ধাঁচ)।
 *
 * ── ⭐ কোনটা আগে — সবচেয়ে নির্দিষ্টটা জেতে ──────────────────────────────
 *   ১. গ্রাহকের নিজের তালিকা (`customer_id`)
 *   ২. গ্রাহকের ধরনের তালিকা — ডিলার, পাইকারি… (`party_type_id`)
 *   ৩. এলাকার তালিকা (`location_id`) — দোকানের নিজের জায়গা বা তার উপরের যেকোনো ধাপ; কাছের ধাপটা আগে
 *   ৪. সবার তালিকা (কোনো লক্ষ্য নেই)
 *   ৫. পণ্যের নিজের `sale_price`
 * ⓘ একই স্তরে কয়েকটা চালু সারি থাকলে যেটার `valid_from` সবচেয়ে নতুন সেটা; সমান হলে যে সারি ঠিক চাওয়া এককের, তারপর নতুন সারি।
 *
 * ⛔ এক তালিকা একজনের জন্যই — দুইটা লক্ষ্য বসানো থাকলেও উপরের ক্রমে প্রথমটাই ধরা হয় ([[level()]])।
 * ⚠️ দর সবসময় চাওয়া এককে ফেরে: সারিটা কার্টনের হলে আর চাওয়া পিসে, তবে কার্টনের দর ÷ মাপ ([[PackConversion]])।
 */
final class SalesPrice
{
    public const CUSTOMER = 'customer';

    public const TIER = 'tier';

    public const TERRITORY = 'territory';

    public const ALL = 'all';

    public const STANDARD = 'standard';

    private const RANK = [self::CUSTOMER => 1, self::TIER => 2, self::TERRITORY => 3, self::ALL => 4];

    public function __construct(private readonly PackConversion $packs) {}

    /**
     * একটা পণ্যের দর — চাওয়া এককে (খালি মানে পণ্যের নিজের একক)।
     */
    public function for(?Customer $customer, Product $product, mixed $date = null, ?int $unitId = null): ResolvedPrice
    {
        return $this->forMany($customer, [$product], $date, [(int) $product->id => $unitId])[(int) $product->id];
    }

    /**
     * অনেক পণ্যের দর একবারে — দুইটা কোয়েরি, পণ্য যতই হোক (কাউন্টারের তালিকা)।
     *
     * @param  iterable<Product>  $products
     * @param  array<int, ?int>  $units  পণ্য → চাওয়া একক
     * @return array<int, ResolvedPrice>
     */
    public function forMany(?Customer $customer, iterable $products, mixed $date = null, array $units = []): array
    {
        $byId = [];

        foreach ($products as $product) {
            $byId[(int) $product->id] = $product;
        }

        $out = [];
        $day = Carbon::parse($date ?? now())->toDateString();
        $lists = $byId === [] ? collect() : $this->listsFor($customer);
        $chain = $this->chainOf($customer);

        $items = $lists->isEmpty() ? collect() : PriceListItem::query()
            ->whereIn('price_list_id', $lists->keys())
            ->whereIn('product_id', array_keys($byId))
            ->whereDate('valid_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day))
            ->get()
            ->groupBy('product_id');

        foreach ($byId as $id => $product) {
            $want = $units[$id] ?? null;
            $want = $want !== null && (int) $want !== (int) $product->unit_id ? (int) $want : null;
            $best = null;
            $bestKey = null;

            foreach ($items->get($id, collect()) as $item) {
                $list = $lists->get((int) $item->price_list_id);
                $level = $list !== null ? $this->level($list, $customer) : null;

                if ($level === null) {
                    continue;
                }

                $price = $this->inUnit($product, $item, $want);

                if ($price === null) {
                    continue;
                }

                $itemUnit = $item->unit_id !== null && (int) $item->unit_id !== (int) $product->unit_id ? (int) $item->unit_id : null;

                // ⓘ ছোট মানে আগে: স্তর, এলাকার দূরত্ব, নতুন মেয়াদ, ঠিক এককের সারি, নতুন সারি
                $key = [
                    self::RANK[$level],
                    $level === self::TERRITORY ? ($chain[(int) $list->location_id] ?? 99) : 0,
                    -Carbon::parse($item->valid_from)->timestamp,
                    $itemUnit === $want ? 0 : 1,
                    -(int) $item->id,
                ];

                if ($bestKey === null || $key < $bestKey) {
                    $bestKey = $key;
                    $best = new ResolvedPrice($price, $level, (int) $list->id, $list->name());
                }
            }

            $out[$id] = $best ?? new ResolvedPrice($this->standard($product, $want), self::STANDARD);
        }

        return $out;
    }

    /**
     * তালিকাটা কোন স্তরের — এই গ্রাহকের জন্য খাটে না হলে null।
     */
    public function level(PriceList $list, ?Customer $customer): ?string
    {
        if ($list->customer_id !== null) {
            return $customer !== null && (int) $list->customer_id === (int) $customer->id ? self::CUSTOMER : null;
        }

        if ($list->party_type_id !== null) {
            return $customer?->party_type_id !== null && (int) $list->party_type_id === (int) $customer->party_type_id ? self::TIER : null;
        }

        if ($list->location_id !== null) {
            return array_key_exists((int) $list->location_id, $this->chainOf($customer)) ? self::TERRITORY : null;
        }

        return self::ALL;
    }

    /**
     * তালিকার লক্ষ্যের স্তর — গ্রাহক না দেখে (পর্দায় "কার জন্য" লেখার জন্য)।
     */
    public static function targetOf(PriceList $list): string
    {
        return match (true) {
            $list->customer_id !== null => self::CUSTOMER,
            $list->party_type_id !== null => self::TIER,
            $list->location_id !== null => self::TERRITORY,
            default => self::ALL,
        };
    }

    /**
     * এই গ্রাহকের জন্য যে চালু তালিকাগুলো খাটতে পারে — একটা কোয়েরি।
     *
     * @return \Illuminate\Support\Collection<int, PriceList>
     */
    private function listsFor(?Customer $customer): \Illuminate\Support\Collection
    {
        $chain = array_keys($this->chainOf($customer));

        return PriceList::query()
            ->active()
            ->where(function ($q) use ($customer, $chain) {
                $q->where(fn ($w) => $w->whereNull('customer_id')->whereNull('party_type_id')->whereNull('location_id'));

                if ($customer !== null) {
                    $q->orWhere('customer_id', $customer->id);

                    if ($customer->party_type_id !== null) {
                        $q->orWhere(fn ($w) => $w->whereNull('customer_id')->where('party_type_id', $customer->party_type_id));
                    }

                    if ($chain !== []) {
                        $q->orWhere(fn ($w) => $w->whereNull('customer_id')->whereNull('party_type_id')->whereIn('location_id', $chain));
                    }
                }
            })
            ->get()
            ->keyBy('id');
    }

    /**
     * দোকানের জায়গা থেকে উপরে — জায়গা → দূরত্ব (নিজে ০, উপরেরটা ১…)।
     *
     * @return array<int, int>
     */
    private function chainOf(?Customer $customer): array
    {
        $node = $customer?->location_id !== null ? Location::query()->find($customer->location_id) : null;
        $chain = [];

        // ⓘ গভীরতার সীমা — তথ্য নষ্ট হয়ে চক্র হলেও থামে ([[Location::ancestors()]]-এর একই কারণ)
        for ($depth = 0; $node !== null && $depth < 10 && ! array_key_exists((int) $node->id, $chain); $depth++) {
            $chain[(int) $node->id] = $depth;
            $node = $node->parent;
        }

        return $chain;
    }

    /** সারির দর চাওয়া এককে — একক মেলানো না গেলে null (সারিটা বাদ, পরের স্তর)। */
    private function inUnit(Product $product, PriceListItem $item, ?int $want): ?string
    {
        $itemUnit = $item->unit_id !== null && (int) $item->unit_id !== (int) $product->unit_id ? (int) $item->unit_id : null;
        $price = (string) $item->price;

        if ($itemUnit === $want) {
            return Money::round($price, 4);
        }

        try {
            $base = $itemUnit === null ? $price : $this->packs->toStockRate($product, $price, $itemUnit);

            return Money::round($want === null ? $base : bcmul($base, $this->packs->factorFor($product, $want), 6), 4);
        } catch (ValidationException) {
            return null;
        }
    }

    /** পণ্যের নিজের দাম — চাওয়া এককে। */
    private function standard(Product $product, ?int $want): string
    {
        $price = (string) ($product->sale_price ?? '0');

        if ($want === null) {
            return Money::round($price, 4);
        }

        try {
            return Money::round(bcmul($price, $this->packs->factorFor($product, $want), 6), 4);
        } catch (ValidationException) {
            return Money::round($price, 4);
        }
    }
}
