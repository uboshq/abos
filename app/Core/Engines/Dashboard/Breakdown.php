<?php

declare(strict_types=1);

namespace App\Core\Engines\Dashboard;

use InvalidArgumentException;

/**
 * একটা মোট, আর তার ভেতরে কী কী।
 *
 * ── কেন এটা আলাদা ধরনের পট ───────────────────────────────────────────
 * "গুদামে কত মাল" বা "মোট বকেয়া" — এই প্রশ্নগুলোর একটা সংখ্যায় উত্তর
 * দিলে সেটা সত্যি হয়েও বিভ্রান্তিকর। তাকে ১০০ থাকতে পারে অথচ বেচার
 * মতো ৭৫; বকেয়া ১১ লাখ হতে পারে অথচ তার ২ লাখ মেয়াদোত্তীর্ণ।
 *
 * ভাগগুলো পাশে না দেখালে মানুষ মোটটা দিয়েই সিদ্ধান্ত নেন, আর ভুলটা
 * ধরা পড়ে অনেক পরে — মাল দিতে গিয়ে, বা টাকা চাইতে গিয়ে।
 */
final class Breakdown
{
    /**
     * @param  list<array{label: string, value: string, tone?: string}>  $parts
     */
    /** ⓘ ভাগের চার আন্তর্জাতিক রূপ — ডোনাট, আড়াআড়ি দণ্ড, খাড়া স্তম্ভ, ফানেল */
    public const CHARTS = ['donut', 'hbars', 'columns', 'funnel'];

    public function __construct(
        public readonly string $label,
        public readonly array $parts,
        public readonly string $hint,
        /**
         * ⭐ চার্টের ধরন — `donut`, `hbars`, `columns` বা `funnel` (মালিক, ৪ অক্টোবর ২০২৬: "vino rokomer int standred graph")।
         * ⓘ না দিলে: ছয় বা কম ভাগ, সব অঋণাত্মক → ডোনাট; নইলে আড়াআড়ি দণ্ড ([[kind()]])।
         */
        public readonly ?string $chart = null,
    ) {
        if ($chart !== null && ! in_array($chart, self::CHARTS, true)) {
            throw new InvalidArgumentException("Breakdown '{$label}' asks for an unknown chart '{$chart}'.");
        }

        if ($parts === []) {
            throw new InvalidArgumentException("Breakdown '{$label}' has no parts.");
        }
    }

    /** ভাগগুলোর মোট — শূন্য হলে ১, একই কারণে ([[Series::peak()]])। */
    public function total(): float
    {
        $total = 0.0;

        foreach ($this->parts as $part) {
            $total += (float) $part['value'];
        }

        return $total > 0 ? $total : 1.0;
    }

    /**
     * কোন ধরনে আঁকা হবে — বলা থাকলে সেটা; ডোনাট কেবল ছয় বা কম ভাগে আর ঋণাত্মক না থাকলে (একটা ঋণাত্মক ভাগ বৃত্তে আঁকা যায় না,
     * আর ছয়ের বেশি টুকরো চোখে আলাদা হয় না)।
     */
    public function kind(): string
    {
        $negative = false;

        foreach ($this->parts as $part) {
            $negative = $negative || self::number($part['value']) < 0;
        }

        if ($this->chart === 'donut' || $this->chart === null) {
            return count($this->parts) <= 6 && ! $negative ? 'donut' : 'hbars';
        }

        return $this->chart;
    }

    /** সাজানো মান থেকে সংখ্যা — "1,234.00", বাংলা অঙ্ক, "98%" */
    public static function number(mixed $value): float
    {
        $plain = strtr((string) $value, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9', ',' => '', '%' => '']);

        return (float) trim($plain);
    }
}
