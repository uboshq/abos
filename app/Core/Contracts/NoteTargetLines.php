<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * ⭐ মূল বিলের সারি — নোটের "⇣ মূল বিল থেকে আনুন" (মালিক, ২৩ নভেম্বর ২০২৬: নোট ও ভাউচারের নকশা v5, "আমি ওকে করে দিলাম")।
 *
 * ⓘ [[NoteTarget]]-এর পাশের চুক্তি — যে বিল নিজের সারি দেখাতে পারে। বিক্রয়ের বিল আর ক্রয়ের বিল দুজনেই দেয়; Accounts কোনো
 * মডিউলের নাম জানে না, কেবল এই চুক্তি চেনে ([[NoteTarget]]-এর একই কারণ)।
 *
 * ⛔ নোটের পণ্যের সারির পরিমাণ বিলের পরিমাণের বেশি নয়, আর ভ্যাটের হার বিলের সারির — তালাবদ্ধ, হাতে বদলায় না।
 */
interface NoteTargetLines
{
    /**
     * বিলের প্রতিটা পণ্যের সারি।
     *
     * - `line_id`     বিলের সারির id
     * - `product_id`  পণ্য
     * - `name`        পণ্যের নাম
     * - `unit`        এককের নাম
     * - `qty`         বিলে যত ছিল
     * - `rate`        এককপ্রতি নিট দর, ভ্যাট বাদে (ছাড়ের পরে)
     * - `vat_rate`    ভ্যাটের হার, শতাংশে (১৫ = ১৫%)
     * - `in_stock`    বিলের মালের যত এখনো মজুদে (কেবল ক্রয়ের বিলে; বিক্রয়ে `null`)
     *
     * @return list<array{line_id: int, product_id: int, name: string, unit: string, qty: string, rate: string, vat_rate: string, in_stock: string|null}>
     */
    public function noteTargetLines(): array;

    /** কাগজের তারিখ ও সময় — মূসক-৬.৭/৬.৮-এ মূল চালানপত্রের "তারিখ ও সময়" */
    public function noteTargetIssuedAt(): ?\DateTimeInterface;
}
