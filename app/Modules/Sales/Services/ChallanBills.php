<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * একটা চালানের বিল — কোনগুলো হয়েছে, আর বিল করার কিছু বাকি আছে কি না (লাইভের যাচাই, ২৯ সেপ্টেম্বর ২০২৬)।
 *
 * ⓘ এক জায়গায়, কারণ তিনজন একই প্রশ্ন করে: চালানের পাতা (বোতাম না লিংক), বিলের ফর্ম (খুলবে কি না),
 * আর বিলের দরজা ([[SalesInvoiceRequest]])। ⛔ বাতিল বিল গোনে না — সেবার নিজের হিসাবের মতোই
 * ([[DeliveryChallanLine::invoicedQty()]])।
 */
final class ChallanBills
{
    /**
     * এই চালানের সারি ধরে যে বিলগুলো হয়েছে (বাতিল বাদে), পুরনোটা আগে।
     *
     * @return Collection<int, SalesInvoice>
     */
    public function of(DeliveryChallan $challan): Collection
    {
        return SalesInvoice::query()
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->whereHas('lines.challanLine', fn (Builder $q) => $q->where('delivery_challan_id', $challan->id))
            ->orderBy('id')
            ->get();
    }

    /** চালানের কোনো সারির কিছু এখনো বিলে আসেনি কি। */
    public function leftToBill(DeliveryChallan $challan): bool
    {
        return $challan->lines->contains(fn ($line) => bccomp($line->uninvoicedQty(), '0', 4) > 0);
    }
}
