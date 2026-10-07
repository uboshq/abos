<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

/**
 * একটা সারির মার্জিন — বিক্রয়, খরচ, আর সীমার নিচে কি না।
 *
 * ⓘ খরচ `null` মানে **জানা নেই** (স্তরে দাম নেই) — শূন্য নয়। ⛔ শূন্য
 * ধরলে প্রতিটা অজানা সারি ১০০% মার্জিন দেখাত, আর ঠিক সেই সারিটাই
 * সবচেয়ে নিরাপদ দেখাত যেটা আমরা মাপতেই পারিনি।
 */
final class MarginLine
{
    public function __construct(
        /** কাগজে সারির ক্রম (০ থেকে) — ভুলের বার্তা `lines.{index}.rate` ঘরে বসে। */
        public readonly int $index,
        public readonly int $productId,
        public readonly string $productName,
        public readonly string $qty,
        /** পরিমাণ × দর − ছাড়; ভ্যাট বাদ — ভ্যাট সরকারের টাকা, আমাদের আয় নয়। */
        public readonly string $net,
        public readonly ?string $cost,
        public readonly ?string $marginPercent,
        public readonly bool $below,
    ) {}

    public function costKnown(): bool
    {
        return $this->cost !== null;
    }
}
