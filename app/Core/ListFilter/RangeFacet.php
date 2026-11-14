<?php

declare(strict_types=1);

namespace App\Core\ListFilter;

use App\Core\Support\Money;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * ⭐ টাকার পরিসর — "কমপক্ষে / সর্বোচ্চ", যেকোনো একটা বা দুটোই (`?due_min=50&due_max=100`)।
 *
 * ⛔ কেবল সাধারণ দশমিক — "1e5" `is_numeric` পেরিয়ে bcmath-এ ভেঙে ৫০০ দিত (পুনঃঅডিট ৯ অক্টোবর ২০২৬, গ্রাহক ১৭);
 *   কমা চলে ("48,440.49")।
 */
final class RangeFacet extends Facet
{
    /**
     * @param  Closure(Builder, ?string, ?string): mixed  $applyRange  কমপক্ষে, সর্বোচ্চ — চার দশমিকের লেখা
     */
    public function __construct(
        string $key,
        string $label,
        private readonly Closure $applyRange,
    ) {
        parent::__construct($key, $label);
    }

    public function kind(): string
    {
        return 'range';
    }

    public function keys(): array
    {
        return [$this->key.'_min', $this->key.'_max'];
    }

    /** @return array{min: ?string, max: ?string}|null */
    public function read(Request $request): mixed
    {
        $min = self::money($request->query($this->key.'_min'));
        $max = self::money($request->query($this->key.'_max'));

        return $min === null && $max === null ? null : ['min' => $min, 'max' => $max];
    }

    /** @param  array{min: ?string, max: ?string}  $value */
    public function apply(Builder $query, mixed $value): void
    {
        ($this->applyRange)($query, $value['min'], $value['max']);
    }

    /** @param  array{min: ?string, max: ?string}  $value */
    public function chips(mixed $value): array
    {
        $chips = [];

        foreach (['min' => '≥', 'max' => '≤'] as $end => $sign) {
            if ($value[$end] !== null) {
                $chips[] = [
                    'text' => $this->label.' '.$sign.' ৳'.Money::format($value[$end]),
                    'drop' => [$this->key.'_'.$end => null],
                ];
            }
        }

        return $chips;
    }

    public static function money(mixed $value): ?string
    {
        $value = is_string($value) ? trim(str_replace(',', '', $value)) : '';

        return preg_match('/^\d{1,14}(\.\d{1,4})?$/', $value) === 1 ? bcadd($value, '0', 4) : null;
    }
}
