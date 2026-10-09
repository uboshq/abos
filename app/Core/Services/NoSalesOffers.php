<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\SalesOffers;
use Illuminate\Validation\ValidationException;

/**
 * অফারের ব্যবস্থা বন্ধ — প্রশ্নে খালি উত্তর, বসাতে চাইলে পরিষ্কার "না"।
 *
 * ⓘ Promotion মডিউল বন্ধ বা নেই এমন কোম্পানিতে বিক্রি অবিকল আগের মতো চলে;
 * ⚠️ তবু কেউ ঠিকানা টাইপ করে বসাতে চাইলে নীরবে কিছু না করে বার্তা দেয়।
 */
final class NoSalesOffers implements SalesOffers
{
    public function enabled(): bool
    {
        return false;
    }

    public function suggest(array $line): array
    {
        return ['eligible' => [], 'almost' => []];
    }

    public function apply(int $offerId, array $line, string $sourceType, int $sourceId, int $sourceLineId): string
    {
        throw ValidationException::withMessages(['promotion' => __('core.offers_off')]);
    }

    public function remove(int $offerId, string $sourceType, int $sourceId, int $sourceLineId): void
    {
        throw ValidationException::withMessages(['promotion' => __('core.offers_off')]);
    }

    public function appliedOn(string $sourceType, int $sourceId): array
    {
        return [];
    }

    public function reverseAll(string $sourceType, int $sourceId): void
    {
        // ⓘ কিছুই বসানো নেই — উল্টানোরও কিছু নেই
    }

    public function carryOrderCoupons(array $orderIds, int $invoiceId, string $room): void
    {
        // ⓘ অফার বন্ধ — কোনো কুপন কাটা হয়নি
    }
}
