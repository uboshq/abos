<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Events;

use App\Core\Events\DomainEvent;
use App\Modules\Accounts\Models\Cheque;

/**
 * ⭐ একটা চেক ফেরত এলো — মালিকের উত্তর ৪, ১১ অক্টোবর ২০২৬ (বাকি নিয়ন্ত্রণ ধাপ ২.৪)।
 *
 * ⓘ মালিক: *"চেক ফেরত এলে গ্রাহক নিজে থেকেই বন্ধ"*। শোনে গ্রাহক মডিউল ([[BlockTheCustomerOnABouncedCheque]])। ⛔ হিসাব এখানে
 * কারও ওপর নির্ভর করে না — কেবল ঘোষণা; চেকের প্রতিটা ফেরতের পথ একটা জায়গা দিয়েই যায় ([[ChequeService::markAs()]]), তাই
 * কোনো পথ বাদ পড়ে না (আদায়ের কাগজের চেক, রসিদ ভাউচারের চেক, নতুন নিয়মের চেক, সই পড়ার পরে ফেরত)।
 *
 * ⚠️ ছোটে লেনদেন পাকা হওয়ার **পরে** — রোলব্যাকে মুছে যাওয়া ফেরতের জন্য কাউকে বন্ধ করা হয় না।
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
                'direction' => (string) $cheque->direction,
                'party_type' => $cheque->party_type,
                'party_id' => $cheque->party_id !== null ? (int) $cheque->party_id : null,
                'amount' => (string) $cheque->amount,
            ],

            companyId: (int) $cheque->company_id,
        );
    }
}
