<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * ⭐ রসিদের তালিকায় আগের টাকা-ঢোকার কাগজ — মালিক, ২০ নভেম্বর ২০২৬ (চূড়ান্ত: "টাকা ঢোকার কাগজ একটাই", রসিদ ভাউচার)।
 *
 * ⓘ রসিদ ভাউচারের আগে গ্রাহকের টাকা কিছুদিন অন্য মডিউলের নিজের কাগজে নেওয়া হত (বিক্রয়ের আদায়, COL-…)। সেগুলো আর লেখা হয় না,
 * কিন্তু খোঁজা লাগে — মালিক অনুমোদন দিয়ে খাতায় টাকা দেখলেন, অথচ রসিদের তালিকায় কাগজটা পেলেন না। ⛔ হিসাব বিক্রয়কে চেনে না
 * ([[\Tests\Feature\Architecture\BoundariesTest]]), তাই রসিদের তালিকা কেবল এই চুক্তি জিজ্ঞেস করে; বিক্রয় বন্ধ থাকলে
 * [[\App\Core\Services\NoOldReceiptPapers]] খালি উত্তর দেয়।
 */
interface OldReceiptPapers
{
    /**
     * রসিদের তালিকার একই ছাঁকনিতে আগের কাগজ — নতুন আগে, বাতিল বাদ; কেবল দেখা (পাতার লিংক), বদল নয়।
     *
     * @param  array<string, string>  $filters  রসিদের তালিকার ছাঁকনি — `from`, `to` (Y-m-d), `party` ("customer:id"), `branch_id`
     * @param  string|null  $search  নম্বর বা গ্রাহক খোঁজা
     * @return array{rows: list<array{no: string, date: string, party: string, amount: string, status: string, url: string|null}>, count: int, total: string, more_url: string|null}
     *         `count` — মোট কয়টা মেলে (দেখানো সারির চেয়ে বেশি হতে পারে); `total` — তাদের পাকাগুলোর টাকা; `more_url` — সবগুলোর তালিকা
     */
    public function forReceiptList(array $filters, ?string $search, int $limit = 20): array;
}
