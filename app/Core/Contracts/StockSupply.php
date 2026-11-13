<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * ⭐ মালের যোগান — খোলা ক্রয় আদেশে কোন পণ্যের কত আসছে, আর কোন মাল এসেছে কিন্তু বিল আসেনি
 * (মজুদের ড্যাশবোর্ড, মালিকের চূড়ান্ত নমুনা, 10 নভেম্বর 2026 রাত: "আবার অর্ডার"-এর "পথে আছে", সমস্যা কেন্দ্রের "বিল ছাড়া মাল")।
 *
 * ⓘ মজুদ ক্রয়কে চেনে না; ক্রয় বাঁধে, না থাকলে কোরের শূন্য ([[\App\Core\Services\NoStockSupply]])।
 */
interface StockSupply
{
    /**
     * খোলা ক্রয় আদেশে যা আসা বাকি — পণ্য ধরে, আর সবচেয়ে আগের প্রত্যাশিত দিন।
     *
     * @return array<int, array{qty: string, expected_on: ?string}>
     */
    public function onOrderByProduct(): array;

    /**
     * মাল এসেছে, সরবরাহকারীর বিল এখনো বসেনি — কয়টা চালান, কত টাকা, সবচেয়ে বড় অঙ্কের সরবরাহকারী।
     *
     * ⓘ `top_name` — সবচেয়ে বড় অঙ্কের সরবরাহকারীর নাম; চাবির নামে পক্ষের ধরন নয়, কোর পক্ষ চেনে না (TheDealerPaidTheCompanyNotMeTest)
     *
     * @return array{receipts: int, value: string, top_name: string}
     */
    public function unbilledReceipts(): array;

    /**
     * ⭐ সরবরাহকারী — সময়মতো পুরো মাল (OTIF %), গড় লিড টাইম (আদেশ থেকে গ্রহণ, দিন), কেনার টাকা; বড় ছয়জন।
     *
     * @return list<array{name: string, otif: ?int, lead: ?int, value: string}>
     */
    public function suppliers(string $from, string $to): array;

    /**
     * ⭐ মাল ঢোকার ধাপ — এই সময়ে ক্রয় আদেশ, মাল এসেছে, মান যাচাই পাস, বিল মিলেছে (তিন-দিক মিলান)।
     *
     * @return array{orders: int, received: int, passed: int, matched: int}
     */
    public function receivingFunnel(string $from, string $to): array;

    /** ⓘ সরবরাহকারীকে ফেরত মাল মজুদ থেকে কোন উৎস-নামে বেরোয় (মজুদের চলাচলের source_type) — ক্রয় না থাকলে null */
    public function returnSource(): ?string;
}
