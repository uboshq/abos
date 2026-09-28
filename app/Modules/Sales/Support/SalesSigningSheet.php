<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryChallanLine;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;

/**
 * চালান আর বিলের সইয়ের পাতা — ২৮ সেপ্টেম্বর ২০২৬ ([[ShowsItselfForSigning]])।
 *
 * ── ⭐ কেন আগে এই দুইটা ────────────────────────────────────────────────
 * সমন্বয়কারী (abos-69), মালিকের সবচেয়ে বড় দরকার: কাউন্টারের বিক্রি সই
 * চাইলে সইকারী দেখেন কেবল নাম আর অঙ্ক। ⛔ কোন পণ্য, কত, কত ফ্রি, কোন দরে —
 * কিছুই না; অথচ ঠিক ওখানেই ভুল (বা ইচ্ছাকৃত ছাড়) লুকায়।
 *
 * ⓘ ঘরগুলো চালান ও বিলের নিজের পাতার মতোই (পণ্য = কোড - নাম, পরিমাণ ও দর
 * Money::format)। ⓘ পক্ষ সবসময় গ্রাহক — পাশের কার্ড আসে [[CustomerCardFacts]]
 * থেকে (ফোন, পয়েন্ট, ঠিকানা, বকেয়া, বাকির সীমা)।
 *
 * ⚠️ এক জায়গায়, দুই মডেলে নয়: দুইটা কাগজ একই প্রশ্নের উত্তর দেয়, আর আলাদা
 * লিখলে একদিন একটায় "ফ্রি" ঘর থাকত, অন্যটায় নয়।
 */
final class SalesSigningSheet
{
    public static function ofChallan(DeliveryChallan $challan): array
    {
        $challan->loadMissing(['lines.product', 'customer', 'warehouse']);

        return self::sheet(
            facts: [
                __('sales::field.customer') => $challan->customer?->name(),
                __('core.print.date') => DateFormat::format($challan->trx_date),
                __('sales::field.warehouse') => $challan->warehouse?->name(),
                __('sales::field.vehicle_no') => $challan->vehicle_no,
                __('sales::field.driver_name') => $challan->driver_name,
                __('core.table.narration') => $challan->narration ?? null,
            ],
            rows: $challan->lines->map(fn (DeliveryChallanLine $l) => self::row(
                $l->product?->code.' - '.$l->product?->name(),
                (string) $l->delivered_qty,
                (string) $l->free_qty,
                (string) $l->rate,
                (string) $l->amount,
            ))->values()->all(),
            total: (string) $challan->total,
            customerId: $challan->customer_id,
        );
    }

    public static function ofInvoice(SalesInvoice $invoice): array
    {
        $invoice->loadMissing(['lines.product', 'lines.challanLine', 'customer', 'warehouse']);

        return self::sheet(
            facts: [
                __('sales::field.customer') => $invoice->customer?->name(),
                __('core.print.date') => DateFormat::format($invoice->trx_date),
                __('sales::field.due_on') => DateFormat::format($invoice->due_on),
                __('sales::field.warehouse') => $invoice->warehouse?->name(),
                __('sales::field.subtotal') => Money::format($invoice->subtotal),
                __('sales::field.discount') => bccomp((string) $invoice->discount, '0', 4) > 0
                    ? Money::format($invoice->discount) : null,
                __('core.table.narration') => $invoice->narration ?? null,
            ],
            rows: $invoice->lines->map(fn (SalesInvoiceLine $l) => self::row(
                $l->product?->code.' - '.$l->product?->name(),
                (string) $l->qty,
                // ⓘ বিলের সারিতে ফ্রি নেই — ফ্রি থাকে যে চালান থেকে বিল হলো তার সারিতে
                (string) ($l->challanLine?->free_qty ?? '0'),
                (string) $l->rate,
                (string) $l->amount,
            ))->values()->all(),
            total: (string) $invoice->total,
            customerId: $invoice->customer_id,
        );
    }

    /**
     * @param  array<string, string|null>  $facts
     * @param  list<array<string, string|null>>  $rows
     */
    private static function sheet(array $facts, array $rows, string $total, ?int $customerId): array
    {
        $facts = array_filter($facts, fn ($v) => filled($v));

        return [
            'facts' => array_map(
                fn ($label, $value) => ['label' => (string) $label, 'value' => (string) $value],
                array_keys($facts),
                $facts,
            ),
            'columns' => [
                ['key' => 'product', 'label' => __('sales::field.product')],
                ['key' => 'qty', 'label' => __('sales::field.quantity'), 'numeric' => true],
                ['key' => 'free', 'label' => __('sales::field.free_qty'), 'numeric' => true],
                ['key' => 'rate', 'label' => __('sales::field.rate'), 'numeric' => true],
                ['key' => 'amount', 'label' => __('sales::field.amount'), 'numeric' => true],
            ],
            'rows' => $rows,
            'totals' => ['amount' => Money::format($total)],
            'party' => $customerId === null ? null : ['type' => 'customer', 'id' => (int) $customerId],
        ];
    }

    /** @return array<string, string|null> */
    private static function row(string $product, string $qty, string $free, string $rate, string $amount): array
    {
        return [
            'product' => $product,
            'qty' => Money::format($qty),
            // ⓘ শূন্য ফ্রি ফাঁকা — নাহলে প্রতিটা সারিতে "০.০০" চোখ টানত আর আসল ফ্রি হারাত
            'free' => bccomp($free, '0', 4) > 0 ? Money::format($free) : null,
            'rate' => Money::format($rate),
            'amount' => Money::format($amount),
        ];
    }
}
