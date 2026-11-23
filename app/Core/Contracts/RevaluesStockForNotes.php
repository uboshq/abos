<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * ⭐ সরবরাহকারীর ডেবিট নোটের দামের ছাড় মজুদের দামেও বসে — যাতে মজুদ-মূল্য রিপোর্ট আর খাতার ১১২০ সবসময় এক অঙ্ক বলে
 * (মালিকের নকশা v5 পরবর্তী, ২৩ নভেম্বর ২০২৬)।
 *
 * ⓘ বিলের মডেল তার নিজের দামের স্তর (বিল বা তার মাল-গ্রহণের) মজুদের পুনর্মূল্যায়নের পথ দিয়ে নতুন দামে বসায় — যে পথ
 * অন্য দামে বিল হলে ক্রয় নিজেও নেয়। তাকে যতটা পড়ে আছে তার দামের তফাতটাই খাতার Cr ১১২০; বাকিটা ৫১৫০।
 */
interface RevaluesStockForNotes
{
    /**
     * বিলের সারির পণ্যের স্তরের দাম এককপ্রতি `per_unit` কমানো (`$sign` = -1) বা ফেরানো (`$sign` = +1)।
     *
     * @param  list<array{line_id: int, product_id: int, amount: string, per_unit: string|null}>  $lines
     *         `per_unit` না দিলে অঙ্ক ÷ বিলের ঐ পণ্যের স্তরের মোট আগমন
     * @return list<array{per_unit: string, shelf: string}>  একই ক্রমে — `shelf` = তাকে পড়ে থাকা মালের দাম যতটা বদলাল (ধনাত্মক)
     */
    public function noteRevalueStock(array $lines, int $sign, string $date, string $noteNo): array;
}
