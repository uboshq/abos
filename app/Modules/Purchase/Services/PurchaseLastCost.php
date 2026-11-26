<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Contracts\LastPurchaseCost;
use App\Core\Support\DateFormat;
use App\Core\Support\DocumentStatus;
use App\Modules\Purchase\Models\PurchaseBillLine;

/**
 * ⭐ শেষ পোস্ট হওয়া ক্রয়ের খরচ — দর, ছাড়, আনলোডিং (সারির নিজের ভাগ, না থাকলে বিলের আনলোডিং টাকার অনুপাতে), প্রতি একক মোট,
 * ফ্রি ধরে প্রতি একক, বিলের নম্বর আর নোট ([[LastPurchaseCost]]; মালিক, ২৬ নভেম্বর ২০২৬)। ⓘ কিছুই লেখে না; কোম্পানি-সীমা মডেলের।
 */
final class PurchaseLastCost implements LastPurchaseCost
{
    public function of(int $productId): ?array
    {
        $line = PurchaseBillLine::query()
            ->where('product_id', $productId)
            ->whereHas('bill', fn ($q) => $q->whereIn('status', DocumentStatus::POSTED))
            ->with(['bill.supplier'])
            ->latest('id')
            ->first();

        if ($line === null) {
            return null;
        }

        $bill = $line->bill;
        $qty = (string) $line->qty;
        $free = (string) ($line->free_qty ?? '0');
        $per = fn (string $v): string => bccomp($qty, '0', 4) > 0 ? bcdiv($v, $qty, 4) : '0';

        // ⓘ আনলোডিং — সারির নিজের ভাগ; না থাকলে বিলের আনলোডিং টাকা সারির টাকার অনুপাতে (সরবরাহকারী দিলে নয়)
        $unload = (string) ($line->unload_cost ?? '0');
        if (bccomp($unload, '0', 4) === 0 && bccomp((string) ($bill->unload_amount ?? '0'), '0', 4) > 0
            && bccomp((string) $bill->subtotal, '0', 4) > 0 && $bill->unload_mode !== 'supplier') {
            $unload = bcdiv(bcmul((string) $bill->unload_amount, (string) $line->amount, 8), (string) $bill->subtotal, 4);
        }

        $rate = (string) $line->rate;
        $discount = $per((string) ($line->discount ?? '0'));
        $unloadEach = $per($unload);
        $total = bcadd(bcsub($rate, $discount, 4), $unloadEach, 4);
        $withFree = bccomp($free, '0', 4) > 0 && bccomp(bcadd($qty, $free, 4), '0', 4) > 0
            ? bcdiv(bcmul($total, $qty, 8), bcadd($qty, $free, 4), 4) : null;

        return [
            'rate' => $rate,
            'discount' => $discount,
            'unload' => $unloadEach,
            'total' => $total,
            'qty' => $qty,
            'free' => $free,
            'withFree' => $withFree,
            'billNo' => (string) $bill->document_no,
            'supplierBillNo' => (string) ($bill->supplier_bill_no ?? ''),
            'date' => DateFormat::format($bill->trx_date),
            'supplier' => (string) ($bill->supplier?->name() ?? ''),
            'notes' => array_values(array_filter([(string) ($line->narration ?? ''), (string) ($bill->narration ?? '')])),
        ];
    }
}
