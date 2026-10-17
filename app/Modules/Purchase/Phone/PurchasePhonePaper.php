<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Phone;

use App\Core\Phone\PhonePaper;
use App\Core\Security\FieldSecurity;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;

/**
 * ক্রয়ের কাগজ ফোনের নিজের পর্দায় — পক্ষ সরবরাহকারী, আর দর/অঙ্ক কেবল ক্রয়মূল্য দেখার চাবিতে ([[PhonePaper]])।
 *
 * <p>⛔ ক্রয়মূল্য ঢাকা ওয়েবের পাতার একই নিয়মে (`FieldSecurity::visible(Product, 'purchase_price')` → `inventory.cost.view`;
 * বিল, আদেশ, গ্রহণ আর ফেরতের পাতা, ছাপা আর ফোনের ক্রয়-তালিকা): চাবি না থাকলে সারিতে অঙ্ক নেই, সর্বমোট নেই, পাতায় দর নেই।
 */
abstract class PurchasePhonePaper extends PhonePaper
{
    public function module(): string
    {
        return 'purchase';
    }

    /** দর আর অঙ্ক দেখা যায় কি না — ওয়েবের পাতার `$showsPrice` */
    protected static function showsPrice(): bool
    {
        return FieldSecurity::visible(Product::class, 'purchase_price');
    }

    public function sumColumn(User $user): ?string
    {
        return static::showsPrice() ? $this->query()->getModel()->getTable().'.total' : null;
    }

    public function with(): array
    {
        return ['supplier'];
    }

    public function row(Model $paper, User $user): array
    {
        return [
            'party' => self::nameOf($paper->getRelationValue('supplier')),
            'total' => static::showsPrice() ? (string) ($paper->getAttribute('total') ?? '0') : null,
        ];
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

    protected static function money(mixed $value): ?string
    {
        return static::showsPrice() ? Money::format((string) ($value ?? '0')) : null;
    }

    protected static function day(mixed $value): ?string
    {
        return $value === null ? null : DateFormat::format($value);
    }

    /**
     * সারির কলাম, শেষে দর আর অঙ্ক — চাবি থাকলে তবেই।
     *
     * @param  array<string, array{0: string, 1?: bool}>  $columns
     * @return array<string, array{0: string, 1?: bool}>
     */
    protected static function priced(array $columns): array
    {
        return static::showsPrice()
            ? $columns + ['rate' => [__('purchase::field.rate'), true], 'amount' => [__('purchase::field.amount'), true]]
            : $columns;
    }

    /**
     * @param  array<string, ?string>  $row
     * @return array<string, ?string>
     */
    protected static function pricedRow(array $row, Model $line, string $rate = 'rate', string $amount = 'amount'): array
    {
        return static::showsPrice()
            ? $row + ['rate' => Money::format((string) ($line->getAttribute($rate) ?? '0')), 'amount' => Money::format((string) ($line->getAttribute($amount) ?? '0'))]
            : $row;
    }

    /** ফ্রি — শূন্য হলে ফাঁকা */
    protected static function freeOf(Model $line, string $column = 'free_qty'): ?string
    {
        $free = (string) ($line->getAttribute($column) ?? '0');

        return bccomp($free, '0', 4) > 0 ? Money::format($free) : null;
    }

    /** শূন্য হলে ফাঁকা — "০.০০"-এর সারি চোখ টানে */
    protected static function moneyIfAny(mixed $value): ?string
    {
        return bccomp((string) ($value ?? '0'), '0', 4) !== 0 ? static::money($value) : null;
    }
}
