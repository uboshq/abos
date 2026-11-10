<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * ⭐ মালের চাহিদা — খোলা বিক্রয় আদেশে কোন পণ্যের কত দেওয়া বাকি, আর না দিতে পারায় কত বিক্রি হারাল
 * (মজুদের ড্যাশবোর্ড, মালিকের চূড়ান্ত নমুনা, 10 নভেম্বর 2026 রাত: সমস্যা কেন্দ্রের "মজুদ শেষ" ঘর)।
 *
 * ⓘ মজুদ বিক্রয়কে চেনে না; বিক্রয় বাঁধে, না থাকলে কোরের শূন্য ([[\App\Core\Services\NoStockDemand]])।
 * ⓘ সব অঙ্ক string (bcmath), পরিমাণ মূল এককে, টাকা দরে।
 */
interface StockDemand
{
    /**
     * খোলা (চালু) বিক্রয় আদেশে যা দেওয়া বাকি — পণ্য ধরে।
     *
     * @return array<int, array{qty: string, value: string, lines: int}>
     */
    public function openByProduct(): array;

    /**
     * এই দিনের পর থেকে "আর দেওয়া হবে না" করা অংশের টাকা — পণ্য ধরে (হারানো বিক্রি)।
     *
     * @return array<int, string>
     */
    public function lostByProduct(string $from): array;

    /** অর্ডার পূরণের হার — শেষ দিন পেরোনো লাইনের কত শতাংশ পুরো পৌঁছেছে (in full); কিছু না থাকলে null */
    public function fillRate(string $from, string $to): ?string;

    /** গ্রস লাভ — বসানো বিক্রয় বিলের (উপমোট − ছাড়) − বিক্রীত মালের খরচ; GMROI-এর লব */
    public function grossProfit(string $from, string $to): string;
}
