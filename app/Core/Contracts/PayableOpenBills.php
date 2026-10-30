<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * ⭐ এক সরবরাহকারীর যে বিলগুলো এখনো পুরো শোধ হয়নি — পরিশোধ ভাউচারের খোলা বিলের কার্ড (cb-ma-5, ২৫ অক্টোবর ২০২৬; fe-র সিদ্ধান্ত ক:
 * নকশা পাতা ৪ "১০০%")।
 *
 * ⓘ রসিদের [[PartyOpenBills]]-এর হুবহু আকার আর নিয়ম — বিলের মালিক মডিউল (ক্রয়) নিজের একমাত্র নিয়মে বাকি মাপে
 * ([[\App\Modules\Purchase\Models\PurchaseBill::dueAmount()]]), হিসাব কেবল এই চুক্তি চেনে। ক্রয় বন্ধ থাকলে [[\App\Core\Services\NoPayableOpenBills]]
 * খালি তালিকা দেয়, মিথ্যা তালিকা নয়।
 */
interface PayableOpenBills
{
    /**
     * শেষ দিন আগে (সরবরাহকারী-পরিশোধের "শেষ দিনের ক্রমে"); `public_id` এক পরিশোধে বহু বিলের চাবি (`bills[i][id]`)।
     *
     * @return list<array{against_type: string, id: int, no: string, date: string, age: int, outstanding: string, due_date: string, days_late: int, total: string, public_id: string}>
     */
    public function openBills(string $partyType, int $partyId, int $limit = 50): array;
}
