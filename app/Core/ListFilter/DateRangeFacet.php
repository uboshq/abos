<?php

declare(strict_types=1);

namespace App\Core\ListFilter;

use App\Core\Support\DateFormat;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ⭐ তারিখের পরিসর — "থেকে / পর্যন্ত" (`?created_from=…&created_to=…`); যেকোনো একটা বা দুটোই।
 *
 * ⓘ চাবির নাম তালিকার নিজের (পুরোনো লিংক অটুট থাকে): `from` আর `to` চাবি দুটো ঘোষণায় আলাদা করে দেওয়া যায়।
 * ⛔ পড়া যায় না এমন তারিখ চুপচাপ বাদ — ৫০০ নয়।
 */
final class DateRangeFacet extends Facet
{
    /**
     * @param  Closure(Builder, ?string, ?string): mixed  $applyRange  থেকে, পর্যন্ত — Y-m-d
     */
    public function __construct(
        string $key,
        string $label,
        private readonly Closure $applyRange,
        private readonly string $fromKey = '',
        private readonly string $toKey = '',
    ) {
        parent::__construct($key, $label);
    }

    public function kind(): string
    {
        return 'date';
    }

    public function fromKey(): string
    {
        return $this->fromKey !== '' ? $this->fromKey : $this->key.'_from';
    }

    public function toKey(): string
    {
        return $this->toKey !== '' ? $this->toKey : $this->key.'_to';
    }

    public function keys(): array
    {
        return [$this->fromKey(), $this->toKey()];
    }

    /** @return array{from: ?string, to: ?string}|null */
    public function read(Request $request): mixed
    {
        $from = self::day($request->query($this->fromKey()));
        $to = self::day($request->query($this->toKey()));

        /*
         * ⓘ উল্টো পরিসর সোজা করে নেওয়া — কেউ "থেকে" ৩১ আর "পর্যন্ত" ১ দিলে তালিকা খালি দেখাত আর মানুষ ভাবত মাসটায়
         *   কিছু হয়নি। আগের তারিখের ঘরের ([[FiltersByDate::applyDateRange()]]) একই নিয়ম।
         */
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return $from === null && $to === null ? null : ['from' => $from, 'to' => $to];
    }

    /** @param  array{from: ?string, to: ?string}  $value */
    public function apply(Builder $query, mixed $value): void
    {
        ($this->applyRange)($query, $value['from'], $value['to']);
    }

    /** @param  array{from: ?string, to: ?string}  $value */
    public function chips(mixed $value): array
    {
        $chips = [];

        foreach (['from' => $this->fromKey(), 'to' => $this->toKey()] as $end => $key) {
            if ($value[$end] !== null) {
                $chips[] = [
                    'text' => $this->label.' '.__('core.list_filter.'.$end).' '.DateFormat::format($value[$end]),
                    'drop' => [$key => null],
                ];
            }
        }

        return $chips;
    }

    public static function day(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
