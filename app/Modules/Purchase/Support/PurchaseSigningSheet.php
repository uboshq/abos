<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Support;

use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseBillLine;

/**
 * ⭐ ক্রয়ের বিল সইকারীর পাতায় — পণ্য, পরিমাণ, ফ্রি, দর, টাকা (মালিক, ১২ নভেম্বর ২০২৬: "Approval পর্দায় পারচেজ ইনভয়েস ভিউ হয় বাট
 *   প্রিভিউ হয় না")। বিক্রির বিলের একই ছাঁচ ([[SalesSigningSheet]]) — অনুমোদনের পর্দা আর ফোন একই সারি পায়।
 * ⓘ সরবরাহকারীর পাশের কার্ড নেই (`party` খালি) — গ্রাহকের মতো সরবরাহকারীর তথ্য-কার্ড এখনো বানানো হয়নি।
 */
final class PurchaseSigningSheet
{
    public static function ofBill(PurchaseBill $bill): array
    {
        $bill->loadMissing(['lines.product', 'supplier', 'warehouse']);

        $facts = array_filter([
            __('purchase::field.supplier') => $bill->supplier?->name(),
            __('purchase::field.supplier_bill_no') => $bill->supplier_bill_no,
            __('core.print.date') => DateFormat::format($bill->trx_date),
            __('purchase::field.due_on') => DateFormat::format($bill->due_on),
            __('purchase::field.warehouse') => $bill->warehouse?->name(),
            __('purchase::field.subtotal') => Money::format($bill->subtotal),
            __('purchase::field.discount') => bccomp((string) $bill->discount, '0', 4) > 0 ? Money::format($bill->discount) : null,
            __('core.table.narration') => $bill->narration ?? null,
        ], fn ($v) => filled($v));

        return [
            'facts' => array_map(fn ($label, $value) => ['label' => (string) $label, 'value' => (string) $value], array_keys($facts), $facts),
            'columns' => [
                ['key' => 'product', 'label' => __('purchase::field.product')],
                ['key' => 'qty', 'label' => __('purchase::field.quantity'), 'numeric' => true],
                ['key' => 'free', 'label' => __('purchase::field.free_qty'), 'numeric' => true],
                ['key' => 'rate', 'label' => __('purchase::field.rate'), 'numeric' => true],
                ['key' => 'amount', 'label' => __('purchase::field.amount'), 'numeric' => true],
            ],
            'rows' => $bill->lines->map(fn (PurchaseBillLine $l) => [
                'product' => $l->product?->code.' - '.$l->product?->name(),
                'qty' => Money::format((string) $l->qty),
                // ⓘ শূন্য ফ্রি ফাঁকা — বিক্রির পাতার একই নিয়ম
                'free' => bccomp((string) $l->free_qty, '0', 4) > 0 ? Money::format((string) $l->free_qty) : null,
                'rate' => Money::format((string) $l->rate),
                'amount' => Money::format((string) $l->amount),
            ])->values()->all(),
            'totals' => ['amount' => Money::format((string) $bill->total)],
            'party' => null,
        ];
    }
}
