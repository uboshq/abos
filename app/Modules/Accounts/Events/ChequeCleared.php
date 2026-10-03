<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Events;

use App\Core\Events\DomainEvent;
use App\Modules\Accounts\Models\Cheque;

/**
 * ⭐ একটা চেক ব্যাংকে পাশ হলো — ২ অক্টোবর ২০২৬ (বিক্রয়ের কাজের ধারা, ধাপ গ)।
 *
 * ⓘ মালিকের নিয়ম: চেক কেবল ক্লিয়ার হলে টাকা (২৬ সেপ্টেম্বর)। টাকার জন্য আটকে থাকা ডেলিভারি অর্ডার এই মুহূর্তে
 * আবার যাচাই হয় — শোনে বিক্রয় মডিউল। ⛔ হিসাব কারও ওপর নির্ভর করে না: এখানে কেবল ঘোষণা, কে শুনবে তা জানে না।
 *
 * ⚠️ ছোটে লেনদেন পাকা হওয়ার **পরে** ([[ChequeService::clear()]]) — আগে ছুটলে শ্রোতা এমন পাশ দেখত যেটা
 * রোলব্যাকে মুছে যেতে পারে।
 */
final class ChequeCleared extends DomainEvent
{
    public static function from(Cheque $cheque): self
    {
        return new self(
            publicId: (string) $cheque->public_id,

            payload: [
                'cheque_id' => (int) $cheque->id,
                'direction' => (string) $cheque->direction,
                'party_type' => $cheque->party_type,
                'party_id' => $cheque->party_id !== null ? (int) $cheque->party_id : null,
                'amount' => (string) $cheque->amount,
            ],
        );
    }
}
