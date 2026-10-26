<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Core\Support\AmountInWords;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesOrder;

/**
 * বিক্রয় আদেশ আর আদায়ের রসিদের নতুন নকশার তথ্য — কাকে, কবে, কোন গুদাম; কোন বিলে কত জমা।
 *
 * মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"বিক্রয় আদেশ, আদায়ের রসিদ egulo doro"* — চালান-ভাউচারের সেই একই ২০ সাজে
 * ([[PaperLook]])। ⓘ পণ্যের সারি আর টাকার সারি আগের মতোই `$doc`-এ ([[SalesPrintController::order()]],
 * `receipt()`); এখানে কেবল যা চলতি কাগজে ছিল না।
 *
 * ⚠️ lazy loading local-এ বন্ধ — যা লাগে সব এক `loadMissing`-এ।
 */
final class OrderPaperFacts
{
    /** @return array<string, mixed> */
    public static function order(SalesOrder $order): array
    {
        $order->loadMissing(['customer.location.parent', 'customer.branch', 'warehouse', 'creator', 'quotation', 'lines.product.unit']);

        $byUnit = [];
        foreach ($order->lines as $line) {
            $unit = (string) $line->packedUnitName();
            $byUnit[$unit] = bcadd($byUnit[$unit] ?? '0', (string) $line->packedQty('ordered_qty'), 4);
        }

        return [
            'no' => (string) $order->document_no,
            'date' => DateFormat::format($order->trx_date),
            'deliver_on' => DateFormat::format($order->deliver_on),
            'quotation_no' => (string) ($order->quotation?->document_no ?? ''),
            'warehouse' => (string) ($order->warehouse?->name() ?? ''),
            'created_by' => (string) ($order->creator?->name ?? ''),
            'to' => self::party($order->customer),
            'items' => (string) $order->lines->count(),
            'total_qty' => self::byUnit($byUnit),
            'total' => Money::format($order->total),
            'words' => AmountInWords::of((string) $order->total, 'en'),
            'words_bn' => AmountInWords::of((string) $order->total, 'bn'),
        ];
    }

    /**
     * @param  array<string, string>  $withheld  ভাষার চাবি → অঙ্ক: গ্রাহকের কাটা VDS/AIT, ছাড়/রাউন্ডিং আর অপ্রয়োগকৃত — দফা ১৭
     *                                            ([[SalesPrintController::receipt()]] যা চলতি কাগজে দেয়, নকশার কাগজেও সেটাই)
     * @return array<string, mixed>
     */
    public static function receipt(Collection $collection, array $withheld = []): array
    {
        $collection->loadMissing(['customer.location.parent', 'customer.branch', 'account', 'creator', 'lines.invoice']);

        return [
            'no' => (string) $collection->document_no,
            'date' => DateFormat::format($collection->trx_date),
            'from' => self::party($collection->customer),
            'account' => (string) ($collection->account?->name() ?? ''),
            'instrument' => (string) ($collection->instrument ?? ''),
            'instrument_no' => (string) ($collection->instrument_no ?? ''),
            'instrument_date' => $collection->instrument_date === null ? '' : DateFormat::format($collection->instrument_date),
            'received_by' => (string) ($collection->creator?->name ?? ''),
            'bills' => $collection->lines->map(fn ($line) => [
                'no' => (string) ($line->invoice?->document_no ?? ''),
                'date' => $line->invoice?->trx_date === null ? '' : DateFormat::format($line->invoice->trx_date),
                'bill_total' => $line->invoice === null ? '' : Money::format($line->invoice->total),
                'amount' => Money::format($line->amount),
            ])->all(),
            'total' => Money::format($collection->amount),
            'words' => AmountInWords::of((string) $collection->amount, 'en'),
            'words_bn' => AmountInWords::of((string) $collection->amount, 'bn'),
            'narration' => (string) ($collection->narration ?? ''),
            /*
             * ⛔ আগে কেবল চলতি কাগজ (`print.document`) এগুলো ছাপত; রসিদের ডিফল্ট নকশা ট্যালি ক্লাসিক ([[PaperDesigns::defaultFor()]])
             * সহ সব নকশা ছাপত শুধু বিল আর মোট — গ্রাহকের কাটা VDS/AIT আর অপ্রয়োগকৃত টাকা রসিদে নেই (Mac, ২২ অক্টোবর ২০২৬, দফা ১৭)।
             */
            'withheld' => array_map(
                fn (string $label, string $amount) => ['label' => $label, 'amount' => Money::format($amount)],
                array_keys($withheld),
                array_values($withheld),
            ),
        ];
    }

    /** @return array{name: string, point: string, address: string, phone: string} */
    private static function party(?object $customer): array
    {
        $node = $customer?->location;
        $point = match (true) {
            $node?->level === Location::POINT => $node,
            $node?->level === Location::ROUTE && $node->parent?->level === Location::POINT => $node->parent,
            default => null,
        };

        return [
            'name' => (string) ($customer?->name('en') ?? ''),
            // ⭐ "পয়েন্ট · শাখা" — এক লাইনে, কাগজের মাপ অক্ষত ([[PaperPlace::of()]]; মালিক, ১৯ অক্টোবর ২০২৬)
            'point' => PaperPlace::of($customer instanceof Customer ? $customer : null, $point),
            'address' => (string) ($customer?->address('en') ?? ''),
            'phone' => (string) ($customer?->phone ?? ''),
        ];
    }

    /** @param array<string, string> $byUnit */
    private static function byUnit(array $byUnit): string
    {
        return implode(', ', array_map(function (string $unit, string $qty) {
            $formatted = rtrim(rtrim(Money::format($qty, 4), '0'), '.');

            return trim(($formatted === '' ? '0' : $formatted).' '.$unit);
        }, array_keys($byUnit), $byUnit));
    }
}
