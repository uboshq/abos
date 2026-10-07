<?php

declare(strict_types=1);

namespace App\Modules\Customer\Panels;

use App\Core\Contracts\ContributesFacts;
use App\Core\Panels\Fact;
use App\Core\Support\Money;
use App\Modules\Customer\Models\Customer;

/**
 * গ্রাহকের ছোট কার্ড — সই দিতে বসা মানুষের জন্য, ২৮ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কেন ─────────────────────────────────────────────────────────────
 * বাকিতে বিক্রি বা জমার কাগজে সইকারীর আসল প্রশ্ন: *"মানুষটা কে, কত বাকি
 * আছে, আর কত পর্যন্ত দেওয়া যায়"*। ⚠️ উত্তরটা ছিল গ্রাহকের পাতায়, অনুমোদনের
 * পাতায় নয় — তাই সই হত না দেখে।
 *
 * ⓘ entity `customer-card`, `customer` নয়: গ্রাহকের নিজের পাতা এই ঘরগুলো
 * আগেই বড় করে দেখায়; `customer`-এ দিলে সেখানে দুইবার উঠত। অনুমোদনের পাতা
 * `{পক্ষের ধরন}-card` চায় ([[ApprovalInboxController::show()]]), আর যে মডিউল
 * যা জানে সে তা যোগ করে।
 */
final class CustomerCardFacts implements ContributesFacts
{
    public const ENTITY = 'customer-card';

    /**
     * @return list<Fact>
     */
    public static function factsFor(string $entity, int $id): array
    {
        if ($entity !== self::ENTITY) {
            return [];
        }

        $customer = Customer::query()->find($id);

        if ($customer === null) {
            return [];
        }

        $limit = (string) $customer->credit_limit;
        $hasLimit = bccomp($limit, '0', 4) > 0;
        $available = $customer->availableLimit();

        return [
            new Fact(label: 'customer::field.phone', value: $customer->phone, sort: 10),
            /*
             * ⓘ "পয়েন্ট" — গ্রাহকের এলাকা বা বাজার, লয়্যালটি পয়েন্ট নয়; সব বিক্রয়
             * তালিকায় গ্রাহকের পরের কলাম (77fae8cf)। সমন্বয়কারীর সংশোধন।
             */
            new Fact(label: 'customer::field.point', value: $customer->location?->name(), sort: 15),
            new Fact(label: 'customer::field.address', value: $customer->address(app()->getLocale()), sort: 20),
            new Fact(label: 'customer::field.outstanding', value: Money::format($customer->outstanding()), sort: 30),
            new Fact(label: 'customer::field.credit_limit', value: $hasLimit ? Money::format($limit) : null, sort: 40),
            new Fact(label: 'customer::field.available_limit',
                value: $hasLimit && $available !== null ? Money::format($available) : null, sort: 50),
        ];
    }
}
