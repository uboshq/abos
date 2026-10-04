<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * হোমের ছাঁকনির উপকরণ — মালিক, ৪ অক্টোবর ২০২৬: হোমের "ফিল্টার" (গুদাম/এলাকা/SR) বদলায় কেবল বিক্রি আর বকেয়া।
 *
 * ⓘ কোর কোনো মডিউল চেনে না; গুদাম, এলাকার গাছ আর কে বিল কেটেছেন — সেটা বিক্রয় জানে। ⓘ [[CustomerSalesFilters]]-এর
 * ধাঁচে: কোর প্রশ্নটা রাখে ([[HomeFilter]]), বিক্রয় উত্তর দেয়; বিক্রয় বন্ধ থাকলে [[NoHomeSalesFilters]] — বাছার কিছু নেই।
 */
interface HomeSalesFilters
{
    /**
     * বাছার তালিকা — কেবল এই কোম্পানির; এর বাইরের নম্বর ছাঁকনি নেয় না।
     *
     * @return array{warehouses: array<int, string>, areas: array<int, string>, sellers: array<int, string>}
     */
    public function choices(): array;

    /**
     * একটা এলাকার গ্রাহক — বিক্রয়ের "এলাকা ধরে" যে নিয়মে গোনে, হুবহু সেই নিয়মে।
     *
     * @return list<int>
     */
    public function customersInArea(int $areaId): array;
}
