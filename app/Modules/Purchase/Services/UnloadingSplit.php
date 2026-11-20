<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Services\SettingsService;
use App\Core\Support\Money;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ আনলোডিংয়ের টাকা কোন সারির ভাগে কতটা — মালিক, ১৮ নভেম্বর ২০২৬ ("ওকে")।
 *
 * ── মালিকের নিয়ম ────────────────────────────────────────────────────────────────
 *  কিছু সারির কার্টনপ্রতি দর হাতে বসানো যায় (বড় কার্টন ১ টাকা, খুব বড় ৫ টাকা) — তারা পায় কার্টন × সেই দর। বাকি টাকা অন্য
 *  সারিগুলোর মধ্যে **কার্টনপ্রতি সমান** ভাগ। মালিকের উদাহরণ: ১০০ কার্টন, ৫০ টাকা দেওয়া, ১০ কার্টন হাতে ১ টাকা = ১০ টাকা →
 *  ৪০ টাকা ৯০ কার্টনে = কার্টনপ্রতি ০.৪৪৪৪…।
 *
 * ── পয়সা পর্যন্ত, যোগফল হুবহু ──────────────────────────────────────────────────
 *  প্রতিটা ভাগ পয়সায় গোল ([[Money::round()]]); ⭐ সমান-ভাগের শেষ সারি বাকিটা নেয় — তাই যোগফল সবসময় দেওয়া অঙ্কটাই
 *  ([[DirectPurchaseService::spreadBillDiscount()]]-এর একই নিয়ম)। হাতে বসানো ছাড়া কোনো সারি না থাকলে বাকি পয়সা শেষ সারিতে।
 *  ⛔ float নয় — টাকা স্ট্রিং, bcmath ([[MoneyIsNeverAFloatTest]])।
 *
 * ── কার্টন মানে কী ─────────────────────────────────────────────────────────────
 *  পণ্যের কেনার প্যাক (পণ্যের ফর্মের "কেনায়" রেডিও); না বাছা থাকলে পণ্যের সবচেয়ে বড় নিজের প্যাক; প্যাক না থাকলে পণ্যের নিজের
 *  এককই এক কার্টন (বস্তা, কার্টনে গোনা পণ্য)। সারির পরিমাণ মূল এককে জমা থাকে ([[ReadsPackedQuantities]]), তাই কার্টন =
 *  (কেনা + ফ্রি) ÷ প্যাকের মাপ। ⓘ ফ্রি কার্টনও নামাতে হয় — তাই গোনায় আসে।
 */
final class UnloadingSplit
{
    /** নিয়ন্ত্রণ প্যানেলের চাবি — কার্টনপ্রতি সাধারণ দর (মালিকের উদাহরণ ০.৪০ টাকা) */
    public const RATE_KEY = 'purchase.unloading.rate_per_carton';

    public const DEFAULT_RATE = '0.40';

    /** আজকের কার্টনপ্রতি দর — নিয়ন্ত্রণ প্যানেল থেকে */
    public static function rate(): string
    {
        $rate = trim((string) app(SettingsService::class)->get(self::RATE_KEY, self::DEFAULT_RATE));

        return is_numeric($rate) && bccomp($rate, '0', 4) >= 0 ? bcadd($rate, '0', 4) : bcadd(self::DEFAULT_RATE, '0', 4);
    }

    /**
     * ভাগ — সারির চাবি → ভাগ (পয়সায়, চার দশমিকের স্ট্রিং)।
     *
     * @param  array<int|string, array{cartons: string, rate: ?string}>  $lines  `rate` null = সমান ভাগে
     * @return array<int|string, string>
     */
    public static function split(string $amount, array $lines): array
    {
        $amount = Money::round($amount === '' ? '0' : $amount, 2);
        $shares = array_map(fn () => '0.0000', $lines);

        if ($lines === [] || bccomp($amount, '0', 4) <= 0) {
            return $shares;
        }

        $hand = '0';
        $freeCartons = '0';
        $lastFree = null;

        foreach ($lines as $key => $line) {
            if (self::isHandSet($line)) {
                $shares[$key] = bcadd(Money::round(bcmul((string) $line['cartons'], (string) $line['rate'], 8), 2), '0', 4);
                $hand = bcadd($hand, $shares[$key], 4);

                continue;
            }

            $freeCartons = bcadd($freeCartons, (string) $line['cartons'], 4);
            $lastFree = $key;
        }

        if (bccomp($hand, $amount, 4) > 0) {
            throw ValidationException::withMessages(['unload_amount' => __('supplier::labour.hand_over_amount', [
                'hand' => Money::format($hand), 'amount' => Money::format($amount),
            ])]);
        }

        $rest = bcsub($amount, $hand, 4);

        // ⓘ সমান ভাগের কেউ নেই (বা তাদের কার্টন শূন্য) — বাকি পয়সা শেষ সারিতে, যাতে যোগফল হুবহু থাকে
        if ($lastFree === null || bccomp($freeCartons, '0', 4) <= 0) {
            $keys = array_keys($lines);
            $last = $lastFree ?? end($keys);
            $shares[$last] = bcadd($shares[$last], $rest, 4);

            return $shares;
        }

        // ⓘ কার্টনপ্রতি — দশ দশমিকে, তারপর প্রতিটা ভাগ পয়সায়; শেষ সমান-ভাগের সারি বাকিটা
        $perCarton = bcdiv($rest, $freeCartons, 10);
        $given = '0';

        foreach ($lines as $key => $line) {
            if (self::isHandSet($line) || $key === $lastFree) {
                continue;
            }

            $shares[$key] = bcadd(Money::round(bcmul((string) $line['cartons'], $perCarton, 10), 2), '0', 4);
            $given = bcadd($given, $shares[$key], 4);
        }

        $shares[$lastFree] = bcsub($rest, $given, 4);

        return $shares;
    }

