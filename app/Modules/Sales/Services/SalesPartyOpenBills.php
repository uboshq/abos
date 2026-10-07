<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Contracts\PartyOpenBills;
use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Models\SalesInvoice;
use Carbon\Carbon;

/**
 * গ্রাহকের খোলা বিক্রয় বিল — রসিদের "কোন বিলের বিপরীতে" তালিকা ([[PartyOpenBills]], Accounts-Finance অডিট ম১, ৪ অক্টোবর ২০২৬)।
 *
 * ⓘ বাকি = [[SalesInvoice::dueAmount()]] — আদায়, বিলে বাঁধা রসিদ আর পাকা ফেরত বাদ দিয়ে; বিলের নিজের পাতা আর আদায়ের পর্দা
 * ঠিক এই অঙ্কই দেখায়। ⛔ আলাদা করে মাপলে (আগের কাঁচা কোয়েরি) ফেরত বাদ যেত না, আর দুই পর্দায় দুই বাকি দেখাত।
 *
 * ⓘ রসিদ একটাই বিলের বিপরীতে বাঁধা যায় (`against_id`, গ২-এর যাচাইসহ); এক টাকায় বহু বিল আর "পুরনো বিল আগে" হয় বিক্রয়ের
 * "আদায়" পর্দায় — হিসাবের উৎস একটাই থাকে (৬৩-এর সিদ্ধান্ত, ৫ অক্টোবর ২০২৬)।
 */
final class SalesPartyOpenBills implements PartyOpenBills
{
    public function openBills(string $partyType, int $partyId, int $limit = 50): array
    {
        if ($partyType !== 'customer' || $partyId <= 0) {
            return [];
        }

        $today = now()->startOfDay();
        $open = [];

        $invoices = SalesInvoice::query()
            ->withCollected()
            ->where('customer_id', $partyId)
            ->where('status', DocumentStatus::CONFIRMED)
            ->orderBy('trx_date')
            ->orderBy('id')
            ->cursor();

        foreach ($invoices as $invoice) {
            $due = $invoice->dueAmount();

            if (bccomp($due, '0', 4) <= 0) {
                continue;
            }

            $date = Carbon::parse($invoice->trx_date)->startOfDay();

            $open[] = [
                'against_type' => SalesInvoice::drillSourceType(),
                'id' => (int) $invoice->id,
                'no' => (string) $invoice->document_no,
                'date' => $date->toDateString(),
                // ⓘ বয়স সার্ভারে — ফোনের ঘড়ি ভুল থাকলে ব্রাউজারে গোনা বয়সও ভুল হত
                'age' => (int) $date->diffInDays($today),
                'outstanding' => $due,
            ];

            if (count($open) >= $limit) {
                break;
            }
        }

        return $open;
    }
}
