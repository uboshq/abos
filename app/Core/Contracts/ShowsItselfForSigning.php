<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * সই দিতে বসা মানুষের জন্য কাগজটা নিজে কী দেখায় — ২৮ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কেন (মালিকের কাজ) ────────────────────────────────────────────────
 * অনুমোদনের পাতায় ছিল পক্ষের নাম, অঙ্ক আর কাগজের একটা লিংক — সারিগুলো
 * নেই, মাধ্যম নেই, লেনদেন নম্বর নেই। ⚠️ সইকারী হয় লিংকে গিয়ে কাগজটা
 * খুঁজতেন, নয় **না দেখেই সই দিতেন** — আর খাতায় থাকত তাঁর নাম।
 *
 * ── ⭐ কেন কাগজ নিজে বলে ─────────────────────────────────────────────
 * অনুমোদন কোরের; সে সতেরো রকম কাগজ দেখে, আর কারও ভিতরে তাকায় না
 * (§১৯.৭)। ⓘ তাই প্রতিটা কাগজ নিজের "সইয়ের পাতা" দেয় — ঘরের নাম সে-ই
 * জানে। যে কাগজ এটা দেয় না, অনুমোদনের পাতা তার জন্য আগের মতোই থাকে।
 *
 * ⚠️ মান সবই **লেখা** (ফরম্যাট করা টাকা, তারিখ, নাম) — পাতা কিছু গণনা করে না।
 */
interface ShowsItselfForSigning
{
    /**
     * @return array{
     *     facts: list<array{label: string, value: string}>,
     *     columns: list<array{key: string, label: string, numeric?: bool}>,
     *     rows: list<array<string, string|null>>,
     *     totals?: array<string, string>,
     *     party?: array{type: string, id: int}|null,
     * }
     *
     * `facts` — ঘর আর মান, খালি মান বাদ দিয়ে। `columns` আর `rows` — কাগজের
     * সারি; `totals` কলামের key ধরে যোগফল। `party` — যার কাগজ, পাতা তার
     * খবর ([[FactRegistry]], entity `{type}-card`) পাশে দেখায়।
     */
    public function signingSheet(): array;
}
