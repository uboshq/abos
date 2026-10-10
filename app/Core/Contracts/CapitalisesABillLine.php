<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use Illuminate\Support\Carbon;

/**
 * ⭐ পাকা ক্রয় বিলের একটা সারি স্থায়ী সম্পদে তোলা — "এই বিলের ফ্রিজটা সম্পদ" (মালিক, ১০ অক্টোবর ২০২৬; IAS 16.15)।
 *
 * ⓘ হিসাব ক্রয়কে চেনে না, এই চুক্তি চেনে। ক্রয় বন্ধ থাকলে [[NoCapitalisesABillLine]] — তালিকা খালি, তোলা যায় না।
 *
 * ⛔ কেনাটা দুইবার নয়: বিল পাকা হওয়ার দিনই খাতায় বসেছে (মজুদ ডেবিট / বিক্রেতার পাওনা ক্রেডিট)। সম্পদে তোলা মানে
 * কেবল মালটা মজুদ থেকে সম্পদে সরানো — মজুদ থেকে বেরোয় (পরিমাণ আর দাম দুইটাই), সম্পদের খাতে ঢোকে। বিক্রেতার
 * পাওনা একটুও নড়ে না।
 */
interface CapitalisesABillLine
{
    /**
     * পাকা বিলের সারি — খোঁজা শব্দে (বিল নম্বর, পণ্য, সরবরাহকারী), নতুন আগে।
     *
     * @return list<array{id: int, bill_id: int, bill_no: string, date: string, supplier_id: ?int, supplier: string,
     *     product: string, qty: string, unit_cost: string, branch_id: ?int}>
     */
    public function lines(?string $term = null, int $limit = 50): array;

    /** @return array{id: int, bill_id: int, bill_no: string, date: string, supplier_id: ?int, supplier: string, product: string, qty: string, unit_cost: string, branch_id: ?int}|null */
    public function line(int $lineId): ?array;

    /**
     * মালটা মজুদ থেকে সম্পদের খাতে — মজুদের নিজের দাখিলায় (সম্পদ ডেবিট / মজুদ ক্রেডিট), মজুদের দামে।
     *
     * @return string যত টাকার মাল সরল — সম্পদের দাম এটাই
     */
    public function capitalise(int $lineId, string $qty, int $assetAccountId, Carbon $on, string $narration): string;
}
