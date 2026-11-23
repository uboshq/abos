<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Support;

/**
 * ⓘ সারির অঙ্ক — নোট আর স্টক ছাড়া ভাউচার একই নিয়মে: অঙ্ক = পরিমাণ × দর (অঙ্ক লিখলে দর অঙ্ক ÷ পরিমাণ), ভ্যাট = অঙ্ক × হার,
 * দুই দশমিকে গোল — পর্দার হিসাব ([[resources/js/components/trade-lines.js]]) হুবহু এটাই করে।
 */
final class LineMath
{
    /**
     * @return array{qty: string, rate: string, amount: string, vat: string, total: string}
     */
    public static function figures(string $qty, string $rate, ?string $amount, string $vatRate): array
    {
        $qty = self::money($qty);
        $rate = self::money($rate);

        if ($amount !== null && trim($amount) !== '') {
            $amount = self::round2(self::money($amount));
            $rate = bccomp($qty, '0', 4) > 0 ? bcdiv($amount, $qty, 4) : $rate;
        } else {
            $amount = self::round2(bcmul($qty, $rate, 6));
        }

        $vat = self::round2(bcdiv(bcmul($amount, self::money($vatRate), 8), '100', 6));

        return ['qty' => $qty, 'rate' => $rate, 'amount' => $amount, 'vat' => $vat, 'total' => bcadd($amount, $vat, 4)];
    }

    public static function round2(string $value): string
    {
        return bcadd(bcadd($value, bccomp($value, '0', 6) >= 0 ? '0.005' : '-0.005', 2), '0', 4);
    }

    public static function money(mixed $value): string
    {
        $value = str_replace(',', '', trim((string) ($value ?? '')));

        return bcadd(is_numeric($value) ? $value : '0', '0', 4);
    }
}
