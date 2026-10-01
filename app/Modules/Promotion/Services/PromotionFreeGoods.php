<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Services;

use App\Core\Contracts\FreeGoodsOffers;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\ConditionKind;

/**
 * অর্ডারের লাইনে কয়টা ফ্রি — [[FreeGoodsOffers]] চুক্তির Promotion-দিক (১ অক্টোবর ২০২৬)।
 *
 * ⓘ হিসাব ইঞ্জিনের, এখানে নতুন কোনো নিয়ম নেই ([[PromotionEngine::offersFor()]]) — কেবল মাল-দেওয়া
 * সুবিধাগুলো (`goods`) বেছে, আর পড়ার মতো একটা বাক্য: *"১২টা কিনলে ১টা ফ্রি"*।
 * ⚠️ ইঞ্জিনের ফ্রি পরিমাণ ধাপ ধরে স্থির — "১২টায় ১" অফারে ২৪টা নিলেও ১, যদি না ২৪-এর আলাদা ধাপ বসানো থাকে।
 * এখানে গুণ করা হয় না: পর্দা যা দেখায়, বিল/চালান ঠিক তা-ই দেবে।
 */
final class PromotionFreeGoods implements FreeGoodsOffers
{
    public function __construct(private readonly PromotionEngine $engine) {}

    public function forLine(array $line): array
    {
        $rows = [];

        foreach ($this->engine->offersFor($line) as $offer) {
            $benefit = $offer['benefit'];

            if ($benefit->kind !== BenefitKind::GOODS) {
                continue;
            }

            $condition = $benefit->condition;
            $buy = $condition !== null && $condition->kind === ConditionKind::QUANTITY && $condition->value_from !== null
                ? $this->plain((string) $condition->value_from)
                : null;
            $free = $this->plain((string) $offer['qty']);
            $gift = $benefit->giftProduct;

            $rows[] = [
                'promotion_id' => (int) $offer['promotion']->id,
                'text' => $buy !== null
                    ? __('promotion::offer.buy_get_free', ['buy' => $buy, 'free' => $free])
                    : $offer['promotion']->name(),
                'buy_qty' => $buy,
                'free_qty' => bcadd((string) $offer['qty'], '0', 4),
                'gift_product_id' => $gift?->id !== null ? (int) $gift->id : null,
                'gift_name' => $gift?->name(),
            ];
        }

        return $rows;
    }

    /** ৪ দশমিকের শূন্য বাদ — "১২.০০০০" নয়, "১২" */
    private function plain(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }
}
