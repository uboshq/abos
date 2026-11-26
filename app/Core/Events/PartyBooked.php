<?php

declare(strict_types=1);

namespace App\Core\Events;

use App\Models\LedgerEntry;

/**
 * ⭐ কোনো পক্ষের খাতায় একটা সারি বসল (লেনদেন পাকা হওয়ার পরে) — পক্ষের জের থেকে যা গোনা হয় তা আবার গোনার খবর (বিলের অটো বাকি;
 *   মালিক, ২৫ নভেম্বর ২০২৬)। ⛔ দাখিলা এই ঘটনায় হয় না — দাখিলা হয়ে গেছে; শ্রোতা কেবল গোনা অঙ্ক মেলায়, আর হারালেও খাতা ঠিক থাকে।
 */
final class PartyBooked extends DomainEvent
{
    public static function from(LedgerEntry $entry): self
    {
        return new self(
            publicId: (string) $entry->public_id,
            payload: ['party_type' => (string) $entry->party_type, 'party_id' => (int) $entry->party_id],
            companyId: (int) $entry->company_id,
        );
    }
}
