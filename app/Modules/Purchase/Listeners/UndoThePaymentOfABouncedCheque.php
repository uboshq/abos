<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Listeners;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Events\ChequeBounced;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Purchase\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * ⛔ সরবরাহকারীকে দেওয়া চেক ফেরত এলে তার পরিশোধও ফেরত — fe, ১৩ অক্টোবর ২০২৬ (Purchase লাইন-ধরা নিরীক্ষা;
 * [[ASupplierChequeBouncedAndTheBillStayedPaidTest]])।
 *
 * ⚠️ আগে Purchase-এ কেউ শুনত না: চেক ফেরতে খাতা ঠিকই উল্টাত (Dr ২১১৫ দেওয়া চেক / Cr সরবরাহকারীর দেনা, পক্ষসহ, ফেরতের তারিখে —
 * [[ChequeService::bounce()]]), কিন্তু পরিশোধটা "নিশ্চিত" থেকে যেত — বিল "পরিশোধিত", সূচি আর প্রস্তাবে বাকি ০, অথচ খাতায় দেনা ফেরত।
 * আবার শোধও করা যেত না, আর পরিশোধটা বাতিলও না (ফেরত চেক আর বাতিল হয় না)।
 *
 * ⭐ এখন পরিশোধ "চেক ফেরত" ({@see Payment::BOUNCED}) — বিলের পরিশোধ-গোনা কেবল পাকা পরিশোধ নেয় ([[PurchaseBill::paidAmount()]]),
 * তাই বিল আবার বাকি। খাতায় এখানে কিছু লেখা হয় না — উল্টো দাখিলা চেকের নিজের। ⓘ গ্রাহকের চেকের পথের মতো: চেক ফেরতের সই চালু
 * থাকলে ঘটনাটা সইয়ের শেষেই আসে।
 */
final class UndoThePaymentOfABouncedCheque
{
    public function handle(ChequeBounced $event): void
    {
        if (($event->payload['direction'] ?? null) !== Cheque::ISSUED || ($event->payload['party_type'] ?? null) !== 'supplier') {
            return;
        }

        CompanyContext::forCompany($event->companyId, function () use ($event): void {
            DB::transaction(function () use ($event): void {
                $payment = Payment::acrossBranches()
                    ->where('cheque_id', (int) ($event->payload['cheque_id'] ?? 0))
                    ->lockForUpdate()
                    ->first();

                // ⓘ একই ঘটনা দুবার এলে, বা পরিশোধ আগেই বাতিল — কিছুই নয়
                if ($payment === null || $payment->status !== DocumentStatus::CONFIRMED) {
                    return;
                }

                $payment->forceFill(['status' => Payment::BOUNCED])->save();
            });
        });
    }
}
