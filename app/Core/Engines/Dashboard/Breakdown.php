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
    public function __construct(
        public readonly string $label,
        public readonly array $parts,
        public readonly string $hint,
    ) {
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
}
