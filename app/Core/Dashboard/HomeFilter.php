<?php

declare(strict_types=1);

namespace App\Core\Dashboard;

use App\Core\Contracts\HomeSalesFilters;
use Closure;
use Illuminate\Http\Request;

/**
 * হোমের ছাঁকনি — মালিক, ৪ অক্টোবর ২০২৬: গুদাম, এলাকা আর SR; বদলায় **কেবল বিক্রি আর বকেয়া**।
 *
 * ⓘ নিয়ম বিক্রয়ের "এক নজরে" পাতার সাথে এক (abos-bb, SalesAnalytics): SR মানে বিলটা যিনি কেটেছেন (`created_by`),
 * এলাকা মানে গ্রাহকের জায়গা থেকে গাছ বেয়ে উপরের টেরিটরি (বন্ধ থাকলে এরিয়া), গুদাম মানে বিলের গুদাম।
 * ⓘ বকেয়া গ্রাহকের, বিলের নয় — তাই বকেয়ায় খাটে কেবল এলাকা; গুদাম বা SR বকেয়া বদলায় না, পাতায় সেটা লেখা থাকে।
 *
 * ⛔ ছাঁকনি চালু থাকে কেবল হোমের সংখ্যাগুলো গোনার সময়টুকু ([[during()]]) — অন্য কোনো পর্দা, রিপোর্ট বা
 * বাকির সীমার যাচাই কখনো এটা দেখে না।
 */
final class HomeFilter
{
    private static ?self $current = null;

    /** @param  list<int>|null  $customers  এলাকার গ্রাহক; `null` মানে এলাকা বাছা নেই */
    private function __construct(
        public readonly ?int $warehouse,
        public readonly ?int $area,
        public readonly ?int $seller,
        public readonly ?array $customers,
    ) {}

    /**
     * অনুরোধ থেকে — কেবল তালিকায় থাকা নম্বর নেয়, তাই অন্য কোম্পানির গুদাম বা মানুষ ঢোকে না।
     *
     * @param  array{warehouses: array<int, string>, areas: array<int, string>, sellers: array<int, string>}  $choices
     */
    public static function fromRequest(Request $request, array $choices, HomeSalesFilters $sales): self
    {
        $pick = function (string $key, array $list) use ($request): ?int {
            $id = (int) $request->query($key, 0);

            return $id > 0 && array_key_exists($id, $list) ? $id : null;
        };

        $area = $pick('area', $choices['areas']);

        return new self(
            warehouse: $pick('warehouse', $choices['warehouses']),
            area: $area,
            seller: $pick('seller', $choices['sellers']),
            customers: $area === null ? null : $sales->customersInArea($area),
        );
    }

    public static function none(): self
    {
        return new self(null, null, null, null);
    }

    public function active(): bool
    {
        return $this->warehouse !== null || $this->area !== null || $this->seller !== null;
    }

    /** পাতার লিংকে রেখে দেওয়ার জন্য — সময় বদলালে ছাঁকনি হারায় না */
    public function query(): array
    {
        return array_filter(['warehouse' => $this->warehouse, 'area' => $this->area, 'seller' => $this->seller]);
    }

    /**
     * কেবল এই কাজটুকুর সময় চালু — পরে আবার আগের মতো, ভুল হলেও ([[current()]] অন্য কোথাও ফাঁস হয় না)।
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function during(Closure $work): mixed
    {
        $before = self::$current;
        self::$current = $this->active() ? $this : null;

        try {
            return $work();
        } finally {
            self::$current = $before;
        }
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    /**
     * বিলের কোয়েরি ছাঁকা — Eloquent বা DB::table, দুটোই।
     *
     * @template T of \Illuminate\Contracts\Database\Query\Builder
     *
     * @param  T  $query
     * @return T
     */
    public function onInvoices($query, string $table)
    {
        return $query
            ->when($this->warehouse !== null, fn ($q) => $q->where($table.'.warehouse_id', $this->warehouse))
            ->when($this->seller !== null, fn ($q) => $q->where($table.'.created_by', $this->seller))
            ->when($this->customers !== null, fn ($q) => $q->whereIn($table.'.customer_id', $this->customers ?: [0]));
    }

    /**
     * বকেয়ার গ্রাহক — কেবল এলাকা খাটে; `null` মানে বকেয়া বদলায় না।
     *
     * @return list<int>|null
     */
    public function dueCustomers(): ?array
    {
        return $this->customers;
    }
}
