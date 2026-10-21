<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Support\Money;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Supplier\Models\Supplier;

/**
 * ⭐ উৎসে কর (AIT) আর উৎসে ভ্যাট (VDS) — একবারই কাটা (fe, ২১ অক্টোবর ২০২৬; নকশা #৫, #২৫; SAP-এর invoice-based আর
 * payment-based withholding)।
 *
 *   বিলে কাটা থাকলে (`withheld_at = bill`, বা বিলের `ait_amount`/`vds_amount`) — পরিশোধে আর কাটা নেই; বিলের বাকি আগেই নিট।
 *   বিলে না কাটা থাকলে — পরিশোধে কাটা হয়, সরবরাহকারীর রেকর্ডের হারে:
 *       AIT = এই বিলে প্রয়োগের ভ্যাট-বাদ অংশ × `ait_rate` %
 *       VDS = এই বিলে প্রয়োগের ভ্যাটের অংশ × `vds_rate` %
 *   (প্রয়োগ বিলের মোটের যে ভাগ, ভ্যাট আর ভ্যাট-বাদ মূল্যও সেই ভাগে)
 *
 * ⓘ সব হিসাব bcmath-এ, পয়সায় গোল ([[Money::round()]]); হার খালি মানে শূন্য — আন্দাজে কোনো হার বসে না। কোনো দিনের সংখ্যা বা
 * হার কোডে নেই: হার সরবরাহকারীর রেকর্ডে।
 */
final class PaymentWithholding
{
    /** বিলেই কাটা হয়েছে কি না — তাহলে পরিশোধে আর নয় */
    public static function withheldOnBill(PurchaseBill $bill): bool
    {
        return $bill->withheld_at === 'bill'
            || bccomp(bcadd((string) ($bill->ait_amount ?? '0'), (string) ($bill->vds_amount ?? '0'), 4), '0', 4) > 0;
    }

    /**
     * এক বিলে এই প্রয়োগের কাটা।
     *
     * @return array{ait: string, vds: string}
     */
    public static function forLine(PurchaseBill $bill, ?Supplier $supplier, string $applied): array
    {
        $none = ['ait' => '0.00', 'vds' => '0.00'];
        $total = bcadd((string) $bill->total, '0', 4);

        if ($supplier === null || self::withheldOnBill($bill) || bccomp($total, '0', 4) <= 0 || bccomp($applied, '0', 4) <= 0) {
            return $none;
        }

        $vat = bcadd((string) ($bill->tax ?? '0'), '0', 4);
        $share = bcdiv($applied, $total, 10);
        $base = bcmul(bcsub($total, $vat, 4), $share, 10);
        $vatPart = bcmul($vat, $share, 10);

        return [
            'ait' => Money::round(bcdiv(bcmul($base, self::rate($supplier->ait_rate), 10), '100', 10)),
            'vds' => Money::round(bcdiv(bcmul($vatPart, self::rate($supplier->vds_rate), 10), '100', 10)),
        ];
    }

    /**
     * সব লাইনের মোট — প্রিভিউর জন্য, কিছু না রেখে।
     *
     * @param  list<array{purchase_bill_id?: mixed, amount?: mixed}>  $lines
     * @return array{ait: string, vds: string}
     */
    public static function forLines(?Supplier $supplier, array $lines): array
    {
        $ait = '0';
        $vds = '0';
        $bills = PurchaseBill::query()->whereKey(array_filter(array_map(fn ($l) => (int) ($l['purchase_bill_id'] ?? 0), $lines)))->get()->keyBy('id');

        foreach ($lines as $line) {
            $bill = $bills->get((int) ($line['purchase_bill_id'] ?? 0));
            $applied = trim((string) ($line['amount'] ?? ''));

            if ($bill === null || ! is_numeric($applied)) {
                continue;
            }

            $cut = self::forLine($bill, $supplier, bcadd($applied, '0', 4));
            $ait = bcadd($ait, $cut['ait'], 4);
            $vds = bcadd($vds, $cut['vds'], 4);
        }

        return ['ait' => Money::round($ait), 'vds' => Money::round($vds)];
    }

    private static function rate(mixed $rate): string
    {
        $rate = trim((string) ($rate ?? ''));

        return is_numeric($rate) && bccomp($rate, '0', 4) > 0 ? bcadd($rate, '0', 4) : '0';
    }
}
