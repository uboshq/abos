<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * যে কাগজ খতিয়ানের উৎস নয় ([[Drillable]] নয়), তবু মালিক নিজে লিখে নিজে অনুমোদন দিলে মাসের রিপোর্টে আসে ([[OwnerSelfApproval]];
 * মালিক, ২০ নভেম্বর ২০২৬) — বেতনের ঠিকানার বদল, চূড়ান্ত পাওনা, "ব্যাংক এই লাইন দেয়নি"।
 *
 * ⓘ সারির লিংক যায় যাঁকে নিয়ে কাগজ তাঁর পাতায়। ⛔ অঙ্ক রিপোর্টে আসে না — ঘরগুলো বেতনের চাবির পেছনে, রিপোর্টের চাবি হিসাবের।
 */
interface ShownInOwnerSelfReport
{
    /** @return array{0: string, 1: string} উৎসের ধরন (drill_sources-এর নাম), আর এই টেবিলের যে ঘরে তার id */
    public static function ownerSelfSource(): array;
}
