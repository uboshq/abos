<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Support\Money;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * ⭐ মান-যাচাইয়ের যন্ত্র ৪ (মালিক, ২৮ নভেম্বর ২০২৬): টাকার সূত্র হাজারো এলোমেলো অঙ্কে প্রমাণ (property test, eris)।
 *
 * ⓘ একটা-দুটো উদাহরণ নয় — যেকোনো অঙ্কের জন্য যা সত্য হতেই হবে:
 *   · গোল করার পরে ঠিক দুই ঘর, আর আসল অঙ্ক থেকে আধা পয়সার বেশি দূরে নয়;
 *   · আবার গোল করলে বদলায় না; ঋণাত্মক অঙ্ক ধনাত্মকের আয়না (অর্ধেক হলে শূন্য থেকে দূরে);
 *   · লাখ-কোটির কমা তুলে দিলে গোল করা অঙ্কটাই; অঙ্ক কেবল ইংরেজি;
 *   · সারি ধরে যোগ = একসাথে যোগ, ক্রম বদলালেও একই; খাতার জের (Dr)/(Cr) চিহ্ন অঙ্কের দিক মেনে।
 * ⛔ ডেটাবেস নয় — খাঁটি PHPUnit, তাই Infection দ্রুত চলে।
 */
final class MoneyHoldsForAnyAmountTest extends TestCase
{
    use TestTrait;

    /** ⓘ −১০০ কোটি থেকে +১০০ কোটি, চার দশমিক ঘর পর্যন্ত */
    private static function amount(int $whole, int $frac): string
    {
        $sign = $whole < 0 ? '-' : '';

        return $sign.abs($whole).'.'.str_pad((string) $frac, 4, '0', STR_PAD_LEFT);
    }

    public function test_rounding_keeps_two_places_within_half_a_paisa_and_is_stable(): void
    {
        $this->forAll(Generators::choose(-1_000_000_000, 1_000_000_000), Generators::choose(0, 9999))
            ->then(function (int $whole, int $frac) {
                $x = self::amount($whole, $frac);
                $r = Money::round($x);

                $this->assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $r, "⛔ {$x} → {$r}: দুই ঘর নয়।");
                $this->assertLessThanOrEqual(0, bccomp(ltrim(bcsub($r, $x, 4), '-'), '0.005', 4), "⛔ {$x} → {$r}: আধা পয়সার বেশি সরেছে।");
                $this->assertSame($r, Money::round($r), "⛔ {$x}: আবার গোল করলে বদলায়।");
            });
    }

    public function test_a_negative_amount_mirrors_the_positive_one(): void
    {
        $this->forAll(Generators::choose(0, 1_000_000_000), Generators::choose(0, 9999))
            ->then(function (int $whole, int $frac) {
                $pos = Money::round(self::amount($whole, $frac));
                $neg = Money::round('-'.self::amount($whole, $frac));

                $this->assertSame(bccomp($pos, '0', 2) === 0 ? '0.00' : '-'.$pos, $neg === '-0.00' ? '0.00' : $neg,
                    "⛔ ±{$whole}.{$frac}: ঋণাত্মক দিক আয়না নয়।");
                $this->assertNotSame('-0.00', $neg, '⛔ পর্দায় "-0.00" দেখাবে।');
            });
    }

    public function test_the_lakh_crore_commas_hide_nothing_and_digits_stay_english(): void
    {
        $this->forAll(Generators::choose(-1_000_000_000, 1_000_000_000), Generators::choose(0, 9999))
            ->then(function (int $whole, int $frac) {
                $x = self::amount($whole, $frac);
                $f = Money::format($x);

                $this->assertMatchesRegularExpression('/^-?(?:\d{1,3}|\d{1,2}(?:,\d{2})*,\d{3})\.\d{2}$/', $f, "⛔ {$x} → {$f}: লাখ-কোটির কমা নয়।");
                $this->assertSame(Money::round($x), str_replace(',', '', $f), "⛔ {$x} → {$f}: কমা তুললে অঙ্ক মেলে না।");
            });
    }

    public function test_a_sum_by_rows_equals_the_sum_at_once_in_any_order(): void
    {
        $this->forAll(Generators::seq(Generators::tuple(Generators::choose(-10_000_000, 10_000_000), Generators::choose(0, 9999))))
            ->then(function (array $rows) {
                $amounts = array_map(fn ($t) => self::amount($t[0], $t[1]), $rows);
                $expected = array_reduce($amounts, fn ($c, $a) => bcadd($c, $a, 4), '0');

                $this->assertSame(0, bccomp($expected, Money::sumOf($amounts, fn ($a) => $a), 4), '⛔ সারি ধরে যোগ আর একসাথে যোগ আলাদা।');
                $this->assertSame(0, bccomp($expected, Money::sumOf(array_reverse($amounts), fn ($a) => $a), 4), '⛔ ক্রম বদলালে যোগফল বদলায়।');
            });
    }

    public function test_the_ledger_balance_sign_follows_the_amount(): void
    {
        $this->forAll(Generators::choose(-1_000_000_000, 1_000_000_000), Generators::choose(0, 9999))
            ->then(function (int $whole, int $frac) {
                $x = self::amount($whole, $frac);
                $r = Money::round($x);
                $s = Money::drCr($x);
                $sign = bccomp($r, '0', 2);

                $this->assertSame($sign > 0 ? '(Dr) ' : ($sign < 0 ? '(Cr) ' : ''), $sign === 0 ? '' : substr($s, 0, 5), "⛔ {$x} → {$s}: (Dr)/(Cr) ভুল দিকে।");
                $this->assertStringNotContainsString('-', $s, "⛔ {$x} → {$s}: জেরে বিয়োগ চিহ্ন (মালিক, ৩ অক্টোবর)।");
            });
    }
}
