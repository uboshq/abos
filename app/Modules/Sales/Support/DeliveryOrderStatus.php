<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

/**
 * ডেলিভারি অর্ডারের অবস্থা — তিন সেশনের এক তালিকা (২ অক্টোবর ২০২৬; abos-2c, abos-86, abos-bb মিলিয়ে)।
 *
 *   draft → submitted → supervisor_pending → supervisor_approved
 *        → accounts_held | accounts_approved   (abos-86: হিসাবের স্বয়ংক্রিয় যাচাই)
 *        → depot_check → invoiced               (abos-bb: ডিপোর যাচাই, বিল ও চালান একসাথে)
 *   পাশে: rejected (সুপারভাইজার ফেরালেন), cancelled।
 *
 * ⛔ কে কোনটা লেখে তা আলাদা — আমি draft…supervisor_approved আর rejected/cancelled; বাকিগুলো যার যার সেবা।
 * ⓘ একটা নাম বদলালে তিন জায়গা ভাঙে — নাম এখানেই, কোথাও আক্ষরিক লেখা নয়।
 */
final class DeliveryOrderStatus
{
    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const SUPERVISOR_PENDING = 'supervisor_pending';

    public const SUPERVISOR_APPROVED = 'supervisor_approved';

    public const ACCOUNTS_HELD = 'accounts_held';

    public const ACCOUNTS_APPROVED = 'accounts_approved';

    public const DEPOT_CHECK = 'depot_check';

    public const INVOICED = 'invoiced';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** ধারার ক্রম — তালিকা আর ট্র্যাকিং এই ক্রমে দেখায় */
    public const FLOW = [
        self::DRAFT, self::SUBMITTED, self::SUPERVISOR_PENDING, self::SUPERVISOR_APPROVED,
        self::ACCOUNTS_HELD, self::ACCOUNTS_APPROVED, self::DEPOT_CHECK, self::INVOICED,
    ];

    /** লেখক নিজে বদলাতে পারেন কেবল এখানে — জমার পরে নয় (সমন্বয়কের শর্ত) */
    public const EDITABLE_BY_WRITER = [self::DRAFT];

    /** শেষ — আর কিছু ঘটে না */
    public const CLOSED = [self::INVOICED, self::REJECTED, self::CANCELLED];

    public static function label(string $status): string
    {
        return __('sales::delivery_order.status.'.$status);
    }
}
