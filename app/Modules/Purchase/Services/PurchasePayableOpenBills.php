<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Contracts\PayableOpenBills;
use App\Core\Support\DocumentStatus;
use App\Modules\Purchase\Models\PurchaseBill;
use Carbon\Carbon;

/**
 * সরবরাহকারীর খোলা ক্রয় বিল — পরিশোধ ভাউচারের "খোলা বিল" কার্ড ([[PayableOpenBills]]; cb-ma-5, ২৫ অক্টোবর ২০২৬)।
 *
 * ⓘ বাকি = [[PurchaseBill::dueAmount()]] — উৎসে কাটা, পরিশোধ, পরিশোধ ভাউচার (একক আর বহু বিলের সারি) আর পোস্ট হওয়া ফেরত বাদ দিয়ে;
 * বিলের পাতা, সরবরাহকারী-পরিশোধ আর বকেয়ার রিপোর্ট ঠিক এই অঙ্কই দেখায়। ⓘ ছাঁকনি ডাটাবেজে ([[PurchaseBill::scopeStillOwed()]]),
 * যাতে পুরনো বাকি বিল তালিকা থেকে না হারায় (ক্রয় ⚠️১৩)।
 */
final class PurchasePayableOpenBills implements PayableOpenBills
{
    public function openBills(string $partyType, int $partyId, int $limit = 50): array
    {
        if ($partyType !== 'supplier' || $partyId <= 0) {
            return [];
        }

        $today = now()->startOfDay();
        $open = [];

        // ⛔ শাখার দেয়াল ছাড়া — বিল গুদামের শাখায়, পরিশোধ লেখকের শাখায় (ক্রয় ⚠️৯, [[PurchaseBill::paidAmount()]])
        $bills = PurchaseBill::acrossBranches()
            ->withPaid()
            ->stillOwed()
            ->where('supplier_id', $partyId)
            ->where('status', DocumentStatus::CONFIRMED)
            ->orderByRaw('COALESCE(pur_bills.due_on, pur_bills.trx_date)')
            ->orderBy('pur_bills.id')
            ->limit($limit)
            ->get();

        foreach ($bills as $bill) {
            $due = $bill->dueAmount();

            if (bccomp($due, '0', 4) <= 0) {
                continue;
            }

            $date = Carbon::parse($bill->trx_date)->startOfDay();
            // ⓘ শেষ দিন না থাকলে বিলের দিনই — বিক্রয়ের একই নিয়ম ([[\App\Modules\Sales\Services\SalesPartyOpenBills]])
            $dueDate = $bill->due_on !== null ? Carbon::parse($bill->due_on)->startOfDay() : $date;

            $open[] = [
                'against_type' => PurchaseBill::drillSourceType(),
                'id' => (int) $bill->id,
                // ⓘ নকশার "বিল · সরবরাহকারীর নং" — আমাদের নম্বরের পাশে সরবরাহকারীর নিজের নম্বর
                'no' => trim($bill->document_no.' '.($bill->supplier_bill_no !== null && $bill->supplier_bill_no !== '' ? '('.$bill->supplier_bill_no.')' : '')),
                'date' => $date->toDateString(),
                'age' => (int) $date->diffInDays($today),
                'outstanding' => $due,
                'due_date' => $dueDate->toDateString(),
                'days_late' => $dueDate->lessThan($today) ? (int) $dueDate->diffInDays($today) : 0,
                'total' => (string) $bill->total,
                'public_id' => (string) $bill->public_id,
            ];
        }

        return $open;
    }
}
