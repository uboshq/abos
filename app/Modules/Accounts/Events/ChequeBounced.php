<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Events;

use App\Core\Events\DomainEvent;
use App\Modules\Accounts\Models\Cheque;

/**
 * ⭐ একটা চেক ফেরত গেল — বাকি নিয়ন্ত্রণ ধাপ ২.৪ (মালিকের উত্তর ৪, ১১ অক্টোবর ২০২৬)।
 *
 * ⓘ ফেরতের প্রতিটা পথ একই জায়গা দিয়ে যায় ([[ChequeService::markAs()]]) — আদায়ের চেক, রসিদের চেক, চেকের নিজের দাখিলা,
 * পাশের পরে ফেরত। শোনে বাকি নিয়ন্ত্রণ: গ্রাহকের বাকি বন্ধ, মালিককে খবর। ⛔ এখানে কেবল ঘোষণা, কে শুনবে তা জানে না।
 *
 * ⚠️ ছোটে লেনদেনের **ভিতরে**, ইচ্ছা করে: বাকি বন্ধ আর চেক ফেরত একসাথে পাকা হয়, নয়তো কোনোটাই না — ফেরত বসল অথচ বাকি
 * খোলা, এমন মুহূর্ত থাকে না। খবরটা শ্রোতা নিজে লেনদেন মিটলে পাঠায়।
 */
final class ChequeBounced extends DomainEvent
{
    public static function from(Cheque $cheque): self
    {
        return new self(
            publicId: (string) $cheque->public_id,

            payload: [
                'cheque_id' => (int) $cheque->id,
                'cheque_no' => (string) $cheque->cheque_no,
                'company_id' => (int) $cheque->company_id,
                'direction' => (string) $cheque->direction,
                'party_type' => $cheque->party_type,
                'party_id' => $cheque->party_id !== null ? (int) $cheque->party_id : null,
                'amount' => (string) $cheque->amount,
            ],
        );
    }
}
