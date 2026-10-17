<?php

declare(strict_types=1);

namespace App\Modules\Sales\Phone;

use App\Core\Phone\PhonePaper;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * বিক্রয়ের কাগজ ফোনের নিজের পর্দায় ([[PhonePaper]])। ⓘ দেয়াল মডেলের নিজের — কোম্পানি, দেখার শাখা, বিক্রয়কর্মীর বাঁধা ডিলার।
 */
abstract class SalesPhonePaper extends PhonePaper
{
    public function module(): string
    {
        return 'sales';
    }

    /** পণ্যের নাম — কোড - নাম, সইয়ের পাতার মতো ([[SalesSigningSheet]]) */
    protected static function productOf(Model $line): string
    {
        $product = $line->getRelationValue('product');

        return trim(($product?->getAttribute('code') ?? '').' - '.(self::nameOf($product) ?? ''), ' -');
    }

    /** পরিমাণ, পণ্যের একক সহ */
    protected static function qtyOf(Model $line, string $column): string
    {
        $unit = self::nameOf($line->getRelationValue('product')?->getRelationValue('unit'));

        return trim(Money::format((string) ($line->getAttribute($column) ?? '0')).' '.($unit ?? ''));
    }

    /** ফ্রি — শূন্য হলে ফাঁকা */
    protected static function freeOf(Model $line, string $column = 'free_qty'): ?string
    {
        $free = (string) ($line->getAttribute($column) ?? '0');

        return bccomp($free, '0', 4) > 0 ? Money::format($free) : null;
    }

    protected static function money(mixed $value): string
    {
        return Money::format((string) ($value ?? '0'));
    }

    /** শূন্য হলে ফাঁকা */
    protected static function moneyIfAny(mixed $value): ?string
    {
        return bccomp((string) ($value ?? '0'), '0', 4) !== 0 ? self::money($value) : null;
    }

    protected static function day(mixed $value): ?string
    {
        return $value === null ? null : DateFormat::format($value);
    }

    protected static function moment(mixed $value): ?string
    {
        return $value === null ? null : DateFormat::formatWithTime($value);
    }
}