    /**
     * প্রস্তাবিত অঙ্ক — হাতে বসানো সারি কার্টন × তার দর, বাকিরা কার্টন × সাধারণ দর; পয়সায় গোল।
     *
     * @param  array<int|string, array{cartons: string, rate: ?string}>  $lines
     */
    public static function expected(array $lines, ?string $rate = null): string
    {
        $rate ??= self::rate();
        $total = '0';

        foreach ($lines as $line) {
            $total = bcadd($total, bcmul((string) $line['cartons'], self::isHandSet($line) ? (string) $line['rate'] : $rate, 8), 8);
        }

        return Money::round($total, 2);
    }

    /** @param  array<int|string, array{cartons: string, rate: ?string}>  $lines */
    public static function cartonsOf(array $lines): string
    {
        return array_reduce($lines, fn (string $sum, array $line) => bcadd($sum, (string) $line['cartons'], 4), '0');
    }

    /**
     * এক কার্টনে পণ্যের মূল এককের কতটা — পণ্য ধরে, এক কোয়েরিতে।
     *
     * @param  iterable<Product>|list<int>  $products
     * @return array<int, string> পণ্যের id → মাপ (কমপক্ষে ১-এর উপরে না হলে '1')
     */
    public static function cartonFactors(iterable $products): array
    {
        $ids = collect($products)->map(fn ($p) => $p instanceof Product ? (int) $p->id : (int) $p)->filter()->unique()->values()->all();
        $factors = array_fill_keys($ids, '1');

        if ($ids === []) {
            return $factors;
        }

        $packs = ProductUnit::query()
            ->whereIn('product_id', $ids)
            ->where('is_active', true)
            ->get(['product_id', 'unit_id', 'factor', 'is_purchase_default'])
            ->groupBy('product_id');

        foreach ($packs as $productId => $rows) {
            // ⓘ মালিকের বাছা কেনার প্যাক আগে, নইলে সবচেয়ে বড় প্যাক
            $chosen = $rows->first(fn (ProductUnit $p) => (bool) $p->is_purchase_default)
                ?? $rows->sort(fn (ProductUnit $a, ProductUnit $b) => bccomp((string) $b->factor, (string) $a->factor, 6))->first();

            $factor = $chosen === null ? '1' : bcadd((string) $chosen->factor, '0', 6);
            $factors[(int) $productId] = bccomp($factor, '1', 6) > 0 ? $factor : '1';
        }

        return $factors;
    }

    /**
     * কাগজের সারি থেকে ভাগের তালিকা — সারির id → কার্টন আর হাতের দর। ⓘ মাল গ্রহণ (`received_qty`) আর বিল (`qty`) দুটোই।
     *
     * @param  iterable<\Illuminate\Database\Eloquent\Model>  $lines
     * @return array<int, array{cartons: string, rate: ?string}>
     */
    public static function rows(iterable $lines, string $qtyField): array
    {
        $lines = collect($lines);
        $factors = self::cartonFactors($lines->pluck('product_id')->all());
        $rows = [];

        foreach ($lines as $line) {
            $qty = bcadd((string) ($line->getAttribute($qtyField) ?? '0'), (string) ($line->getAttribute('free_qty') ?? '0'), 4);
            $rate = $line->getAttribute('unload_rate');

            $rows[(int) $line->getKey()] = [
                'cartons' => self::cartons($qty, $factors[(int) $line->getAttribute('product_id')] ?? '1'),
                'rate' => $rate === null ? null : (string) $rate,
            ];
        }

        return $rows;
    }

    /** মূল এককের পরিমাণ → কার্টন (চার দশমিকে) */
    public static function cartons(string $baseQty, string $factor): string
    {
        return bccomp($factor, '0', 6) > 0 ? bcdiv($baseQty, $factor, 4) : bcadd($baseQty, '0', 4);
    }

    /** @param  array{cartons: string, rate: ?string}  $line */
    private static function isHandSet(array $line): bool
    {
        $rate = $line['rate'] ?? null;

        return $rate !== null && $rate !== '' && is_numeric($rate) && bccomp((string) $rate, '0', 4) >= 0;
    }
}
