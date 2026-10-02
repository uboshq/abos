<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Core\Support\AmountInWords;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Models\DeliveryChallan;
use Illuminate\Support\Facades\Route;

/**
 * চালানের নতুন নকশাগুলোর তথ্য — কাকে, কোথায়, কোন গাড়িতে, কোন আদেশে, আর QR।
 *
 * মালিক, ৩০ সেপ্টেম্বর ২০২৬: চালানের ২০টা নকশা। ⓘ পণ্যের সারি আগের মতোই `$doc->lines` থেকে
 * ([[SalesPrintController::productLines()]]) — এখানে কেবল যা চলতি কাগজে ছিল না: গ্রাহকের ঠিকানা-ফোন-পয়েন্ট,
 * আদেশের নম্বর, মোট পরিমাণ একক ধরে, আর স্ক্যানের ঠিকানা।
 *
 * ⚠️ lazy loading local-এ বন্ধ — যা লাগে সব এক `loadMissing`-এ।
 */
final class ChallanPaperFacts
{
    /** @return array<string, mixed> */
    public static function of(DeliveryChallan $challan): array
    {
        $challan->loadMissing([
            'customer.location.parent', 'order', 'warehouse', 'vehicle.vehicleType', 'creator',
            'lines.product.unit',
        ]);

        $customer = $challan->customer;
        $node = $customer?->location;
        $point = match (true) {
            $node?->level === Location::POINT => $node,
            $node?->level === Location::ROUTE && $node->parent?->level === Location::POINT => $node->parent,
            default => null,
        };

        // ⓘ মোট পরিমাণ একক ধরে — "৪ বস্তা, ১৪ পিস"; ভিন্ন একক যোগ করা অর্থহীন
        $byUnit = [];
        // ⭐ পরিমাণ + ফ্রি, একক ধরে — "মোট পরিমাণ" কলামের নিচের ঘর (মালিক, ২ অক্টোবর ২০২৬)
        $withFree = [];
        foreach ($challan->lines as $line) {
            $unit = (string) $line->packedUnitName();
            $packed = (string) $line->packedQty('delivered_qty');
            $byUnit[$unit] = bcadd($byUnit[$unit] ?? '0', $packed, 4);
            $withFree[$unit] = bcadd($withFree[$unit] ?? '0', bcadd($packed, self::freeInPacks($line), 4), 4);
        }
        $join = fn (array $sums) => implode(', ', array_map(
            fn (string $unit, string $qty) => trim(self::qty($qty).' '.$unit),
            array_keys($sums), $sums,
        ));
        $totalQty = $join($byUnit);

        return [
            'no' => (string) $challan->document_no,
            'date' => DateFormat::format($challan->trx_date),
            'ship_date' => DateFormat::format($challan->ship_date ?? $challan->trx_date),
            'order_no' => (string) ($challan->order?->document_no ?? ''),
            'warehouse' => (string) ($challan->warehouse?->name() ?? ''),
            'created_by' => (string) ($challan->creator?->name ?? ''),
            'to' => [
                'name' => (string) ($customer?->name('en') ?? ''),
                'point' => (string) ($point?->name('en') ?? ''),
                'address' => (string) ($challan->ship_to ?: ($customer?->address('en') ?? '')),
                'phone' => (string) ($customer?->phone ?? ''),
            ],
            'transport' => [
                'carrier' => (string) $challan->transportLabel(),
                'vehicle' => trim(implode(' ', array_filter([
                    (string) ($challan->vehicle?->vehicleType?->name() ?? ''),
                    (string) $challan->vehiclePlate(),
                ]))),
                'driver' => (string) ($challan->driver_name ?? ''),
                'driver_phone' => (string) ($challan->driver_phone ?? ''),
            ],
            'items' => (string) $challan->lines->count(),
            'total_qty' => $totalQty,
            'total_qty_with_free' => $join($withFree),
            'total' => Money::format($challan->total),
            'words' => AmountInWords::of((string) $challan->total, 'en'),
            'words_bn' => AmountInWords::of((string) $challan->total, 'bn'),
            // ⭐ সই-করা টোকেন (১ অক্টোবর ২০২৬) — QR-এ কেবল অস্বচ্ছ টোকেন, বাতিলে মরে ([[PaperToken]])
            'scan_url' => $challan->public_id !== null && Route::has('sales.qr')
                ? route('sales.qr', app(\App\Modules\Sales\Services\PaperToken::class)->for($challan))
                : '',
        ];
    }

    /** ফ্রি প্যাকের এককে — [[SalesPrintController::freeInPacks()]]-এর একই নিয়ম */
    private static function freeInPacks($line): string
    {
        $free = (string) ($line->free_qty ?? '0');
        $base = (string) ($line->delivered_qty ?? '0');

        return $line->wasEnteredInAPack() && bccomp($base, '0', 6) !== 0
            ? bcdiv(bcmul($free, (string) $line->entered_qty, 8), $base, 4)
            : $free;
    }

    private static function qty(string $value): string
    {
        $formatted = rtrim(rtrim(Money::format($value, 4), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
